#!/usr/bin/env python3
"""Tests of 1.2.0: no logging in as users with higher global rights."""
exec(open('/tmp/jtest/e2e.py').read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])

# Manager (6) and Administrator (7) may use the login actions
RIGHTS = {'core.manage': {'6': 1, '7': 1}, 'fgloginasuser.frontend': {'6': 1, '7': 1}, 'fgloginasuser.backend': {'6': 1, '7': 1}}
START = '/administrator/index.php?option=com_fgloginasuser&task=impersonate.start'
adm, mike, carol, xena = ID('adm'), ID('mike'), ID('carol'), ID('xena')
sql("update jos_users set block=0, requireReset=0 where username in ('adm','mike','carol','alice','xena')")
GR = lambda u: sql("select group_concat(group_id order by group_id) from jos_user_usergroup_map where user_id=%s" % u)


def attempt(user, target, client='site'):
    b = admin_login(user)
    t = tok(users_page(b).text)
    r = b.s.post(BASE + START, data={'id': target, 'client': client, t: '1'}, allow_redirects=False)
    done = 'fgLasHandoff' in r.text or sess_user(b, 1) == target
    note = sql("select note from jos_fgloginasuser_logs order by id desc limit 1")
    return done, note, b


print('groups: mike', GR(mike), 'carol', GR(carol), 'adm', GR(adm), 'xena', GR(xena))
reset(); set_rules(RIGHTS)

print('\n== default (option on)')
ok_, note, _ = attempt('mike', adm)
check('Manager cannot log in as an Administrator (frontend)', not ok_ and note == 'COM_FGLOGINASUSER_ERR_HIGHER', (ok_, note))
ok_, note, _ = attempt('mike', adm, 'administrator')
check('... nor in the backend', not ok_ and note == 'COM_FGLOGINASUSER_ERR_HIGHER', (ok_, note))
check('Manager can log in as an equal (Manager)', attempt('mike', carol)[0])
check('Manager can log in as a Registered user', attempt('mike', ID('alice'))[0])
check('Administrator can log in as a Manager (lower rights)', attempt('adm', mike)[0])
ok_, note, b = attempt('adm', adm)
check('(self is still refused for its own reason)', note == 'COM_FGLOGINASUSER_ERR_SELF', note)
# a second Administrator
sql("delete from jos_users where username='adm2'")
subprocess.run(['php', 'cli/joomla.php', 'user:add', '--username=adm2', '--name=Adm Two', '--password=' + PW, '--email=adm2@example.test', '--usergroup=Administrator'], cwd='/tmp/jtest/www', capture_output=True)
adm2 = ID('adm2')
check('Administrator can log in as another Administrator (equal rights)', attempt('adm', adm2)[0])
# Registered + Administrator target
sql("insert ignore into jos_user_usergroup_map (user_id, group_id) values (%s, 7)" % ID('alice'))
ok_, note, _ = attempt('mike', ID('alice'))
check('a Registered user who is also an Administrator is treated as one (refused)', not ok_ and note == 'COM_FGLOGINASUSER_ERR_HIGHER', (ok_, note))
sql("delete from jos_user_usergroup_map where user_id=%s and group_id=7" % ID('alice'))

print('\n== the reason is shown on the Users screen')
b = admin_login('mike')
page = users_page(b).text
check('the disabled button explains the refusal', 'global permissions that you do not have' in page and 'icon-lock' in page, '')

print('\n== option off')
reset(); set_rules(RIGHTS); set_params({'block_higher': 0})
check('option off: Manager can log in as an Administrator (old behaviour)', attempt('mike', adm)[0])

print('\n== Super User')
reset(); set_rules(RIGHTS)
check('a Super User is not restricted by the rule', attempt('root', adm)[0])

print('\n== combined with restrictions')
reset(); set_rules(RIGHTS)
set_params({'restrictions': {'restrictions0': {'actor_group': '6', 'target_groups': ['2']}}})
ok_, note, _ = attempt('mike', carol)
check('group restrictions still apply on top (Manager target not in the allowed groups)', not ok_ and note == 'COM_FGLOGINASUSER_ERR_RESTRICTED', (ok_, note))

reset(); sql("delete from jos_users where username='adm2'")
check('no PHP warnings/deprecations/notices in any response', not PHP_ERRORS, PHP_ERRORS[:2])
passed = sum(1 for _, o in RESULTS if o)
print(f'\n{passed}/{len(RESULTS)} 1.2.0 checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
