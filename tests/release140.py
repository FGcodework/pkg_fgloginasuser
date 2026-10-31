#!/usr/bin/env python3
"""Tests of 1.4.0: how an impersonation ended, optional rights re-check, log export and filters, per-administrator step-up limit."""
import csv, io
exec(open(__import__('os').path.join(__import__('os').path.dirname(__import__('os').path.abspath(__file__)), 'e2e.py')).read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])

RIGHTS = {'core.manage': {'7': 1, '6': 1}, 'fgloginasuser.frontend': {'7': 1, '6': 1}, 'fgloginasuser.backend': {'7': 1, '6': 1},
          'fgloginasuser.logs': {'7': 1, '6': 1}}
START = '/administrator/index.php?option=com_fgloginasuser&task=impersonate.start'
LOGS = '/administrator/index.php?option=com_fgloginasuser&view=logs'
adm, carol, alice = ID('adm'), ID('carol'), ID('alice')


def begin(b, target=None, password=None):
    t = tok(users_page(b).text)
    data = {'id': target or carol, 'client': 'administrator', t: '1'}
    if password is not None:
        data['stepup_password'] = password
    return b.s.post(BASE + START, data=data, allow_redirects=True)


def stop(b):
    m = re.search(r'([0-9a-f]{32})=1', b.get('/administrator/index.php?option=com_fgloginasuser&view=users').text)
    return b.get('/administrator/index.php?option=com_fgloginasuser&task=impersonate.stop&' + (m.group(1) if m else 'x') + '=1')


def last_end(key_col='note'):
    return sql("select %s from jos_fgloginasuser_logs where actor_username in ('adm','root') and status='ended' order by id desc limit 1" % key_col)


def last_alog():
    return sql("select message_language_key from jos_action_logs where extension='com_fgloginasuser' order by id desc limit 1")


print('\n== how it ended')
reset(); set_rules(RIGHTS)
b = admin_login('adm'); begin(b)
check('(setup) impersonation started', sess_user(b, 1) == carol)
stop(b)
check('return button: note says returned', last_end() == 'COM_FGLOGINASUSER_NOTE_RETURNED', last_end())
check('return button: action log has its own message', last_alog() == 'COM_FGLOGINASUSER_ACTIONLOG_ENDED_RETURNED', last_alog())

reset(); set_rules(RIGHTS)
b = admin_login('adm'); begin(b)
home = b.get('/administrator/index.php')
lo = re.search(r'href="([^"]*task=logout[^"]*)"', home.text)
b.get(lo.group(1).replace('&amp;', '&')) if lo else None
check('logout inside the impersonation: note says logout', last_end() == 'COM_FGLOGINASUSER_NOTE_LOGOUT', last_end())
check('logout: action log has its own message', last_alog() == 'COM_FGLOGINASUSER_ACTIONLOG_ENDED_LOGOUT', last_alog())

print('\n== rights re-check')
reset(); set_rules(RIGHTS)
b = admin_login('adm'); begin(b)
set_rules({})
b.get('/administrator/index.php')
check('option off (default): withdrawn rights do not end the impersonation', sess_user(b, 1) == carol, sess_user(b, 1))
stop(b)

reset(); set_rules(RIGHTS); set_params({'recheck_rights': 1})
b = admin_login('adm'); begin(b)
b.get('/administrator/index.php')
check('option on, rights intact: impersonation continues', sess_user(b, 1) == carol, sess_user(b, 1))
set_rules({})
r = b.get('/administrator/index.php')
check('option on, right withdrawn: ends on the very next request', sess_user(b, 1) != carol and sess_user(b, 1) != adm, sess_user(b, 1))
check('  note says rights lost', last_end() == 'COM_FGLOGINASUSER_NOTE_RIGHTS_LOST', last_end())
check('  action log has its own message', last_alog() == 'COM_FGLOGINASUSER_ACTIONLOG_ENDED_RIGHTS', last_alog())
check('  nothing is handed back (no admin session of the actor)', adm not in [x for x in [sess_user(b, 1)]], sess_user(b, 1))

reset(); set_rules(RIGHTS); set_params({'recheck_rights': 1})
b = admin_login('adm'); begin(b)
sql("update jos_users set block=1 where username='adm'")
try:
    b.get('/administrator/index.php')
    check('option on, administrator blocked: ends', sess_user(b, 1) != carol, sess_user(b, 1))
finally:
    sql("update jos_users set block=0 where username='adm'")

print('\n== time limit')
reset(); set_rules(RIGHTS); set_params({'max_minutes': 1})
b = admin_login('adm'); begin(b)
time.sleep(64)
b.get('/administrator/index.php')
check('time limit: note says timeout', last_end() == 'COM_FGLOGINASUSER_NOTE_TIMEOUT', last_end())
check('time limit: action log has its own message', last_alog() == 'COM_FGLOGINASUSER_ACTIONLOG_ENDED_TIMEOUT', last_alog())

print('\n== export and filters')
reset(); set_rules(RIGHTS)
b = admin_login('adm'); begin(b); stop(b)
begin(b, alice); stop(b)
sql("insert into jos_fgloginasuser_logs (actor_id,actor_username,target_id,target_username,client,status,ip,note,reason,token_hash,token_expires,created) "
    "values (999,'=evil()',998,'+t,\"q\";x','site','pending','1.2.3.4','','Reason; with, comma','%s',now(),date_format(now() - interval 3 day, '%%Y-%%m-%%d 10:00:00'))" % ('ab' * 32))
DAY = sql("select date(created) from jos_fgloginasuser_logs where actor_id=999")
total = int(sql('select count(*) from jos_fgloginasuser_logs'))


def export(b, task, **filters):
    t = tok(b.get(LOGS).text)
    data = {t: '1'}
    for k, v in filters.items():
        data['filter[%s]' % k] = v
    return b.s.post(BASE + '/administrator/index.php?option=com_fgloginasuser&task=logs.' + task, data=data, allow_redirects=False)


r = export(b, 'exportCsv')
check('CSV: download with csv content type and file name', r.status_code == 200 and r.headers.get('Content-Type', '').startswith('text/csv') and
      re.search(r'filename="fgloginasuser-logs-\d{8}-\d{6}\.csv"', r.headers.get('Content-Disposition', '')), (r.status_code, r.headers))
check('CSV: no-store', 'no-store' in r.headers.get('Cache-Control', ''))
body = r.content.decode('utf-8')
check('CSV: starts with a BOM', r.content.startswith(b'\xef\xbb\xbf'))
rows = list(csv.reader(io.StringIO(body.lstrip('﻿'))))
check('CSV: header + every row', len(rows) == total + 1, (len(rows), total))
check('CSV: no token hash column or value', 'token' not in rows[0] and ('ab' * 32) not in body)
ev = [x for x in rows[1:] if x[0] == sql("select max(id) from jos_fgloginasuser_logs")][0]
check("CSV: formula cell is neutralised ('=evil())", ev[3] == "'=evil()", ev[3])
check('CSV: separators and quotes survive', ev[5] == "'+t,\"q\";x", ev[5])
check('CSV: note is translated (returned)', any('Ended by the administrator' in x[10] for x in rows[1:]))
check('CSV: newest first', int(rows[1][0]) > int(rows[-1][0]))

r = export(b, 'exportExcel')
rows = list(csv.reader(io.StringIO(r.content.decode('utf-8').lstrip('﻿')), delimiter=';'))
check('Excel CSV: semicolon separated, all columns', len(rows) == total + 1 and len(rows[0]) == 14, (len(rows), len(rows[0])))

r = export(b, 'exportJson')
data = json.loads(r.content.decode('utf-8'))
check('JSON: content type and valid, all rows', r.headers.get('Content-Type', '').startswith('application/json') and len(data) == total, (r.headers.get('Content-Type'), len(data)))
check('JSON: no token hash, keys present', 'token_hash' not in data[0] and 'actor_username' in data[0] and 'ab' * 32 not in r.text)

n_adm = int(sql("select count(*) from jos_fgloginasuser_logs where actor_username like '%adm%'"))
data = json.loads(export(b, 'exportJson', actor='adm').content)
check('filter actor limits the export', len(data) == n_adm and all('adm' in d['actor_username'] for d in data), (len(data), n_adm))
data = json.loads(export(b, 'exportJson', target='alice').content)
check('filter target limits the export', len(data) == 1 and data[0]['target_username'] == 'alice', len(data))
data = json.loads(export(b, 'exportJson', date_from=DAY, date_to=DAY).content)
check('date from/to selects that day only', len(data) == 1 and data[0]['actor_username'] == '=evil()', len(data))
data = json.loads(export(b, 'exportJson', date_from=str(sql("select date(now() - interval 2 day)")), date_to=str(sql("select date(now() - interval 1 day)"))).content)
check('empty range gives an empty list', data == [], data)
data = json.loads(export(b, 'exportJson', date_from='nonsense', actor='adm').content)
check('invalid date is ignored, other filters still apply', len(data) == n_adm, len(data))
data = json.loads(export(b, 'exportJson', search='adm', status='ended').content)
check('search and status together (bound values do not clash)', len(data) > 0 and all(d['status'] == 'ended' for d in data), len(data))
data = json.loads(export(b, 'exportJson', actor='%').content)
check('LIKE wildcards in the filter are literal', data == [], len(data))

check('export is written to the User Actions Log', last_alog() == 'COM_FGLOGINASUSER_ACTIONLOG_EXPORTED', last_alog())
page = b.get(LOGS + '&filter[date_from]=' + DAY + '&filter[date_to]=' + DAY + '').text
r = b.get(LOGS + '&filter[date_from]=nonsense&filter[actor]=adm')
check('a bad date in the URL does not break the Logs screen (and is not kept)', r.status_code == 200 and tok(r.text) is not None and 'nonsense' not in r.text, r.status_code)
check('list view applies the date filter', '=evil()' in page and 'alice' not in re.sub(r'<option.*?</option>', '', page, flags=re.S), '')

# no right to view logs -> no export
set_rules({k: v for k, v in RIGHTS.items() if k != 'fgloginasuser.logs'})
t_page = b.get('/administrator/index.php?option=com_fgloginasuser&view=users').text
r = b.s.post(BASE + '/administrator/index.php?option=com_fgloginasuser&task=logs.exportCsv', data={(tok(t_page) or 'x'): '1'}, allow_redirects=False)
check('without the logs right: no export', not r.headers.get('Content-Type', '').startswith('text/csv') and '=evil' not in r.text, (r.status_code, r.headers.get('Content-Type')))
set_rules(RIGHTS)
guest = Browser()
r = guest.s.post(BASE + '/administrator/index.php?option=com_fgloginasuser&task=logs.exportCsv', data={}, allow_redirects=False)
check('not signed in: no export', not r.headers.get('Content-Type', '').startswith('text/csv'))
r = b.s.post(BASE + '/administrator/index.php?option=com_fgloginasuser&task=logs.exportCsv', data={}, allow_redirects=False)
check('without a token: no export', not r.headers.get('Content-Type', '').startswith('text/csv'))

print('\n== step-up attempts per administrator')
reset(); set_rules(RIGHTS); set_params({'stepup_mode': 1})
sa = admin_login('adm')
for i in range(5):
    begin(sa, carol, 'wrong-%d' % i)
n = int(sql("select count(*) from jos_fgloginasuser_logs where note='COM_FGLOGINASUSER_ERR_STEPUP_PASSWORD'"))
check('(setup) five wrong passwords recorded', n == 5, n)
sb = admin_login('adm')
begin(sb, carol, PW)
check('another session of the same administrator is locked, even with the right password',
      sess_user(sb, 1) == adm and int(sql("select count(*) from jos_fgloginasuser_logs where status in ('active','ended','pending')")) == 0,
      logs())
check('  recorded as locked', sql("select note from jos_fgloginasuser_logs order by id desc limit 1") == 'COM_FGLOGINASUSER_ERR_STEPUP_LOCKED')
sc = admin_login('root')
begin(sc, carol, 'Rootpass-12345!')
check("another administrator is not affected", sql("select count(*) from jos_fgloginasuser_logs where actor_username='root' and status in ('active','ended')") != '0', logs())
sql("update jos_fgloginasuser_logs set created = created - interval 20 minute where actor_username='adm'")
sd = admin_login('adm')
begin(sd, carol, PW)
check('after the window the attempts count anew', sess_user(sd, 1) == carol, sess_user(sd, 1))
stop(sd)
check('the named lock is released', sql("select is_free_lock('fgloginasuser.stepup.%s')" % adm) == '1')

# race: parallel wrong guesses from separate sessions never exceed the limit
reset(); set_rules(RIGHTS); set_params({'stepup_mode': 1})
import threading
sess = [admin_login('adm') for _ in range(12)]
tks = [tok(users_page(x).text) for x in sess]
bar = threading.Barrier(len(sess))


def go(x, t):
    bar.wait()
    x.s.post(BASE + START, data={'id': carol, 'client': 'administrator', t: '1', 'stepup_password': 'nope'}, allow_redirects=False)


th = [threading.Thread(target=go, args=a) for a in zip(sess, tks)]
[x.start() for x in th]
[x.join() for x in th]
wrong = int(sql("select count(*) from jos_fgloginasuser_logs where note='COM_FGLOGINASUSER_ERR_STEPUP_PASSWORD'"))
check('12 parallel wrong guesses from 12 sessions: at most 5 are evaluated', wrong == 5, wrong)

# off by default: nothing is counted or locked when confirmation is off
reset(); set_rules(RIGHTS)
sa = admin_login('adm')
for i in range(7):
    begin(sa, carol); stop(sa)
check('confirmation off: unaffected by the new limit', int(sql("select count(*) from jos_fgloginasuser_logs where status='ended'")) == 7)

reset()
check('no PHP warnings/deprecations/notices in any response', not PHP_ERRORS, PHP_ERRORS[:2])
passed = sum(1 for _, o in RESULTS if o)
print(f'\n{passed}/{len(RESULTS)} 1.4.0 checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
