# Changelog

## 1.2.5 - 2026-10-20

- **Returning to the original administrator re-checks the account like the start does**: ending a backend impersonation verified only that the account exists, is not blocked and may log in to the backend, but not that it is activated, which the start checks. An account deactivated (activation not cleared) during an impersonation, without being blocked, could be restored into the session (reproduced). The check is now one method, `AccessPolicy::canRestore()`: account exists, not blocked, activated, `core.login.admin`. It deliberately does not require rights to this tool, so leaving a foreign identity always stays possible; if the account may not be restored the session simply ends.
- Tests: `tests/release125.py`.

## 1.2.4 - 2026-10-20

- **Limit of unused login links is race-proof**: the check of *Maximum unused login links* and the insert of the new token used to be two separate steps, so parallel requests of one administrator could all pass the check (measured: limit 3, 12 parallel requests, 4-5 tokens created). Both now run as one step under a per-administrator database lock (`GET_LOCK`) in `LogStore::createPendingLimited()`. If the lock cannot be obtained the request is refused (fails closed). The one-time token itself was never affected; its consumption was already an atomic conditional update.
- Tests: `tests/release124.py` (parallel starts never exceed the limit).

## 1.2.3 - 2026-10-20

- **Log clean-up never deletes a live impersonation**: *Delete all* and the retention setting used to delete "active" rows older than a day (and retention by age deleted any row), although with *Maximum duration* 0 a session can live longer; its end was then recorded nowhere. Active rows are now never deleted by age. Instead each active impersonation remembers its session (SHA-256 of the session id, new column `session_hash`, update script `1.2.3.sql`), and a row is closed ("Ended: the session no longer exists") only when that session is verifiably gone from the session table. Rows without a recorded session (older versions) or with sessions kept outside the database are only *presumed* ended after a day, labelled as such, and marked, not deleted.
- The Logs screen reconciles the rows when it is opened, so the status is accurate.
- Tests: `tests/release123.py`.

## 1.2.2 - 2026-10-20

- **Dependency on the system plugin made visible**: the Users and Logs screens show a warning when *System - FG Login as User* is disabled (what is lost for running impersonations and what to do). New README section *The system plugin is part of the security*.
- Tests: `tests/release122.py` (return path without the plugin, package update and audit table outage during a login).

## 1.2.1 - 2026-10-20

- **Identity confirmation fails closed**: in *Password and second factor code* mode a failure to read the account's second factors (for instance a database error) no longer falls back to the password alone; the login is refused with a clear message and a log entry. Core's own helper hides database errors (it returns an empty list), so the lookup is now our own query. The code now tells apart *has a usable factor*, *has only factors that cannot be asked here*, *has none* and *lookup failed*.
- New option *Accounts whose second factor cannot be asked here* (WebAuthn / e-mail only): *Password only* (as before, default) or *Refuse*.
- Tests: `tests/release121.py`.

## 1.2.0 - 2026-10-20

Hardening from a second review.

- **No higher rights** (new option, on for new installs): an administrator who is not a Super User cannot log in as a user who holds a global permission (`core.manage`, create, edit, edit state, edit own, delete) that the administrator lacks. Before, a Manager with the login permission could take over an Administrator. Permissions inside single components are not compared; the group restrictions remain for those.
- **Updates keep the old behaviour**: when updating from an older version the option is stored as *off* unless it was already set, so existing set-ups do not change until you switch it on (Options -> Security).
- The Options screen now says plainly that granting the login permission does not limit which users can be logged in as; the permission descriptions say the same; the restrictions description states that rules of several groups are combined (union).
- Tests: `tests/release120.py`.

## 1.1.4 - 2026-10-20

- Fix: the title of the Options screen showed the raw key `com_fgloginasuser_configuration`; it is now "FG Login as User: Options" (Slovak: "Možnosti").

## 1.1.3 - 2026-10-20

- **Limit of unused login links**: an administrator can have at most 10 frontend logins that were started but not yet opened (new option *Maximum unused login links per administrator*, 1-100). Further attempts are refused and recorded as denied until one is used or expires.
- Tests: `tests/release113.py`.

## 1.1.2 - 2026-10-20

- **Confirm identity before logging in as another user** (new option, **off by default**): the dialog asks the administrator for their own password and, with *Password and second factor code*, for a code of their second factor (TOTP, YubiKey; core's try limit applies). A stolen or left-open administrator session is therefore not enough. The confirmation is remembered for a configurable time (5 minutes by default), only for that very user, never in an impersonated session. Wrong attempts are limited per session (5, then 15 minutes lock) and recorded as denied. WebAuthn and e-mail second factors cannot be asked for in this dialog; an account that has only those is asked for the password only. The password is never stored or logged.
- Tests: `tests/release112.py` (includes a real TOTP code).

## 1.1.1 - 2026-10-20

- **Predefined reasons**: Options -> Security has a list of reasons; the dialog then shows a drop-down (plus an optional note, or the text of *Other...*, which can be switched off). A reason picked from the list is stored as `Label` or `Label: note`. With *Allow other reasons* off, the server accepts only listed reasons.
- **Logs filter by reason**: with predefined reasons configured, the Logs screen has a *reason* filter (each reason also matches its entries with a note) and *no reason given*.
- Tests: `tests/release111.py`; `browser_click.py` also drives the drop-down.

## 1.1.0 - 2026-10-20

Feature release (the "later" items of the 1.0.5 review).

- **Reason for the login**: the dialog asks why the administrator logs in as somebody else (new option *Reason for the login*: do not ask / optional / required; optional by default). The reason is saved in the log (new column, added by the update script), the User Actions Log, the notification e-mail and shown in the return bar. A required reason is also enforced on the server.
- **Maximum duration** (new option, 60 minutes by default, 0 = no limit): an impersonation ends automatically with the first request after the limit, is recorded as ended, and the session is closed. The return bar shows when it ends.
- **Login token no longer travels in a URL**: the administrator hands it to the site in the body of a POST request (small self-submitting page with an external script, so it works under a strict Content-Security-Policy). The token is therefore not in the browser history, in server or proxy access logs, or in a Referer header. The site no longer accepts the token from the query string.
- Database: `#__fgloginasuser_logs.reason` (update script `1.1.0.sql`).
- Tests: `tests/release110.py` covers the three features.

## 1.0.8 - 2026-10-20

Security and robustness release, based on an external audit of 1.0.5; every fix has a regression test (`tests/audit.py`) that fails on 1.0.7 and passes here.

- **Ordinary logout inside an impersonated session** (module, menu, administrator menu) now only ends that impersonation. Before, Joomla's own logout handler also destroyed every other session of the impersonated user, i.e. the real person's own logins, and the audit row stayed "active".
- **Shared sessions** (*Global Configuration -> System -> Shared Session*): logging in as another user is refused, because the impersonated identity would replace the actor's own administrator session and could not be left again.
- **Actor is re-checked when the one-time token is used**: a blocked, not activated or meanwhile unauthorised administrator can no longer complete a login link that was issued earlier. The actor must also be allowed to log in to the administration.
- **Group restrictions**: a rule for a parent group now also applies to administrators in its child groups; a target must have *all* its groups within the allowed ones (before, one allowed group was enough even if the user was also in a more privileged group); new option *Administrators without a restriction* (allow / deny) for a strict "deny unless a rule allows" mode.
- **Users with a pending forced password reset** cannot be impersonated (Joomla would trap the session on the password form and block the way back).
- **Return bar plugin disabled**: logging in as another user is refused until the *System - FG Login as User* plugin is enabled.
- **Second login in the same browser session** closes the previous impersonation in the audit log instead of leaving it "active".
- **Denial cannot overwrite a success**: an attempt that is refused (wrong IP, failed re-check) only changes a row that is still in the expected state.
- **Audit outage cannot trap a user**: ending an impersonation no longer depends on the audit table; "started" is reported (audit, e-mail, User Actions Log) only after the identity was really switched; a failed session renewal aborts the switch and is logged as denied.
- **Log access**: new permission *View and delete the audit log* (`fgloginasuser.logs`); e-mail addresses in the component's Users screen (and in its search) are shown only to those who can manage users in `com_users`. Existing non-Super-User administrators lose access to the log until the permission is granted in *Options -> Permissions*.
- **Log clean-up**: *Delete all* asks for confirmation and removes only finished history (unexpired login links and last-day impersonations are kept); retention also runs when the log is opened.
- Token-from-another-IP notes are stored as translatable language keys; the package description mentions all three extensions; disabled buttons expose their reason as accessible name.

## 1.0.7 - 2026-10-20

- The update server (`updates.xml`) and the release workflow now refer to the `master` branch (1.0.6 pointed to `main`). No functional change to the extension code.

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
