# Changelog

## 1.0.6 - 2026-10-20

- Joomla update server: the package manifest now points to `updates.xml` in the GitHub repository, so new releases show up in *System -> Update -> Extensions*. `build.sh` regenerates `updates.xml` and refuses to build when the manifest versions or the changelog disagree.
- Repository: GitHub release workflow, logo, funding link. No functional change to the extension code.

## 1.0.5 - 2026-10-20

- The backend-login confirmation is now a styled dialog (native `<dialog>`, no extra dependencies) showing the username in the title, a short explanation and Cancel / *Log in to backend* buttons, instead of the browser's `confirm()` box. Falls back to `confirm()` where `<dialog>` is unsupported.

## 1.0.4 - 2026-10-20

- Core Users list (`com_users`): the buttons now live in their own **Login as** column right after *Username*, with compact labels (*Frontend* / *Backend*; the full sentence is the tooltip). The column is only added when the header and every row can be matched, so the table never gets out of line.
- The component's own Users screen shows the full button texts again, as before 1.0.3.

## 1.0.3 - 2026-10-20

- Tidier buttons: compact default labels (*Frontend* / *Backend*) with the full sentence ("Login to frontend as alice") as tooltip and accessible name; a button text set in the options still wins.
- Buttons in the core Users list now form one wrapping flex row with even spacing instead of ragged inline buttons.

## 1.0.2 - 2026-10-20

- Fixed: the login buttons did nothing useful in 1.0.1 - the action URL of the shared hidden form was HTML-escaped twice (`&amp;amp;`), so the browser posted to the component's default view instead of `impersonate.start` (frontend button opened the backend in a new tab, backend button just reloaded). Affected the Users screen of the component and the buttons in `com_users`.
- Tests: the end-to-end suite now reads the form action from the rendered HTML (instead of using a hard-coded URL) and a new browser test (`tests/browser_click.py`, Playwright/Chromium) clicks the real buttons.

## 1.0.1 - 2026-10-20

- Login buttons directly in the core **Users** list (`com_users`), honouring the same ACL, restrictions and safety rules; option *Buttons in the Users list* in the system plugin.
- **User Actions Log** integration through the new plugin *Action Log - FG Login as User*: started (frontend / backend), ended and denied logins are written to the Joomla log with actor, target and reason.
- The package now contains three extensions; the new plugin is enabled on a fresh install only (never re-enabled on update if an administrator disabled it).
- `com_fgloginasuser` is registered in `#__action_logs_extensions` (install and update SQL) and removed again on uninstall.
- End-to-end suite extended to 125 checks.

## 1.0.0 - 2026-10-20

Initial release.

- Log in as any eligible user to the **frontend** (one-time token, separate site session) or to the **backend** (in-place session switch).
- Users screen with search, group/status filters and per-row buttons; buttons are disabled with a tooltip explaining why.
- Dedicated ACL actions: *Login to frontend as user* and *Login to backend as user* (Super Users always allowed).
- Safety rules: no self-login, no blocked/non-activated users, target must be allowed to log in on that client, Super Users only reachable by Super Users and only when enabled, optional per-group restrictions.
- Frontend token: 256-bit random, only the SHA-256 hash is stored, single use (atomic claim), short lifetime, optionally bound to the requesting IP, rechecked against permissions when used.
- Session switch does not touch the target's `lastvisitDate`, skips the MFA captive page (the administrator is already authenticated), forks the session id and removes the old session row.
- Ending an impersonation destroys only the current session (never the real user's own sessions).
- Companion plugin *System - FG Login as User* shows a bar with a return button on both sides.
- Audit log (including denied attempts) with filters, delete and purge, automatic retention.
- Optional e-mail notification through Joomla's mailer; failures are logged and never block a login.
- Configurable button texts (`%s` = username), redirects, custom CSS.
- en-GB and sk-SK translations.
