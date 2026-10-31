#!/usr/bin/env python3
"""Tests of 1.5.0: per-group maximum duration, bar stylesheet, webhook, history on the user edit page.
Needs the HTTPS receiver /tmp/hook/receiver.py (self-signed certificate trusted by the PHP server, see env.sh)."""
import datetime, hashlib, hmac
exec(open('/tmp/jtest/e2e.py').read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])

RIGHTS = {'core.manage': {'7': 1, '6': 1}, 'fgloginasuser.frontend': {'7': 1, '6': 1}, 'fgloginasuser.backend': {'7': 1, '6': 1},
          'fgloginasuser.logs': {'7': 1, '6': 1}}
START = '/administrator/index.php?option=com_fgloginasuser&task=impersonate.start'
adm, carol, alice = ID('adm'), ID('carol'), ID('alice')
RECV = '/tmp/hook/received.jsonl'
LOGFILE = '/tmp/jtest/www/administrator/logs/com_fgloginasuser.log.php'


def begin(b, target=None, client='administrator'):
    t = tok(users_page(b).text)
    return b.s.post(BASE + START, data={'id': target or carol, 'client': client, t: '1'}, allow_redirects=True)


def stop(b):
    m = re.search(r'([0-9a-f]{32})=1', b.get('/administrator/index.php?option=com_fgloginasuser&view=users').text)
    return b.get('/administrator/index.php?option=com_fgloginasuser&task=impersonate.stop&' + (m.group(1) if m else 'x') + '=1')


def until_minutes(b):
    """Minutes from now to the end announced by the bar (None = no limit shown)."""
    html = b.get('/administrator/index.php').text
    m = re.search(r'ends automatically at (\d\d):(\d\d)', html)
    if not m:
        return None
    now = datetime.datetime.now(datetime.timezone.utc)
    end = now.replace(hour=int(m.group(1)), minute=int(m.group(2)), second=0, microsecond=0)
    d = (end - now).total_seconds() / 60
    return d + 1440 if d < -60 else d


def near(v, want):
    return v is not None and abs(v - want) <= 1.6


def duration(params):
    reset(); set_rules(RIGHTS); set_params(params)
    b = admin_login('adm'); begin(b)
    v = until_minutes(b)
    stop(b)
    return v


print('\n== maximum duration per group')
check('no rows: the general limit (60)', near(duration({'max_minutes': 60}), 60))
check('row for the actor group with 5 minutes: 5', near(duration({'max_minutes': 60, 'restrictions': {'r0': {'actor_group': 7, 'target_groups': [6], 'max_minutes': 5}}}), 5))
check('row longer than the general limit: general wins', near(duration({'max_minutes': 30, 'restrictions': {'r0': {'actor_group': 7, 'target_groups': [6], 'max_minutes': 120}}}), 30))
check('inherited group (Manager row applies to an Administrator) and the shortest of several rows wins',
      near(duration({'max_minutes': 60, 'restrictions': {'r0': {'actor_group': 6, 'target_groups': [6], 'max_minutes': 30},
                                                        'r1': {'actor_group': 7, 'target_groups': [6], 'max_minutes': 10}}}), 10))
check('row of a group the actor is not in: ignored', near(duration({'max_minutes': 60, 'restrictions': {'r0': {'actor_group': 8, 'target_groups': [6], 'max_minutes': 3}}}), 60))
check('row without a number limits nothing', near(duration({'max_minutes': 45, 'restrictions': {'r0': {'actor_group': 7, 'target_groups': [6], 'max_minutes': 0}}}), 45))
check('no general limit but a row: the row applies', near(duration({'max_minutes': 0, 'restrictions': {'r0': {'actor_group': 7, 'target_groups': [6], 'max_minutes': 15}}}), 15))
check('no limit anywhere: no end announced', duration({'max_minutes': 0}) is None)

print('\n== return bar')
reset(); set_rules(RIGHTS)
b = admin_login('adm'); begin(b)
html = b.get('/administrator/index.php').text
bar = re.search(r'<div id="fg-las-bar".*?</div>', html, re.S).group(0)
check('bar has no inline styles', 'style=' not in bar, bar[:200])
check('stylesheet link is in the head', re.search(r'<head>.*media/com_fgloginasuser/css/bar\.css.*</head>', html, re.S) is not None)
css = b.get('/media/com_fgloginasuser/css/bar.css')
check('stylesheet is served and uses variables', css.status_code == 200 and '--fg-las-bar-bg' in css.text and 'var(--fg-las-bar-bg)' in css.text)
check('return link is still there', 'task=impersonate.stop' in bar)
stop(b)
reset(); set_rules(RIGHTS)
b = admin_login('adm'); r = start(b, alice, 'site', follow=True)
site = Browser(); loc = r.text
m = re.search(r'login\.consume', loc)
r = b.s.post(BASE + START, data={'id': alice, 'client': 'site', tok(users_page(b).text): '1'}, allow_redirects=False)
tk = re.search(r'name="token" value="([^"]+)"', r.text)
if tk:
    site.s.post(BASE + '/index.php?option=com_fgloginasuser&task=login.consume', data={'token': tk.group(1)}, allow_redirects=True)
    page = site.get('/index.php').text
    check('frontend bar uses the stylesheet too', 'id="fg-las-bar"' in page and 'style=' not in re.search(r'<div id="fg-las-bar".*?</div>', page, re.S).group(0) and 'bar.css' in page)
else:
    check('frontend bar uses the stylesheet too', False, r.text[:200])
reset()


def received():
    try:
        return [json.loads(x) for x in open(RECV).read().splitlines() if x]
    except FileNotFoundError:
        return []


def clear():
    if os.path.exists(RECV):
        os.remove(RECV)


import os
HOOK = 'https://127.0.0.1:9443/hook'
print('\n== webhook')
reset(); set_rules(RIGHTS); clear()
b = admin_login('adm'); begin(b); stop(b)
check('off by default: nothing is sent', received() == [])

reset(); set_rules(RIGHTS); clear(); set_params({'webhook_enabled': 1, 'webhook_url': HOOK, 'webhook_preset': 'generic', 'webhook_secret': 's3cret'})
b = admin_login('adm'); begin(b); stop(b)
got = received()
check('generic: started and ended arrive', [json.loads(g['body'])['event'] for g in got] == ['started', 'ended'], [g['body'][:80] for g in got])
if got:
    d = json.loads(got[0]['body'])
    check('generic: content', d['actor']['username'] == 'adm' and d['target']['username'] == 'carol' and d['client'] == 'administrator' and d['ip'] and d['time'], d)
    sig = got[0]['headers'].get('X-FG-Signature', '')
    check('generic: HMAC signature verifies', sig == 'sha256=' + hmac.new(b's3cret', got[0]['body'].encode(), hashlib.sha256).hexdigest(), sig)
    check('generic: no token or password anywhere', not re.search(r'token|passw', ' '.join(g['body'] for g in got), re.I))
else:
    check('generic: content', False); check('generic: HMAC signature verifies', False); check('generic: no token or password anywhere', False)

for preset, key in (('slack', 'text'), ('discord', 'content'), ('teams', 'text')):
    reset(); set_rules(RIGHTS); clear(); set_params({'webhook_enabled': 1, 'webhook_url': HOOK, 'webhook_preset': preset, 'webhook_events': ['started']})
    b = admin_login('adm'); begin(b); stop(b)
    got = received()
    body = json.loads(got[0]['body']) if got else {}
    check('%s: one message, only the chosen event, text with the names' % preset, len(got) == 1 and 'adm' in body.get(key, '') and 'carol' in body.get(key, ''), got)
    if preset == 'discord':
        check('discord: mentions are not parsed', body.get('allowed_mentions') == {'parse': []}, body)

reset(); set_rules(RIGHTS); clear(); set_params({'webhook_enabled': 1, 'webhook_url': HOOK, 'webhook_events': ['denied']})
b = admin_login('adm'); begin(b, adm); begin(b); stop(b)
got = received()
check('only denied events: the refused attempt is sent, nothing else', len(got) == 1 and json.loads(got[0]['body'])['event'] == 'denied', [g['body'][:120] for g in got])

reset(); set_rules(RIGHTS); clear(); set_params({'webhook_enabled': 1, 'webhook_url': 'http://127.0.0.1:9443/hook'})
b = admin_login('adm'); begin(b); stop(b)
check('http address is never called', received() == [])
reset(); set_rules(RIGHTS); clear(); set_params({'webhook_enabled': 1, 'webhook_url': 'https://user:pw@127.0.0.1:9443/hook'})
b = admin_login('adm'); begin(b); stop(b)
check('address with credentials is never called', received() == [])

if os.path.exists(LOGFILE):
    os.remove(LOGFILE)
reset(); set_rules(RIGHTS); clear(); set_params({'webhook_enabled': 1, 'webhook_url': 'https://127.0.0.1:9443/fail'})
b = admin_login('adm'); begin(b)
check('receiver answers 500: the login still works', sess_user(b, 1) == carol, sess_user(b, 1))
stop(b)
check('  failure is written to the log file', os.path.exists(LOGFILE) and 'Webhook failed' in open(LOGFILE).read())

reset(); set_rules(RIGHTS); clear(); set_params({'webhook_enabled': 1, 'webhook_url': 'https://127.0.0.1:9443/redir'})
b = admin_login('adm'); begin(b); stop(b)
check('redirect answer: login unaffected', sess_user(b, 1) == adm)

reset(); set_rules(RIGHTS); clear(); set_params({'webhook_enabled': 1, 'webhook_url': 'https://127.0.0.1:9443/slow'})
b = admin_login('adm'); t0 = time.time(); begin(b); dt = time.time() - t0
check('slow receiver (8 s): the login waits only about 3 s', sess_user(b, 1) == carol and dt < 6.5, round(dt, 1))
stop(b)

reset(); set_rules(RIGHTS); clear(); set_params({'webhook_enabled': 1, 'webhook_url': 'https://127.0.0.1:1/nothing'})
b = admin_login('adm'); begin(b)
check('unreachable receiver: login works', sess_user(b, 1) == carol)
stop(b)

print('\n== history on the user edit page')
reset(); set_rules(RIGHTS)
b = admin_login('adm'); begin(b); stop(b)
EDIT = '/administrator/index.php?option=com_users&task=user.edit&id='
page = b.get(EDIT + carol).text
check('user edit page shows the history to someone who may view the logs', 'fg-las-history' in page and 'Last logins as this user' in page, '')
check('  with the other party and a link to the filtered log', 'filter[target]=carol' in page.replace('%5B', '[').replace('%5D', ']') and '>adm<' in page.replace(' ', ''), '')
b.get('/administrator/index.php?option=com_users&task=user.cancel')
page = b.get(EDIT + adm).text
check("administrator's own page lists what he did", 'Last logins made by this user' in page and 'carol' in page)
b.get('/administrator/index.php?option=com_users&task=user.cancel')
set_rules({k: v for k, v in RIGHTS.items() if k != 'fgloginasuser.logs'})
page = b.get(EDIT + carol).text
check('without the logs right: not shown', 'fg-las-history' not in page and 'name="jform[username]"' in page)
b.get('/administrator/index.php?option=com_users&task=user.cancel')
set_rules(RIGHTS)
page = b.get('/administrator/index.php?option=com_users&task=user.add').text
check('new user form: not shown, form intact', 'fg-las-history' not in page and 'name="jform[username]"' in page)
b.get('/administrator/index.php?option=com_users&task=user.cancel')

reset()
check('no PHP warnings/deprecations/notices in any response', not PHP_ERRORS, PHP_ERRORS[:2])
passed = sum(1 for _, o in RESULTS if o)
print(f'\n{passed}/{len(RESULTS)} 1.5.0 checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
