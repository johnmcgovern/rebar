//! Cache store shared by every PHP process on the machine, backed by LMDB.
//!
//! LMDB memory-maps one file; readers never block and writers are serialized
//! with a cross-process lock. With the file on tmpfs (/dev/shm) it is
//! effectively shared memory. Cache data is disposable, so durability is
//! traded away (no fsync) for speed.

use crate::store::{is_expired, BinStats, EntryView, Store, StoreResult};
use heed::types::Bytes;
use heed::{Database, Env, EnvFlags, EnvOpenOptions, MdbError};
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, HashMap};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{LazyLock, Mutex};

/// LMDB's default maximum key size.
const MAX_KEY: usize = 511;
/// Fixed header: created (f64), expire (i64), tags len (u32), checksum len (u32).
const HEADER: usize = 8 + 8 + 4 + 4;

/// Holds the store's epoch. Bin keys never start with NUL, so it can't clash.
const EPOCH_KEY: &[u8] = b"\0epoch";

/// Times the store filled up and was flushed, in this process.
pub static EVICTIONS: AtomicU64 = AtomicU64::new(0);

pub struct SharedStore {
    /// The process that opened `env`. LMDB handles must not cross fork().
    pid: u32,
    map_size: usize,
    env: Env,
    db: Database<Bytes, Bytes>,
}

/// Open stores, by path. A process can use several (tests do), and callers
/// always name the one they mean, so opening one never redirects another.
static SHARED: LazyLock<Mutex<HashMap<String, SharedStore>>> = LazyLock::new(Default::default);

/// Opens (or reuses) the store at `path`. Safe to call on every request.
pub fn open(path: &str, map_size: usize) -> StoreResult<()> {
    let mut stores = SHARED.lock().unwrap();
    let pid = std::process::id();
    if let Some(s) = stores.get(path) {
        if s.pid == pid && s.map_size == map_size {
            return Ok(());
        }
    }
    // A handle inherited across fork() must not be used or closed by the
    // child (closing would release the parent's reader slot), so leak it.
    if let Some(old) = stores.remove(path) {
        if old.pid != pid {
            std::mem::forget(old);
        }
    }
    std::fs::create_dir_all(path).map_err(|e| format!("rebar: cannot create {path}: {e}"))?;
    // SAFETY: the environment is opened once per process (enforced above) and
    // the flags only give up durability, which a cache does not need.
    let env = unsafe {
        EnvOpenOptions::new()
            .map_size(map_size)
            .max_readers(1024)
            .flags(EnvFlags::NO_SYNC | EnvFlags::NO_META_SYNC | EnvFlags::WRITE_MAP | EnvFlags::MAP_ASYNC)
            .open(path)
    }
    .map_err(err)?;
    // Reader slots of processes that died mid-read would otherwise leak.
    env.clear_stale_readers().map_err(err)?;
    let mut wtxn = env.write_txn().map_err(err)?;
    let db: Database<Bytes, Bytes> = env.create_database(&mut wtxn, None).map_err(err)?;
    if db.get(&wtxn, EPOCH_KEY).map_err(err)?.is_none() {
        db.put(&mut wtxn, EPOCH_KEY, &new_epoch().to_le_bytes()).map_err(err)?;
    }
    wtxn.commit().map_err(err)?;
    stores.insert(path.to_owned(), SharedStore { pid, map_size, env, db });
    Ok(())
}

/// Runs `f` with the store at `path`; errors if this process hasn't opened it.
pub fn with<R>(path: &str, f: impl FnOnce(&SharedStore) -> StoreResult<R>) -> StoreResult<R> {
    let stores = SHARED.lock().unwrap();
    match stores.get(path) {
        Some(s) if s.pid == std::process::id() => f(s),
        _ => Err(format!("rebar: shared store {path} not open in this process; call rebar_shared_open()")),
    }
}

/// A random offset for cache tag counters, chosen whenever the store is
/// created or cleared. Checksums are sums of counters, so after a reset (a
/// flush, or a reboot wiping /dev/shm) every old checksum stops matching, even
/// for items kept in other backends such as the database. Without it, a tag
/// invalidated once before the reset would count 0 again and stale items
/// cached before that invalidation would look valid.
fn new_epoch() -> u64 {
    use std::hash::{BuildHasher, RandomState};
    RandomState::new().hash_one((std::time::SystemTime::now(), std::process::id())) % (1 << 31) + 1
}

fn err(e: heed::Error) -> String {
    format!("rebar shared store: {e}")
}

/// "<bin>\0" — bins never contain NUL, so this prefix selects exactly one bin.
fn bin_prefix(bin: &str) -> Vec<u8> {
    let mut k = Vec::with_capacity(bin.len() + 1);
    k.extend_from_slice(bin.as_bytes());
    k.push(0);
    k
}

/// "<bin>\0<cid>", or "<bin>\0#<sha256>" when that would exceed LMDB's limit.
fn key(bin: &str, cid: &str) -> Vec<u8> {
    let mut k = bin_prefix(bin);
    if k.len() + cid.len() <= MAX_KEY {
        k.extend_from_slice(cid.as_bytes());
    } else {
        k.push(b'#');
        for b in Sha256::digest(cid.as_bytes()) {
            k.extend_from_slice(format!("{b:02x}").as_bytes());
        }
    }
    k
}

fn encode(e: &EntryView) -> Vec<u8> {
    let mut v = Vec::with_capacity(HEADER + e.tags.len() + e.checksum.len() + e.data.len());
    v.extend_from_slice(&e.created.to_le_bytes());
    v.extend_from_slice(&e.expire.to_le_bytes());
    v.extend_from_slice(&(e.tags.len() as u32).to_le_bytes());
    v.extend_from_slice(&(e.checksum.len() as u32).to_le_bytes());
    v.extend_from_slice(e.tags.as_bytes());
    v.extend_from_slice(e.checksum.as_bytes());
    v.extend_from_slice(e.data);
    v
}

fn decode(v: &[u8]) -> StoreResult<EntryView<'_>> {
    let corrupt = || "rebar shared store: corrupt entry".to_string();
    if v.len() < HEADER {
        return Err(corrupt());
    }
    let created = f64::from_le_bytes(v[0..8].try_into().unwrap());
    let expire = i64::from_le_bytes(v[8..16].try_into().unwrap());
    let tags_len = u32::from_le_bytes(v[16..20].try_into().unwrap()) as usize;
    let checksum_len = u32::from_le_bytes(v[20..24].try_into().unwrap()) as usize;
    let rest = v.get(HEADER..).ok_or_else(corrupt)?;
    let (tags, rest) = rest.split_at_checked(tags_len).ok_or_else(corrupt)?;
    let (checksum, data) = rest.split_at_checked(checksum_len).ok_or_else(corrupt)?;
    Ok(EntryView {
        data,
        created,
        expire,
        tags: std::str::from_utf8(tags).map_err(|_| corrupt())?,
        checksum: std::str::from_utf8(checksum).map_err(|_| corrupt())?,
    })
}

/// Rewrites the expire field in place in an encoded entry.
fn with_expire(v: &[u8], expire: i64) -> Vec<u8> {
    let mut out = v.to_vec();
    out[8..16].copy_from_slice(&expire.to_le_bytes());
    out
}

fn is_map_full(e: &heed::Error) -> bool {
    matches!(e, heed::Error::Mdb(MdbError::MapFull))
}

/// Flush the store once live data pages pass this share of the map. LMDB
/// never overwrites pages in place, so even deleting needs free pages: a
/// completely full map cannot be cleared. Evicting early keeps headroom.
const EVICT_AT_PERCENT: usize = 70;

impl SharedStore {
    /// Runs a write transaction, then evicts everything if the map is getting
    /// full (like APCu does when it runs out of memory). A MAP_FULL error is
    /// still handled by flushing and retrying once.
    fn write(&self, f: impl Fn(&mut heed::RwTxn) -> heed::Result<()>) -> StoreResult<()> {
        let attempt = || -> heed::Result<()> {
            let mut wtxn = self.env.write_txn()?;
            f(&mut wtxn)?;
            wtxn.commit()
        };
        match attempt() {
            Err(e) if is_map_full(&e) => {
                self.clear().map_err(err)?;
                attempt().map_err(err)
            }
            Err(e) => Err(err(e)),
            Ok(()) => {
                let st = self.env.stat();
                let used = (st.branch_pages + st.leaf_pages + st.overflow_pages) * st.page_size as usize;
                if used * 100 > self.map_size * EVICT_AT_PERCENT {
                    self.clear().map_err(err)?;
                }
                Ok(())
            }
        }
    }

    /// Empties the store. The new epoch is written in the same transaction,
    /// so no reader can see counters without one.
    fn clear(&self) -> heed::Result<()> {
        EVICTIONS.fetch_add(1, Ordering::Relaxed);
        let mut wtxn = self.env.write_txn()?;
        self.db.clear(&mut wtxn)?;
        self.db.put(&mut wtxn, EPOCH_KEY, &new_epoch().to_le_bytes())?;
        wtxn.commit()
    }

    /// Returns epoch + invalidation count for every tag, in order.
    pub fn tag_counts(&self, bin: &str, tags: &[String]) -> StoreResult<Vec<u64>> {
        let rtxn = self.env.read_txn().map_err(err)?;
        let epoch = read_u64(self.db.get(&rtxn, EPOCH_KEY).map_err(err)?);
        tags.iter()
            .map(|t| Ok(epoch + read_u64(self.db.get(&rtxn, &key(bin, t)).map_err(err)?)))
            .collect()
    }

    /// Deletes all of a bin's tag counters and starts a new epoch, so every
    /// checksum computed before is invalid.
    pub fn purge_tags(&self, bin: &str) -> StoreResult<()> {
        let prefix = bin_prefix(bin);
        self.write(|txn| {
            let mut iter = self.db.prefix_iter_mut(txn, &prefix)?;
            while iter.next().transpose()?.is_some() {
                // SAFETY: no reference into the database is held across this call.
                unsafe { iter.del_current()? };
            }
            drop(iter);
            self.db.put(txn, EPOCH_KEY, &new_epoch().to_le_bytes())
        })
    }

    /// Increments each tag's invalidation count, in one transaction.
    pub fn invalidate_tags(&self, bin: &str, tags: &[String]) -> StoreResult<()> {
        let keys: Vec<_> = tags.iter().map(|t| key(bin, t)).collect();
        self.write(|txn| {
            for k in &keys {
                let n = read_u64(self.db.get(txn, k)?) + 1;
                self.db.put(txn, k, &n.to_le_bytes())?;
            }
            Ok(())
        })
    }
}

fn read_u64(v: Option<&[u8]>) -> u64 {
    v.and_then(|b| b.try_into().ok()).map(u64::from_le_bytes).unwrap_or(0)
}

impl Store for SharedStore {
    fn set(&self, bin: &str, cid: &str, entry: EntryView) -> StoreResult<()> {
        let (k, v) = (key(bin, cid), encode(&entry));
        // An item this big could never fit next to the rest; treat as a miss.
        if v.len() > self.map_size / 8 {
            return Ok(());
        }
        self.write(|txn| self.db.put(txn, &k, &v))
    }

    fn get_multiple(
        &self,
        bin: &str,
        cids: &[String],
        found: &mut dyn FnMut(&str, EntryView) -> StoreResult<()>,
    ) -> StoreResult<()> {
        let rtxn = self.env.read_txn().map_err(err)?;
        for cid in cids {
            if let Some(v) = self.db.get(&rtxn, &key(bin, cid)).map_err(err)? {
                found(cid, decode(v)?)?;
            }
        }
        Ok(())
    }

    fn delete_multiple(&self, bin: &str, cids: &[String]) -> StoreResult<()> {
        let keys: Vec<_> = cids.iter().map(|c| key(bin, c)).collect();
        self.write(|txn| {
            for k in &keys {
                self.db.delete(txn, k)?;
            }
            Ok(())
        })
    }

    fn delete_all(&self, bin: &str) -> StoreResult<()> {
        let prefix = bin_prefix(bin);
        self.write(|txn| {
            let mut iter = self.db.prefix_iter_mut(txn, &prefix)?;
            while iter.next().transpose()?.is_some() {
                // SAFETY: no reference into the database is held across this call.
                unsafe { iter.del_current()? };
            }
            Ok(())
        })
    }

    fn set_expire(&self, bin: &str, cids: Option<&[String]>, expire: i64) -> StoreResult<()> {
        self.write(|txn| {
            let updates: Vec<(Vec<u8>, Vec<u8>)> = match cids {
                Some(cids) => {
                    let mut out = Vec::new();
                    for cid in cids {
                        let k = key(bin, cid);
                        if let Some(v) = self.db.get(txn, &k)? {
                            out.push((k, with_expire(v, expire)));
                        }
                    }
                    out
                }
                None => self
                    .db
                    .prefix_iter(txn, &bin_prefix(bin))?
                    .map(|r| r.map(|(k, v)| (k.to_vec(), with_expire(v, expire))))
                    .collect::<heed::Result<_>>()?,
            };
            for (k, v) in &updates {
                self.db.put(txn, k, v)?;
            }
            Ok(())
        })
    }

    fn garbage_collection(&self, bin: &str, request_time: i64) -> StoreResult<u64> {
        let prefix = bin_prefix(bin);
        let removed = std::cell::Cell::new(0);
        self.write(|txn| {
            removed.set(0);
            let mut iter = self.db.prefix_iter_mut(txn, &prefix)?;
            while let Some((_, v)) = iter.next().transpose()? {
                let expired = v.len() >= 16
                    && is_expired(i64::from_le_bytes(v[8..16].try_into().unwrap()), request_time);
                if expired {
                    // SAFETY: `v` is not used after this call.
                    unsafe { iter.del_current()? };
                    removed.set(removed.get() + 1);
                }
            }
            Ok(())
        })?;
        Ok(removed.get())
    }

    fn stats(&self) -> StoreResult<Vec<BinStats>> {
        let rtxn = self.env.read_txn().map_err(err)?;
        let mut bins: BTreeMap<String, (u64, u64)> = BTreeMap::new();
        for r in self.db.iter(&rtxn).map_err(err)? {
            let (k, v) = r.map_err(err)?;
            if k.first() == Some(&0) {
                continue;
            }
            let bin = k.split(|b| *b == 0).next().unwrap_or_default();
            let totals = bins.entry(String::from_utf8_lossy(bin).into_owned()).or_default();
            totals.0 += 1;
            totals.1 += decode(v).map(|e| e.data.len() as u64).unwrap_or(0);
        }
        Ok(bins
            .into_iter()
            .map(|(bin, (items, bytes))| BinStats { bin, items, bytes })
            .collect())
    }
}

/// Size of the map in use (pages actually holding data), in bytes.
pub fn used_bytes(path: &str) -> StoreResult<u64> {
    with(path, |s| s.env.non_free_pages_size().map_err(err))
}
