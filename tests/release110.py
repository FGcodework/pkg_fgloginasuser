#!/usr/bin/env python3
"""Tests of the 1.1.0 features: POST hand-over of the token, reason for the login, maximum duration."""
exec(open('/tmp/jtest/e2e.py').read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])

site_user = lambda b: sess_user(b, 0)
RIGHTS = {'core.manage': {'7': 1, '6': 1}, 'fgloginasuser.frontend': {'7': 1, '6': 1}, 'fgloginasuser.backend': {'7': 1, '6': 1}, 'fgloginasuser.logs': {'7': 1}}
START = '/administrator/index.php?option=com_fgloginasuser&task=impersonate.start'
CONSUME = BASE + '/index.php?option=com_fgloginasuser&task=login.consume'


def raw_start(b, uid, client, reason=None):
    t = tok(users_page(b).text)
    data = {'id': uid, 'client': client, t: '1'}
    if reason is not None:
        data['reason'] = reason
    return b.s.post(BASE + START, data=data, allow_redirects=False)


def handoff_token(r):
    m = re.search(r'name="token" value="([0-9a-f]{64})"', r.text)
    return m.group(1) if m else None


def logrow(where=''):
    return sql("select status,reason,note from jos_fgloginasuser_logs %s order by id desc limit 1" % where)


# ------------------------------------------------------------ token hand-over
print('\n== token hand-over by POST')
reset(); set_rules(RIGHTS)
adm = admin_login('adm')
r = raw_start(adm, alice, 'site')
token = handoff_token(r)
check('start answers with a hand-over page, not a redirect', r.status_code == 200 and token and 'Location' not in r.headers, (r.status_code, r.headers.get('Location')))
check('... that posts to the site', 'method="post" action="' + CONSUME.replace('&', '&amp;') in r.text, r.text[:300])
check('... without the token in any URL or script', all(token not in x for x in re.findall(r'(?:href|action|src)="[^"]*"', r.text)) and '<script>' not in r.text)
check('... not cacheable and without referrer', 'no-store' in r.headers.get('Cache-Control', '') and r.headers.get('Referrer-Policy') == 'no-referrer', dict(r.headers))
js = requests.get(BASE + '/media/com_fgloginasuser/js/handoff.js')
check('the external hand-over script is served', js.status_code == 200 and 'fgLasHandoff' in js.text, js.status_code)

fresh = Browser()
g = fresh.s.get(CONSUME + '&token=' + token, allow_redirects=True)
check('the token in a query string is refused (GET)', site_user(fresh) != alice, site_user(fresh))
check('... and was not used up by that attempt', sql("select status from jos_fgloginasuser_logs where status='pending'") == 'pending')
p = fresh.s.post(CONSUME, data={'token': token}, allow_redirects=True)
check('POST of the token logs in', site_user(fresh) == alice, site_user(fresh))
other = Browser()
other.s.post(CONSUME, data={'token': token}, allow_redirects=True)
check('replay of the same POST is refused', site_user(other) != alice)
r2 = raw_start(adm, alice, 'site')
t2 = handoff_token(r2)
qb = Browser(); qb.s.post(CONSUME + '&token=' + t2, data={}, allow_redirects=True)
check('a POST with the token only in the query string is refused', site_user(qb) != alice and sql("select count(*) from jos_fgloginasuser_logs where status='pending'") == '1')

# ------------------------------------------------------------ reason
print('\n== reason for the login')
reset(); set_rules(RIGHTS)
adm = admin_login('adm')
raw_start(adm, alice, 'site', reason='  Ticket #42:\x07 <b>check</b>\n invoices  ')
row = sql("select reason from jos_fgloginasuser_logs order by id desc limit 1")
check('reason is stored cleaned (tags, control characters and line breaks removed)', row == 'Ticket #42: check invoices', repr(row))
raw_start(adm, alice, 'site', reason='x' * 400)
check('reason is cut to 255 characters', sql("select char_length(reason) from jos_fgloginasuser_logs order by id desc limit 1") == '255')

reset(); set_rules(RIGHTS)
b = admin_login('root')
raw_start(b, alice, 'site', reason='R&D "x" y')
r = raw_start(b, alice, 'site', reason='R&D "x" y')
fresh = Browser(); fresh.s.post(CONSUME, data={'token': handoff_token(r)}, allow_redirects=True)
home = fresh.get('/')
bar = re.search(r'id="fg-las-bar".*?</div>', home.text, re.S)
check('return bar shows the reason, escaped', bar and 'R&amp;D &quot;x&quot;' in bar.group(0) and 'R&D "x"' not in bar.group(0), bar.group(0)[:400] if bar else 'no bar')
page = b.get('/administrator/index.php?option=com_fgloginasuser&view=logs')
check('Logs screen shows the reason, escaped', 'R&amp;D &quot;x&quot;' in page.text and 'R&D "x"' not in page.text)
am = sql("select message from jos_action_logs where extension='com_fgloginasuser' and message_language_key like '%STARTED_SITE_REASON' order by id desc limit 1")
check('User Actions Log entry carries the reason', 'R&D' in am, am[:200])
al = b.get('/administrator/index.php?option=com_actionlogs&view=actionlogs')
check('... and renders it escaped', 'R&amp;D' in al.text and 'R&D "x"' not in al.text, '')

reset(); set_rules(RIGHTS)
b = admin_login('root')
set_params({'reason_mode': 2})
r = raw_start(b, alice, 'site', reason='')
check('required reason: empty request is refused on the server', handoff_token(r) is None and logrow()[:6] == 'denied', logrow())
check('... with the reason in the log', 'required' in sql("select note from jos_fgloginasuser_logs order by id desc limit 1") or 'COM_FGLOGINASUSER_ERR_REASON' in sql("select note from jos_fgloginasuser_logs order by id desc limit 1"))
r = raw_start(b, carol, 'administrator', reason='   ')
check('... also for the backend, nothing switched', sess_user(b, 1) == root, sess_user(b, 1))
r = raw_start(b, alice, 'site', reason='ok')
check('required reason: with a reason it works', handoff_token(r) is not None)
set_params({'reason_mode': 0})
r = raw_start(b, alice, 'site', reason='')
check('reason switched off: nothing is asked', handoff_token(r) is not None)
set_params({})
r = raw_start(b, alice, 'site', reason='')
check('optional (default): empty is fine', handoff_token(r) is not None)

# mail
open('/tmp/jtest/mails.txt', 'w').close()
set_params({'notify_enabled': 1, 'notify_email': 'audit@example.test'})
r = raw_start(b, alice, 'site', reason='Mail reason')
Browser().s.post(CONSUME, data={'token': handoff_token(r)})
time.sleep(1.5)
check('notification e-mail contains the reason', 'Mail reason' in open('/tmp/jtest/mails.txt', errors='replace').read())
set_params({})

# ------------------------------------------------------------ maximum duration
print('\n== maximum duration')
reset(); set_rules(RIGHTS); set_params({'max_minutes': 1})
b = admin_login('root')
r = raw_start(b, alice, 'site')
site = Browser(); site.s.post(CONSUME, data={'token': handoff_token(r)}, allow_redirects=True)
raw_start(b, carol, 'administrator')
check('limit set: both impersonations are active', site_user(site) == alice and sess_user(b, 1) == carol, (site_user(site), sess_user(b, 1)))
check('... and the bar tells when they end', 'ends automatically at' in site.get('/').text)
time.sleep(64)
rs = site.get('/', allow_redirects=False)
ra = b.s.get(BASE + '/administrator/index.php', allow_redirects=False)
check('after the limit the site request is redirected and the session is gone', rs.status_code in (302, 303) and site_user(site) is None, (rs.status_code, site_user(site)))
check('... the same for the backend', ra.status_code in (302, 303) and sess_user(b, 1) is None, (ra.status_code, sess_user(b, 1)))
st = sql("select group_concat(status), group_concat(note) from jos_fgloginasuser_logs")
check('both are recorded as ended with the timeout note', st.startswith('ended,ended') or st.count('ended') == 2 and st.count('NOTE_TIMEOUT') == 2, st)

reset(); set_rules(RIGHTS); set_params({'max_minutes': 0})
b = admin_login('root')
raw_start(b, carol, 'administrator')
check('limit 0: no deadline in the session, no hint in the bar', 'ends automatically' not in b.get('/administrator/index.php').text and sess_user(b, 1) == carol)
reset()

check('no PHP warnings/deprecations/notices in any response', not PHP_ERRORS, PHP_ERRORS[:2])
passed = sum(1 for _, ok in RESULTS if ok)
print(f'\n{passed}/{len(RESULTS)} 1.1.0 checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
