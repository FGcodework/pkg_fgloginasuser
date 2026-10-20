"""Real-browser test of the dialog in a browser WITHOUT <dialog>.showModal (1.2.7).

Presets only (no free text), a required reason and the identity confirmation: the same options as in the
native dialog must be offered and the login must start. Needs the throw-away Joomla of e2e.py.
Usage: python3 browser_fallback.py
"""
import subprocess, sys
from playwright.sync_api import sync_playwright

BASE = 'http://127.0.0.1:8080'
fails = 0


def check(name, ok, info=''):
    global fails
    print(('PASS  ' if ok else 'FAIL  ') + name + ('' if ok else '  -> ' + str(info)))
    fails += 0 if ok else 1


def params(js):
    subprocess.run(['mysql', 'joomla', '-e', "update jos_extensions set params=%s where element='com_fgloginasuser' and type='component'" % js], check=True)


params("JSON_SET(COALESCE(NULLIF(params,''),'{}'),'$.reason_mode',2,'$.reason_other',0,'$.stepup_mode',1,'$.reason_presets',JSON_OBJECT('reason_presets0',JSON_OBJECT('label','Customer support'),'reason_presets1',JSON_OBJECT('label','Permissions test')))")
subprocess.run(['mysql', 'joomla', '-e', "delete from jos_fgloginasuser_logs"], check=True)
try:
    with sync_playwright() as p:
        browser = p.chromium.launch()
        ctx = browser.new_context()
        ctx.add_init_script("delete HTMLDialogElement.prototype.showModal;")
        pg = ctx.new_page()
        errors = []
        pg.on('pageerror', lambda e: errors.append(str(e)))
        prompts = []
        pg.on('dialog', lambda d: (prompts.append(d.type), d.dismiss()))
        pg.goto(BASE + '/administrator/index.php')
        pg.fill('#mod-login-username', 'root')
        pg.fill('#mod-login-password', 'Rootpass-12345!')
        pg.click('button.login-button, #btn-login-submit')
        pg.wait_for_selector('.header-title, #menu', timeout=15000)
        check('(setup) showModal really is missing', pg.evaluate("typeof HTMLDialogElement.prototype.showModal") == 'undefined')
        pg.goto(BASE + '/administrator/index.php?option=com_users&view=users&filter[search]=carol')
        if pg.locator('.shepherd-button-secondary').count():
            pg.locator('.shepherd-button-secondary').first.click()
        check('dialog is hidden before use', not pg.locator('#fgLasDialog').is_visible())
        pg.locator('.fg-las-btn[data-client="site"]').first.click()
        pg.wait_for_selector('#fgLasDialog[open]', timeout=5000)
        check('the same dialog opens as an overlay (no window.prompt)', pg.locator('#fgLasDialog').is_visible() and not prompts, prompts)
        opts = pg.locator('#fgLasReasonSelect option').all_inner_texts()
        check('preset list is offered', 'Customer support' in opts and 'Permissions test' in opts, opts)
        check('free text ("Other") is not offered', not any('other' in o.lower() for o in opts) and pg.locator('#fgLasReasonSelect option[value="other"]').count() == 0, opts)
        check('identity confirmation field is offered', pg.locator('#fgLasStepPw').is_visible())
        pg.click('#fgLasDialogOk')
        pg.wait_for_timeout(400)
        check('nothing chosen: dialog stays open', pg.locator('#fgLasDialog[open]').count() == 1)
        pg.select_option('#fgLasReasonSelect', 'p:Customer support')
        pg.fill('#fgLasStepPw', 'Rootpass-12345!')
        with ctx.expect_page(timeout=15000) as new:
            pg.click('#fgLasDialogOk')
        tab = new.value
        tab.wait_for_load_state()
        tab.wait_for_timeout(1000)
        bar = tab.locator('#fg-las-bar').inner_text() if tab.locator('#fg-las-bar').count() else ''
        check('login starts with the preset as reason', 'Customer support' in bar, bar)
        tab.close()
        check('dialog closed after OK', pg.locator('#fgLasDialog[open]').count() == 0)
        pg.locator('.fg-las-btn[data-client="site"]').first.click()
        pg.wait_for_selector('#fgLasDialog[open]', timeout=5000)
        pg.keyboard.press('Escape')
        pg.wait_for_timeout(300)
        check('Escape closes it', pg.locator('#fgLasDialog[open]').count() == 0)
        pg.locator('.fg-las-btn[data-client="site"]').first.click()
        pg.wait_for_selector('#fgLasDialog[open]', timeout=5000)
        pg.click('#fgLasDialogCancel')
        pg.wait_for_timeout(300)
        check('Cancel closes it and the page did not navigate', pg.locator('#fgLasDialog[open]').count() == 0 and 'com_users' in pg.url, pg.url)
        check('no JS errors', not errors, errors)
        browser.close()
finally:
    params("JSON_REMOVE(params,'$.reason_mode','$.reason_other','$.stepup_mode','$.reason_presets')")
    subprocess.run(['mysql', 'joomla', '-e', "delete from jos_fgloginasuser_logs"], check=True)

print('\nFAILED: %d' % fails if fails else '\nall fallback checks passed')
sys.exit(1 if fails else 0)
