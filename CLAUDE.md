# CLAUDE.md - Big Z Properties bookkeeping

Guidance for Claude Code when working on this project.

## Project Overview

Bookkeeping automation for **Big Z Properties** (rental real estate). Big Z is **unrelated to the
RoundSphere companies** in `~/books` (X-Cart, Seller Labs Group, RoundSphere, RoundSphere PEO,
MouseDining, Data Automation, QSL): no intercompany accounts, shared vendors, payroll, cash
forecast, bookkeeping calendar or accountant docs. Don't bring anything from those books into
this one, or the other way round. Start Claude sessions for Big Z here (`~/bigz/bigz`), not
from `~`, so they use their own memory.

It lives in `~/bigz` rather than `~/books` so the RoundSphere scripts that work through every
company in `~/books` never reach it. It still uses the shared code and the shared QuickBooks
callback from `~/books/bookkeeping/bookkeeping` (see below).

Big Z was also set up in LineLedger (books.roundsphere.com, `~/books/lineledger`) on 2026-10-24
as a possible QuickBooks replacement.

## Directory Structure

```
~/bigz/
├── conf/project.properties   # secrets: Intuit keys, token encryption key (not in git)
├── var/qbo-token             # QuickBooks connection (encrypted)
├── conf/htpasswd             # logins for bigz.roundsphere.com (not in git)
├── applogs/                  # script logs, qbo-errors.log
├── logs/                     # Apache logs for bigz.roundsphere.com
├── uploads/                  # files uploaded through bigz.roundsphere.com (not in git)
└── bigz/                     # this repo
    ├── app/                  # common.php, project.properties, classes/
    ├── bin/cronjobs/         # scheduled jobs (none yet)
    └── maint/                # qbo_connect.php, qbo_test.php, oneoff/
```

## Common Commands

```bash
php maint/qbo_connect.php    # (re)connect QuickBooks
php maint/qbo_test.php       # check the connection
make test                    # phpstan + phpcs (also runs as a pre-commit hook)
```

## Configuration

- `app/project.properties` (committed): company `bigz`, paths, QBO redirect URI.
- `../conf/project.properties` (not in git): `qbo.client_id/_secret` and `qbo.dev_client_id/_secret`
  (the same Intuit app as the RoundSphere companies), `encrypt.encryption_key` (Big Z's own).

Read with `\RoundSphere\Properties::getProperty()`. Comment lines in properties files must not
contain `=`.

## QuickBooks connection

- `../var/qbo-token`, encrypted with `\RoundSphere\Encrypt` (Intuit requires encryption at rest).
  Refreshes itself on use; expires after ~100 days unused.
- `php maint/qbo_connect.php` prints a link that finishes at bk.roundsphere.com. That callback
  finds companies under `~/books/<company>/`, and Big Z through `company_dir.bigz = /home/ubuntu/bigz`
  in `~/books/bookkeeping/bookkeeping/app/project.properties`. Apache (ProtectHome=read-only) may
  write only the folders in `deploy/etc/apache2-systemd-override.conf` (here).
- Failed Intuit requests are logged (with `intuit_tid`, never data) to `../applogs/qbo-errors.log`.
- Intuit's security requirements: `~/books/bookkeeping/bookkeeping/docs/`.
- `app::qbo(true)` is an Intuit sandbox company (connect with `--sandbox`); test writes there first.

## Website: bigz.roundsphere.com

DocumentRoot `www/`, vhost `deploy/etc/httpd.conf` (symlinked as sites-available/bigz.conf). Behind the shared
AWS ALB, which terminates TLS with the *.roundsphere.com certificate. Every page needs a login (Apache Basic auth,
`../conf/htpasswd`, separate from the RoundSphere logins): pages here show Big Z data. Add a user with
`htpasswd -B ~/bigz/conf/htpasswd <name>`. Only GET/HEAD are allowed, plus POST to `upload.php`. `deploy/etc/apache2-systemd-override.conf`
(symlinked as apache2.service.d/bigz.conf) lets Apache write `../logs`, `../uploads`, `../var` and `../applogs`.

Uploader (`www/index.php`, `upload.js`, `upload.php`, `app/classes/uploads.class.php`): drop one or more files on
the home page; each goes up in its own request (up to 100 MB, set in the vhost) and is saved to `../uploads` (outside
the web root, so nothing uploaded is ever served or run) under a cleaned name, never overwriting (`name (2).pdf`).
`upload.php` only accepts requests whose Origin is bigz.roundsphere.com (the browser would send the Basic auth login
with a POST from any site). Each upload is logged in `../applogs/app.log` with the login name. Files are owned by
www-data, mode 644, so ubuntu can read them. After adding a class, run `composer dump-autoload`.

## Cash flow forecast (planned)

The user wants a daily cash flow forecast on bigz.roundsphere.com like the RoundSphere one. Design notes, and what
changes for rentals, are in `docs/cash-forecast.md`.

## Shared library

`roundsphere/bookkeeping` (`~/books/bookkeeping/bookkeeping`), pinned to an exact version in
`composer.json`. Never edit `vendor/roundsphere/bookkeeping/`; change the library, tag it, then
bump the pin here.

## Conventions

- PHP, RoundBase style (see `~/develop/justsign/CLAUDE.md`): lowercase static helper classes in
  `app/classes/*.class.php`, top-to-bottom scripts in `bin/cronjobs/` and `maint/`.
- Descriptive variable names (`$property`, not `$p`). Money is integer cents until written to QuickBooks.
- Never write to QuickBooks without the user's OK. One-off writes go in
  `maint/oneoff/YYYY-MM-DD-what.php` (previews unless `--post`; first comment records the outcome
  and QuickBooks Ids); read-only lookups go in `tmp/` (not in git). See `maint/oneoff/README.md`.
- Never print secrets or tokens.
