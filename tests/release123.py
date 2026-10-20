#!/usr/bin/env python3
"""Tests of 1.2.3: log clean-up must never delete a live impersonation."""
exec(open('/tmp/jtest/e2e.py').read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])
import hashlib

RIGHTS = {'core.manage': {'7': 1, '6': 1}, 'fgloginasuser.frontend': {'7': 1, '6': 1}, 'fgloginasuser.backend': {'7': 1, '6': 1}, 'fgloginasuser.logs': {'7': 1}}
START = '/administrator/index.php?option=com_fgloginasuser&task=impersonate.start'
LOGS = '/administrator/index.php?option=com_fgloginasuser&view=logs'
adm, carol, alice = ID('adm'), ID('carol'), ID('alice')
sha = lambda s: hashlib.sha256(s.encode()).hexdigest()


def begin(b, uid, client):
    t = tok(users_page(b).text)
    return b.s.post(BASE + START, data={'id': uid, 'client': client, t: '1'}, allow_redirects=True)


def purge_all(b):
    t = tok(b.get(LOGS).text)
    b.post('/administrator/index.php?option=com_fgloginasuser&task=logs.purge', {t: '1'})


def row(i):
    return sql("select concat(status,'|',note) from jos_fgloginasuser_logs where id=%s" % i)


def old_row(target, status, days, session_hash=''):
    sql("insert into jos_fgloginasuser_logs (actor_id,actor_username,target_id,target_username,client,status,ip,note,session_hash,created,started) "
        "values (%s,'root',%s,'%s','administrator','%s','127.0.0.1','','%s',date_sub(utc_timestamp(), interval %d day),date_sub(utc_timestamp(), interval %d day))" % (adm, alice, target, status, session_hash, days, days))
    return sql("select max(id) from jos_fgloginasuser_logs")


print('\n== the session is recorded')
reset(); set_rules(RIGHTS); set_params({'max_minutes': 0})
b = admin_login('adm')
begin(b, carol, 'administrator')
sid = [c.value for c in b.s.cookies if sql("select count(*) from jos_session where session_id='%s'" % c.value.replace("'", '')) == '1' and sess_user(b, 1) == carol]
rid = sql("select id from jos_fgloginasuser_logs where status='active' order by id desc limit 1")
rh = sql("select session_hash from jos_fgloginasuser_logs where id=%s" % rid)
check('backend: the log row holds the SHA-256 of the live session id (not the id)', sid and rh == sha(sid[0]) and sid[0] not in rh, (sid, rh))
alice_site = Browser()
raw = admin_login('root'); t = tok(users_page(raw).text)
r = raw.s.post(BASE + START, data={'id': alice, 'client': 'site', t: '1'})
tkn = re.search(r'name="token" value="([0-9a-f]{64})"', r.text).group(1)
alice_site.s.post(BASE + '/index.php?option=com_fgloginasuser&task=login.consume', data={'token': tkn})
srow = sql("select id from jos_fgloginasuser_logs where client='site' and status='active' order by id desc limit 1")
shash = sql("select session_hash from jos_fgloginasuser_logs where id=%s" % srow)
live = [c.value for c in alice_site.s.cookies if sql("select count(*) from jos_session where session_id='%s' and client_id=0" % c.value.replace("'", '')) == '1']
check('frontend: the same', live and shash == sha(live[0]), (live, shash))

print('\n== a live impersonation survives every clean-up')
sql("update jos_fgloginasuser_logs set created=date_sub(utc_timestamp(), interval 3 day), started=date_sub(utc_timestamp(), interval 3 day) where status='active'")
rb = admin_login('root')
purge_all(rb)
check('"delete all" keeps active rows older than a day whose session lives', row(rid).startswith('active|') and row(srow).startswith('active|'), (row(rid), row(srow)))
set_params({'max_minutes': 0, 'log_retention_days': 1})
rb.get(LOGS); begin(admin_login('adm'), carol, 'administrator')
check('retention by age keeps them too', row(rid).startswith('active|') and row(srow).startswith('active|'), (row(rid), row(srow)))
home = b.get('/administrator/index.php').text
m = re.search(r'task=impersonate\.stop&(?:amp;)?([0-9a-f]{32})=1', home)
b.get('/administrator/index.php?option=com_fgloginasuser&task=impersonate.stop&' + m.group(1) + '=1')
check('and the end of the long impersonation is recorded in its own row', row(rid).startswith('ended|'), row(rid))

print('\n== sessions that are verifiably gone')
reset(); set_rules(RIGHTS)
gone = old_row('t_gone', 'active', 0, sha('no-such-session'))
sql("update jos_fgloginasuser_logs set created=date_sub(utc_timestamp(), interval 10 minute), started=date_sub(utc_timestamp(), interval 10 minute) where id=%s" % gone)
fresh = old_row('t_fresh', 'active', 0, sha('no-such-session-either'))
rb = admin_login('root'); rb.get(LOGS)
check('opening the Logs screen closes a row whose session is gone', row(gone) == 'ended|COM_FGLOGINASUSER_NOTE_SESSION_GONE', row(gone))
check('... but not one that is newer than the grace period', row(fresh).startswith('active|'), row(fresh))
purge_all(rb)
check('"delete all" then removes the closed row', row(gone) == '', row(gone))

print('\n== rows without a recorded session')
reset(); set_rules(RIGHTS)
legacy_old = old_row('t_old', 'active', 3)
legacy_new = old_row('t_new', 'active', 0)
sql("update jos_fgloginasuser_logs set created=date_sub(utc_timestamp(), interval 2 hour), started=date_sub(utc_timestamp(), interval 2 hour) where id=%s" % legacy_new)
rb = admin_login('root'); purge_all(rb)
check('older than a day: marked as presumed ended, NOT deleted', row(legacy_old) == 'ended|COM_FGLOGINASUSER_NOTE_SESSION_PRESUMED', row(legacy_old))
check('younger than a day: left active', row(legacy_new).startswith('active|'), row(legacy_new))
check('the presumed note is readable on the Logs screen and survives another "delete all"', 'Presumed ended' in rb.get(LOGS + '&list[limit]=100').text and (purge_all(rb) or row(legacy_old).startswith('ended|')))

print('\n== sessions kept outside the database')
pr = subprocess.run(['php', '/tmp/jtest/reconcile_probe.php', '/tmp/jtest/www', root, alice], capture_output=True, text=True, cwd='/tmp/jtest/www')
try:
    out = json.loads(pr.stdout.strip().splitlines()[-1])
except Exception:
    out = {}
check('not in #__session: a row with a recorded session is NOT closed for a "missing" session row', out.get('with_hash_3h_kept') == 'active', pr.stdout[-300:] + pr.stderr[-200:])
check('... only a row older than a day without a recorded session is presumed ended', out.get('no_hash_3d_presumed') == 'ended' and out.get('no_hash_3h_kept') == 'active', out)
check('... with sessions in the database the same row IS closed', out.get('with_hash_3h_when_db') == 'ended', out)

print('\n== other rows are cleaned as before')
reset(); set_rules(RIGHTS)
e = old_row('t_ended', 'ended', 5); d = old_row('t_denied', 'denied', 5)
rb = admin_login('root'); purge_all(rb)
check('ended and denied rows are deleted by "delete all"', row(e) == '' and row(d) == '')

reset()
check('no PHP warnings/deprecations/notices in any response', not PHP_ERRORS, PHP_ERRORS[:2])
passed = sum(1 for _, o in RESULTS if o)
print(f'\n{passed}/{len(RESULTS)} 1.2.3 checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
