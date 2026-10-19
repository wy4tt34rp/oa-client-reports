# Ocular Acumen Client Reports

A reusable WordPress plugin that gives approved client users a private Google Analytics 4 page report. Each WordPress installation has its own client name, logo, accent color, property ID, timezone, and Google service account credentials.

Version 1.0.1. Requires WordPress 6.4 or later, PHP 8.0 or later, PHP OpenSSL, and outbound HTTPS access to Google's OAuth and Analytics Data API endpoints. Network-wide multisite activation is not supported; activate and configure separately on each site.

## Client features

- Open the previous completed month by default.
- Choose another month or custom dates, up to 366 days through yesterday.
- View page titles, views, active users, views per active user, average engagement time per active user, and all-event counts.
- Review a daily total-views chart and exact daily values.
- Search page titles, sort columns, and browse 50 rows per page.
- Export every matching page row to CSV, including rows beyond the visible page.
- Print the report or use the browser's Save as PDF option.

The chart and summary cover the full selected date range. Page-title search filters the table and its CSV rows. The CSV labels its full-period summary and daily data separately. There is no additional tracking script.

## Installation

1. Upload the ZIP using **Plugins > Add New Plugin > Upload Plugin**.
2. Activate **Ocular Acumen Client Reports**.
3. Complete the Google setup below.
4. Open **Settings > Client Reports**. Enter the numeric GA4 property ID and exact GA4 reporting timezone. Set the client name, optional Media Library logo, and accent color. Save changes.
5. Click **Test saved connection**. It checks the property, timezone, and required report fields.
6. Click **Create website report page**, or place [oa_client_reports] on an existing page.
7. Assign approved WordPress users the **Client Report Viewer** role under **Users**. Administrators also have access. Existing editors and subscribers are not automatically authorized.
8. Exclude the report URL and /wp-json/oa-reports/ from page/CDN caching. Confirm anonymous users cannot retrieve the report or API data.

The report is also under **Tools > Client Reports**. The dedicated capability **oa_view_client_reports** can be added to an existing client role by an administrator who needs to preserve the user's other permissions.

## Where Google login information goes

This release uses a Google service account. It does not ask for or store anyone's Google password and does not include a “Sign in with Google” OAuth screen.

### Create read-only access

1. In [Google Cloud Console](https://console.cloud.google.com/), select or create a project managed by Ocular Acumen or the client.
2. Enable **Google Analytics Data API** for that project.
3. Open **IAM & Admin > Service Accounts**. Create a service account for this client's reporting. It does not need a broad Google Cloud project role.
4. In Google Analytics, open the client's property and **Admin > Property access management**. Add the service account's email address with **Viewer** access to that property only.
5. In the service account's **Keys** tab, create a JSON key. Transfer it directly to the website server using your normal secure hosting/SSH workflow.

Use a separate service account/key per client when practical. A public source repository or plugin ZIP must never contain real keys, tokens, or client data.

### Store the key in wp-config.php

Above the “stop editing” line in the site's wp-config.php, add:

    define('OA_REPORTS_CREDENTIALS_JSON', <<<'OA_GOOGLE_JSON'
    PASTE THE ENTIRE SERVICE-ACCOUNT JSON HERE
    OA_GOOGLE_JSON
    );

Replace the placeholder with the complete contents of the downloaded JSON file, including its opening and closing braces. Keep the single quotes around the first OA_GOOGLE_JSON marker. This PHP nowdoc format preserves the JSON's backslashes and escaped newlines. Put both markers and the pasted JSON at the start of their lines without extra indentation.

No separate credential file is needed with this method. Keep wp-config.php and its backups private, as with the database credentials already stored there. Do not commit the configured file or include it in the plugin ZIP. The plugin reads the constant on the server and does not save the service account key to WordPress options.

Enter the property ID and timezone in **Settings > Client Reports**, then click **Test saved connection**. That screen displays the active credential source and service account email, but never the private key or access token. The frontend receives only report data and its normal WordPress REST nonce.

### Optional: use a separate private key file

Place the JSON key outside **every public document root**, including WordPress and wp-content. Use restrictive permissions that let the PHP service account read it, normally mode 0600 for the key and 0700 for its directory with appropriate ownership.

Example server layout:

    /srv/client/private/oa-reports-service-account.json
    /srv/client/public_html/wp-config.php

Add this above the “stop editing” line in wp-config.php, adapting the path to the actual host:

    define('OA_REPORTS_CREDENTIALS_FILE', '/srv/client/private/oa-reports-service-account.json');

With this alternative, only the file path is added to wp-config.php. Do not use the Media Library, plugin directory, or a public symlink to the key. The plugin checks known public roots; the hosting administrator must also account for other virtual hosts or public aliases.

Use one credential method per site. If both constants are present, OA_REPORTS_CREDENTIALS_JSON takes precedence. An empty, malformed, or invalid JSON constant produces a setup error instead of falling back to the file. Remove the JSON constant to switch back to the file method.

If organization policy prohibits downloadable service-account keys, do not weaken that policy. This release needs a supported host credential arrangement or a future OAuth/workload-identity adapter before use.

## Data and refresh behavior

The plugin calls the official GA4 Data API with the analytics.readonly scope. It uses **unifiedScreenName**, matching **Page title and screen name** in Pages and screens. It requests all users and all events for the configured property. There is no separate stream, hostname, or event filter in this release.

Period totals are requested without a page or date dimension. Active users are never summed across rows or days. Average engagement time uses total engagement duration divided by active users at the corresponding grain. Google-returned views per active user are preserved.

Three queries retrieve the page table, full-period totals, and daily views. More than 10,000 page rows are paginated, with a 50,000-row safety limit. Larger reports fail explicitly and request a narrower period. Rows with zero views and nonzero other metrics are retained. The daily chart fills omitted zero-view dates only after a successful complete response.

The configured timezone must match GA4's returned timezone. A mismatch produces a setup error. Google thresholding, sampling, and other-row aggregation are disclosed when indicated in metadata. GA4 reporting identity and processing can produce differences from the UI; validate a completed month against the UI before client use.

Successful responses are fresh for six hours and retained in WordPress transients for up to 30 days. The latest successfully collected previous calendar month is also retained in a non-autoloaded WordPress option. A failed refresh preserves a matching saved report and labels it stale. This is not a permanent archive of every historical month.

One daily WordPress cron check refreshes the previous completed month from the 2nd through the 8th and records the result in settings. Outside that window it makes no scheduled Google request. Date selection also retrieves data on demand. Use the host's scheduler to run due WordPress cron events for reliable timing. Deactivation removes the scheduled check.

Removing the plugin preserves settings, roles, and cached reports for reinstallation. When permanently retiring it, delete its report page, remove the viewer role from affected users, revoke the Google key/access, and remove stored plugin options. Do not delete credentials shared with another authorized installation.

## Access and maintenance

- Every report request requires a signed-in WordPress user, the report capability, and a valid REST nonce.
- Report and REST responses send private/no-store/noindex headers. The plugin signals LiteSpeed not to cache the report page.
- Provider errors are reduced to safe messages. Private keys, JWTs, and tokens are not written to plugin logs or browser responses.
- Access tokens are temporarily cached server-side in WordPress transients. Protect the database, backups, and object-cache service accordingly.
- Google requests use fixed HTTPS endpoints, certificate verification, and no redirects.
- CSV text fields neutralize spreadsheet formula prefixes.
- A custom Update URI prevents an unrelated WordPress.org slug from replacing this plugin. Updates use reviewed release ZIPs; no automatic update service is included.

## Development and release

Canonical source:
/Users/KO-Mac/Library/CloudStorage/Dropbox/KO WordPress Plugins/public-plugin-repos/oa-client-reports

ZIP destination:
/Users/KO-Mac/Library/CloudStorage/Dropbox/KO WordPress Plugins/archived zips

    php -d memory_limit=512M tests/integration.php /private/tmp/oa-reports-wp-qa/wordpress/wp-load.php
    php tests/config-credentials.php /private/tmp/oa-reports-wp-qa/wordpress/wp-load.php valid
    node tests/export.test.cjs
    python3 scripts/package.py

The integration suite refuses to run outside the named disposable test installation. It uses synthetic provider fixtures. Run the config credential test in separate processes with valid, empty, malformed, wrong-type, oversized, and wrong-fields modes. Tests, generated keys, and local preview code are excluded from the installable ZIP.

See RELEASE-VALIDATION.md for executed checks and remaining live acceptance steps.

## References

- [Google Analytics Data API quickstart](https://developers.google.com/analytics/devguides/reporting/data/v1/quickstart)
- [Google service account authentication](https://developers.google.com/identity/protocols/oauth2/service-account)
- [GA4 API metrics and dimensions](https://developers.google.com/analytics/devguides/reporting/data/v1/api-schema)
- [WordPress REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)
