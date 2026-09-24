#!/usr/bin/env python3
"""Inclusive ms/request for named code families in folded stacks.

Usage: families.py <requests> <file.folded>...
A sample counts toward a family if any frame in its stack matches.
"""
import re
import sys

PERIOD_MS = 0.5
FAMILIES = {
    "Masterminds HTML5 parser (pure PHP)": r"^Masterminds\\HTML5",
    "Html::load / Html::serialize": r"Drupal\\Component\\Utility\\Html::(load|serialize|normalize)",
    "Xss::filter": r"Drupal\\Component\\Utility\\Xss::",
    "text filters (check_markup, filter plugins)": r"Drupal\\filter\\(Plugin\\Filter|Element\\ProcessedText|FilterProcessResult)|ProcessedText::preRenderText",
    "CKEditor5 settings (EditorManager::getAttachments)": r"Drupal\\editor\\Plugin\\EditorManager::getAttachments",
    "CKEditor5 HTMLRestrictions": r"Drupal\\ckeditor5\\HTMLRestrictions",
    "Twig templates": r"^__TwigTemplate_",
    "cache reads (RustBackend::getMultiple)": r"RustBackend::getMultiple",
    "unserialize (cache payloads)": r"^unserialize$|PhpSerialize::decode",
    "Rust calls (drust_*)": r"^drust_",
    "database queries": r"StatementBase::clientExecute",
    "container get/create": r"Drupal\\Component\\DependencyInjection\\Container::(get|createService)",
    "class autoloading": r"Composer\\Autoload\\ClassLoader::loadClass",
    "BigPipe placeholders": r"BigPipe::sendPlaceholders",
}
nreq = int(sys.argv[1])
files = sys.argv[2:]
rx = {k: re.compile(v) for k, v in FAMILIES.items()}
totals = {}
for path in files:
    fam = dict.fromkeys(FAMILIES, 0)
    total = 0
    for line in open(path):
        stack, _, n = line.rstrip("\n").rpartition(" ")
        n = int(n)
        total += n
        frames = stack.split(";")
        for k, r in rx.items():
            if any(r.search(f) for f in frames):
                fam[k] += n
    totals[path] = (total, fam)

names = [p.split("/")[-1].removesuffix(".folded") for p in files]
print("| Family | " + " | ".join(names) + " |")
print("|---|" + "---|" * len(files))
print("| **whole request** | " + " | ".join(f"**{t * PERIOD_MS / nreq:.1f}**" for t, _ in totals.values()) + " |")
for k in FAMILIES:
    print(f"| {k} | " + " | ".join(f"{f[k] * PERIOD_MS / nreq:.2f}" for _, f in totals.values()) + " |")
