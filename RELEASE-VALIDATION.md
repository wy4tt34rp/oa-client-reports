# Version 1.0.1 validation

October 19, 2026.

The installable plugin was built locally. It has not been deployed to a client website or connected to a real client Google service account.

## Completed checks

- Version 1.0.1 reran the 66 integration checks and added 42 wp-config credential checks across six isolated PHP processes. These cover nowdoc escaping, valid RSA signing, successful administrator connection testing, JSON precedence over a configured file, operation without a key file, and rejection of empty, malformed, oversized, incorrectly typed, and invalid-field credentials. Rendered pages, API responses, and stored options were checked for private credential leakage.
- PHP source and test syntax checks passed for 1.0.1. The JavaScript and browser checks below were completed for 1.0.0; those assets are unchanged in 1.0.1.
- PHP syntax checks passed for all source and test PHP files.
- JavaScript syntax checks passed for report, export utility, and admin scripts.
- 66 integration checks passed in disposable WordPress 7.1.3 using the official SQLite integration and PHP 8.5.11.
- 20 CSV checks passed in Node, including formula neutralization, quote escaping, Unicode, selected dates, stale status, source warnings, and separation of period totals from searched page rows.
- Integration coverage includes anonymous and subscriber denial, authorized viewer access, administrator-only connection testing, nonce rejection, no-store response headers, leap dates, year rollover, range limits, RSA JWT verification, fixed token endpoints, pagination above 10,000 rows, duplicate/partial-response rejection, quota failures, failure cooldown, and retention of successful monthly data.
- Browser review used the actual shortcode inside the disposable WordPress site with synthetic provider responses.
- The month picker changed the range to August 1 through August 31. The custom date picker changed it to September 12 through September 30. The report values and chart changed with those requests.
- Page search narrowed the page table to one matching title. A malicious-looking test title rendered as plain text.
- Desktop and 390-pixel viewport checks showed usable controls, cards, chart, and horizontally scrollable tables.
- The release ZIP is built from an explicit 13-file allowlist. Tests, test fixtures, generated credentials, Git files, and local preview authentication are excluded. The packaging script checks CRC and byte-for-byte source parity.

## Remaining live acceptance

- Install on the intended WordPress site and connect a dedicated Google service account with Viewer access.
- Verify the actual GA4 timezone and compare a completed month with the Google Analytics UI, including row count, totals, and engagement time.
- Check access for the intended client account and anonymous visitors through the site's actual cache/CDN.
- Confirm the host's WordPress cron execution and the first unattended monthly refresh.
- Test browser CSV delivery and final printed/PDF output in the client's normal browser. CSV contents are covered by automated tests; the in-app browser did not expose a saved download or a reviewable print preview.
- Minimum PHP 8.0 and WordPress 6.4 compatibility are declared targets; the installed runtime checks used the newer versions listed above. Divi-specific rendering has not been checked on the live client theme.

No existing client tracking, live settings, credentials, or site files were changed.
