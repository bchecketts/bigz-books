# Cash flow forecast for Big Z

Built 2026-11-07: https://bigz.roundsphere.com/cash-flow/ (moved from /finance/cash-flow-forecast/ 2026-11-16). How it works now is under "Big Z's
forecast" at the end; the rest is the design it was based on.

The user wanted a daily cash flow forecast for Big Z on bigz.roundsphere.com, like the one built for the
RoundSphere companies (https://bk.roundsphere.com/finance/cash-flow-forecast/). These notes describe that
forecast's design, so it can be rebuilt here, and what changes for rental property.

**Copy the code's ideas, never its data.** The RoundSphere forecast is in `~/books/bookkeeping/bookkeeping`
(`bin/cash-forecast/`, `app/cash-forecast.json`, `www/cash-flow/page.html`, and the "Cash flow
forecast" section of its CLAUDE.md). Read it for patterns. Don't link to it, include it, or carry over any of its
companies, amounts or assumptions: Big Z gets its own copies of whatever is reused.

## How the RoundSphere forecast works

- **Two steps.** `snapshot.php` reads QuickBooks that morning (bank balances, open bills and invoices, the last
  14 days of bank moves, and 6 months of actual daily balances from the balance sheet). `build.php` then works
  forward day by day and writes `data.json` and a static `page.html`.
- **Assumptions are a JSON file in git**, not code. It holds recurring items such as
  `{"name": ..., "day": 15 | "last", "amount": -1234, "from": "YYYY-MM-DD", "until": ...}`, plus settings
  (`months_ahead`, minimum balances). The user tells Claude about a change, Claude edits the JSON and rebuilds,
  and the git history is the record of the plan.
- **QuickBooks drives what's already known.** Open bills are paid on their due date, and open invoices are
  collected some number of days after issue. A bill or invoice can stand in for the recurring item that modelled
  it (`replaces`), and `open_overrides` sets a known date for one. Anything past its expected day and still open
  is counted today and listed under "Overdue".
- **No double counting.** Each planned item is matched to what's already recorded in the bank accounts' last 14
  days: up to 5 days early or 10 days late, the same amount within $1, or within 1% when the names share a word.
  A matched item isn't counted again, and the page lists the matches. (QuickBooks' API can't see the bank feed's
  For Review items, so a payment the bank has but QuickBooks doesn't is still counted as planned.)
- **The page** is a chart of `months_back` (3) months of actual balances plus the forecast through `months_ahead` (12), a daily table
  of balance and events, the low point, the overdue items and the matches. It's a static page served through a
  small `index.php` that adds "Updated N minutes ago". Each day's page and data are archived in `archive/YYYY-MM-DD/`.
- **Runs from cron** every hour through the working day, and again whenever Claude is asked to "run the forecast".

## What's different for rental property

- **Rent per unit** (a class per property in QuickBooks), due on the 1st, usually received by the grace day.
  Model each lease's rent and term, with vacancies between leases. Late or unpaid rent shows as open invoices if
  rent is invoiced in QuickBooks; if not, decide how to track it with the user.
- **Mortgages**: principal, interest and escrow on a fixed day. Only the total payment matters for cash; the split
  is for the books.
- **Lumpy annual costs**: property taxes and insurance (unless escrowed), HOA dues, and licenses or inspections.
- **Repairs and turnover**: a monthly reserve per property, or actual estimates when the user knows them.
- **Security deposits** are held money, not income: show what's owed back, and pay it out when a lease ends.
- **Property sales or purchases** as one-time items.
- **A property-level view** (cash in and out by property) is probably as useful as the company total.

## Suggested layout

- `bin/cash-forecast/snapshot.php`: QuickBooks numbers (`app::qbo()`).
- `bin/cash-forecast/build.php`: writes `www/cash-flow/` (page.html, data.json, archive/).
- `app/cash-forecast.json`: the assumptions.
- A cron entry in `deploy/etc/cronjobs.worker` (Big Z's own file, copied to /etc/cron.d/books-bigz).
- The site is already behind the Basic auth login (see CLAUDE.md), so the page needs no other protection.

## Big Z's forecast

- `bin/cash-forecast/build.php` reads QuickBooks (`cashforecast::snapshot()`, read-only; kept in
  `../var/cash-forecast-snapshot.json` for `--cached` re-runs) and writes `www/cash-flow/` (page.html,
  data.json, archive/; not in git). The committed `index.php` there serves page.html with its age.
- Cash is the accounts in `cash_accounts`. The property managers' holding accounts and the mortgage escrows aren't
  cash: the managers' money arrives as their monthly remittance, an item like any other.
- **QuickBooks is behind.** 2025 is fully booked, but in 2026 the First American account mostly stopped being
  entered after January, so its QuickBooks balance is wrong. `balance_overrides` takes the bank's balance for an
  account (at the end of `as_of`); what QuickBooks records after that day is added. Planned items between `as_of`
  and today that aren't in QuickBooks are counted today, so the override should be recent. The page warns about
  an account with nothing recorded in 30 days.
- Matching to what's recorded works as in the RoundSphere forecast; the text matched also includes the other
  account of a transfer (remittances are transfers from the managers' holding accounts with no payee).
- The first assumptions (2026-11-07) come from the 2025-2026 bank history; items marked `estimate` are guesses for
  the user to confirm (the Commercial Bank loans and the Farmers LOC have no payments recorded).
- Cron: `deploy/etc/cronjobs.worker` (11:00 and 20:00 UTC), to be installed as /etc/cron.d/books-bigz (see the file).
