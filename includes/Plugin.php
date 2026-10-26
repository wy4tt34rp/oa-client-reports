<?php
namespace OcularAcumen\Reports;

final class Plugin
{
    public const CAPABILITY = 'oa_view_client_reports';
    public const OPTION = 'oa_reports_settings';
    public const CRON = 'oa_reports_monthly_refresh';

    public static function boot(): void
    {
        add_shortcode('oa_client_reports', [self::class, 'shortcode']);
        add_action('admin_menu', [self::class, 'menus']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_enqueue_scripts', [self::class, 'adminAssets']);
        add_action('wp_enqueue_scripts', [self::class, 'frontendAssets']);
        add_action('admin_post_oa_reports_create_page', [self::class, 'createPage']);
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('rest_post_dispatch', [self::class, 'privateResponse'], 10, 3);
        add_action('template_redirect', [self::class, 'protectPage'], 0);
        add_action(self::CRON, [self::class, 'refreshMonth']);
        add_filter('plugin_action_links_' . plugin_basename(OA_REPORTS_FILE), static function ($links) {
            array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=oa-client-reports-settings')) . '">Settings</a>');
            return $links;
        });
    }

    public static function activate(): void
    {
        add_role('oa_report_viewer', 'Client Report Viewer', ['read' => true, self::CAPABILITY => true]);
        $viewer = get_role('oa_report_viewer');
        if ($viewer) {
            $viewer->add_cap(self::CAPABILITY);
        }
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(self::CAPABILITY);
        }
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 300, 'daily', self::CRON);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    public static function settings(): array
    {
        return array_merge([
            'property' => '', 'timezone' => wp_timezone_string() ?: 'UTC',
            'client_name' => get_bloginfo('name'), 'accent' => '#126d72', 'logo_id' => 0, 'page_id' => 0,
        ], (array) get_option(self::OPTION, []));
    }

    public static function credentialFile(): string
    {
        return defined('OA_REPORTS_CREDENTIALS_FILE') && is_string(OA_REPORTS_CREDENTIALS_FILE) ? OA_REPORTS_CREDENTIALS_FILE : '';
    }

    public static function credentialJson(): ?string
    {
        if (!defined('OA_REPORTS_CREDENTIALS_JSON')) {
            return null;
        }
        if (!is_string(OA_REPORTS_CREDENTIALS_JSON)) {
            throw new \RuntimeException('OA_REPORTS_CREDENTIALS_JSON in wp-config.php must contain the service account JSON as a string.');
        }
        return OA_REPORTS_CREDENTIALS_JSON;
    }

    public static function hasCredentials(): bool
    {
        return defined('OA_REPORTS_CREDENTIALS_JSON') || self::credentialFile() !== '';
    }

    public static function service(string $type = 'pages', string $group = 'channel'): ReportService
    {
        $settings = self::settings();
        return new ReportService(new GoogleClient(self::credentialFile(), $settings['property'], null, self::credentialJson()), $settings, $type, $group);
    }

    public static function menus(): void
    {
        add_management_page('Website reports', 'Client Reports', self::CAPABILITY, 'oa-client-reports', static function () {
            echo '<div class="wrap">' . self::shortcode() . '</div>';
        });
        add_options_page('Client Reports settings', 'Client Reports', 'manage_options', 'oa-client-reports-settings', [self::class, 'settingsPage']);
    }

    public static function registerSettings(): void
    {
        register_setting('oa_reports', self::OPTION, ['type' => 'array', 'sanitize_callback' => [self::class, 'sanitizeSettings']]);
    }

    public static function sanitizeSettings($input): array
    {
        $old = self::settings();
        if (!is_array($input)) {
            return $old;
        }
        $new = $old;
        $property = sanitize_text_field($input['property'] ?? '');
        if ($property === '' || preg_match('/^[1-9][0-9]{0,19}$/D', $property)) {
            $new['property'] = $property;
        } else {
            add_settings_error(self::OPTION, 'property', 'Use the numeric GA4 property ID, not a G- measurement ID.');
        }
        try {
            $timezone = sanitize_text_field($input['timezone'] ?? '');
            Dates::timezone($timezone);
            $new['timezone'] = $timezone;
        } catch (\InvalidArgumentException $e) {
            add_settings_error(self::OPTION, 'timezone', $e->getMessage());
        }
        $new['client_name'] = sanitize_text_field($input['client_name'] ?? '') ?: get_bloginfo('name');
        $new['accent'] = sanitize_hex_color($input['accent'] ?? '') ?: $old['accent'];
        $logo = absint($input['logo_id'] ?? 0);
        $new['logo_id'] = $logo && wp_attachment_is_image($logo) ? $logo : 0;
        // The managed page ID is not editable through submitted settings.
        return $new;
    }

    public static function adminAssets(string $hook): void
    {
        if ($hook === 'tools_page_oa-client-reports') {
            self::assets();
        }
        if ($hook === 'settings_page_oa-client-reports-settings') {
            wp_enqueue_media();
            wp_enqueue_script('oa-reports-admin', plugins_url('assets/admin.js', OA_REPORTS_FILE), ['jquery'], OA_REPORTS_VERSION, true);
        }
    }

    public static function assets(): void
    {
        wp_enqueue_style('oa-client-reports', plugins_url('assets/report.css', OA_REPORTS_FILE), [], OA_REPORTS_VERSION);
        wp_enqueue_script('oa-report-utils', plugins_url('assets/report-utils.js', OA_REPORTS_FILE), [], OA_REPORTS_VERSION, true);
        wp_enqueue_script('oa-client-reports', plugins_url('assets/report.js', OA_REPORTS_FILE), ['oa-report-utils'], OA_REPORTS_VERSION, true);
    }

    public static function frontendAssets(): void
    {
        $post = get_queried_object();
        if ($post instanceof \WP_Post && (has_shortcode($post->post_content, 'oa_client_reports') || $post->ID === (int) self::settings()['page_id'])) {
            self::assets();
        }
    }

    public static function settingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = self::settings();
        ?>
        <div class="wrap">
            <h1>Client Reports settings</h1>
            <p>Connect this website to its Google Analytics property and choose how the client report looks.</p>
            <?php settings_errors(self::OPTION); ?>
            <form method="post" action="options.php">
                <?php settings_fields('oa_reports'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="oa-client">Client name</label></th><td><input class="regular-text" id="oa-client" name="oa_reports_settings[client_name]" value="<?php echo esc_attr($settings['client_name']); ?>" required></td></tr>
                    <tr><th scope="row"><label for="oa-property">GA4 property ID</label></th><td><input id="oa-property" name="oa_reports_settings[property]" inputmode="numeric" pattern="[1-9][0-9]*" value="<?php echo esc_attr($settings['property']); ?>"><p class="description">The numeric property ID in Google Analytics Admin &gt; Property details.</p></td></tr>
                    <tr><th scope="row"><label for="oa-timezone">Reporting timezone</label></th><td><input class="regular-text" id="oa-timezone" name="oa_reports_settings[timezone]" value="<?php echo esc_attr($settings['timezone']); ?>" required><p class="description">Use the exact GA4 timezone, for example America/New_York. The connection test checks it.</p></td></tr>
                    <tr><th scope="row"><label for="oa-accent">Accent color</label></th><td><input id="oa-accent" type="color" name="oa_reports_settings[accent]" value="<?php echo esc_attr($settings['accent']); ?>"></td></tr>
                    <tr><th scope="row">Client logo</th><td>
                        <input id="oa-logo-id" type="hidden" name="oa_reports_settings[logo_id]" value="<?php echo esc_attr($settings['logo_id']); ?>">
                        <div id="oa-logo-preview"><?php if ($settings['logo_id']) { echo wp_get_attachment_image($settings['logo_id'], 'thumbnail'); } ?></div>
                        <button class="button" id="oa-choose-logo" type="button">Choose logo</button>
                        <button class="button" id="oa-remove-logo" type="button">Remove logo</button>
                    </td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2>Google connection</h2>
            <?php
            try {
                $credentials = GoogleClient::credentials(self::credentialFile(), self::credentialJson());
                echo '<p>Credential source: ' . (defined('OA_REPORTS_CREDENTIALS_JSON') ? '<code>wp-config.php</code> JSON constant' : 'private server file') . '.</p>';
                echo '<p>Service account: <code>' . esc_html($credentials['client_email']) . '</code></p>';
                unset($credentials);
            } catch (\RuntimeException $e) {
                echo '<p>' . esc_html($e->getMessage()) . '</p>';
            }
            ?>
            <p>Enable the Google Analytics Data API and give the service account <strong>Viewer</strong> access to this property only.</p>
            <p>In <code>wp-config.php</code>, define <code>OA_REPORTS_CREDENTIALS_JSON</code> with the complete service account JSON key. See the plugin README for the copy-and-paste format. Alternatively, define <code>OA_REPORTS_CREDENTIALS_FILE</code> as the absolute path to a JSON key outside all public website directories. The JSON constant takes precedence. No key is uploaded or stored through this screen.</p>
            <p><button class="button" id="oa-test-connection" type="button" data-url="<?php echo esc_url(rest_url('oa-reports/v1/test')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>">Test saved connection</button> <span id="oa-test-status" role="status" aria-live="polite"></span></p>
            <h2>Client access</h2>
            <p>Assign approved WordPress users the <strong>Client Report Viewer</strong> role in Users. Administrators can also view reports. Existing editors and subscribers do not receive access automatically.</p>
            <p>The report is available under <a href="<?php echo esc_url(admin_url('tools.php?page=oa-client-reports')); ?>">Tools &gt; Client Reports</a>, or on a website page containing <code>[oa_client_reports]</code>.</p>
            <?php if ($settings['page_id'] && get_post_status($settings['page_id'])) : ?>
                <p><a class="button" href="<?php echo esc_url(get_permalink($settings['page_id'])); ?>">Open website report</a></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="oa_reports_create_page">
                    <?php wp_nonce_field('oa_reports_create_page'); ?>
                    <?php submit_button('Create website report page', 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>
            <p>Exclude the report page and <code>/wp-json/oa-reports/</code> from any full-page or CDN cache.</p>
            <h2>Monthly refresh</h2>
            <p>The plugin checks last month's report daily from the 2nd through the 8th. WordPress cron runs when the website receives traffic. A host cron that runs WordPress due events is recommended for reliable timing.</p>
            <?php $status = get_option('oa_reports_refresh_status', []); if (!empty($status['message'])) : ?>
                <p>Last check: <?php echo esc_html(wp_date('M j, Y g:i a', (int) $status['at'])); ?>. <?php echo esc_html($status['message']); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function createPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to create the report page.', '', ['response' => 403]);
        }
        check_admin_referer('oa_reports_create_page');
        $settings = self::settings();
        if (!$settings['page_id'] || !get_post_status($settings['page_id'])) {
            $existing = get_page_by_path('website-report');
            $id = $existing && has_shortcode($existing->post_content, 'oa_client_reports') ? $existing->ID : wp_insert_post([
                'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Website report',
                'post_name' => 'website-report', 'post_content' => '[oa_client_reports]',
            ], true);
            if (is_wp_error($id)) {
                wp_die('The report page could not be created. Please try again.');
            }
            $settings['page_id'] = (int) $id;
            update_option(self::OPTION, $settings, false);
        }
        wp_safe_redirect(admin_url('options-general.php?page=oa-client-reports-settings'));
        exit;
    }

    public static function protectPage(): void
    {
        $post = get_queried_object();
        if ($post instanceof \WP_Post && (has_shortcode($post->post_content, 'oa_client_reports') || $post->ID === (int) self::settings()['page_id'])) {
            self::noCache();
        }
    }

    private static function noCache(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        do_action('litespeed_control_set_nocache', 'Private client report');
        if (!headers_sent()) {
            nocache_headers();
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        }
    }

    public static function shortcode(): string
    {
        self::noCache();
        if (!current_user_can(self::CAPABILITY)) {
            if (is_user_logged_in()) {
                return '<p>Your account does not have access to this report. Please contact your website administrator.</p>';
            }
            return '<p>Please <a href="' . esc_url(wp_login_url(get_permalink() ?: home_url('/'))) . '">sign in</a> to view this website report.</p>';
        }
        self::assets();
        $settings = self::settings();
        // Also support shortcodes stored in builder content discovered after wp_head.
        ob_start();
        wp_print_styles('oa-client-reports');
        $styles = (string) ob_get_clean();
        return $styles . Renderer::html([
            'client' => $settings['client_name'],
            'accent' => $settings['accent'],
            'logo' => $settings['logo_id'] ? wp_get_attachment_image_url($settings['logo_id'], 'medium') : '',
            'endpoint' => rest_url('oa-reports/v1/report'),
            'nonce' => wp_create_nonce('wp_rest'),
            'timezone' => $settings['timezone'],
            'dates' => Dates::previousMonth($settings['timezone']),
            'quarter' => Dates::previousQuarter($settings['timezone']),
            'yesterday' => (new \DateTimeImmutable('yesterday', Dates::timezone($settings['timezone'])))->format('Y-m-d'),
            'configured' => $settings['property'] !== '' && self::hasCredentials(),
        ]);
    }

    public static function permission($request)
    {
        if (!is_user_logged_in() || !current_user_can(self::CAPABILITY)) {
            return new \WP_Error('oa_reports_forbidden', 'You do not have permission to view this report.', ['status' => 403]);
        }
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return new \WP_Error('oa_reports_nonce', 'Your session has expired. Reload this page and sign in again.', ['status' => 403]);
        }
        return true;
    }

    public static function routes(): void
    {
        register_rest_route('oa-reports/v1', '/report', [
            'methods' => 'POST', 'permission_callback' => [self::class, 'permission'],
            'callback' => [self::class, 'reportResponse'],
            'args' => [
                'reportType' => ['type' => 'string', 'default' => 'pages', 'enum' => ['pages', 'traffic']],
                'group' => ['type' => 'string', 'default' => 'channel', 'enum' => ['channel', 'sourceMedium']],
                'start' => ['required' => true, 'type' => 'string', 'validate_callback' => static fn($v) => is_string($v) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $v)],
                'end' => ['required' => true, 'type' => 'string', 'validate_callback' => static fn($v) => is_string($v) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $v)],
            ],
        ]);
        register_rest_route('oa-reports/v1', '/test', [
            'methods' => 'POST',
            'permission_callback' => static function ($request) {
                if (!current_user_can('manage_options')) {
                    return new \WP_Error('oa_reports_forbidden', 'Administrator access is required.', ['status' => 403]);
                }
                return self::permission($request);
            },
            'callback' => static function () {
                try {
                    $dates = Dates::previousMonth(self::settings()['timezone']);
                    $data = self::service()->report($dates['start'], $dates['end'], true);
                    if (!empty($data['stale'])) {
                        throw new \RuntimeException($data['notice']);
                    }
                    return rest_ensure_response(['message' => 'Connected. Property access, timezone, and report fields verified.']);
                } catch (\Throwable $e) {
                    return new \WP_Error('oa_reports_connection', self::safeError($e), ['status' => 502]);
                }
            },
        ]);
    }

    private static function safeError(\Throwable $e): string
    {
        return $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException
            ? $e->getMessage() : 'The report could not be loaded. Please contact your website administrator.';
    }

    public static function reportResponse($request)
    {
        try {
            return rest_ensure_response(self::service($request['reportType'] ?? 'pages', $request['group'] ?? 'channel')->report($request['start'], $request['end']));
        } catch (\Throwable $e) {
            return new \WP_Error('oa_reports_unavailable', self::safeError($e), ['status' => $e instanceof \InvalidArgumentException ? 400 : 502]);
        }
    }

    public static function privateResponse($response, $server, $request)
    {
        if (str_starts_with($request->get_route(), '/oa-reports/')) {
            $response->header('Cache-Control', 'private, no-store, max-age=0');
            $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $response->header('Vary', 'Cookie');
        }
        return $response;
    }

    public static function refreshMonth(): void
    {
        $settings = self::settings();
        if ($settings['property'] === '' || !self::hasCredentials()) {
            return;
        }
        $day = (int) (new \DateTimeImmutable('now', Dates::timezone($settings['timezone'])))->format('j');
        if ($day < 2 || $day > 8) {
            return;
        }
        try {
            $dates = Dates::previousMonth($settings['timezone']);
            $data = self::service()->report($dates['start'], $dates['end'], true);
            $message = !empty($data['stale']) ? $data['notice'] : 'Previous month refreshed successfully.';
        } catch (\Throwable $e) {
            $message = self::safeError($e);
        }
        update_option('oa_reports_refresh_status', ['at' => time(), 'message' => $message], false);
    }
}
