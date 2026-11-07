# One-off scripts that wrote to QuickBooks

Every script that changes Big Z's QuickBooks data outside the regular jobs (`bin/cronjobs/`) and the
reusable maintenance scripts (`maint/`) is kept here, named `YYYY-MM-DD-what.php` by the day it was run,
with a first comment saying what it did and the outcome (QuickBooks Ids). They're a record, not tools to
re-run: most check that records are as expected and stop otherwise, but the data has moved on since.

Read-only lookups stay in `tmp/` (not in git).

Related records: the QuickBooks memos on the entries themselves, the commit messages, and the GitHub
issues of this repo.
