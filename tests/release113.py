#!/usr/bin/env python3
"""Tests of 1.1.3: limit of unused login links per administrator."""
exec(open('/tmp/jtest/e2e.py').read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])

RIGHTS = {'core.manage': {'7': 1, '6': 1}, 'fgloginasuser.frontend': {'7': 1, '6': 1}, 'fgloginasuser.backend': {'7': 1, '6': 1}}
START = '/administrator/index.php?option=com_fgloginasuser&task=impersonate.start'
CONSUME = BASE + '/index.php?option=com_fgloginasuser&task=login.consume'
adm = ID('adm')


def raw_start(b, uid, client):
    t = tok(users_page(b).text)
    return b.s.post(BASE + START, data={'id': uid, 'client': client, t: '1'}, allow_redirects=False)


def ok(r):
    return r.status_code == 200 and 'fgLasHandoff' in r.text


def token(r):
    return re.search(r'name="token" value="([0-9a-f]{64})"', r.text).group(1)


def last_note():
    return sql("select concat(status,'|',note) from jos_fgloginasuser_logs order by id desc limit 1")


def pending(actor):
    return sql("select count(*) from jos_fgloginasuser_logs where status='pending' and actor_id=%s" % actor)


reset(); set_rules(RIGHTS); set_params({'max_pending': 3})
b = admin_login('adm')
rs = [raw_start(b, alice, 'site') for _ in range(3)]
check('up to the limit all logins start', all(ok(r) for r in rs) and pending(adm) == '3', pending(adm))
r = raw_start(b, alice, 'site')
check('over the limit: refused and recorded', not ok(r) and last_note() == 'denied|COM_FGLOGINASUSER_ERR_TOO_MANY' and pending(adm) == '3', last_note())
check('the backend is not affected by the limit', 'carol' and sess_user(b, 1) == adm and not ok(raw_start(b, carol, 'administrator')) and sess_user(b, 1) == carol, sess_user(b, 1))
b = admin_login('adm')
check('(setup) a fresh session of the same administrator is still limited', not ok(raw_start(b, alice, 'site')) and last_note() == 'denied|COM_FGLOGINASUSER_ERR_TOO_MANY', last_note())

Browser().s.post(CONSUME, data={'token': token(rs[0])}, allow_redirects=True)
check('using one link frees a place', pending(adm) == '2' and ok(raw_start(b, alice, 'site')))
check('... and the limit applies again', not ok(raw_start(b, alice, 'site')))

sql("update jos_fgloginasuser_logs set token_expires = date_sub(utc_timestamp(), interval 1 minute) where status='pending' and actor_id=%s" % adm)
check('expired links do not count', ok(raw_start(b, alice, 'site')))

reset(); set_rules(RIGHTS); set_params({'max_pending': 1})
b1 = admin_login('adm'); b2 = admin_login('root')
raw_start(b1, alice, 'site')
check('the limit is per administrator, not global', ok(raw_start(b2, alice, 'site')) and not ok(raw_start(b1, alice, 'site')))

reset(); set_rules(RIGHTS); set_params({})
b = admin_login('adm')
rs = [raw_start(b, alice, 'site') for _ in range(11)]
check('default limit is 10', sum(ok(r) for r in rs) == 10 and not ok(rs[10]), [ok(r) for r in rs])
reset()
check('no PHP warnings/deprecations/notices in any response', not PHP_ERRORS, PHP_ERRORS[:2])
passed = sum(1 for _, o in RESULTS if o)
print(f'\n{passed}/{len(RESULTS)} 1.1.3 checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
