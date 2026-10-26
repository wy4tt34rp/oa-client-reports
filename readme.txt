=== Ocular Acumen Client Reports ===
Contributors: ocularacumen
Tags: analytics, reports, ga4
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private client-facing Google Analytics reports with date selection and per-site branding.

== Description ==

Give approved WordPress users GA4 Pages & Screens and Traffic acquisition reports with month and quarter pickers, custom dates, daily charts, sortable searchable tables, CSV export, and print layout.

Configure each site's client name, logo, accent, GA4 property, and timezone. Store the Google service account JSON directly in wp-config.php or use a private server file outside the web root. See README.md for setup.

The plugin sends selected dates and a configured property ID to the Google Analytics Data API using a read-only service account. It does not add tracking code. It requires an existing GA4 property and Google Cloud API access.

Google API terms: https://developers.google.com/terms
Google privacy policy: https://policies.google.com/privacy

== Installation ==

Upload and activate. Open Settings > Client Reports. Complete the service account setup in README.md. Test the saved connection, create a report page, and assign approved users the Client Report Viewer role.

Shortcode: [oa_client_reports]

== Changelog ==

= 1.1.1 =
* Rename the report selector to Select a Report and widen it slightly.
* Use Pages & Screens consistently in the selector, report title, and CSV report name.

= 1.1.0 =
* Add Traffic acquisition by session channel group or source / medium with nine metrics.
* Add Last quarter and completed-quarter/year selection alongside month and custom dates.
* Preserve period totals, rate precision, currency, and selected report/grouping in CSV and print.
* Isolate report/group caches and disclose differences between daily counts and full-period totals.

= 1.0.1 =
* Support service account JSON directly in wp-config.php using OA_REPORTS_CREDENTIALS_JSON.
* Keep external credential files supported and identify the active source in settings.
* Validate inline credentials without exposing the key or silently switching accounts.

= 1.0.0 =
* Initial reusable Ocular Acumen release.
* Month and custom date selection, GA4 metrics, daily views, page search and sorting.
* CSV and browser print/PDF export.
* Report-only access, private response cache controls, scheduled monthly refresh checks.
