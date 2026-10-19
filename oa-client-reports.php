<?php
/**
 * Plugin Name: Ocular Acumen Client Reports
 * Description: Private GA4 page reports with date selection, client branding, CSV export, and report-only WordPress access.
 * Version: 1.0.1
 * Author: Ocular Acumen
 * Author URI: https://ocularacumen.com/
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * Text Domain: oa-client-reports
 * Update URI: https://ocularacumen.com/plugins/oa-client-reports/
 */

defined('ABSPATH') || exit;
define('OA_REPORTS_VERSION', '1.0.1');
define('OA_REPORTS_FILE', __FILE__);
foreach (['Dates', 'GoogleClient', 'ReportService', 'Renderer', 'Plugin'] as $oa_reports_class) {
    require_once __DIR__ . '/includes/' . $oa_reports_class . '.php';
}
unset($oa_reports_class);
register_activation_hook(__FILE__, ['OcularAcumen\Reports\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['OcularAcumen\Reports\Plugin', 'deactivate']);
add_action('plugins_loaded', ['OcularAcumen\Reports\Plugin', 'boot']);
