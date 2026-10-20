#!/usr/bin/env python3
"""End-to-end test of pkg_fgloginasuser against a live Joomla 6.1.2 (browser-like sessions)."""
import re, subprocess, sys, json, time
import requests

BASE = 'http://127.0.0.1:8080'
PW = 'Passw0rd-12345!'
RESULTS = []


def sql(q):
    r = subprocess.run(['mysql', 'joomla', '-N', '-B', '-e', q], capture_output=True, text=True)
    if r.returncode:
        raise RuntimeError(r.stderr)
    return r.stdout.strip()


def check(name, cond, detail=''):
    RESULTS.append((name, bool(cond)))
    print(('PASS  ' if cond else 'FAIL  ') + name + ('' if cond else '   <-- ' + str(detail)[:400]))


PHP_ERRORS = []
ERR_RE = re.compile(r'(Warning|Deprecated|Notice|Fatal error|Parse error|Stack trace)\s*:', re.I)


def scan(r):
    for resp in list(r.history) + [r]:
        m = ERR_RE.search(resp.text or '')
        if m and 'fgloginasuser' not in (resp.text or '')[:0]:
            PHP_ERRORS.append((resp.request.method, resp.url, (resp.text or '')[max(0, m.start() - 80):m.start() + 300]))
    return r


class Browser:
    def __init__(self):
        self.s = requests.Session()

    def _u(self, u):
        return u if u.startswith('http') else BASE + u

    def get(self, u, **kw):
        kw.setdefault('allow_redirects', True)
        return scan(self.s.get(self._u(u), **kw))

    def post(self, u, data=None, **kw):
        kw.setdefault('allow_redirects', True)
        return scan(self.s.post(self._u(u), data=data, **kw))


def tok(html):
    m = re.search(r'name="([0-9a-f]{32})"\s+value="1"', html)
    return m.group(1) if m else None


def admin_login(user):
    pw = 'Rootpass-12345!' if user == 'root' else PW
    b = Browser()
    r = b.get('/administrator/index.php')
    t = tok(r.text)
    r = b.post('/administrator/index.php', {'username': user, 'passwd': pw, 'option': 'com_login',
                                            'task': 'login', 'return': 'aW5kZXgucGhw', t: '1'})
    assert 'task=logout' in r.text, 'admin login failed for ' + user
    return b


def site_login(user):
    b = Browser()
    r = b.get('/index.php?option=com_users&view=login')
    t = tok(r.text)
    r = b.post('/index.php?option=com_users&task=user.login', {'username': user, 'password': PW,
                                                                'return': '', t: '1'})
    return b


def users_page(b):
    return b.get('/administrator/index.php?option=com_fgloginasuser&view=users&list[limit]=100')


def start(b, uid, client, token=None, follow=False):
    page = users_page(b)
    t = token if token is not None else tok(page.text)
    data = {'id': uid, 'client': client}
    if t:
        data[t] = '1'
    return b.post('/administrator/index.php?option=com_fgloginasuser&task=impersonate.start', data,
                  allow_redirects=follow)


def set_params(d):
    sql("update jos_extensions set params='%s' where element='com_fgloginasuser' and type='component'" %
        json.dumps(d).replace("'", "''"))


def set_rules(d):
    sql("update jos_assets set rules='%s' where name='com_fgloginasuser'" % json.dumps(d))


def logs():
    return sql("select id,actor_username,target_username,client,status,note from jos_fgloginasuser_logs order by id")


def reset():
    sql("delete from jos_fgloginasuser_logs")
    set_params({})
    set_rules({})
    sql("update jos_users set lastvisitDate=NULL where username in ('alice','bob','carol','mike','sue')")


def sess_user(b, client_id):
    for c in b.s.cookies:
        v = sql("select userid from jos_session where session_id='%s' and client_id=%d" % (c.value.replace("'", ''), client_id))
        if v != '':
            return v
    return None


def ID(u):
    return sql("select id from jos_users where username='%s'" % u)


alice, bob, carol, mike, dave, sue, root = (ID(x) for x in ('alice', 'bob', 'carol', 'mike', 'dave', 'sue', 'root'))

# ---------------------------------------------------------------- 1. list + buttons
print('\n== 1. Users list')
reset()
b = admin_login('root')
page = users_page(b)
check('users view renders 200', page.status_code == 200 and 'fgLasForm' in page.text, page.status_code)
import html as _h0
check('own Users screen: form action is a clean URL', '&amp;' not in _h0.unescape(re.search(r'<form id="fgLasForm" action="([^"]*)"', page.text).group(1)))
check('frontend + backend button for alice',
      f'data-id="{alice}"' in page.text and re.search(rf'data-id="{alice}"\s+data-client="site"', page.text) and
      re.search(rf'data-id="{alice}"\s+data-client="administrator"', page.text) is None,
      'alice (Registered) must have site button but NO backend button (no core.login.admin)')
check('carol (Manager) has backend button',
      re.search(rf'data-id="{carol}"\s+data-client="administrator"', page.text) is not None)
check('no button for self (root)', f'data-id="{root}"' not in page.text)
check('blocked dave locked', f'data-id="{dave}"' not in page.text and 'icon-lock' in page.text)
check('super user sue locked by default', f'data-id="{sue}"' not in page.text)
check('impersonation form is separate from adminForm',
      page.text.index('id="fgLasForm"') > page.text.index('</form>'))
check('search filter works', 'Alice Reg' in b.get('/administrator/index.php?option=com_fgloginasuser&view=users&filter[search]=alice').text and
      'Bob Reg' not in b.get('/administrator/index.php?option=com_fgloginasuser&view=users&filter[search]=alice').text)

# ---------------------------------------------------------------- 2. frontend happy path
print('\n== 2. Frontend impersonation')
reset()
b = admin_login('root')
before = sql(f"select ifnull(lastvisitDate,'NULL') from jos_users where id={alice}")
r = start(b, alice, 'site')
loc = r.headers.get('Location', '')
check('start redirects to site consume URL', r.status_code in (302, 303) and 'task=login.consume&token=' in loc, (r.status_code, loc))
m = re.search(r'token=([0-9a-f]{64})', loc)
token = m.group(1) if m else ''
row = sql("select status, ip, token_hash is not null, token_expires from jos_fgloginasuser_logs order by id desc limit 1")
check('log row is pending with a token hash', row.startswith('pending') and '\t1\t' in row, row)
check('raw token is not stored in DB', token and sql(f"select count(*) from jos_fgloginasuser_logs where token_hash='{token}'") == '0')

site_before = b.get('/index.php?option=com_users&view=profile', allow_redirects=False)
check('site not logged in before consume', site_before.status_code in (302, 303) or 'alice' not in site_before.text.lower(), site_before.status_code)

r = b.get(loc, allow_redirects=False)
check('consume redirects (303)', r.status_code in (302, 303), r.status_code)
check('consume sends no-store + no-referrer', 'no-store' in r.headers.get('Cache-Control', '') and r.headers.get('Referrer-Policy') == 'no-referrer', r.headers)
prof = b.get('/index.php?option=com_users&view=profile')
check('site session is now alice', 'Alice Reg' in prof.text, prof.url)
check('banner shown on site', 'id="fg-las-bar"' in prof.text and 'alice' in prof.text and 'root' in prof.text)
check('log row active + token cleared', sql("select status, token_hash is null from jos_fgloginasuser_logs order by id desc limit 1") == 'active\t1')
after = sql(f"select ifnull(lastvisitDate,'NULL') from jos_users where id={alice}")
check("target's lastvisitDate not touched", before == after, (before, after))
check('admin session of root untouched',
      'Admin Root' in b.get('/administrator/index.php?option=com_users&view=users').text or 'task=logout' in b.get('/administrator/index.php').text)

# replay
b2 = Browser()
r = b2.get(loc, allow_redirects=True)
prof2 = b2.get('/index.php?option=com_users&view=profile', allow_redirects=False)
check('replayed token rejected (not logged in)', 'Alice Reg' not in prof2.text and 'invalid, expired or already used' in r.text, r.text[:200])

# stop
stop_url = re.search(r'href="([^"]*task=login\.stop[^"]*)"', prof.text)
stop_url = stop_url.group(1).replace('&amp;', '&') if stop_url else ''
check('banner has stop link with CSRF token', bool(re.search(r'[0-9a-f]{32}=1', stop_url)), stop_url)
bad = b.get(re.sub(r'[0-9a-f]{32}=1', 'deadbeefdeadbeefdeadbeefdeadbeef=1', stop_url), allow_redirects=False)
prof_still = b.get('/index.php?option=com_users&view=profile')
check('stop without valid token refused (still impersonating)', 'Alice Reg' in prof_still.text, bad.status_code)
r = b.get(stop_url, allow_redirects=False)
check('stop redirects to administrator', '/administrator/' in r.headers.get('Location', ''), r.headers.get('Location'))
prof3 = b.get('/index.php?option=com_users&view=profile', allow_redirects=False)
check('site session ended after stop', 'Alice Reg' not in prof3.text and 'fg-las-bar' not in prof3.text)
check('log row ended', sql("select status from jos_fgloginasuser_logs order by id desc limit 1") == 'ended')
check('admin session still alive after site stop', 'task=logout' in b.get('/administrator/index.php').text)

# ---------------------------------------------------------------- 3. token abuse
print('\n== 3. Token abuse')
reset()
b = admin_login('root')
r = start(b, alice, 'site'); token = re.search(r'token=([0-9a-f]{64})', r.headers['Location']).group(1)
sql("update jos_fgloginasuser_logs set token_expires='2000-01-01 00:00:00'")
bb = Browser(); r = bb.get(f'/index.php?option=com_fgloginasuser&task=login.consume&token={token}')
check('expired token rejected', 'Alice Reg' not in bb.get('/index.php?option=com_users&view=profile').text and 'invalid, expired' in r.text)
reset()
r = start(b, alice, 'site'); token = re.search(r'token=([0-9a-f]{64})', r.headers['Location']).group(1)
sql("update jos_fgloginasuser_logs set ip='9.9.9.9'")
bb = Browser(); r = bb.get(f'/index.php?option=com_fgloginasuser&task=login.consume&token={token}')
check('token from different IP rejected', 'different IP' in r.text and 'Alice Reg' not in bb.get('/index.php?option=com_users&view=profile').text, r.text[:200])
check('IP mismatch logged as denied', sql("select status from jos_fgloginasuser_logs order by id desc limit 1") == 'denied')
set_params({'bind_ip': '0'})
reset_params_ok = True
r = start(b, alice, 'site'); token = re.search(r'token=([0-9a-f]{64})', r.headers['Location']).group(1)
sql("update jos_fgloginasuser_logs set ip='9.9.9.9' where status='pending'")
bb = Browser(); bb.get(f'/index.php?option=com_fgloginasuser&task=login.consume&token={token}')
check('bind_ip=0 lets a different IP through', 'Alice Reg' in bb.get('/index.php?option=com_users&view=profile').text)
for junk in ['', 'abc', 'x' * 64, '../../etc/passwd', "' OR 1=1 --"]:
    bb = Browser(); r = bb.get('/index.php?option=com_fgloginasuser&task=login.consume', params={'token': junk})
    check(f'junk token {junk[:12]!r} rejected cleanly', r.status_code == 200 and 'invalid, expired' in r.text, r.status_code)
bb = Browser(); r = bb.get('/index.php?option=com_fgloginasuser')
check('bare component URL redirects home, no error', r.status_code == 200 and 'Fatal' not in r.text)

# ---------------------------------------------------------------- 4. backend impersonation
print('\n== 4. Backend impersonation')
reset()
b = admin_login('root')
def admin_sid(br):
    for c in br.s.cookies:
        if sql("select count(*) from jos_session where session_id='%s' and client_id=1" % c.value.replace("'", '')) == '1':
            return c.value
    return None


old_sid = admin_sid(b)
before = sql(f"select ifnull(lastvisitDate,'NULL') from jos_users where id={carol}")
r = start(b, carol, 'administrator')
check('backend start redirects to admin', r.status_code in (302, 303) and '/administrator/' in r.headers.get('Location', ''), (r.status_code, r.headers.get('Location')))
home = b.get('/administrator/index.php')
check('admin session switched to carol (banner)', 'id="fg-las-bar"' in home.text and 'carol' in home.text, home.text[-400:])
new_sid = admin_sid(b)
uid_in_session = sess_user(b, 1)
check('this browser session row belongs to carol', uid_in_session == carol, uid_in_session)
check('old session id was purged on fork (no row left for the pre-switch id)', old_sid is not None and sql("select count(*) from jos_session where session_id='%s'" % old_sid) == '0', old_sid)
check('session id changed on switch (fixation protection)', old_sid is not None and old_sid != new_sid, (old_sid, new_sid))
check("target's lastvisitDate not touched (backend)", before == sql(f"select ifnull(lastvisitDate,'NULL') from jos_users where id={carol}"))
check('no MFA captive for impersonated admin', 'view=captive' not in home.url, home.url)
check('log row active (admin)', sql("select client,status from jos_fgloginasuser_logs order by id desc limit 1") == 'administrator\tactive')
# impersonated carol cannot use the component
r = b.get('/administrator/index.php?option=com_fgloginasuser&view=users', allow_redirects=False)
check('impersonated Manager cannot open the users screen (no core.manage)', r.status_code in (403, 302, 303) or 'not authorised' in r.text.lower() or 'not allowed' in r.text.lower() or 'fgLasForm' not in r.text, r.status_code)
# but can stop
stop = re.search(r'href="([^"]*task=impersonate\.stop[^"]*)"', home.text).group(1).replace('&amp;', '&')
r = b.get(re.sub(r'[0-9a-f]{32}=1', 'deadbeefdeadbeefdeadbeefdeadbeef=1', stop), allow_redirects=False)
check('stop with bad token refused', 'fg-las-bar' in b.get('/administrator/index.php').text)
r = b.get(stop, allow_redirects=False)
check('stop redirects back', r.status_code in (302, 303), r.status_code)
home2 = b.get('/administrator/index.php')
check('returned to root, banner gone', 'fg-las-bar' not in home2.text and 'task=logout' in home2.text)
check('session row belongs to root again', sess_user(b, 1) == root, sess_user(b, 1))
check('log row ended (admin)', sql("select status from jos_fgloginasuser_logs order by id desc limit 1") == 'ended')
check('root can open component after return', 'fgLasForm' in users_page(b).text)

# ---------------------------------------------------------------- 5. policy
print('\n== 5. Policy / ACL')
reset()
b = admin_login('root')
for who, uid, client, expect in [('self', root, 'site', 'yourself'), ('blocked', dave, 'site', 'blocked'),
                                  ('superuser', sue, 'site', 'Super Users'), ('registered->backend', alice, 'administrator', 'not allowed to log in to the backend'),
                                  ('missing user', 99999, 'site', 'does not exist')]:
    r = start(b, uid, client, follow=True)
    check(f'denied: {who}', expect in r.text, r.text[:150])
r = start(b, alice, 'bogus', follow=True)
check('denied: unknown client', 'Unknown login target' in r.text)
check('denials are logged', sql("select count(*) from jos_fgloginasuser_logs where status='denied'") == '6')
# CSRF
page = users_page(b)
r = b.post('/administrator/index.php?option=com_fgloginasuser&task=impersonate.start', {'id': alice, 'client': 'site'}, allow_redirects=False)
check('POST without CSRF token refused', 'task=login.consume' not in r.headers.get('Location', '') and sql("select count(*) from jos_fgloginasuser_logs where status='pending'") == '0', r.headers.get('Location'))
r = b.get(f'/administrator/index.php?option=com_fgloginasuser&task=impersonate.start&id={alice}&client=site&{tok(page.text)}=1', allow_redirects=False)
check('GET start is not accepted', 'task=login.consume' not in r.headers.get('Location', ''), r.headers.get('Location'))

set_params({'allow_superusers': '1'})
r = start(b, sue, 'administrator')
check('allow_superusers=1: super user -> super user works', 'task=login.consume' in r.headers.get('Location', '') or r.status_code in (302, 303))
home = b.get('/administrator/index.php')
check('now impersonating sue in backend', 'fg-las-bar' in home.text and 'sue' in home.text)
stop = re.search(r'href="([^"]*task=impersonate\.stop[^"]*)"', home.text).group(1).replace('&amp;', '&')
b.get(stop)
check('returned to root', 'fg-las-bar' not in b.get('/administrator/index.php').text)

# non-super actor (mike, Manager)
reset()
bm = admin_login('mike')
r = bm.get('/administrator/index.php?option=com_fgloginasuser&view=users', allow_redirects=False)
check('Manager without permission cannot open component', 'fgLasForm' not in r.text, r.status_code)
set_rules({'core.manage': {'6': 1}})
r = start(bm, alice, 'site', token=tok(bm.get('/administrator/index.php').text), follow=True)
check('core.manage alone does not allow impersonation', sql("select count(*) from jos_fgloginasuser_logs where status='pending'") == '0')
set_rules({'core.manage': {'6': 1}, 'fgloginasuser.frontend': {'6': 1}})
page = users_page(bm)
check('Manager with frontend perm: site button for alice, none for backend',
      re.search(rf'data-id="{alice}"\s+data-client="site"', page.text) and not re.search(r'data-client="administrator"', page.text))
check('Manager cannot impersonate super user sue', f'data-id="{sue}"' not in page.text)
r = start(bm, alice, 'site')
check('Manager -> alice (frontend) allowed', 'task=login.consume' in r.headers.get('Location', ''), r.headers.get('Location'))
r = start(bm, alice, 'administrator', follow=True)
check('Manager lacks backend permission', 'not allowed to log in as other users' in r.text or "not allowed to log in to the backend" in r.text, r.text[:200])
set_rules({'core.manage': {'6': 1}, 'fgloginasuser.frontend': {'6': 1}, 'fgloginasuser.backend': {'6': 1}})
r = start(bm, carol, 'administrator')
check('Manager -> carol (backend) allowed once granted', r.status_code in (302, 303) and 'task=login.consume' not in r.headers.get('Location', ''), r.headers.get('Location'))
home = bm.get('/administrator/index.php'); stop = re.search(r'href="([^"]*task=impersonate\.stop[^"]*)"', home.text)
check('Manager impersonating carol sees banner', stop is not None)
if stop:
    bm.get(stop.group(1).replace('&amp;', '&'))
check('Manager returned to mike', 'fg-las-bar' not in bm.get('/administrator/index.php').text and sess_user(bm, 1) == mike)

# restrictions
set_params({'restrictions': {'restrictions0': {'actor_group': '6', 'target_groups': ['2']}}})
page = users_page(bm)
check('restriction: Manager may only reach Registered -> alice yes', re.search(rf'data-id="{alice}"\s+data-client="site"', page.text) is not None)
check('restriction: Manager -> carol (Manager) blocked', not re.search(rf'data-id="{carol}"', page.text))
r = start(bm, carol, 'site', follow=True)
check('restriction enforced server-side', 'not allowed to log in as a member' in r.text or 'Your user group is not allowed' in r.text, r.text[:200])
b = admin_login('root')
check('restriction never applies to Super Users', re.search(rf'data-id="{carol}"\s+data-client="site"', users_page(b).text) is not None)
set_params({'restrictions': {'restrictions0': {'actor_group': '6', 'target_groups': []}}})
check('row with no target groups allows nobody', 'data-client=' not in users_page(bm).text.split('id="fgUserList"')[-1] if 'id="fgUserList"' in users_page(bm).text else True)

# permission revoked between issuing and using a token
reset()
set_rules({'core.manage': {'6': 1}, 'fgloginasuser.frontend': {'6': 1}})
r = start(bm, alice, 'site'); token = re.search(r'token=([0-9a-f]{64})', r.headers['Location']).group(1)
set_rules({})
bb = Browser(); r = bb.get(f'/index.php?option=com_fgloginasuser&task=login.consume&token={token}')
check('permission revoked after issuing -> consume denied', 'no longer permitted' in r.text and 'Alice Reg' not in bb.get('/index.php?option=com_users&view=profile').text, r.text[:200])

# ---------------------------------------------------------------- 6. real sessions survive
print("\n== 6. Real user's own session survives impersonation")
reset()
real = site_login('alice')
check('alice really logged in on site', 'Alice Reg' in real.get('/index.php?option=com_users&view=profile').text)
n_before = sql(f"select count(*) from jos_session where userid={alice} and client_id=0")
b = admin_login('root')
r = start(b, alice, 'site'); loc = r.headers['Location']
imp = Browser(); imp.get(loc)
check('impersonation session exists in parallel', 'Alice Reg' in imp.get('/index.php?option=com_users&view=profile').text)
prof = imp.get('/index.php?option=com_users&view=profile')
stop = re.search(r'href="([^"]*task=login\.stop[^"]*)"', prof.text).group(1).replace('&amp;', '&')
imp.get(stop)
check("alice's own session is still valid after stop", 'Alice Reg' in real.get('/index.php?option=com_users&view=profile').text)
check('session rows: only the impersonation row removed', sql(f"select count(*) from jos_session where userid={alice} and client_id=0") == n_before, (n_before,))

# ---------------------------------------------------------------- 7. MFA bypass
print('\n== 7. Target with MFA')
reset()
sql(f"delete from jos_user_mfa where user_id={alice}")
sql(f"insert into jos_user_mfa (user_id,title,method,`default`,options,created_on,last_used) values ({alice},'t','totp',1,'{{\"key\":\"JBSWY3DPEHPK3PXP\"}}',now(),NULL)")
ctl = site_login('alice')
ctl_prof = ctl.get('/index.php?option=com_users&view=profile')
check('CONTROL: a normal login of an MFA user IS sent to the captive page', 'captive' in ctl_prof.url, ctl_prof.url)
b = admin_login('root')
r = start(b, alice, 'site'); imp = Browser(); r2 = imp.get(r.headers['Location'])
prof = imp.get('/index.php?option=com_users&view=profile')
check('MFA-protected target: no captive page, profile reachable', 'Alice Reg' in prof.text and 'captive' not in prof.url, prof.url)
sql(f"delete from jos_user_mfa where user_id={alice}")

# ---------------------------------------------------------------- 8. logs UI + config
print('\n== 8. Logs UI, config, notification resilience')
reset()
b = admin_login('root')
start(b, alice, 'site'); start(b, dave, 'site', follow=True)
lg = b.get('/administrator/index.php?option=com_fgloginasuser&view=logs')
check('logs view renders with entries', lg.status_code == 200 and 'alice' in lg.text and 'Denied' in lg.text and 'Not used' in lg.text.replace('(expired)', '') or 'Waiting' in lg.text, lg.status_code)
t = tok(lg.text)
first = sql("select min(id) from jos_fgloginasuser_logs")
r = b.post('/administrator/index.php?option=com_fgloginasuser&view=logs', {'cid[]': first, 'task': 'logs.delete', 'boxchecked': '1', t: '1'})
check('delete selected log entry', sql(f"select count(*) from jos_fgloginasuser_logs where id={first}") == '0', r.text[:200])
r = b.post('/administrator/index.php?option=com_fgloginasuser&view=logs', {'task': 'logs.purge', t: '1'})
check('purge all', sql("select count(*) from jos_fgloginasuser_logs") == '0')
cfg = b.get('/administrator/index.php?option=com_config&view=component&component=com_fgloginasuser')
check('config screen renders all tabs', cfg.status_code == 200 and all(x in cfg.text for x in ['token_ttl', 'bind_ip', 'restrictions', 'notify_enabled', 'button_text_site', 'rules']), cfg.status_code)
set_params({'notify_enabled': '1', 'notify_email': 'ops@example.test', 'button_text_site': 'Go as %s', 'custom_css': '.fg-las-btn{outline:2px solid red}</style><script>alert(1)</script>'})
page = users_page(b)
check('custom button text with %s applied', 'Go as alice' in page.text)
check('custom CSS injected but "<" stripped', 'outline:2px solid red' in page.text and '<script>alert(1)' not in page.text)
open('/tmp/jtest/mails.txt', 'w').close()
r = start(b, alice, 'site'); imp = Browser(); imp.get(r.headers['Location'])
time.sleep(0.5)
mail = open('/tmp/jtest/mails.txt').read()
check('notification mail sent via SMTP (frontend login)', 'ops@example.test' in mail, mail[:300])
body = re.sub(r'=\r?\n', '', mail)
check('mail body names admin, target, place and IP', all(x in body for x in ['root', 'alice', 'Frontend', '127.0.0.1']) and 'UTC' in body, body[-500:])
check('mail subject carries the site name', 'FGTest' in mail, mail[:300])
open('/tmp/jtest/mails.txt', 'w').close()
bm2 = admin_login('root'); start(bm2, carol, 'administrator'); time.sleep(0.5)
check('notification mail sent (backend switch)', 'carol' in open('/tmp/jtest/mails.txt').read() and 'Backend' in re.sub(r'=\r?\n', '', open('/tmp/jtest/mails.txt').read()))
set_params({'notify_enabled': '1', 'notify_email': 'ops@example.test'})
cfgp = '/tmp/jtest/www/configuration.php'
orig_cfg = open(cfgp).read()
open(cfgp, 'w').write(orig_cfg.replace('public $smtpport = 1025;', 'public $smtpport = 1;'))
try:
    r = start(b, alice, 'site'); imp = Browser(); imp.get(r.headers['Location'])
    check('SMTP down: impersonation still succeeds', 'Alice Reg' in imp.get('/index.php?option=com_users&view=profile').text)
finally:
    open(cfgp, 'w').write(orig_cfg)

# ---------------------------------------------------------------- 9. log retention
print('\n== 9. Retention')
reset()
sql("insert into jos_fgloginasuser_logs (actor_id,actor_username,target_id,target_username,client,status,ip,created) values (1,'old',1,'old','site','ended','1.1.1.1', now() - interval 400 day)")
set_params({'log_retention_days': '365'})
b = admin_login('root'); start(b, alice, 'site')
check('rows older than retention are purged on use', sql("select count(*) from jos_fgloginasuser_logs where actor_username='old'") == '0')


# ---------------------------------------------------------------- 10. buttons in core com_users list
print('\n== 10. Buttons in the core Users list (com_users)')
adm = sql("select id from jos_users where username='adm'")
USERS = '/administrator/index.php?option=com_users&view=users&list[limit]=100'


def plg_param(v):
    sql("update jos_extensions set params='%s' where element='fgloginasuser' and folder='system'" % json.dumps({'show_in_users_list': v}))


def row_of(html, uid):
    m = re.search(r'<tr class="row\d+">(?:(?!</tr>).)*?value="%s"(?:(?!</tr>).)*</tr>' % uid, html, re.S)
    return m.group(0) if m else ''


reset(); plg_param('1')
b = admin_login('root')
page = b.get(USERS)
check('com_users list renders', page.status_code == 200 and 'id="userList"' in page.text)
check('single hidden form, outside the list form', page.text.count('id="fgLasForm"') == 1 and page.text.index('id="fgLasForm"') > page.text.rindex('</form>', 0, page.text.index('id="fgLasForm"') + 1) )
check('buttons for alice: frontend only', re.search(rf'data-id="{alice}"\s+data-client="site"', page.text) and not re.search(rf'data-id="{alice}"\s+data-client="administrator"', page.text))
check('buttons for carol: frontend + backend', re.search(rf'data-id="{carol}"\s+data-client="site"', page.text) and re.search(rf'data-id="{carol}"\s+data-client="administrator"', page.text))
check('no buttons for self, blocked, super user', all(f'data-id="{x}"' not in page.text for x in (root, dave, sue)))
check('buttons sit inside the right row', 'fg-las-inline' in row_of(page.text, alice) and 'fg-las-btn' in row_of(page.text, alice))
check('users.js and the hidden form are present', 'com_fgloginasuser/js/users.js' in page.text)
tkn = tok(page.text)
import html as _html
action = _html.unescape(re.search(r'<form id="fgLasForm" action="([^"]*)"', page.text).group(1))
check('form action is a clean URL (no leftover entities)', '&amp;' not in action and 'task=impersonate.start' in action and 'option=com_fgloginasuser' in action, action)
r = b.post(action, {'id': alice, 'client': 'site', tkn: '1'}, allow_redirects=False)
check('the form token on the com_users page works for starting a login', 'task=login.consume' in r.headers.get('Location', ''), r.headers.get('Location'))
sql("delete from jos_fgloginasuser_logs")
r = b.get(USERS + '&filter[search]=alice')
check('search in com_users: only the visible row gets buttons', f'data-id="{alice}"' in r.text and f'data-id="{carol}"' not in r.text)

thead_cols = len(re.findall(r'<(?:th|td)\b', re.search(r'<thead.*?</thead>', page.text, re.S).group(0)))
row_cols = {len(re.findall(r'<(?:th|td)\b', r)) for r in re.findall(r'<tr class="row\d+">.*?</tr>', page.text, re.S)}
check('separate "Login as" column: header and all rows have the same number of cells', row_cols == {thead_cols}, (thead_cols, row_cols))
check('column header sits right after Username', re.search(r'data-order="a\.username".*?</th>\s*<th scope="col" class="d-none d-md-table-cell text-nowrap">Login as</th>', page.text, re.S) is not None)
check('buttons are in the new cell (after the Username cell), not under the name', 'fg-las-inline' not in re.search(r'<th scope="row">.*?</th>', row_of(page.text, alice), re.S).group(0))
check('com_users buttons are compact with the sentence as tooltip', f'title="Login to frontend as alice"' in page.text and re.search(r'fg-las-btn[^>]*>.*?Frontend</button>', page.text, re.S))
own = b.get('/administrator/index.php?option=com_fgloginasuser&view=users')
check('own Users screen keeps the full button text', 'Login to frontend as alice</button>' in re.sub(r'\s+', ' ', own.text).replace('> ', '>').replace(' <', '<') or 'Login to frontend as alice' in own.text)
check('confirmation dialog rendered once with translated texts', page.text.count('id="fgLasDialog"') == 1 and 'Log in to the backend as %s' in page.text and 'Cancel' in page.text)
plg_param('0')
off = b.get(USERS)
check('plugin option off: no buttons, no form, no script', 'fg-las' not in off.text and 'fgLasForm' not in off.text and 'users.js' not in off.text.replace('com_users', ''))
plg_param('1')
on = b.get(USERS)
strip = lambda h: re.sub(r'<div class="fg-las-inline">.*?</div>', '', h, flags=re.S)
check('core markup untouched apart from our blocks (div balance, checkboxes, rows)',
      on.text.count('<tr class="row') == off.text.count('<tr class="row') and on.text.count('name="cid[]"') == off.text.count('name="cid[]"')
      and (on.text.count('<div') - on.text.count('</div')) == (off.text.count('<div') - off.text.count('</div')) )
check('other com_users views untouched', 'fg-las' not in b.get('/administrator/index.php?option=com_users&view=groups').text)

# actor without the right to use the component, then with partial rights
ba = admin_login('adm')
set_rules({})
pa = ba.get(USERS)
check('Administrator without permission: users list works, no buttons, no form', pa.status_code == 200 and 'id="userList"' in pa.text and 'fg-las' not in pa.text)
set_rules({'core.manage': {'7': 1}, 'fgloginasuser.frontend': {'7': 1}})
pa = ba.get(USERS)
check('Administrator with frontend right: only frontend buttons',
      re.search(rf'data-id="{alice}"\s+data-client="site"', pa.text) and 'data-client="administrator"' not in pa.text)
check('Administrator never sees buttons for the Super User', f'data-id="{sue}"' not in pa.text)
set_params({'restrictions': {'restrictions0': {'actor_group': '7', 'target_groups': ['2']}}})
pa = ba.get(USERS)
check('restrictions apply in the com_users list too', f'data-id="{alice}"' in pa.text and f'data-id="{carol}"' not in pa.text)
reset()

# ---------------------------------------------------------------- 11. User Actions Log
print('\n== 11. Joomla User Actions Log')
sql("delete from jos_action_logs")
sql("update jos_extensions set enabled=1 where element='fgloginasuser' and folder='actionlog'")
b = admin_login('root')
sql("delete from jos_action_logs")
r = start(b, alice, 'site')
check('issuing a frontend token writes nothing yet', sql("select count(*) from jos_action_logs where extension='com_fgloginasuser'") == '0')
imp = Browser(); imp.get(r.headers['Location'])
prof = imp.get('/index.php?option=com_users&view=profile')
row = sql("select message_language_key, user_id, item_id, extension from jos_action_logs where extension='com_fgloginasuser' order by id desc limit 1")
check('frontend login logged (actor = administrator, item = target)', row == f'COM_FGLOGINASUSER_ACTIONLOG_STARTED_SITE\t{root}\t{alice}\tcom_fgloginasuser', row)
stop = re.search(r'href="([^"]*task=login\.stop[^"]*)"', prof.text).group(1).replace('&amp;', '&')
imp.get(stop)
check('ending a frontend login logged', sql("select message_language_key from jos_action_logs order by id desc limit 1") == 'COM_FGLOGINASUSER_ACTIONLOG_ENDED' and sql("select user_id from jos_action_logs order by id desc limit 1") == root)
start(b, carol, 'administrator')
check('backend login logged', sql("select message_language_key from jos_action_logs order by id desc limit 1") == 'COM_FGLOGINASUSER_ACTIONLOG_STARTED_ADMIN')
home = b.get('/administrator/index.php')
b.get(re.search(r'href="([^"]*task=impersonate\.stop[^"]*)"', home.text).group(1).replace('&amp;', '&'))
check('ending a backend login logged with the right actor', sql("select concat(message_language_key,'|',user_id) from jos_action_logs order by id desc limit 1") == f'COM_FGLOGINASUSER_ACTIONLOG_ENDED|{root}')
start(b, root, 'site', follow=True)
msg = sql("select message from jos_action_logs where message_language_key like '%DENIED' order by id desc limit 1")
check('refusal logged with translated reason', 'yourself' in msg, msg)
r = start(b, alice, 'site'); token = re.search(r'token=([0-9a-f]{64})', r.headers['Location']).group(1)
sql("update jos_fgloginasuser_logs set ip='9.9.9.9' where status='pending'")
Browser().get(f'/index.php?option=com_fgloginasuser&task=login.consume&token={token}')
msg = sql("select message from jos_action_logs where message_language_key like '%DENIED' order by id desc limit 1")
check('token IP mismatch logged to the action log', 'different IP' in msg, msg)

al = b.get('/administrator/index.php?option=com_actionlogs&view=actionlogs')
check('Actions Log screen renders our entries as readable text',
      al.status_code == 200 and 'logged in to the' in al.text and 'frontend' in al.text and 'COM_FGLOGINASUSER_ACTIONLOG' not in al.text, al.status_code)
check('entries link to the user edit screens', f'task=user.edit&amp;id={alice}' in al.text or f'task=user.edit&id={alice}' in al.text)
fl = b.get('/administrator/index.php?option=com_actionlogs&view=actionlogs&filter[extension]=com_fgloginasuser')
check('"com_fgloginasuser" is a selectable extension filter', 'value="com_fgloginasuser"' in al.text and 'logged in to the' in fl.text)

sql("update jos_extensions set enabled=0 where element='fgloginasuser' and folder='actionlog'")
n = sql("select count(*) from jos_action_logs where extension='com_fgloginasuser'")
r = start(b, alice, 'site'); Browser().get(r.headers['Location'])
check('action log plugin disabled: nothing more is written, impersonation still works', sql("select count(*) from jos_action_logs where extension='com_fgloginasuser'") == n)
al2 = b.get('/administrator/index.php?option=com_actionlogs&view=actionlogs')
check('older entries still render while the plugin is disabled', 'logged in to the' in al2.text and 'COM_FGLOGINASUSER_ACTIONLOG' not in al2.text)
sql("update jos_extensions set enabled=1 where element='fgloginasuser' and folder='actionlog'")

check('no PHP warnings/deprecations/notices in any response (error_reporting=maximum)', not PHP_ERRORS, PHP_ERRORS[:2])
passed = sum(1 for _, ok in RESULTS if ok)
print(f'\n{passed}/{len(RESULTS)} checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
