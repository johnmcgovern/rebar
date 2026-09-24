//! drust: Rust-backed services for Drupal, exposed as a PHP extension.
//!
//! The PHP side (the `drust` Drupal module) implements Drupal's interfaces and
//! delegates storage and bookkeeping to the functions defined here, so contrib
//! code never knows the difference.
//!
//! Cache functions take a store: "local" (private to the PHP process) or the
//! path of a shared LMDB store, opened first with drust_shared_open().

mod shared;
mod store;

use ext_php_rs::binary_slice::BinarySlice;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, ZendStr, Zval};
use std::sync::atomic::{AtomicU64, Ordering};
use store::{EntryView, Store, StoreResult};

static HITS: AtomicU64 = AtomicU64::new(0);
static MISSES: AtomicU64 = AtomicU64::new(0);
static WRITES: AtomicU64 = AtomicU64::new(0);

/// Runs `f` against the named store, turning errors into PHP exceptions.
fn with_store<R>(store: &str, f: impl FnOnce(&dyn Store) -> StoreResult<R>) -> PhpResult<R> {
    match store {
        "local" => f(&*store::LOCAL),
        path => shared::with(path, |s| f(s)),
    }
    .map_err(PhpException::from)
}

/// Returns a short banner so PHP can confirm the Rust extension is loaded.
#[php_function]
pub fn drust_hello(name: &str) -> String {
    format!("Hello {name}, from Rust {}", env!("CARGO_PKG_VERSION"))
}

/// Opens the shared store for this process (no-op if already open).
#[php_function]
pub fn drust_shared_open(path: &str, map_size_mb: i64) -> PhpResult<()> {
    shared::open(path, map_size_mb.max(1) as usize * 1024 * 1024).map_err(PhpException::from)
}

/// Stores one item in a bin.
#[php_function]
pub fn drust_cache_set(
    store: &str,
    bin: &str,
    cid: &str,
    data: BinarySlice<u8>,
    created: f64,
    expire: i64,
    tags: &str,
    checksum: &str,
) -> PhpResult<()> {
    WRITES.fetch_add(1, Ordering::Relaxed);
    let entry = EntryView { data: &data, created, expire, tags, checksum };
    with_store(store, |s| s.set(bin, cid, entry))
}

/// Fetches items by cid. Returns cid => [data, created, expire, tags, checksum]
/// for every item found; validity (expiry, tags) is decided on the PHP side
/// because it needs the request time and the checksum provider.
#[php_function]
pub fn drust_cache_get_multiple(
    store: &str,
    bin: &str,
    cids: Vec<String>,
) -> PhpResult<ZBox<ZendHashTable>> {
    let mut out = ZendHashTable::new();
    with_store(store, |s| {
        s.get_multiple(bin, &cids, &mut |cid, e| {
            let mut item = ZendHashTable::with_capacity(5);
            let fail = |e: ext_php_rs::error::Error| e.to_string();
            // One copy, straight from the store into a PHP string.
            let mut data = Zval::new();
            data.set_zend_string(ZendStr::new(e.data, false));
            item.insert("data", data).map_err(fail)?;
            item.insert("created", e.created).map_err(fail)?;
            item.insert("expire", e.expire).map_err(fail)?;
            item.insert("tags", e.tags).map_err(fail)?;
            item.insert("checksum", e.checksum).map_err(fail)?;
            out.insert(cid, item).map_err(fail)
        })
    })?;
    let hits = out.len() as u64;
    HITS.fetch_add(hits, Ordering::Relaxed);
    MISSES.fetch_add(cids.len() as u64 - hits, Ordering::Relaxed);
    Ok(out)
}

#[php_function]
pub fn drust_cache_delete_multiple(store: &str, bin: &str, cids: Vec<String>) -> PhpResult<()> {
    with_store(store, |s| s.delete_multiple(bin, &cids))
}

/// Removes every item in a bin (deleteAll() and removeBin()).
#[php_function]
pub fn drust_cache_delete_all(store: &str, bin: &str) -> PhpResult<()> {
    with_store(store, |s| s.delete_all(bin))
}

/// Marks items invalid by moving their expiry into the past.
#[php_function]
pub fn drust_cache_invalidate_multiple(
    store: &str,
    bin: &str,
    cids: Vec<String>,
    request_time: i64,
) -> PhpResult<()> {
    with_store(store, |s| s.set_expire(bin, Some(&cids), request_time - 1))
}

/// Marks every item in a bin invalid (deprecated invalidateAll()).
#[php_function]
pub fn drust_cache_invalidate_all(store: &str, bin: &str, request_time: i64) -> PhpResult<()> {
    with_store(store, |s| s.set_expire(bin, None, request_time - 1))
}

/// Drops expired items from a bin; returns how many were removed.
#[php_function]
pub fn drust_cache_garbage_collection(store: &str, bin: &str, request_time: i64) -> PhpResult<i64> {
    with_store(store, |s| s.garbage_collection(bin, request_time)).map(|n| n as i64)
}

/// Process-wide counters plus per-bin item counts and payload bytes.
#[php_function]
pub fn drust_cache_stats(store: &str) -> PhpResult<ZBox<ZendHashTable>> {
    let bins_stats = with_store(store, |s| s.stats())?;
    let mut bins = ZendHashTable::new();
    for b in bins_stats {
        let mut t = ZendHashTable::with_capacity(2);
        t.insert("items", b.items as i64)?;
        t.insert("bytes", b.bytes as i64)?;
        bins.insert(b.bin.as_str(), t)?;
    }
    let mut out = ZendHashTable::new();
    out.insert("store", store)?;
    out.insert("pid", std::process::id() as i64)?;
    out.insert("hits", HITS.load(Ordering::Relaxed) as i64)?;
    out.insert("misses", MISSES.load(Ordering::Relaxed) as i64)?;
    out.insert("writes", WRITES.load(Ordering::Relaxed) as i64)?;
    if store != "local" {
        out.insert("used_bytes", shared::used_bytes(store).map_err(PhpException::from)? as i64)?;
        out.insert("evictions", shared::EVICTIONS.load(Ordering::Relaxed) as i64)?;
    }
    out.insert("bins", bins)?;
    Ok(out)
}

/// Cache tag invalidation counters, in a shared store (counters must be
/// visible to every process). Returns tag => epoch + count for every tag.
#[php_function]
pub fn drust_tags_get(store: &str, bin: &str, tags: Vec<String>) -> PhpResult<ZBox<ZendHashTable>> {
    let counts = shared::with(store, |s| s.tag_counts(bin, &tags)).map_err(PhpException::from)?;
    let mut out = ZendHashTable::with_capacity(tags.len() as u32);
    for (tag, n) in tags.iter().zip(counts) {
        out.insert(tag.as_str(), n as i64)?;
    }
    Ok(out)
}

/// Increments the invalidation counter of each tag.
#[php_function]
pub fn drust_tags_invalidate(store: &str, bin: &str, tags: Vec<String>) -> PhpResult<()> {
    shared::with(store, |s| s.invalidate_tags(bin, &tags)).map_err(PhpException::from)
}

/// Deletes all tag counters in a bin and starts a new epoch.
#[php_function]
pub fn drust_tags_purge(store: &str, bin: &str) -> PhpResult<()> {
    shared::with(store, |s| s.purge_tags(bin)).map_err(PhpException::from)
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .function(wrap_function!(drust_hello))
        .function(wrap_function!(drust_shared_open))
        .function(wrap_function!(drust_cache_set))
        .function(wrap_function!(drust_cache_get_multiple))
        .function(wrap_function!(drust_cache_delete_multiple))
        .function(wrap_function!(drust_cache_delete_all))
        .function(wrap_function!(drust_cache_invalidate_multiple))
        .function(wrap_function!(drust_cache_invalidate_all))
        .function(wrap_function!(drust_cache_garbage_collection))
        .function(wrap_function!(drust_cache_stats))
        .function(wrap_function!(drust_tags_get))
        .function(wrap_function!(drust_tags_invalidate))
        .function(wrap_function!(drust_tags_purge))
}
