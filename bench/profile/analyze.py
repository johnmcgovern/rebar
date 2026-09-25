#!/usr/bin/env python3
"""Summarizes Excimer folded stacks: where wall-clock time goes.

Usage: analyze.py <file.folded> [top-n] [requests]

Prints time per request by subsystem (by the innermost matching frame, so
buckets don't overlap), then the top functions by inclusive and self time.
"""
import collections
import re
import sys

PERIOD_MS = 0.5
path = sys.argv[1]
top = int(sys.argv[2]) if len(sys.argv) > 2 else 25
nreq = int(sys.argv[3]) if len(sys.argv) > 3 else None

# Innermost frame matching wins. Order matters only for ties in one frame.
BUCKETS = [
    ("database (PDO)", re.compile(r"^PDO|PDOStatement|StatementWrapperIterator::execute|Connection::query")),
    ("rebar (Rust)", re.compile(r"^rebar_")),
    ("cache (unserialize)", re.compile(r"^unserialize$")),
    ("autoload/includes", re.compile(r"ClassLoader|^Composer|require|include|spl_autoload")),
    ("twig render", re.compile(r"^__TwigTemplate_|Twig\\")),
    ("render system", re.compile(r"Drupal\\Core\\Render\\")),
    ("entity/field", re.compile(r"Drupal\\Core\\(Entity|Field|TypedData)\\")),
    ("routing/access", re.compile(r"Drupal\\Core\\(Routing|Access)\\|Symfony\\Component\\Routing")),
    ("container/DI", re.compile(r"Drupal\\Component\\DependencyInjection|Drupal\\Core\\DependencyInjection")),
    ("theme/preprocess", re.compile(r"Drupal\\Core\\Theme\\|ThemeManager|preprocess")),
    ("event dispatch", re.compile(r"EventDispatcher")),
    ("kernel/bootstrap", re.compile(r"DrupalKernel|HttpKernel|StackedHttpKernel")),
]

stacks = collections.Counter()
for line in open(path):
    stack, _, count = line.rstrip("\n").rpartition(" ")
    if stack:
        stacks[stack] += int(count)

total = sum(stacks.values())

incl, self_t, bucket = collections.Counter(), collections.Counter(), collections.Counter()
for stack, n in stacks.items():
    frames = stack.split(";")
    self_t[frames[-1]] += n
    for f in set(frames):
        incl[f] += n
    for f in reversed(frames):
        name = next((b for b, rx in BUCKETS if rx.search(f)), None)
        if name:
            bucket[name] += n
            break
    else:
        bucket["other PHP"] += n

per = f"per request over {nreq}" if nreq else "total"
div = nreq or 1
ms = lambda n: n * PERIOD_MS / div
print(f"{path}: {total} samples, {ms(total):.1f}ms {per}\n")
print("| Subsystem | ms | share |\n|---|---|---|")
for name, n in bucket.most_common():
    print(f"| {name} | {ms(n):.2f} | {100 * n / total:.1f}% |")
print(f"\nTop {top} inclusive:\n\n| ms | share | function |\n|---|---|---|")
for f, n in incl.most_common(top):
    print(f"| {ms(n):.2f} | {100 * n / total:.1f}% | `{f}` |")
print(f"\nTop {top} self:\n\n| ms | share | function |\n|---|---|---|")
for f, n in self_t.most_common(top):
    print(f"| {ms(n):.2f} | {100 * n / total:.1f}% | `{f}` |")
