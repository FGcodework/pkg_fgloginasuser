#!/usr/bin/env python3
"""Tests of 1.1.2: confirmation of the administrator's identity (password / second factor) before an impersonation."""
exec(open('/tmp/jtest/e2e.py').read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])
import base64, hashlib, hmac, struct

RIGHTS = {'core.manage': {'7': 1, '6': 1}, 'fgloginasuser.frontend': {'7': 1, '6': 1}, 'fgloginasuser.backend': {'7': 1, '6': 1}, 'fgloginasuser.logs': {'7': 1}}
START = '/administrator/index.php?option=com_fgloginasuser&task=impersonate.start'
KEY = 'JBSWY3DPEHPK3PXP'
adm = ID('adm')


def totp(offset=0):
    n = int(time.time() // 30) + offset
    h = hmac.new(base64.b32decode(KEY), struct.pack('>Q', n), hashlib.sha1).digest()
    o = h[-1] & 15
    return '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000)


def raw_start(b, uid, client, password=None, code=None, reason=None):
    t = tok(users_page(b).text)
    data = {'id': uid, 'client': client, t: '1'}
    if password is not None:
        data['stepup_password'] = password
    if code is not None:
        data['stepup_code'] = code
    if reason is not None:
        data['reason'] = reason
    return b.s.post(BASE + START, data=data, allow_redirects=False)


def ok(r):
    return r.status_code == 200 and 'fgLasHandoff' in r.text


def last_note():
    return sql("select concat(status,'|',note) from jos_fgloginasuser_logs order by id desc limit 1")


def mfa(user, method='totp', opts='{"key":"%s"}' % KEY):
    sql("delete from jos_user_mfa where user_id=%s" % user)
    if method:
        sql("insert into jos_user_mfa (user_id,title,method,`default`,options,created_on,last_used) values (%s,'t','%s',1,'%s',now(),NULL)" % (user, method, opts))


PW_ADM = PW
mfa(adm, None)

print('\n== off by default')
reset(); set_rules(RIGHTS)
b = admin_login('adm')
page = users_page(b).text
check('default: no password field in the dialog', 'fgLasStepPw' not in page and 'data-stepup="0"' in page)
check('default: a login starts without any password', ok(raw_start(b, alice, 'site')))

print('\n== password')
reset(); set_rules(RIGHTS); set_params({'stepup_mode': 1})
b = admin_login('adm')
page = users_page(b).text
check('mode 1: dialog asks for the password only', 'id="fgLasStepPw"' in page and 'fgLasStepCode' not in page and 'data-stepup="1"' in page)
check('... the hidden form carries the fields', 'name="stepup_password"' in page and 'name="stepup_code"' in page)
r = raw_start(b, alice, 'site')
check('nothing typed: refused with the request to confirm', not ok(r) and last_note() == 'denied|COM_FGLOGINASUSER_ERR_STEPUP_REQUIRED', last_note())
check('... no token was issued', sql("select count(*) from jos_fgloginasuser_logs where status='pending'") == '0')
r = raw_start(b, alice, 'site', password='wrong')
check('wrong password: refused', not ok(r) and last_note() == 'denied|COM_FGLOGINASUSER_ERR_STEPUP_PASSWORD', last_note())
r = raw_start(b, carol, 'administrator', password='wrong')
check('wrong password for the backend: nothing switched', sess_user(b, 1) == adm, sess_user(b, 1))
r = raw_start(b, alice, 'site', password=PW_ADM)
check('right password: the login starts', ok(r))
check('the password is nowhere in the logs', PW_ADM not in sql("select group_concat(note) from jos_fgloginasuser_logs") and PW_ADM not in sql("select ifnull(group_concat(message),'') from jos_action_logs where extension='com_fgloginasuser'"))
check('now valid: the next login needs no password', ok(raw_start(b, alice, 'site')))
page = users_page(b).text
check('... and the dialog does not ask again', 'fgLasStepPw' not in page and 'data-stepup="0"' in page)
other = admin_login('adm')
check('another session of the same administrator is not covered', not ok(raw_start(other, alice, 'site')) and last_note() == 'denied|COM_FGLOGINASUSER_ERR_STEPUP_REQUIRED', last_note())

print('\n== not valid for an impersonated session')
reset(); set_rules(RIGHTS); set_params({'stepup_mode': 1})
b = admin_login('adm')
raw_start(b, carol, 'administrator', password=PW_ADM)
check('setup: backend switch to carol worked', sess_user(b, 1) == carol, sess_user(b, 1))
check('the impersonated session does not inherit the confirmation (dialog asks for carol)', 'id="fgLasStepPw"' in users_page(b).text)
home = b.get('/administrator/index.php').text
m = re.search(r'task=impersonate\.stop&(?:amp;)?([0-9a-f]{32})=1', home)
b.get('/administrator/index.php?option=com_fgloginasuser&task=impersonate.stop&' + m.group(1) + '=1')
check('back as the administrator: the earlier confirmation is still valid for him', sess_user(b, 1) == adm and ok(raw_start(b, alice, 'site')), sess_user(b, 1))

print('\n== brute force')
reset(); set_rules(RIGHTS); set_params({'stepup_mode': 1})
b = admin_login('adm')
for i in range(5):
    raw_start(b, alice, 'site', password='nope%d' % i)
r = raw_start(b, alice, 'site', password=PW_ADM)
check('after 5 wrong attempts even the right password is locked out', not ok(r) and last_note() == 'denied|COM_FGLOGINASUSER_ERR_STEPUP_LOCKED', last_note())
check('a new session is not locked (the limit protects an open session)', ok(raw_start(admin_login('adm'), alice, 'site', password=PW_ADM)))

print('\n== second factor')
reset(); set_rules(RIGHTS); set_params({'stepup_mode': 2})
b = admin_login('adm')
check('mode 2 without a second factor on the account: password only', 'fgLasStepCode' not in users_page(b).text and ok(raw_start(b, alice, 'site', password=PW_ADM)))
mfa(adm, None)
# log in first, then add the second factor, so that the captive page does not interfere
b = admin_login('adm'); mfa(adm)
page = users_page(b).text
check('mode 2 with TOTP: the dialog asks for the code too', 'id="fgLasStepPw"' in page and 'id="fgLasStepCode"' in page)
r = raw_start(b, alice, 'site', password=PW_ADM)
check('password without code: refused (code requested)', not ok(r) and last_note() == 'denied|COM_FGLOGINASUSER_ERR_STEPUP_REQUIRED', last_note())
r = raw_start(b, alice, 'site', password=PW_ADM, code='000000')
check('wrong code: refused', not ok(r) and last_note() == 'denied|COM_FGLOGINASUSER_ERR_STEPUP_CODE', last_note())
check('... the core try counter of the method counts', sql("select tries from jos_user_mfa where user_id=%s" % adm) == '1', sql("select tries from jos_user_mfa where user_id=%s" % adm))
r = raw_start(b, alice, 'site', password='wrong', code=totp())
check('right code, wrong password: refused', not ok(r) and last_note() == 'denied|COM_FGLOGINASUSER_ERR_STEPUP_PASSWORD', last_note())
r = raw_start(b, alice, 'site', password=PW_ADM, code=totp())
check('right password and code: the login starts', ok(r), last_note())
check('... and the try counter is reset', sql("select tries from jos_user_mfa where user_id=%s" % adm) == '0', sql("select tries from jos_user_mfa where user_id=%s" % adm))
check('... the following login needs nothing', ok(raw_start(b, alice, 'site')))
reset(); set_rules(RIGHTS); set_params({'stepup_mode': 2})
mfa(adm, None); b = admin_login('adm'); mfa(adm, 'webauthn', '{}')
check('only a WebAuthn factor (not askable here): password only', 'fgLasStepCode' not in users_page(b).text and ok(raw_start(b, alice, 'site', password=PW_ADM)))
mfa(adm, None)

print('\n== validity time')
reset(); set_rules(RIGHTS); set_params({'stepup_mode': 1, 'stepup_minutes': 1})
b = admin_login('adm')
raw_start(b, alice, 'site', password=PW_ADM)
check('within the time: valid', ok(raw_start(b, alice, 'site')))
time.sleep(62)
check('after the time: asked again', not ok(raw_start(b, alice, 'site')) and last_note() == 'denied|COM_FGLOGINASUSER_ERR_STEPUP_REQUIRED', last_note())

reset(); mfa(adm, None)
check('no PHP warnings/deprecations/notices in any response', not PHP_ERRORS, PHP_ERRORS[:2])
passed = sum(1 for _, o in RESULTS if o)
print(f'\n{passed}/{len(RESULTS)} 1.1.2 checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
