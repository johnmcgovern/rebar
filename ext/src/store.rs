//! Cache storage behind the PHP functions: a common trait plus the
//! per-process store. The shared (LMDB) store lives in `shared.rs`.

use std::collections::HashMap;
use std::sync::{LazyLock, RwLock};

/// Mirrors Drupal's Cache::PERMANENT.
pub const PERMANENT: i64 = -1;

/// A borrowed view of one cache item. `data` is the PHP-serialized payload.
pub struct EntryView<'a> {
    pub data: &'a [u8],
    pub created: f64,
    pub expire: i64,
    pub tags: &'a str,
    pub checksum: &'a str,
}

/// Per-bin totals for the status page.
pub struct BinStats {
    pub bin: String,
    pub items: u64,
    pub bytes: u64,
}

pub type StoreResult<T> = Result<T, String>;

/// Everything Drupal's CacheBackendInterface needs from storage.
pub trait Store {
    fn set(&self, bin: &str, cid: &str, entry: EntryView) -> StoreResult<()>;
    /// Calls `found` for each cid that exists (validity is decided in PHP).
    fn get_multiple(
        &self,
        bin: &str,
        cids: &[String],
        found: &mut dyn FnMut(&str, EntryView) -> StoreResult<()>,
    ) -> StoreResult<()>;
    fn delete_multiple(&self, bin: &str, cids: &[String]) -> StoreResult<()>;
    fn delete_all(&self, bin: &str) -> StoreResult<()>;
    /// Sets `expire` on the given cids, or on every item in the bin if `None`.
    fn set_expire(&self, bin: &str, cids: Option<&[String]>, expire: i64) -> StoreResult<()>;
    /// Removes items that expired before `request_time`; returns the count.
    fn garbage_collection(&self, bin: &str, request_time: i64) -> StoreResult<u64>;
    fn stats(&self) -> StoreResult<Vec<BinStats>>;
}

pub fn is_expired(expire: i64, request_time: i64) -> bool {
    expire != PERMANENT && expire < request_time
}

struct Entry {
    data: Vec<u8>,
    created: f64,
    expire: i64,
    tags: String,
    checksum: String,
}

impl Entry {
    fn view(&self) -> EntryView<'_> {
        EntryView {
            data: &self.data,
            created: self.created,
            expire: self.expire,
            tags: &self.tags,
            checksum: &self.checksum,
        }
    }
}

/// bin => (cid => entry), private to one PHP process. It survives across
/// requests on the same worker but is invisible to other processes.
#[derive(Default)]
pub struct LocalStore {
    bins: RwLock<HashMap<String, HashMap<String, Entry>>>,
}

pub static LOCAL: LazyLock<LocalStore> = LazyLock::new(Default::default);

impl Store for LocalStore {
    fn set(&self, bin: &str, cid: &str, e: EntryView) -> StoreResult<()> {
        let entry = Entry {
            data: e.data.to_vec(),
            created: e.created,
            expire: e.expire,
            tags: e.tags.to_owned(),
            checksum: e.checksum.to_owned(),
        };
        let mut bins = self.bins.write().unwrap();
        bins.entry(bin.to_owned()).or_default().insert(cid.to_owned(), entry);
        Ok(())
    }

    fn get_multiple(
        &self,
        bin: &str,
        cids: &[String],
        found: &mut dyn FnMut(&str, EntryView) -> StoreResult<()>,
    ) -> StoreResult<()> {
        let bins = self.bins.read().unwrap();
        if let Some(items) = bins.get(bin) {
            for cid in cids {
                if let Some(e) = items.get(cid) {
                    found(cid, e.view())?;
                }
            }
        }
        Ok(())
    }

    fn delete_multiple(&self, bin: &str, cids: &[String]) -> StoreResult<()> {
        if let Some(items) = self.bins.write().unwrap().get_mut(bin) {
            for cid in cids {
                items.remove(cid);
            }
        }
        Ok(())
    }

    fn delete_all(&self, bin: &str) -> StoreResult<()> {
        self.bins.write().unwrap().remove(bin);
        Ok(())
    }

    fn set_expire(&self, bin: &str, cids: Option<&[String]>, expire: i64) -> StoreResult<()> {
        let mut bins = self.bins.write().unwrap();
        let Some(items) = bins.get_mut(bin) else {
            return Ok(());
        };
        match cids {
            Some(cids) => {
                for cid in cids {
                    if let Some(e) = items.get_mut(cid) {
                        e.expire = expire;
                    }
                }
            }
            None => items.values_mut().for_each(|e| e.expire = expire),
        }
        Ok(())
    }

    fn garbage_collection(&self, bin: &str, request_time: i64) -> StoreResult<u64> {
        let mut bins = self.bins.write().unwrap();
        let Some(items) = bins.get_mut(bin) else {
            return Ok(0);
        };
        let before = items.len();
        items.retain(|_, e| !is_expired(e.expire, request_time));
        Ok((before - items.len()) as u64)
    }

    fn stats(&self) -> StoreResult<Vec<BinStats>> {
        let bins = self.bins.read().unwrap();
        Ok(bins
            .iter()
            .map(|(bin, items)| BinStats {
                bin: bin.clone(),
                items: items.len() as u64,
                bytes: items.values().map(|e| e.data.len() as u64).sum(),
            })
            .collect())
    }
}
