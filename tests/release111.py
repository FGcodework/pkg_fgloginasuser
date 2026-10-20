#!/usr/bin/env python3
"""Tests of the 1.1.1 features: predefined reasons and the Logs filter by reason."""
exec(open('/tmp/jtest/e2e.py').read().split('# ---------------------------------------------------------------- 1. list + buttons')[0])

RIGHTS = {'core.manage': {'7': 1, '6': 1}, 'fgloginasuser.frontend': {'7': 1, '6': 1}, 'fgloginasuser.backend': {'7': 1, '6': 1}, 'fgloginasuser.logs': {'7': 1}}
START = '/administrator/index.php?option=com_fgloginasuser&task=impersonate.start'


def presets(*labels, **extra):
    d = {'reason_presets': {'reason_presets%d' % i: {'label': l} for i, l in enumerate(labels)}}
    d.update(extra)
    set_params(d)


def raw_start(b, uid, client, reason=None):
    t = tok(users_page(b).text)
    data = {'id': uid, 'client': client, t: '1'}
    if reason is not None:
        data['reason'] = reason
    return b.s.post(BASE + START, data=data, allow_redirects=False)


def ok(r):
    return r.status_code == 200 and 'fgLasHandoff' in r.text


def last():
    return sql("select status, reason, note from jos_fgloginasuser_logs order by id desc limit 1")


reset(); set_rules(RIGHTS)
b = admin_login('root')

print('\n== dialog')
page = users_page(b).text
check('no presets: free-text field only, no drop-down', 'id="fgLasReason"' in page and 'fgLasReasonSelect' not in page)
presets('Customer support', 'Permissions test', 'R&D <x>')
page = users_page(b).text
check('presets: drop-down with the configured reasons', 'id="fgLasReasonSelect"' in page and 'value="p:Customer support"' in page and 'value="p:Permissions test"' in page)
check('... labels are escaped', 'value="p:R&amp;D &lt;x&gt;"' in page and 'R&D <x>' not in page)
check('... with "Other..." and an optional empty choice', 'value="other"' in page and 'No reason' in page)
check('... optional mode: the drop-down is not required', 'required' not in re.search(r'<select id="fgLasReasonSelect"[^>]*>', page).group(0))
presets('Customer support', reason_mode=2)
page = users_page(b).text
check('required mode: the drop-down is required', 'required' in re.search(r'<select id="fgLasReasonSelect"[^>]*>', page).group(0))
presets('Customer support', reason_other=0)
page = users_page(b).text
check('other reasons off: no "Other..." item', 'value="other"' not in page)

print('\n== server side')
presets('Customer support', 'Permissions test', reason_other=0)
check('listed reason accepted', ok(raw_start(b, alice, 'site', 'Customer support')) and last().startswith('pending\tCustomer support'), last())
check('listed reason with a note accepted', ok(raw_start(b, alice, 'site', 'Customer support: ticket 55')) and last().startswith('pending\tCustomer support: ticket 55'), last())
r = raw_start(b, alice, 'site', 'Something else')
check('other off: a free-text reason is refused', not ok(r) and last().startswith('denied'), last())
check('... with the proper message', 'COM_FGLOGINASUSER_ERR_REASON_INVALID' in last(), last())
check('... also a reason that only starts like a listed one', not ok(raw_start(b, alice, 'site', 'Customer supporter')) and last().startswith('denied'), last())
check('other off, empty (optional mode): accepted', ok(raw_start(b, alice, 'site', '')))
r = raw_start(b, carol, 'administrator', 'Something else')
check('backend refused too, nothing switched', sess_user(b, 1) == root, sess_user(b, 1))
presets('Customer support', reason_other=1)
check('other on: a free-text reason is accepted', ok(raw_start(b, alice, 'site', 'Something else')))
set_params({})

print('\n== Logs filter')
reset(); set_rules(RIGHTS)
rows = [('r_support', 'Customer support'), ('r_support_note', 'Customer support: ticket 7'), ('r_supporter', 'Customer supporter'),
        ('r_perm', 'Permissions test'), ('r_none', ''), ('r_us', 'A_B: n'), ('r_x', 'AxB: n'), ('r_us2', 'A_B'), ('r_pct', '50%: n'), ('r_pct2', '50x: n')]
for tgt, rsn in rows:
    sql("insert into jos_fgloginasuser_logs (actor_id,actor_username,target_id,target_username,client,status,ip,note,reason,created) "
        "values (%s,'root',%s,'%s','site','ended','127.0.0.1','','%s',now())" % (root, alice, tgt, rsn))
b = admin_login('root')
LOGS = '/administrator/index.php?option=com_fgloginasuser&view=logs&list[limit]=100'


def shown(f):
    t = b.get(LOGS + ('&filter[reason]=' + requests.utils.quote(f) if f is not None else '')).text
    return {tgt for tgt, _ in rows if '>' + tgt + '<' in t}


no = b.get(LOGS).text
check('no presets: no reason filter on the screen', 'filter[reason]' not in no and 'name="filter[reason]"' not in no)
presets('Customer support', 'Permissions test', 'A_B', '50%')
page = b.get(LOGS).text
check('presets: the reason filter lists them', 'name="filter[reason]"' in page and 'Customer support' in page and 'no reason given' in page)
check('filter by a reason matches it with and without a note, nothing similar', shown('Customer support') == {'r_support', 'r_support_note'}, shown('Customer support'))
check('filter by another reason', shown('Permissions test') == {'r_perm'}, shown('Permissions test'))
check('"_" in a reason is not a wildcard', shown('A_B') == {'r_us', 'r_us2'}, shown('A_B'))
check('"%" in a reason is not a wildcard', shown('50%') == {'r_pct'}, shown('50%'))
check('no reason given', shown('__none__') == {'r_none'}, shown('__none__'))
check('clearing the filter shows everything', len(shown('')) == len(rows), shown(''))
check('a search by text still finds entries by their reason', 'r_perm' in b.get(LOGS + '&filter[search]=Permissions').text)
reset()
check('no PHP warnings/deprecations/notices in any response', not PHP_ERRORS, PHP_ERRORS[:2])
passed = sum(1 for _, o in RESULTS if o)
print(f'\n{passed}/{len(RESULTS)} 1.1.1 checks passed')
sys.exit(0 if passed == len(RESULTS) else 1)
