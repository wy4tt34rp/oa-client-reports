<?php
// Run only against a disposable WordPress installation: php tests/integration.php /absolute/wp-load.php
if (PHP_SAPI !== 'cli' || empty($argv[1]) || !str_starts_with(realpath($argv[1]) ?: '', '/private/tmp/oa-reports-wp-qa/')) {
    exit("This test suite requires the disposable /private/tmp/oa-reports-wp-qa WordPress installation.\n");
}
require $argv[1];
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/FixtureTransport.php';
use OcularAcumen\Reports\Dates;
use OcularAcumen\Reports\GoogleClient;
use OcularAcumen\Reports\ReportService;
use OcularAcumen\Reports\Plugin;
use OcularAcumen\Reports\Tests\FixtureTransport;

$count = 0;
function check(bool $condition, string $message): void {
    global $count;
    $count++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function throws(callable $callback, string $contains): void {
    try { $callback(); } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $contains), 'Expected error: ' . $contains . '; got: ' . $e->getMessage()); return;
    }
    check(false, 'Missing error: ' . $contains);
}
function clearReports(): void {
    global $wpdb;
    foreach (['_transient_oa_reports_%', '_transient_timeout_oa_reports_%', 'oa_reports_lock_%'] as $pattern) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
    }
    delete_option('oa_reports_latest_month');
    wp_cache_flush();
}

activate_plugin('oa-client-reports/oa-client-reports.php');
if (!class_exists(Plugin::class)) require dirname(__DIR__) . '/oa-client-reports.php';
Plugin::boot();
$admin = get_user_by('login', 'qa_admin');
wp_set_current_user($admin->ID);
check(current_user_can(Plugin::CAPABILITY), 'Administrator capability is installed');
check((bool) get_role('oa_report_viewer')->has_cap(Plugin::CAPABILITY), 'Report viewer role has report capability');
check(!get_role('oa_report_viewer')->has_cap('manage_options'), 'Report viewer cannot administer settings');
Plugin::activate();
check(count(array_filter(_get_cron_array(), static fn($events) => isset($events[Plugin::CRON]))) === 1, 'Activation does not duplicate cron');
check(Dates::previousMonth('America/New_York', new DateTimeImmutable('2026-01-15')) === ['start' => '2025-12-01', 'end' => '2025-12-31'], 'January crosses year correctly');
check(Dates::previousMonth('America/New_York', new DateTimeImmutable('2024-03-15'))['end'] === '2024-02-29', 'Leap February');
check(Dates::validate('2024-01-01', '2024-12-31', 'America/New_York')['end'] === '2024-12-31', '366-day range accepted');
throws(fn() => Dates::validate('2026-02-30', '2026-03-01', 'America/New_York'), 'valid dates');
throws(fn() => Dates::validate('2026-02-02', '2026-02-01', 'America/New_York'), 'on or before');
throws(fn() => Dates::validate('2024-01-01', '2025-01-01', 'America/New_York'), '366 days');
throws(fn() => Dates::validate('2099-01-01', '2099-01-03', 'America/New_York'), 'through yesterday');
throws(fn() => Dates::timezone('not/a-timezone'), 'valid reporting timezone');

$private = '/private/tmp/oa-reports-wp-qa/fixture-service-account.json';
$rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($rsa, $pem);
file_put_contents($private, json_encode(['type' => 'service_account', 'client_email' => 'synthetic@example.test', 'private_key' => $pem, 'token_uri' => 'https://example.invalid/not-allowed']));
chmod($private, 0600);
check(GoogleClient::credentials($private)['client_email'] === 'synthetic@example.test', 'Reads external credential');
$inside = ABSPATH . 'forbidden-key.json';
file_put_contents($inside, file_get_contents($private));
throws(fn() => GoogleClient::credentials($inside), 'outside all public');
unlink($inside);
$symlink = ABSPATH . 'symlink-key.json';
symlink($private, $symlink);
throws(fn() => GoogleClient::credentials($symlink), 'outside all public');
unlink($symlink);
throws(fn() => new GoogleClient($private, '../other'), 'numeric GA4');
throws(fn() => GoogleClient::credentials('/missing/file'), 'missing or unreadable');

$settings = ['property' => '123456789', 'timezone' => 'America/New_York', 'client_name' => 'Example Client', 'accent' => '#126d72', 'logo_id' => 0, 'page_id' => 0];
update_option(Plugin::OPTION, $settings);
clearReports();
$transport = new FixtureTransport();
$service = new ReportService(new GoogleClient($private, $settings['property'], $transport), $settings);
$dates = Dates::previousMonth($settings['timezone']);
$data = $service->report($dates['start'], $dates['end']);
check(count($data['rows']) === 68, 'Returns all page titles');
check($data['totals']['users'] === 9500.0, 'Uses period-wide distinct user query');
check(array_sum(array_column($data['rows'], 'users')) !== $data['totals']['users'], 'Fixture exercises nonadditive users');
check($data['totals']['engagementSeconds'] === 37.0, 'Computes weighted period engagement');
check($data['rows'][67]['views'] === 0.0 && $data['rows'][67]['users'] === 2.0, 'Preserves zero-view active rows');
check(array_sum(array_column($data['daily'], 'views')) === $data['totals']['views'], 'Chart reconciles to period views');
check(count($transport->requests) === 2, 'One token exchange and one batch request');
$jwt = explode('.', $transport->requests[0][1]['body']['assertion']);
$claims = json_decode(base64_decode(strtr($jwt[1], '-_', '+/')), true);
check($claims['scope'] === 'https://www.googleapis.com/auth/analytics.readonly', 'Read-only Google scope');
check($claims['aud'] === 'https://oauth2.googleapis.com/token', 'Fixed Google token audience');
$signature = base64_decode(strtr($jwt[2], '-_', '+/'));
check(openssl_verify($jwt[0] . '.' . $jwt[1], $signature, openssl_pkey_get_details($rsa)['key'], OPENSSL_ALGO_SHA256) === 1, 'JWT has a valid RSA signature');
check($transport->requests[0][0] === 'https://oauth2.googleapis.com/token', 'Credential token_uri cannot redirect secrets');
check($transport->requests[1][1]['redirection'] === 0, 'Bearer requests cannot follow redirects');
$service->report($dates['start'], $dates['end']);
check(count($transport->requests) === 2, 'Fresh cache avoids provider calls');
check(get_option('oa_reports_latest_month')['data']['start'] === $dates['start'], 'Latest complete month is retained');
$transport->mode = 'quota';
$stale = $service->report($dates['start'], $dates['end'], true);
check($stale['stale'] && $stale['totals'] === $data['totals'], 'Quota failure retains last successful result');
check(!str_contains($stale['notice'], 'RAW PROVIDER'), 'Provider response content is not disclosed');
$before = count($transport->requests);
$service->report($dates['start'], $dates['end'], true);
check(count($transport->requests) === $before, 'Failure cooldown avoids repeated provider calls');

foreach (['incomplete' => 'incomplete report', 'invalid' => 'invalid metric', 'timezone' => 'timezone', 'restricted' => 'access restrictions', 'partial-daily' => 'incomplete daily', 'duplicate-title' => 'duplicate page'] as $mode => $expected) {
    clearReports(); $transport->mode = $mode;
    throws(fn() => $service->report($dates['start'], $dates['end']), $expected);
    check(!get_option('oa_reports_latest_month'), 'Rejected response never replaces monthly snapshot: ' . $mode);
}
clearReports(); $transport->mode = 'threshold';
check(count($service->report($dates['start'], $dates['end'])['warnings']) === 1, 'Threshold metadata is disclosed');
clearReports(); $transport->mode = 'empty';
$empty = $service->report($dates['start'], $dates['end']);
check($empty['rows'] === [] && $empty['totals']['views'] === 0, 'Successful empty report is explicit');
clearReports(); $transport->mode = 'success'; $transport->pageCount = 10002;
$transport->requests = [];
$paged = $service->report($dates['start'], $dates['end']);
check(count($paged['rows']) === 10002, 'Pagination retrieves more than 10000 rows');
$last = json_decode(end($transport->requests)[1]['body'], true);
check($last['requests'][0]['offset'] === '10000', 'Pagination advances exact offset');
clearReports(); $transport->pageCount = 50001;
throws(fn() => $service->report($dates['start'], $dates['end']), '50,000');
clearReports(); $transport->pageCount = 68;
add_option('oa_reports_lock_' . $service->identity(), time(), '', false);
throws(fn() => $service->report($dates['start'], $dates['end']), 'Another report');
clearReports();

rest_get_server();
$request = new WP_REST_Request('POST', '/oa-reports/v1/report');
$request->set_param('start', $dates['start']);
$request->set_param('end', $dates['end']);
wp_set_current_user(0);
$denied = rest_get_server()->dispatch($request);
check($denied->get_status() === 403, 'Anonymous REST access denied');
check(!str_contains(Plugin::shortcode(), 'data-oa-config'), 'Anonymous shortcode contains no report or nonce');
$subscriber = get_user_by('login', 'qa_subscriber');
if (!$subscriber) $subscriberId = wp_insert_user(['user_login' => 'qa_subscriber', 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
else $subscriberId = $subscriber->ID;
wp_set_current_user($subscriberId);
$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
check(rest_get_server()->dispatch($request)->get_status() === 403, 'Unapproved subscriber denied');
wp_set_current_user($admin->ID);
$request->set_header('X-WP-Nonce', 'invalid');
check(rest_get_server()->dispatch($request)->get_status() === 403, 'Invalid nonce denied');
$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
$hook = static function ($pre, $options, $url) use ($transport) {
    return $transport($url, $options);
};
add_filter('pre_http_request', $hook, 10, 3);
$allowed = rest_get_server()->dispatch($request);
check($allowed->get_status() === 200 && count($allowed->get_data()['rows']) === 68, 'Administrator endpoint works through WordPress HTTP adapter');
$privateResponse = Plugin::privateResponse($allowed, rest_get_server(), $request);
check($privateResponse->get_headers()['Cache-Control'] === 'private, no-store, max-age=0', 'REST response prohibits shared caching');
$viewer = get_user_by('login', 'qa_viewer');
if (!$viewer) $viewerId = wp_insert_user(['user_login' => 'qa_viewer', 'user_pass' => wp_generate_password(32), 'role' => 'oa_report_viewer']);
else $viewerId = $viewer->ID;
wp_set_current_user($viewerId);
$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
check(rest_get_server()->dispatch($request)->get_status() === 200, 'Report-only viewer can retrieve data');
$testRequest = new WP_REST_Request('POST', '/oa-reports/v1/test');
$testRequest->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
check(rest_get_server()->dispatch($testRequest)->get_status() === 403, 'Viewer cannot run administrator connection test');
$markup = Plugin::shortcode();
check(str_contains($markup, 'type="date"') && str_contains($markup, 'type="month"'), 'Both date controls render');
check(!str_contains($markup, $pem) && !str_contains($markup, 'fixture-service-account'), 'Frontend never contains credentials or credential path');
wp_set_current_user($admin->ID);
$cleaned = Plugin::sanitizeSettings(['property' => 'G-INVALID', 'timezone' => 'invalid', 'client_name' => '<b>New Client</b>', 'accent' => 'red', 'page_id' => 999]);
check($cleaned['property'] === $settings['property'], 'Invalid property does not overwrite valid setting');
check($cleaned['timezone'] === $settings['timezone'], 'Invalid timezone does not overwrite valid setting');
check($cleaned['client_name'] === 'New Client' && $cleaned['page_id'] === 0, 'Brand text sanitized and managed page protected');
Plugin::deactivate();
check(!wp_next_scheduled(Plugin::CRON), 'Deactivation removes schedule');
Plugin::activate();
remove_filter('pre_http_request', $hook, 10);
clearReports();
echo "$count integration checks passed on WordPress " . get_bloginfo('version') . " / PHP " . PHP_VERSION . ".\n";
