#!/usr/bin/env python3
"""Tests of 1.2.1: the second-factor step-up fails closed."""
exec(open('/tmp/jtest/e2e.py').read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])

RIGHTS = {'core.manage': {'7': 1, '6': 1}, 'fgloginasuser.frontend': {'7': 1, '6': 1}, 'fgloginasuser.backend': {'7': 1, '6': 1}}
START = '/administrator/index.php?option=com_fgloginasuser&task=impersonate.start'
adm = ID('adm')


def raw_start(b, uid, client, password=None, code=None):
    t = tok(users_page(b).text)
    data = {'id': uid, 'client': client, t: '1'}
    if password is not None:
        data['stepup_password'] = password
    if code is not None:
        data['stepup_code'] = code
    return b.s.post(BASE + START, data=data, allow_redirects=False)


def ok(r):
    return r.status_code == 200 and 'fgLasHandoff' in r.text


def last_note():
    return sql("select concat(status,'|',note) from jos_fgloginasuser_logs order by id desc limit 1")


def mfa(user, method='totp', opts='{}'):
    sql("delete from jos_user_mfa where user_id=%s" % user)
    if method:
        sql("insert into jos_user_mfa (user_id,title,method,`default`,options,created_on,last_used) values (%s,'t','%s',1,'%s',now(),NULL)" % (user, method, opts))


mfa(adm, None)

print('\n== lookup failure')
reset(); set_rules(RIGHTS); set_params({'stepup_mode': 2})
b = admin_login('adm')
sql("rename table jos_user_mfa to jos_user_mfa_off")
try:
    page = users_page(b)
    check('with the second-factor table unreadable the Users screen still renders', page.status_code == 200 and 'fgLasStepPw' in page.text, page.status_code)
    r = raw_start(b, alice, 'site', password=PW)
    check('mode 2: a correct password alone is NOT enough when the lookup fails', not ok(r) and last_note() == 'denied|COM_FGLOGINASUSER_ERR_STEPUP_MFA_UNAVAILABLE', last_note())
    r = raw_start(b, carol, 'administrator', password=PW)
    check('... also for the backend, nothing switched', not ok(r) and sess_user(b, 1) == adm, sess_user(b, 1))
    check('... and it was not remembered as confirmed', not ok(raw_start(b, alice, 'site', password=PW)))
    set_params({'stepup_mode': 1})
    check('mode 1 does not depend on the second-factor lookup', ok(raw_start(b, alice, 'site', password=PW)))
finally:
    sql("rename table jos_user_mfa_off to jos_user_mfa")

print('\n== factors that cannot be asked here')
reset(); set_rules(RIGHTS); set_params({'stepup_mode': 2})
b = admin_login('adm'); mfa(adm, 'webauthn')
check('default: only WebAuthn -> password only (as documented)', ok(raw_start(b, alice, 'site', password=PW)))
reset(); set_rules(RIGHTS); set_params({'stepup_mode': 2, 'stepup_unsupported': 1})
mfa(adm, None); b = admin_login('adm'); mfa(adm, 'webauthn')
r = raw_start(b, alice, 'site', password=PW)
check('option "refuse": only WebAuthn -> refused with the explanation', not ok(r) and last_note() == 'denied|COM_FGLOGINASUSER_ERR_STEPUP_MFA_UNSUPPORTED', last_note())
mfa(adm, 'email')
check('... the same for an e-mail factor', not ok(raw_start(b, alice, 'site', password=PW)) and last_note() == 'denied|COM_FGLOGINASUSER_ERR_STEPUP_MFA_UNSUPPORTED', last_note())
mfa(adm, None)
check('option "refuse": no second factor at all is not "unsupported" (password only)', ok(raw_start(b, alice, 'site', password=PW)))
reset(); set_rules(RIGHTS); set_params({'stepup_mode': 2, 'stepup_unsupported': 1})
mfa(adm, None); b = admin_login('adm'); mfa(adm, 'totp', '{"key":"JBSWY3DPEHPK3PXP"}')
check('option "refuse": a TOTP account still gets the code field and is not refused as unsupported', 'fgLasStepCode' in users_page(b).text and last_note() != 'denied|COM_FGLOGINASUSER_ERR_STEPUP_MFA_UNSUPPORTED')
mfa(adm, None)

reset()
check('no PHP warnings/deprecations/notices in any response', not PHP_ERRORS, PHP_ERRORS[:2])
passed = sum(1 for _, o in RESULTS if o)
print(f'\n{passed}/{len(RESULTS)} 1.2.1 checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
