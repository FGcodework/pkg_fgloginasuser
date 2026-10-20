#!/usr/bin/env python3
"""Tests of 1.2.5: returning to the original administrator re-checks activation (same as the start)."""
exec(open('/tmp/jtest/e2e.py').read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])

RIGHTS = {'core.manage': {'7': 1, '6': 1}, 'fgloginasuser.frontend': {'7': 1, '6': 1}, 'fgloginasuser.backend': {'7': 1, '6': 1}}
START = '/administrator/index.php?option=com_fgloginasuser&task=impersonate.start'
adm, carol = ID('adm'), ID('carol')


def begin(b):
    t = tok(users_page(b).text)
    return b.s.post(BASE + START, data={'id': carol, 'client': 'administrator', t: '1'}, allow_redirects=True)


def stop(b):
    m = re.search(r'([0-9a-f]{32})=1', b.get('/administrator/index.php?option=com_fgloginasuser&view=users').text)
    return b.get('/administrator/index.php?option=com_fgloginasuser&task=impersonate.stop&' + (m.group(1) if m else 'x') + '=1')


def run(setup, undo):
    reset(); set_rules(RIGHTS)
    b = admin_login('adm'); begin(b)
    started = sess_user(b, 1) == carol
    sql(setup)
    try:
        stop(b)
        return started, sess_user(b, 1)
    finally:
        sql(undo)


s, u = run("update jos_users set activation='abc' where username='adm'", "update jos_users set activation='' where username='adm'")
check('(setup) impersonation started', s)
check('original account deactivated (activation set, not blocked): not restored', u != adm and u != carol, u)
s, u = run("update jos_users set block=1 where username='adm'", "update jos_users set block=0 where username='adm'")
check('original account blocked: not restored', u != adm and u != carol, u)
s, u = run("update jos_users set activation='0' where username='adm'", "update jos_users set activation='' where username='adm'")
check('activation "0" counts as activated: restored', u == adm, u)
s, u = run('select 1', 'select 1')
check('healthy account: restored', u == adm, u)
reset()
# the original account loses the rights to this tool during the impersonation: leaving must still work
set_rules(RIGHTS); b = admin_login('adm'); begin(b)
set_rules({}); stop(b)
check('rights to the tool withdrawn meanwhile: still restored', sess_user(b, 1) == adm, sess_user(b, 1))
reset()
check('no PHP warnings/deprecations/notices in any response', not PHP_ERRORS, PHP_ERRORS[:2])
passed = sum(1 for _, o in RESULTS if o)
print(f'\n{passed}/{len(RESULTS)} 1.2.5 checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
