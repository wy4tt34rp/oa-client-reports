<?php
// Each mode runs in a separate process because PHP constants cannot be redefined.
if (PHP_SAPI !== 'cli' || empty($argv[1]) || !str_starts_with(realpath($argv[1]) ?: '', '/private/tmp/oa-reports-wp-qa/')) {
    exit("This test requires the disposable /private/tmp/oa-reports-wp-qa WordPress installation.\n");
}
$mode = $argv[2] ?? 'valid';
if (!in_array($mode, ['valid', 'empty', 'malformed', 'wrong-type', 'oversized', 'wrong-fields'], true)) {
    throw new RuntimeException('Unknown test mode.');
}
$rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($rsa, $pem);
$marker = 'inline-only-secret-marker';
$json = json_encode(['type' => 'service_account', 'client_email' => 'inline@example.test', 'private_key' => $pem, 'private_key_id' => $marker]);
$values = [
    'valid' => $json, 'empty' => '', 'malformed' => '{"private_key":"' . $marker,
    'oversized' => str_repeat('x', 32769), 'wrong-fields' => '{"type":"service_account","client_email":[]}',
];
// Exercise the documented nowdoc format with real JSON \n escaping.
$config = tempnam('/private/tmp/oa-reports-wp-qa', 'config-test-');
chmod($config, 0600);
try {
    $declaration = $mode === 'wrong-type'
        ? "define('OA_REPORTS_CREDENTIALS_JSON', ['invalid']);"
        : "define('OA_REPORTS_CREDENTIALS_JSON', <<<'OA_GOOGLE_JSON'\n" . $values[$mode] . "\nOA_GOOGLE_JSON\n);";
    file_put_contents($config, "<?php\n" . $declaration . "\n");
    require $config;
} finally {
    unlink($config);
}
require $argv[1];
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/FixtureTransport.php';

use OcularAcumen\Reports\Plugin;
use OcularAcumen\Reports\GoogleClient;
use OcularAcumen\Reports\Dates;
use OcularAcumen\Reports\Tests\FixtureTransport;

$count = 0;
function check(bool $ok, string $label): void {
    global $count;
    $count++;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
}
global $wpdb;
foreach (['_transient_oa_reports_%', '_transient_timeout_oa_reports_%', 'oa_reports_lock_%'] as $pattern) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
}
delete_option('oa_reports_latest_month');
wp_cache_flush();
update_option(Plugin::OPTION, ['property' => '123456789', 'timezone' => 'America/New_York']);
wp_set_current_user(get_user_by('login', 'qa_admin')->ID);
$transport = new FixtureTransport();
add_filter('pre_http_request', static fn($pre, $options, $url) => $transport($url, $options), 10, 3);
$dates = Dates::previousMonth('America/New_York');
$request = new WP_REST_Request('POST', '/oa-reports/v1/test');
$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
$response = rest_get_server()->dispatch($request);
ob_start();
Plugin::settingsPage();
$settingsMarkup = ob_get_clean();
$markup = Plugin::shortcode();
check(!str_contains($settingsMarkup . $markup . wp_json_encode($response->get_data()), $marker), 'No private credential metadata in output');
check(!str_contains($settingsMarkup . $markup, $pem) && !str_contains($settingsMarkup . $markup, substr(explode("\n", $pem)[1], 0, 40)), 'No private key in rendered pages');
check(!$wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 1", '%' . $wpdb->esc_like($marker) . '%')), 'Credential JSON not stored in WordPress options');

if ($mode === 'valid') {
    check($response->get_status() === 200, 'Administrator connection test authenticates from wp-config');
    check(str_contains($settingsMarkup, 'inline@example.test') && str_contains($settingsMarkup, 'JSON constant'), 'Settings identify active JSON account');
    check(Plugin::credentialJson() === $json, 'Nowdoc preserves JSON backslashes');
    check(GoogleClient::credentials('/missing/key/file', $json)['private_key'] === $pem, 'JSON credential needs no separate file and preserves PEM newlines');
    preg_match('/data-oa-config="([^"]+)"/', $markup, $matches);
    $clientConfig = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);
    check($clientConfig['configured'] === true, 'Report is enabled with wp-config credentials');
    check(count($transport->requests) === 2, 'JSON account performs token exchange and report request');
    $jwt = explode('.', $transport->requests[0][1]['body']['assertion']);
    $claims = json_decode(base64_decode(strtr($jwt[1], '-_', '+/')), true);
    check($claims['iss'] === 'inline@example.test', 'JSON account takes precedence over configured file account');
    check(openssl_verify($jwt[0] . '.' . $jwt[1], base64_decode(strtr($jwt[2], '-_', '+/')), openssl_pkey_get_details($rsa)['key'], OPENSSL_ALGO_SHA256) === 1, 'Inline key signs a valid RSA JWT');
    check($claims['scope'] === 'https://www.googleapis.com/auth/analytics.readonly', 'Inline authentication remains read only');
} else {
    check($response->get_status() === 502, 'Invalid JSON configuration fails connection test');
    check($transport->requests === [], 'Invalid explicit configuration never falls back to file or sends HTTP');
    check(!str_contains($settingsMarkup, 'synthetic@example.test'), 'Invalid JSON never displays fallback account');
}
echo "$count wp-config credential checks passed ($mode).\n";
