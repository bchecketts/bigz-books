# Cash flow forecast: how to build one for Big Z (not built yet)

The user wants a daily cash flow forecast for Big Z on bigz.roundsphere.com, like the one built for the
RoundSphere companies (https://bk.roundsphere.com/finance/cash-flow-forecast/). These notes describe that
forecast's design, so it can be rebuilt here, and what changes for rental property.

**Copy the code's ideas, never its data.** The RoundSphere forecast is in `~/books/bookkeeping/bookkeeping`
(`bin/cash-forecast/`, `app/cash-forecast.json`, `www/finance/cash-flow-forecast/page.html`, and the "Cash flow
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
- **The page** is a chart of 6 months of actual balances plus the forecast through `months_ahead`, a daily table
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
- `bin/cash-forecast/build.php`: writes `www/finance/cash-flow-forecast/` (page.html, data.json, archive/).
- `app/cash-forecast.json`: the assumptions.
- A cron entry in `deploy/etc/cronjobs.worker` (Big Z's own file, copied to /etc/cron.d/books-bigz).
- The site is already behind the Basic auth login (see CLAUDE.md), so the page needs no other protection.

Start by asking the user for each property's rent, mortgage and escrow, and the usual timing of each.
