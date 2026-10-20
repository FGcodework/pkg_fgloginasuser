#!/usr/bin/env python3
"""Tests of 1.2.4: the limit of unused login links holds under parallel requests."""
exec(open('/tmp/jtest/e2e.py').read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])
import threading

RIGHTS = {'core.manage': {'7': 1, '6': 1}, 'fgloginasuser.frontend': {'7': 1, '6': 1}, 'fgloginasuser.backend': {'7': 1, '6': 1}}
START = '/administrator/index.php?option=com_fgloginasuser&task=impersonate.start'
LIMIT, N = 3, 12
adm, alice = ID('adm'), ID('alice')


def storm():
    sess = []
    for _ in range(N):
        b = admin_login('adm')
        sess.append((b, tok(users_page(b).text)))
    bar = threading.Barrier(N)
    out = []

    def go(b, t):
        bar.wait()
        out.append(b.s.post(BASE + START, data={'id': alice, 'client': 'site', t: '1'}, allow_redirects=False))

    th = [threading.Thread(target=go, args=s) for s in sess]
    [x.start() for x in th]
    [x.join() for x in th]
    return out


counts = []
for run in range(3):
    reset(); set_rules(RIGHTS); set_params({'max_pending': LIMIT})
    out = storm()
    counts.append(int(sql("select count(*) from jos_fgloginasuser_logs where status='pending' and actor_id=%s" % adm)))
    if run == 0:
        okc = sum(1 for r in out if r.status_code == 200 and 'fgLasHandoff' in r.text)
        denied = int(sql("select count(*) from jos_fgloginasuser_logs where status='denied' and note='COM_FGLOGINASUSER_ERR_TOO_MANY'"))
check('parallel starts never exceed the limit', all(c == LIMIT for c in counts), counts)
check('exactly the limit got a hand-over page', okc == LIMIT, okc)
check('the others are refused and recorded', denied == N - LIMIT, denied)
check('the named lock is released afterwards', sql("select is_free_lock('fgloginasuser.pending.%s')" % adm) == '1')
reset()
check('no PHP warnings/deprecations/notices in any response', not PHP_ERRORS, PHP_ERRORS[:2])
passed = sum(1 for _, o in RESULTS if o)
print(f'\n{passed}/{len(RESULTS)} 1.2.4 checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
