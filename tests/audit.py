#!/usr/bin/env python3
"""Regression tests for the findings of the external audit of 1.0.5 (each check states the SAFE behaviour)."""
exec(open('/tmp/jtest/e2e.py').read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])
import os

CONF = '/tmp/jtest/www/configuration.php'
adm = ID('adm')
GRP = lambda u: sql("select group_concat(group_id) from jos_user_usergroup_map where user_id=%s" % ID(u))


def conf(key, val):
    s = open(CONF).read()
    s = re.sub(r'public \$%s = [^;]*;' % key, 'public $%s = %s;' % (key, val), s)
    open(CONF, 'w').write(s)


def consume(b, loc):
    return b.get(loc, allow_redirects=True)


def loc(r):
    return r.headers.get('Location', '')


def any_user(b):
    for c in b.s.cookies:
        v = sql("select concat(userid,'/',client_id) from jos_session where session_id='%s'" % c.value.replace("'", ''))
        if v != '':
            return v
    return None


def site_user(b):
    return sess_user(b, 0)


def admin_user(b):
    return sess_user(b, 1)


FRONTEND_ONLY = {'core.manage': {'7': 1}, 'fgloginasuser.frontend': {'7': 1}}
ALL_RIGHTS = {'core.manage': {'7': 1, '6': 1}, 'fgloginasuser.frontend': {'7': 1, '6': 1}, 'fgloginasuser.backend': {'7': 1, '6': 1}}

# make sure fixtures exist
if not sql("select id from jos_users where username='xena'"):
    subprocess.run(['php', 'cli/joomla.php', 'user:add', '--username=xena', '--name=Xena Both', '--password=' + PW,
                    '--email=xena@example.test', '--usergroup=Registered,Manager'], cwd='/tmp/jtest/www', capture_output=True)
xena = ID('xena')
sql("update jos_users set block=0, requireReset=0 where username in ('adm','alice','bob','carol','xena','mike')")

# ------------------------------------------------------------ F01 shared sessions
print('\n== F01 shared session')
reset(); set_rules(FRONTEND_ONLY)
conf('shared_session', 'true')
try:
    b = admin_login('adm')
    r = start(b, carol, 'site')
    consume_loc = loc(r)
    refused = 'task=login.consume' not in consume_loc
    check('F01 frontend impersonation is refused while sessions are shared', refused, consume_loc)
    if not refused:
        b.get(consume_loc)
        check('F01 (exposure) the session of the actor was not turned into the target', all(v.split('/')[0] != carol for v in [x for x in [sql("select concat(userid,'/',client_id) from jos_session where session_id='%s'" % c.value.replace("'", '')) for c in b.s.cookies] if x]), [c.name for c in b.s.cookies])
    sql("delete from jos_session where userid=%s" % carol)
    b2 = admin_login('adm'); start(b2, carol, 'administrator'); check('F01 backend impersonation is refused while sessions are shared too', sql("select count(*) from jos_session where userid=%s" % carol) == '0')
finally:
    conf('shared_session', 'false')

# ------------------------------------------------------------ F02 actor revalidation
print('\n== F02 actor revalidation on consume')
reset(); set_rules(FRONTEND_ONLY)
b = admin_login('adm')
r = start(b, alice, 'site'); token_loc = loc(r)
sql("update jos_users set block=1 where username='adm'")
fresh = Browser(); fresh.get(token_loc)
check('F02 token of a blocked actor is not honoured', site_user(fresh) != alice, site_user(fresh))
sql("update jos_users set block=0 where username='adm'")

reset(); set_rules(FRONTEND_ONLY)
b = admin_login('adm')
r = start(b, alice, 'site'); token_loc = loc(r)
sql("update jos_users set activation='abc' where username='adm'")
fresh = Browser(); fresh.get(token_loc)
check('F02 token of a not-activated actor is not honoured', site_user(fresh) != alice, site_user(fresh))
sql("update jos_users set activation='' where username='adm'")

# ------------------------------------------------------------ F03 group restrictions
print('\n== F03 group restrictions')
reset(); set_rules(ALL_RIGHTS)
set_params({'restrictions': {'restrictions0': {'actor_group': '6', 'target_groups': ['2']}}})
b = admin_login('adm')   # Administrator(7) is a child of Manager(6)
r = start(b, carol, 'site')
check('F03 rule on the PARENT group applies to the actor in a child group (Manager target refused)', 'task=login.consume' not in loc(r), loc(r))
r = start(b, bob, 'site')
check('F03 ... and the allowed target (Registered) is still reachable', 'task=login.consume' in loc(r), loc(r))
bm = admin_login('mike')
r = start(bm, xena, 'site')
check('F03 target in an allowed group AND an extra privileged group (Registered+Manager) is refused', 'task=login.consume' not in loc(r), loc(r))
r = start(bm, bob, 'site')
check('F03 plain Registered target still allowed for Manager', 'task=login.consume' in loc(r), loc(r))
reset(); set_rules(ALL_RIGHTS)
set_params({'restrictions_default': '1'})
r = start(admin_login('mike'), bob, 'site')
check('F03 strict mode: no rule for the actor = refused', 'task=login.consume' not in loc(r), loc(r))
reset(); set_rules(ALL_RIGHTS)
r = start(admin_login('mike'), bob, 'site')
check('F03 default mode (no rules) stays permissive, as documented', 'task=login.consume' in loc(r), loc(r))

# ------------------------------------------------------------ F04 requireReset
print('\n== F04 requireReset')
reset()
sql("update jos_users set requireReset=1 where username='alice'")
b = admin_login('root')
r = start(b, alice, 'site')
check('F04 target with a pending forced password reset is refused', 'task=login.consume' not in loc(r), loc(r))
sql("update jos_users set requireReset=1 where username='carol'")
start(b, carol, 'administrator')
check('F04 ... also for the backend', admin_user(b) == root, admin_user(b))
b = admin_login('root') if admin_user(b) != root else b
sql("update jos_users set requireReset=0 where username in ('alice','carol')")

# ------------------------------------------------------------ F05 normal logout
print('\n== F05 ordinary logout in an impersonated session')
reset()
sql("delete from jos_session where userid in (%s,%s)" % (alice, carol))
real = site_login('alice')
check('F05 setup: alice has her own site session', sql("select count(*) from jos_session where userid=%s and client_id=0" % alice) == '1')
b = admin_login('root')
r = start(b, alice, 'site'); b.get(loc(r))
page = b.get('/index.php?option=com_users&view=login')
t = tok(page.text)
b.post('/index.php?option=com_users&task=user.logout', {t: '1', 'return': ''})
check("F05 alice's own session survives a normal logout of the impersonated session",
      sql("select count(*) from jos_session where userid=%s and client_id=0" % alice) == '1', sql("select count(*) from jos_session where userid=%s and client_id=0" % alice))
check('F05 the impersonation is recorded as ended', 'ended' in logs(), logs())
check('F05 the impersonated session itself is gone', site_user(b) != alice)
# backend flavour
reset()
sql("delete from jos_session where userid=%s" % carol)
carol_real = admin_login('carol')
b = admin_login('root')
start(b, carol, 'administrator')
home = b.get('/administrator/index.php')
lo = re.search(r'href="([^"]*task=logout[^"]*)"', home.text)
if lo:
    b.get(lo.group(1).replace('&amp;', '&'))
check("F05 backend: carol's own admin session survives", sql("select count(*) from jos_session where userid=%s and client_id=1" % carol) == '1',
      sql("select count(*) from jos_session where userid=%s and client_id=1" % carol))

# ------------------------------------------------------------ F07 second frontend impersonation
print('\n== F07 second impersonation in the same browser')
reset()
b = admin_login('root')
r = start(b, alice, 'site'); b.get(loc(r))
r = start(b, bob, 'site'); b.get(loc(r))
rows = logs()
check('F07 the first impersonation is closed (not left "active") when it is replaced',
      not re.search(r'\talice\tsite\tactive', rows), rows)

# ------------------------------------------------------------ F08 return bar mechanism
print('\n== F08 system plugin disabled')
reset()
sql("update jos_extensions set enabled=0 where element='fgloginasuser' and folder='system'")
try:
    b = admin_login('root')
    r = start(b, alice, 'site')
    check('F08 no new impersonation without the return bar plugin (frontend)', 'task=login.consume' not in loc(r), loc(r))
    start(b, carol, 'administrator')
    check('F08 ... nor in the backend', admin_user(b) == root, admin_user(b))
finally:
    sql("update jos_extensions set enabled=1 where element='fgloginasuser' and folder='system'")

# ------------------------------------------------------------ F11 audit outage must not trap the user
print('\n== F11 audit table outage while ending')
reset()
b = admin_login('root')
start(b, carol, 'administrator')
home = b.get('/administrator/index.php')
stop_url = re.search(r'href="([^"]*task=impersonate\.stop[^"]*)"', home.text).group(1).replace('&amp;', '&')
sql("rename table jos_fgloginasuser_logs to jos_fgloginasuser_logs_x")
try:
    b.get(stop_url)
finally:
    sql("rename table jos_fgloginasuser_logs_x to jos_fgloginasuser_logs")
check('F11 ending still works while the audit table is unavailable (back to root, not stuck as carol)', admin_user(b) == root, admin_user(b))

# ------------------------------------------------------------ F13 retention / purge
print('\n== F13 purge keeps live rows')
reset()
sql("insert into jos_fgloginasuser_logs (actor_id,actor_username,target_id,target_username,client,status,ip,note,token_hash,token_expires,created) "
    "values (%s,'root',%s,'alice','site','pending','127.0.0.1','',sha2('x',256),date_add(now(), interval 1 hour),now())" % (root, alice))
b = admin_login('root')
page = b.get('/administrator/index.php?option=com_fgloginasuser&view=logs')
t = tok(page.text)
b.post('/administrator/index.php?option=com_fgloginasuser&task=logs.purge', {t: '1'})
check('F13 "delete all" does not destroy an unexpired pending token', sql("select count(*) from jos_fgloginasuser_logs where status='pending'") == '1')
page = b.get('/administrator/index.php?option=com_fgloginasuser&view=logs')
btn = re.search(r'<joomla-toolbar-button[^>]*task="logs\.purge"[^>]*>', page.text)
check('F13 "delete all" asks for confirmation in the UI', btn is not None and 'message=' in btn.group(0), btn.group(0) if btn else 'no button')

# ------------------------------------------------------------ F14 separate rights for logs
print('\n== F14 viewing logs and users needs its own right')
reset(); set_rules({'core.manage': {'7': 1}, 'fgloginasuser.frontend': {'7': 1}})
ba = admin_login('adm')
r = ba.get('/administrator/index.php?option=com_fgloginasuser&view=logs')
check('F14 an operator with only "login" rights cannot read the audit log', r.status_code == 403 or 'class="table' not in r.text, r.status_code)
r = ba.get('/administrator/index.php?option=com_fgloginasuser&view=users')
check('F14 ... and still sees the users screen needed for the buttons', r.status_code == 200 and 'fgLasForm' in r.text, r.status_code)

check('no PHP warnings/deprecations/notices in any response', not PHP_ERRORS, PHP_ERRORS[:2])
reset()
passed = sum(1 for _, ok in RESULTS if ok)
print(f'\n{passed}/{len(RESULTS)} audit checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
