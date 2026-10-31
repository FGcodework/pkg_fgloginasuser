#!/usr/bin/env python3
"""Checks every translation against en-GB: same keys, same placeholders (%s %d %1$s, {name}, HTML tags, \\n),
valid ini syntax, no empty values, and that each language is declared in the manifests.
Usage: python3 tests/check_languages.py   (from anywhere; exits 1 on any problem)"""
import collections, glob, os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLACEHOLDER = re.compile(r'%(?:\d+\$)?[sd]|\{[a-z_]+\}|</?[a-zA-Z][^>]*>|\\n')
LINE = re.compile(r'^([A-Z0-9_]+)="(.*)"$')
problems = []


def load(path):
    out = {}
    for n, line in enumerate(open(path, encoding='utf-8').read().splitlines(), 1):
        s = line.strip()
        if not s or s.startswith(';'):
            continue
        m = LINE.match(s)
        if not m:
            problems.append(f'{path}:{n}: not a KEY="value" line: {s[:60]}')
            continue
        if m.group(1) in out:
            problems.append(f'{path}:{n}: duplicate key {m.group(1)}')
        if re.search(r'(?<!\\)"', m.group(2)):
            problems.append(f'{path}:{n}: unescaped quote in {m.group(1)}')
        if m.group(2).strip() == '':
            problems.append(f'{path}:{n}: empty value for {m.group(1)}')
        out[m.group(1)] = m.group(2)
    return out


def ph(v):
    return collections.Counter(PLACEHOLDER.findall(v))


# Only the extension's own folders: CI unpacks a whole Joomla next to them (and dist/ holds built packages).
OWN = ('com_fgloginasuser', 'plg_system_fgloginasuser', 'plg_actionlog_fgloginasuser', 'language')
english = []
for top in OWN:
    english += glob.glob(os.path.join(ROOT, top, '**', 'en-GB', '*.ini'), recursive=True)
english = sorted(english)
langs = set()
for en in english:
    ref = load(en)
    for other in sorted(glob.glob(os.path.join(os.path.dirname(os.path.dirname(en)), '*', os.path.basename(en)))):
        tag = os.path.basename(os.path.dirname(other))
        if tag == 'en-GB':
            continue
        langs.add(tag)
        tr = load(other)
        rel = os.path.relpath(other, ROOT)
        for k in ref.keys() - tr.keys():
            problems.append(f'{rel}: missing key {k}')
        for k in tr.keys() - ref.keys():
            problems.append(f'{rel}: unknown key {k}')
        for k in ref.keys() & tr.keys():
            if ph(ref[k]) != ph(tr[k]):
                problems.append(f'{rel}: placeholders differ in {k}: {dict(ph(ref[k]))} vs {dict(ph(tr[k]))}')
    # every English file has a counterpart in each language found anywhere
for en in english:
    for tag in langs:
        counterpart = os.path.join(os.path.dirname(os.path.dirname(en)), tag, os.path.basename(en))
        if not os.path.exists(counterpart):
            problems.append(f'missing file {os.path.relpath(counterpart, ROOT)}')

manifests = [os.path.join(ROOT, p) for p in ('pkg_fgloginasuser.xml', 'com_fgloginasuser/fgloginasuser.xml',
                                             'plg_system_fgloginasuser/fgloginasuser.xml', 'plg_actionlog_fgloginasuser/fgloginasuser.xml')]
for mf in manifests:
    text = open(mf, encoding='utf-8').read()
    for tag in langs | {'en-GB'}:
        if f'tag="{tag}"' not in text:
            problems.append(f'{os.path.relpath(mf, ROOT)}: language {tag} is not declared')

print('languages: en-GB + ' + ', '.join(sorted(langs)) + f'; {len(english)} files each')
for p in problems:
    print('PROBLEM  ' + p)
print('language check: ' + ('FAILED, %d problems' % len(problems) if problems else 'OK'))
sys.exit(1 if problems else 0)
