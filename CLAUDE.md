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
    ├── bin/cash-forecast/    # build.php: the cash flow forecast
    ├── bin/cronjobs/         # scheduled jobs (none yet)
    ├── maint/                # qbo_connect.php, qbo_test.php, oneoff/
    ├── docs/                 # cash-forecast.md
    ├── www/                  # bigz.roundsphere.com (uploader)
    └── deploy/etc/           # Apache vhost, Apache systemd override, cron
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
- `app::qbo(true)` is an Intuit sandbox company (connect with `--sandbox`); test writes there first. Big Z's sandbox
  isn't connected yet. Once (2026-11-16, account type test) the user allowed MouseDining's sandbox connection
  (`~/books/mousedining`, Intuit sample data); that was a one-time exception, not a standing permission.

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

## Cash flow forecast

bigz.roundsphere.com/cash-flow/ (the old /finance/cash-flow-forecast/ redirects there): daily cash through the end of the month `months_ahead` (12) after this
one. `php bin/cash-forecast/build.php` (cron, `deploy/etc/cronjobs.worker`) reads QuickBooks (read-only) and writes the
page; the assumptions (recurring rent remittances, mortgages, HOA dues, taxes, one-time items, and bank balances to use
where QuickBooks is behind) are in `app/cash-forecast.json`. When the user reports a change, edit the JSON and rebuild
(`--cached` reuses the last QuickBooks numbers). Details in `docs/cash-forecast.md`.

## Monthly: Rent Oconee Clarke statement

Rent Oconee Clarke (property manager for most properties) sends an owner statement each month for the period from the
15th to the 14th; the user uploads it through the site. Read the PDF from `../uploads`, transcribe it into
`statements/rent-oconee-clarke/YYYY-MM.json` (the month the period ends; same shape as the earlier ones, statement
account codes as printed, no tenant names; `generated` is the date in the file name, `Combined Reports_YYYYMMDD...`),
then `php maint/rent_oconee_clarke.php <file>` previews and `--post` writes, after the user's OK. The script checks
the math, maps properties to classes and statement codes to accounts with generic payees (Plumbing, Maintenance...;
the contractor goes in the memo), and skips what's already in QuickBooks. A new property or code stops it: add it to
`PROPERTY_CLASSES` / `ACCOUNT_CODES`. "2505 W. Broad St. #412" is the 412 Rivers Edge class. Prepaid rent the manager
holds isn't entered (user's choice: it's minor). Tenants often pay late in bunches, so a property may show no rent for
months.

## Shared library

`roundsphere/bookkeeping` (`~/books/bookkeeping/bookkeeping`), pinned to an exact version in
`composer.json`. Never edit `vendor/roundsphere/bookkeeping/`; change the library, tag it, then
bump the pin here.

## Conventions

- PHP, RoundBase style (see `~/develop/justsign/CLAUDE.md`): lowercase static helper classes in
  `app/classes/*.class.php`, top-to-bottom scripts in `bin/cronjobs/` and `maint/`.
- Descriptive variable names (`$property`, not `$p`). Money is integer cents until written to QuickBooks.
- Classes (one per property) go on income and expense lines only, never on balance-sheet lines (bank, loans,
  escrow, fixed assets, equity): property detail there comes from per-property accounts. Older entries have classes on
  loan and asset lines; the user chose to leave them (2026-11-16) since balance sheet by class isn't used.
- 1020-1026 Deni Ct is one 4-plex (one loan, one insurance policy), class "1020-1026 Deni Ct" with the four units as
  sub-classes ("1020-1026 Deni Ct:1022 Deni Ct"...). Building-wide costs (interest, Bank Charges & Fees, Insurance,
  Property Tax, Taxes & Licenses, landscaping) are split over the four unit classes by rent (1020 $1,400, 1022 $600,
  1024 $1,400, 1026 $1,200 as of Nov 2026); `rent_oconee_clarke.php` does it for landscaping (`SHARED_COSTS`).
- Property sale: take the property's book cost (not the sale price) off its fixed asset account, net the selling costs
  (commissions, seller credits, closing fees) against the gain, and put the gain in Realized Capital Gains (like
  #2742, 132 Rivers Edge). In a 1031 exchange the gain is deferred instead: it lowers the replacement properties' cost
  (the accountant books the amount at year end, e.g. #4024 for 1449 Eisenhower into 146/148/152 Johnson Dr). Eisenhower
  (2025) was booked with the price and the costs as expenses; its $244,000 now offsets #4024 in Opening Balance Equity
  (#4585), the costs left since the 2025 return is filed.
- Never write to QuickBooks without the user's OK. One-off writes go in
  `maint/oneoff/YYYY-MM-DD-what.php` (previews unless `--post`; first comment records the outcome
  and QuickBooks Ids); read-only lookups go in `tmp/` (not in git). See `maint/oneoff/README.md`.
- Never print secrets or tokens.
