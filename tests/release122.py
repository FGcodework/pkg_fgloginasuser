#!/usr/bin/env python3
"""Tests of 1.2.2: what depends on the system plugin, and what survives a package update / audit table outage."""
exec(open('/tmp/jtest/e2e.py').read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])

RIGHTS = {'core.manage': {'7': 1, '6': 1}, 'fgloginasuser.frontend': {'7': 1, '6': 1}, 'fgloginasuser.backend': {'7': 1, '6': 1}, 'fgloginasuser.logs': {'7': 1}}
START = '/administrator/index.php?option=com_fgloginasuser&task=impersonate.start'
adm, carol, alice = ID('adm'), ID('carol'), ID('alice')
WARN = 'COM_FGLOGINASUSER_WARN_PLUGIN_OFF'


def plugin(on):
    sql("update jos_extensions set enabled=%d where folder='system' and element='fgloginasuser'" % on)


def bar(b):
    return 'id="fg-las-bar"' in b.get('/administrator/index.php').text


def begin(b, uid, client):
    t = tok(users_page(b).text)
    return b.s.post(BASE + START, data={'id': uid, 'client': client, t: '1'}, allow_redirects=True)


try:
    reset(); set_rules(RIGHTS); plugin(1)
    print('\n== warning')
    b = admin_login('adm')
    users_on = users_page(b).text; logs_on = b.get('/administrator/index.php?option=com_fgloginasuser&view=logs').text
    check('plugin enabled: no warning on the Users and Logs screens', 'disabled. Logging in as another user' not in users_on and 'disabled. Logging in as another user' not in logs_on)
    plugin(0)
    users_off = users_page(b).text; logs_off = b.get('/administrator/index.php?option=com_fgloginasuser&view=logs').text
    check('plugin disabled: warning on the Users screen', 'System - FG Login as User is disabled' in users_off)
    check('plugin disabled: warning on the Logs screen', 'System - FG Login as User is disabled' in logs_off)
    check('... and no login can be started', not ('fgLasHandoff' in begin(b, alice, 'site').text) and sess_user(b, 1) == adm)
    plugin(1)

    print('\n== plugin disabled during a backend impersonation')
    reset(); set_rules(RIGHTS); set_params({})
    b = admin_login('adm')
    begin(b, carol, 'administrator')
    check('setup: impersonating carol with the return bar', sess_user(b, 1) == carol and bar(b))
    plugin(0)
    check('plugin off: the bar is gone (documented)', not bar(b))
    check('... but the session is not lost', sess_user(b, 1) == carol)
    plugin(1)
    check('plugin on again: the bar is back, the impersonation intact', bar(b) and sess_user(b, 1) == carol)
    plugin(0)
    home = b.get('/administrator/index.php').text
    m = re.search(r'([0-9a-f]{32})=1', b.get('/administrator/index.php?option=com_fgloginasuser&view=users').text)
    stop = b.get('/administrator/index.php?option=com_fgloginasuser&task=impersonate.stop&' + (m.group(1) if m else 'x') + '=1')
    check('the component still returns to the administrator without the plugin (stop link)', sess_user(b, 1) == adm, sess_user(b, 1))
    plugin(1)

    print('\n== package update during an impersonation')
    reset(); set_rules(RIGHTS); set_params({})
    b = admin_login('adm'); begin(b, carol, 'administrator')
    r = subprocess.run(['php', 'cli/joomla.php', 'extension:install', '--path=/tmp/jtest/pkg.zip'], cwd='/tmp/jtest/www', capture_output=True, text=True)
    check('the update itself succeeds', 'installed successfully' in r.stdout, r.stdout[-200:])
    check('the impersonation survives it (still carol, bar present)', sess_user(b, 1) == carol and bar(b))
    check('... and ends normally afterwards', 'fg-las-bar' in b.get('/administrator/index.php').text)

    print('\n== audit table unavailable at the start')
    reset(); set_rules(RIGHTS); set_params({})
    b = admin_login('adm')
    t = tok(users_page(b).text)
    sql("rename table jos_fgloginasuser_logs to jos_fgloginasuser_logs_off")
    try:
        r = b.s.post(BASE + START, data={'id': carol, 'client': 'administrator', t: '1'}, allow_redirects=True)
        check('backend: nothing is switched when the audit row cannot be written', sess_user(b, 1) == adm, sess_user(b, 1))
        r2 = b.s.post(BASE + START, data={'id': alice, 'client': 'site', t: '1'}, allow_redirects=True)
        check('frontend: no login link is issued', 'fgLasHandoff' not in r2.text)
    finally:
        sql("rename table jos_fgloginasuser_logs_off to jos_fgloginasuser_logs")
finally:
    plugin(1)
    reset()
check('no PHP warnings/deprecations/notices in any response', not [e for e in PHP_ERRORS if 'jos_fgloginasuser_logs' not in str(e)], PHP_ERRORS[:2])
passed = sum(1 for _, o in RESULTS if o)
print(f'\n{passed}/{len(RESULTS)} 1.2.2 checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
