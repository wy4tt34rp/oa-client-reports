# Version 1.1.1 validation

October 26, 2026.

Presentation-only update: rename the report field to Select a Report, give its control a 170px flex basis while retaining responsive wrapping, and use Pages & Screens for the option and report title/CSV name. Data queries, date controls, credentials, and access rules are unchanged.

- PHP syntax checks passed for the changed renderer and plugin entry point.
- JavaScript syntax validation passed for the changed report utility.
- Git whitespace checks passed.
- Release packaging checks the explicit allowlist, private-key exclusion, ZIP CRC, and source parity.
- Verify the labels, dropdown width, and both report choices after installation. Site-specific acceptance is recorded in the client handoff.

The broader integration suite below was run for 1.1.0; it was not repeated for this presentation-only patch.

# Version 1.1.0 validation

October 26, 2026.

This update adds Traffic acquisition to the existing reusable report screen and adds completed calendar-quarter selection. Client settings and credentials remain outside the package.

## Completed checks

- 88 integration checks passed in disposable WordPress 7.1.3 with the official SQLite integration and PHP 8.5.11. Existing page reports, credentials, permissions, nonce checks, private responses, pagination, cache fallback, and monthly retention remain covered.
- New coverage checks full-quarter query boundaries, property timezone rollover, session-scoped channel/source attribution, independent full-period totals, engagement duration divided by sessions, negative revenue, reporting currency, invalid rates, empty periods, cache isolation, allowed report/group parameters, and authorized report-only access through REST.
- 42 wp-config credential checks passed across six isolated PHP processes.
- 35 JavaScript CSV/date checks passed, including formula neutralization, exact units, quarterly labels, report/group/search provenance, rate fractions, currency, and rejection of invalid quarters.
- PHP and JavaScript syntax checks and Git whitespace checks passed.
- Local browser checks confirmed switching between reports, Last quarter, choosing Q4 of the previous year, excluding the unfinished current quarter, source/medium grouping, source search, column sorting, custom dates, and full-period totals that remain separate from searched rows.
- Desktop and narrow-screen checks confirmed usable controls, responsive summary cards, and contained scrolling for the wide acquisition table. Primary-button hover contrast was corrected.
- The actual production GA4 property accepted both new session dimensions and all nine requested metrics for a completed quarter. Both groupings returned identical full-period totals. Client-specific evidence is stored in the private client workspace, not this public plugin repository.
- The daily sessions query differed from the period summary in that real response. The existing reconciliation notice correctly disclosed the difference; neither set of Google-returned values was overwritten.
- Release packaging verifies an explicit allowlist, absence of private-key material, ZIP CRC, and byte-for-byte source parity.

## Deployment checks

Confirm the installed version and load both report types on the intended site after uploading the ZIP. Verify the quarter and a custom range, the source/medium grouping, and the client account's existing access. This document records package validation; client-specific deployment status belongs in the client handoff.

Browser CSV file delivery and the final printed/PDF document have not been verified in the client's normal browser. CSV content is covered by tests. No scheduled quarterly job is added; report selection fetches the chosen period on demand and uses the existing six-hour cache.

The previous-month default, page report, configured credentials, access capability, and monthly refresh schedule are preserved. Minimum PHP 8.0 and WordPress 6.4 remain declared targets; runtime checks used the newer versions above.
