<p align="center"><img src="assets/logo.webp" alt="FG Login as User" width="128"></p>

<h1 align="center">FG Login as User</h1>

<p align="center">
  <a href="https://github.com/FGcodework/pkg_fgloginasuser/releases/latest"><img src="https://img.shields.io/github/v/tag/FGcodework/pkg_fgloginasuser?label=version&color=ff6b4a" alt="Version"></a>
  <img src="https://img.shields.io/badge/Joomla-5%20%7C%206-1c3d5a?logo=joomla&logoColor=white" alt="Joomla 5/6">
  <img src="https://img.shields.io/badge/PHP-8.3+-777bb4?logo=php&logoColor=white&color=purple" alt="PHP 8.3+">
  <a href="https://github.com/FGcodework/pkg_fgloginasuser/blob/master/LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0+-green" alt="License"></a>
  <img src="https://img.shields.io/github/downloads/FGcodework/pkg_fgloginasuser/total?color=brown" alt="Downloads">
  <a href="https://ko-fi.com/FGcodework"><img src="https://img.shields.io/badge/support-Ko--fi-F16061.svg?logo=ko-fi&logoColor=white" alt="Support on Ko-fi"></a>
</p>

Native **Joomla 5.4+ / 6** extension for administrators, part of the FG extension series: log in as another user to check what they see, reproduce a problem, or test permissions - to the **frontend** or to the **backend**.

Package `pkg_fgloginasuser` = component `com_fgloginasuser` + system plugin `plg_system_fgloginasuser` (return bar and buttons in the core Users list) + action log plugin `plg_actionlog_fgloginasuser` (Joomla *User Actions Log* integration).

## Features

- **Frontend login** - opens the site as the user (new tab by default). The site session is separate from your administrator session, so the administration stays open.
- **Backend login** - switches your administrator session to another backend user in place (useful for testing what a Manager sees). A bar at the bottom of every page offers *Return to my account*.
- **Buttons in the core *Users* list** (`com_users`) - the same buttons (compact labels, full text as tooltip), with the same permission and restriction checks, appear in their own *Login as* column after *Username*. Can be switched off in the system plugin options.
- **Reason for the login** - the dialog asks why you log in as somebody else (do not ask / optional / required, see Options -> Security). Saved in the log, the User Actions Log and the notification e-mail, shown in the return bar.
- **Predefined reasons** - optionally a drop-down of reasons you define (with a note, and *Other...* which can be switched off); the Logs screen can be filtered by them.
- **Confirm identity first** (off by default) - before a login as another user the dialog asks for the administrator's own password and, optionally, a code of their second factor (TOTP, YubiKey). The confirmation is remembered for a few minutes; wrong attempts are limited and logged.
- **Maximum duration** - an impersonation ends automatically after the configured minutes (60 by default, 0 = no limit).
- **Joomla User Actions Log** - started / ended / denied logins are written to *Users -> User Actions Log* (filterable by extension). Disable the action log plugin to stop writing.
- Users screen with search / group / status filters; unavailable actions are greyed out with the reason in a tooltip.
- Permissions per Joomla user group (Options -> Permissions): *Login to frontend as user*, *Login to backend as user*. Super Users are always allowed.
- Optional restrictions: members of group A may only log in as members of groups X, Y.
- Audit log of every attempt (also denied ones), filters, delete, purge, retention in days.
- Optional e-mail notification (uses Joomla's mailer, so SMTP works on hosts without PHP `mail()`).
- Custom button text (`%s` = username), redirect after login (menu item or URL), custom CSS.

> The `com_users` buttons are injected into the rendered page HTML (Joomla has no event for extra row content in that list). If a future core template changes, the buttons do not appear (the page is left untouched), a warning with the reason is written to `com_fgloginasuser.log.php` in the Joomla log folder (once per session), and the extension's own Users screen keeps working. After a Joomla update run `tests/e2e.py` / `tests/release126.py` to check the injection against the real list.

## Requirements

Joomla 5.4+ or 6.x, PHP 8.3+, MySQL / MariaDB. Developed on Joomla 6.1.2; the whole test suite (`tests/`) is also run against Joomla 5.4.5. Joomla 5 only receives security fixes until 12 October 2027, so Joomla 6 is the primary target.

## Installation

Download `pkg_fgloginasuser_vX.X.X.zip` from the [latest release](https://github.com/FGcodework/pkg_fgloginasuser/releases/latest) and install it through *System -> Install -> Extensions*. The plugin is enabled automatically on a fresh install. Then grant the permissions: *Users -> Login as User -> Options -> Permissions* (give the group `Access Administration Interface` plus one or both login actions).

Updates are announced by Joomla itself (*System -> Update -> Extensions*) through the update server `updates.xml` in this repository, and are also picked up by [FG Extension Manager](https://github.com/FGcodework/com_fgextensionmanager).

## Security model

| Rule | Detail |
| --- | --- |
| CSRF | Starting an impersonation is POST-only with a Joomla form token; stopping needs the session token. |
| Frontend token | At most 10 unused ones per administrator (configurable), 256 random bits, DB stores only its SHA-256, single use (atomic `UPDATE ... WHERE status='pending'`), default 60 s, optionally bound to the requesting IP, handed to the site in a POST body (never in a URL), response sent with `Cache-Control: no-store` and `Referrer-Policy: no-referrer`. Permissions are re-evaluated when the token is used. |
| Higher rights | Unless you are a Super User you cannot log in as a user who holds a global permission (`core.manage`, create, edit, edit state, edit own, delete) that you lack (option *Do not allow logging in as users with higher rights*, on for new installs, kept off when updating from an older version). Permissions inside single components are not compared. |
| Targets | Never yourself, never blocked or non-activated users, only users allowed to log in on that client (`core.login.site` / `core.login.admin`). |
| Super Users | Only another Super User can log in as a Super User, and only when *Allow impersonating Super Users* is on (off by default). |
| Restrictions | Rows in Options -> Security limit which groups an administrator group may log in as. Super Users are never restricted. A row with no target groups allows nobody. |
| Session | `lastvisitDate` of the target is not modified, session id is regenerated, the old session row is deleted, the MFA captive page is skipped for the impersonated session. |
| Log clean-up | *Delete all* and the retention never delete a login that is still active. A row is closed only when its session (remembered as a hash) is gone from the session table; without that information it is only presumed ended after a day and kept. |
| Ending | Only the current session is destroyed - not core `logout()`, which would also terminate the real user's own sessions. |

Things to know:

- The one-time token travels in a POST body from the administrator to the site, so it does not appear in URLs, access logs or Referer headers.
- After the maximum duration the impersonated session is closed (not handed back to the administrator), who then signs in again.
- A **backend** switch changes the session of that browser, so other administrator tabs in the same browser are switched as well.
- If your connection alternates between IPv4 and IPv6 between the administrator and the site request, turn off *Bind login link to IP address*.
- While impersonating a user in the backend, an impersonated user without access to this component can still leave via the bar (the *stop* task is exempt from `core.manage`, but only while the session carries an impersonation marker).

## The system plugin is part of the security

The return bar, the automatic end after the maximum duration and the special handling of an ordinary logout in an impersonated session are done by the plugin *System - FG Login as User*; a component cannot run on every request. The component refuses to start a login while the plugin is disabled, and the Users and Logs screens show a warning. If the plugin is disabled (or the package is removed) *while* someone is logged in as another user, that session has no return bar, no time limit, and a normal logout there ends all sessions of the impersonated user, including the real person's own. End such logins in the Logs / by deleting the session, or enable the plugin again; the impersonation itself is not lost (a package update in the middle of an impersonation was tested and keeps it). If the audit table cannot be written when a login starts, nothing is switched (the request fails).

## Not included

- Buttons inside third-party shop/membership lists (HikaShop, J2Store, VirtueMart, Community Builder ...). The extension has its own Users screen.
- PostgreSQL schema.

## Tests

`tests/release126.py` (13 checks) simulates changed core markup of the Users list (diagnostic logging). `tests/release125.py` (7 checks) covers returning to the original administrator. `tests/release124.py` (5 checks) covers the limit of unused login links under parallel requests. `tests/release123.py` (16 checks, with `tests/reconcile_probe.php`) covers log clean-up versus live impersonations. `tests/release122.py` (15 checks) covers the dependency on the system plugin (warning, behaviour while it is off, package update and audit table outage during a login). `tests/release121.py` (11 checks) covers the fail-closed second factor step-up. `tests/release120.py` (13 checks) covers the "no higher rights" rule. `tests/release113.py` (10 checks) covers the limit of unused login links. `tests/release112.py` (31 checks) covers the identity confirmation, including a real TOTP code (it waits about a minute for the validity time). `tests/release111.py` (25 checks) covers predefined reasons and the Logs filter. `tests/release110.py` (30 checks) covers the 1.1.0 features (POST hand-over, reason, maximum duration; it waits about a minute for the time limit). `tests/audit.py` (26 checks) holds the regression tests for the findings of the 1.0.5 security review (shared sessions, actor revalidation, group inheritance, forced password reset, logout in an impersonated session, audit-table outage, log permissions, purge); it reuses the helpers of `e2e.py`. `tests/logstore_probe.php <joomla-root> <actorId> <targetId>` checks the token claim / refusal race against the real database. `tests/browser_click.py` additionally clicks the real buttons in Chromium (Playwright); `tests/browser_fallback.py` does the same without `<dialog>.showModal` (presets only, required reason, identity confirmation). `tests/e2e.py` is the browser-like end-to-end suite used for the release (133 checks: both targets, ACL, restrictions, token replay / expiry / IP mismatch / junk, CSRF, MFA, parallel real sessions, mail, retention, `com_users` list buttons, User Actions Log, PHP warnings). It expects a throw-away Joomla at `http://127.0.0.1:8080` with the users `root`, `alice`, `bob`, `carol` (Manager), `mike` (Manager), `dave` (blocked), `sue` (Super User) - see the top of the script.

## Repository layout

| Path | What |
| --- | --- |
| `pkg_fgloginasuser.xml`, `script.php`, `language/` | Package manifest, install script (preflight checks, plugin enabling), package language files |
| `com_fgloginasuser/` | Component (administrator + site + media) |
| `plg_system_fgloginasuser/` | System plugin: return bar, buttons and *Login as* column in `com_users` |
| `plg_actionlog_fgloginasuser/` | Action log plugin: language strings for the User Actions Log |
| `tests/` | End-to-end suite (`e2e.py`), review regression and feature tests (`audit.py`, `release110.py`, `release111.py`, `release112.py`, `release113.py`, `release120.py`, `release121.py`, `release122.py`, `release123.py`, `release124.py`, `release125.py`, `release126.py`, `logstore_probe.php`) and real-browser click tests (`browser_click.py`, `browser_fallback.py`) |
| `build.sh` | Builds `dist/pkg_fgloginasuser_vX.X.X.zip` and regenerates `updates.xml` |
| `updates.xml` | Joomla update server feed (generated) |

## Releasing

1. Bump `<version>` in all four manifests, add a `## X.X.X - date` entry to `CHANGELOG.md`.
2. Run `./build.sh` (checks that the versions agree, builds the ZIP, regenerates `updates.xml`) and commit.
3. Tag and push: `git tag vX.X.X && git push origin master vX.X.X`. The *Release* workflow lints the sources, verifies that tag, manifest and `updates.xml` agree, and publishes the GitHub release with the ZIP attached.

## Support

If you like this extension, you can support its development on [Ko-fi](https://ko-fi.com/fgcodework). More FG extensions: [fgcodework.github.io](https://fgcodework.github.io).

## License

GNU General Public License version 2 or later - see [LICENSE](LICENSE).

## About

Part of the FG extension series by [FGcodework](https://github.com/FGcodework). Inspired by the idea of the Web357 *Login as User* component; this is an independent implementation written for Joomla 6 (also runs on Joomla 5.4+) and works on the backend as well.

## Known limitations

- The one-time login token is a bearer credential (valid 60 s, single use, bound to the IP by default); whoever holds it within that time can use it.
- The identity confirmation cannot ask for second factors that need the browser or send a code on request (WebAuthn, e-mail); an account with only those is asked for the password only (or refused, see option *Accounts whose second factor cannot be asked here*). A failure to read the second factors refuses the login. Its limit on wrong attempts is per session.
- Viewing the audit log needs the separate permission `Log` (`fgloginasuser.logs`).
