#!/usr/bin/env python3
"""Derive git-filter-repo --replace-text expressions from the scrub commit.

The scrub commit (session/repo-hygiene) replaced real phone numbers, emails,
customer names, street names and a Twilio account SID with fiction, in place.
This script reads that commit's diff and writes, for every replacement it
made, the old==>new pair, so `git filter-repo --replace-text` can make the
same replacement in every historical commit.

The OUTPUT CONTAINS THE REAL VALUES. Write it outside the repository and
delete it when the rewrite is done. It is never committed.

    python scripts/security/scrub-expressions.py <SCRUB_SHA> > ../pii-expressions.txt

Phone numbers are emitted as regex: lines covering every format (bare, +1,
dashes, dots, brackets), keeping each occurrence's separators.
"""
import difflib
import re
import subprocess
import sys

sha = sys.argv[1]
PHONE = re.compile(r'(?<!\d)(?:\+?1[\s.-]?)?\(?([2-9]\d{2})\)?[\s.-]?([2-9]\d{2})[\s.-]?(\d{4})(?!\d)')
WORD = re.compile(r"[\w.@+'()-]+|\s+|.", re.S)


def git(*args):
    return subprocess.run(['git', *args], capture_output=True, check=True).stdout.decode('utf-8', 'replace')


files = [f for f in git('diff', '--name-only', '--diff-filter=M', f'{sha}^', sha).splitlines() if f]
phones = {}
literals = {}
for f in files:
    old = git('show', f'{sha}^:{f}').splitlines()
    new = git('show', f'{sha}:{f}').splitlines()
    sm = difflib.SequenceMatcher(None, old, new, autojunk=False)
    for op, i1, i2, j1, j2 in sm.get_opcodes():
        if op != 'replace' or (i2 - i1) != (j2 - j1):
            continue
        for a, b in zip(old[i1:i2], new[j1:j2]):
            pa = [''.join(m.groups()) for m in PHONE.finditer(a)]
            pb = [''.join(m.groups()) for m in PHONE.finditer(b)]
            if len(pa) == len(pb):
                for x, y in zip(pa, pb):
                    if x != y and y[3:6] == '555':
                        phones[x] = y
            wa, wb = WORD.findall(a), WORD.findall(b)
            ws = difflib.SequenceMatcher(None, wa, wb, autojunk=False)
            for wop, k1, k2, l1, l2 in ws.get_opcodes():
                if wop != 'replace':
                    continue
                x, y = ''.join(wa[k1:k2]).strip(), ''.join(wb[l1:l2]).strip()
                if x and y and x != y and not PHONE.search(x) and len(x) >= 4:
                    literals[x] = y

for x, y in sorted(phones.items()):
    print('regex:(?<!\\d)((?:\\+?1)?[\\s.-]?\\(?%s\\)?[\\s.-]?)%s([\\s.-]?)%s(?!\\d)==>\\g<1>%s\\g<2>%s'
          % (x[:3], x[3:6], x[6:], y[3:6], y[6:]))
for x, y in sorted(literals.items(), key=lambda kv: -len(kv[0])):
    if '==>' in x or '\n' in x:
        continue
    print(f'{x}==>{y}')
