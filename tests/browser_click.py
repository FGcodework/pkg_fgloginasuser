"""Real-browser click test (Playwright + Chromium): the buttons must actually start an impersonation.

Needs the throw-away Joomla from e2e.py at http://127.0.0.1:8080 with the users root / alice / carol.
Usage: python3 browser_click.py
"""
import subprocess, sys
from playwright.sync_api import sync_playwright

BASE = 'http://127.0.0.1:8080'
SCREENS = ['com_fgloginasuser&view=users', 'com_users&view=users']
fails = 0


def check(name, ok, info=''):
    global fails
    print(('PASS  ' if ok else 'FAIL  ') + name + ('' if ok else '  -> ' + str(info)))
    fails += 0 if ok else 1


def login(ctx):
    pg = ctx.new_page()
    pg.on('dialog', lambda d: d.accept())
    pg.goto(BASE + '/administrator/index.php')
    pg.fill('#mod-login-username', 'root')
    pg.fill('#mod-login-password', 'Rootpass-12345!')
    pg.click('button.login-button, #btn-login-submit')
    pg.wait_for_selector('.header-title, #menu', timeout=15000)
    return pg


def open_screen(pg, screen):
    pg.goto(BASE + '/administrator/index.php?option=' + screen + '&filter[search]=carol')
    if pg.locator('.shepherd-button-secondary').count():
        pg.locator('.shepherd-button-secondary').first.click()


with sync_playwright() as p:
    browser = p.chromium.launch()
    for screen in SCREENS:
        ctx = browser.new_context()
        pg = login(ctx)
        errors = []
        pg.on('pageerror', lambda e: errors.append(str(e)))

        # frontend button -> new tab on the site, logged in as the user
        open_screen(pg, screen)
        btn = pg.locator('.fg-las-btn[data-client="site"]').first
        check(screen + ': frontend button present', btn.count() == 1)
        btn.click()
        pg.wait_for_selector('#fgLasDialog[open]', timeout=5000)
        check(screen + ': frontend button opens the dialog with the reason field', pg.locator('#fgLasReason').is_visible())
        check(screen + ': ... showing the frontend wording', 'frontend' in pg.locator('#fgLasDialogTitle').inner_text().lower())
        pg.fill('#fgLasReason', 'Browser test reason')
        with ctx.expect_page(timeout=15000) as new:
            pg.click('#fgLasDialogOk')
        tab = new.value
        tab.wait_for_load_state()
        tab.wait_for_timeout(1000)
        check(screen + ': frontend tab is the site, not the backend', '/administrator' not in tab.url, tab.url)
        check(screen + ': ... and the URL carries no token', 'token' not in tab.url, tab.url)
        check(screen + ': frontend tab shows the return bar', tab.locator('#fg-las-bar').count() == 1, tab.url)
        check(screen + ': ... with the reason and the end time', 'Browser test reason' in tab.locator('#fg-las-bar').inner_text() and 'ends automatically' in tab.locator('#fg-las-bar').inner_text(), tab.locator('#fg-las-bar').inner_text() if tab.locator('#fg-las-bar').count() else '')
        tab.close()

        # predefined reasons: drop-down, "Other..." needs text, preset + note is stored as "Label: note"
        subprocess.run(['mysql', 'joomla', '-e', "update jos_extensions set params=JSON_SET(COALESCE(NULLIF(params,''),'{}'),'$.reason_presets',JSON_OBJECT('reason_presets0',JSON_OBJECT('label','Customer support'),'reason_presets1',JSON_OBJECT('label','Permissions test'))) where element='com_fgloginasuser' and type='component'"], check=True)
        open_screen(pg, screen)
        pg.locator('.fg-las-btn[data-client="site"]').first.click()
        pg.wait_for_selector('#fgLasDialog[open]', timeout=5000)
        check(screen + ': drop-down offered, text field hidden until a choice', pg.locator('#fgLasReasonSelect').is_visible() and not pg.locator('#fgLasReason').is_visible())
        pg.select_option('#fgLasReasonSelect', 'other')
        check(screen + ': "Other..." shows the text field', pg.locator('#fgLasReason').is_visible())
        pg.click('#fgLasDialogOk')
        pg.wait_for_timeout(400)
        check(screen + ': "Other..." with empty text keeps the dialog open', pg.locator('#fgLasDialog[open]').count() == 1)
        pg.select_option('#fgLasReasonSelect', 'p:Customer support')
        pg.fill('#fgLasReason', 'ticket 9')
        with ctx.expect_page(timeout=15000) as new:
            pg.click('#fgLasDialogOk')
        tab = new.value
        tab.wait_for_load_state()
        tab.wait_for_timeout(800)
        check(screen + ': preset + note arrives as "Label: note"', 'Customer support: ticket 9' in (tab.locator('#fg-las-bar').inner_text() if tab.locator('#fg-las-bar').count() else ''))
        tab.close()
        subprocess.run(['mysql', 'joomla', '-e', "update jos_extensions set params=JSON_REMOVE(params,'$.reason_presets') where element='com_fgloginasuser' and type='component'"], check=True)

        # required reason: an empty field keeps the dialog open
        subprocess.run(['mysql', 'joomla', '-e', "update jos_extensions set params=JSON_SET(COALESCE(NULLIF(params,''),'{}'),'$.reason_mode',2) where element='com_fgloginasuser' and type='component'"], check=True)
        open_screen(pg, screen)
        pg.locator('.fg-las-btn[data-client="site"]').first.click()
        pg.wait_for_selector('#fgLasDialog[open]', timeout=5000)
        pg.click('#fgLasDialogOk')
        pg.wait_for_timeout(500)
        check(screen + ': required reason keeps the dialog open when empty', pg.locator('#fgLasDialog[open]').count() == 1)
        pg.click('#fgLasDialogCancel')
        pg.wait_for_timeout(400)
        subprocess.run(['mysql', 'joomla', '-e', "update jos_extensions set params=JSON_SET(params,'$.reason_mode',1) where element='com_fgloginasuser' and type='component'"], check=True)

        # backend button -> same tab becomes the backend of the user
        open_screen(pg, screen)
        pg.locator('.fg-las-btn[data-client="administrator"]').first.click()
        pg.wait_for_selector('#fgLasDialog[open]', timeout=5000)
        title = pg.locator('#fgLasDialogTitle').inner_text()
        check(screen + ': confirmation dialog names the user', 'carol' in title, title)
        if screen == SCREENS[0]:
            pg.screenshot(path='dialog.png')
        pg.click('#fgLasDialogCancel')
        pg.wait_for_timeout(800)
        check(screen + ': Cancel closes the dialog and changes nothing',
              pg.locator('#fgLasDialog[open]').count() == 0 and pg.locator('#fg-las-bar').count() == 0)
        pg.keyboard.press('Escape')
        pg.locator('.fg-las-btn[data-client="administrator"]').first.click()
        pg.wait_for_selector('#fgLasDialog[open]', timeout=5000)
        pg.keyboard.press('Escape')
        pg.wait_for_timeout(500)
        check(screen + ': Escape also cancels', pg.locator('#fg-las-bar').count() == 0)
        pg.locator('.fg-las-btn[data-client="administrator"]').first.click()
        pg.wait_for_selector('#fgLasDialog[open]', timeout=5000)
        pg.click('#fgLasDialogOk')
        pg.wait_for_selector('#fg-las-bar', timeout=15000)
        check(screen + ': backend switch shows the return bar', pg.locator('#fg-las-bar').count() == 1, pg.url)
        pg.locator('#fg-las-bar a').first.click()
        pg.wait_for_load_state()
        check(screen + ': return to own account works', pg.locator('#fg-las-bar').count() == 0, pg.url)
        check(screen + ': no JS errors', not errors, errors)
        ctx.close()
    browser.close()

print('\nFAILED: %d' % fails if fails else '\nall browser checks passed')
sys.exit(1 if fails else 0)
