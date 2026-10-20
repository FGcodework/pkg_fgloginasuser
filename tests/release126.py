#!/usr/bin/env python3
"""Tests of 1.2.6: when the core user list markup changes, the buttons are skipped and a diagnostic is logged (once per session)."""
exec(open('/tmp/jtest/e2e.py').read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])
import os

RIGHTS = {'core.manage': {'7': 1, '6': 1}, 'fgloginasuser.frontend': {'7': 1, '6': 1}, 'fgloginasuser.backend': {'7': 1, '6': 1}}
WWW = '/tmp/jtest/www'
CORE = WWW + '/administrator/components/com_users/tmpl/users/default.php'
OVR_DIR = WWW + '/administrator/templates/atum/html/com_users/users'
OVR = OVR_DIR + '/default.php'
LOGF = WWW + '/administrator/logs/com_fgloginasuser.log.php'
USERS = '/administrator/index.php?option=com_users&view=users'
alice = ID('alice')


def override(old, new):
    os.makedirs(OVR_DIR, exist_ok=True)
    src = open(CORE).read()
    assert old in src, old
    open(OVR, 'w').write(src.replace(old, new))


def drop():
    if os.path.exists(OVR):
        os.remove(OVR)


def logged():
    return open(LOGF).read().count('Login-as buttons were not added') if os.path.exists(LOGF) else 0


def wipe():
    if os.path.exists(LOGF):
        os.remove(LOGF)


try:
    reset(); set_rules(RIGHTS); drop(); wipe()
    b = admin_login('root')
    p = b.get(USERS)
    check('(control) real Joomla list: buttons present, nothing logged', f'data-id="{alice}"' in p.text and logged() == 0, logged())

    for name, old, new, why in [
        ('row class changed', 'class="row<?php echo $i % 2; ?>"', 'class="zebra<?php echo $i % 2; ?>"', 'no row of the user list matched'),
        ('username header changed', "'a.username'", "'a.usr'", 'Username column header'),
    ]:
        wipe(); drop(); override(old, new)
        b = admin_login('root')
        p = b.get(USERS)
        check(name + ': page still renders, no buttons, no half-built column', p.status_code == 200 and 'id="userList"' in p.text and 'fg-las-btn' not in p.text and 'fgLasForm' not in p.text and 'Login as' not in p.text.split('id="userList"')[1][:3000], p.status_code)
        check(name + ': diagnostic written once with the reason', logged() == 1 and why in open(LOGF).read(), logged())
        check(name + ': nothing about it shown to the visitor', 'were not added' not in p.text and 'com_fgloginasuser.log' not in p.text)
        b.get(USERS); b.get(USERS)
        check(name + ': not repeated within the session', logged() == 1, logged())
        b2 = admin_login('root'); b2.get(USERS)
        check(name + ': a new session logs again (still visible to the administrator)', logged() == 2, logged())

    drop(); wipe()
    b = admin_login('root')
    p = b.get(USERS + '&filter[search]=zzzznomatch')
    check('empty list (no table) is not a failure', logged() == 0 and p.status_code == 200, logged())
    b.get(USERS + '&filter[search]=')
finally:
    drop(); wipe(); reset()

check('no PHP warnings/deprecations/notices in any response', not PHP_ERRORS, PHP_ERRORS[:2])
passed = sum(1 for _, o in RESULTS if o)
print(f'\n{passed}/{len(RESULTS)} 1.2.6 checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
