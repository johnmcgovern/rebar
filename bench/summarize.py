#!/usr/bin/env python3
"""Summarizes remote-bench.sh output: median rps and p50 per mode/path/site.

The spread columns are max/min req/s across rounds for each site: a large one
means the host was noisy during that run and the medians deserve suspicion.
"""
import re
import statistics
import sys
from collections import defaultdict

runs = defaultdict(list)
for line in open(sys.argv[1]):
    m = re.match(r"round=\d+ mode=(\w+) (\w+) (\S+) rps=([\d.]+) p50=(\d+)ms p95=(\d+)ms", line)
    if m:
        mode, site, path, rps, p50, p95 = m.groups()
        runs[(mode, path, site)].append((float(rps), int(p50), int(p95)))

def spread(rs):
    return max(r[0] for r in rs) / min(r[0] for r in rs)


sites = sorted({k[2] for k in runs})
base, test = sites[0], sites[1]
print(f"| Mode | Path | {base} req/s | {test} req/s | Change | {base} p50/p95 | {test} p50/p95 | Spread {base} / {test} |")
print("|---|---|---|---|---|---|---|---|")
for mode, path in sorted({(k[0], k[1]) for k in runs}, key=lambda k: (k[0] != "anon", k[1])):
    b, t = runs[(mode, path, base)], runs[(mode, path, test)]
    med = lambda rs, i: statistics.median(r[i] for r in rs)
    brps, trps = med(b, 0), med(t, 0)
    print(f"| {mode} | `{path}` | {brps:.0f} | {trps:.0f} | {100 * (trps - brps) / brps:+.1f}% "
          f"| {med(b, 1):.0f}/{med(b, 2):.0f}ms | {med(t, 1):.0f}/{med(t, 2):.0f}ms "
          f"| {spread(b):.2f}x / {spread(t):.2f}x |")
