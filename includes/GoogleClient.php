<?php
namespace OcularAcumen\Reports;

class GoogleClient
{
    private string $credentialFile;
    private ?string $credentialJson;
    private string $property;
    private $http;

    public function __construct(string $credentialFile, string $property, ?callable $http = null, ?string $credentialJson = null)
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $property)) {
            throw new \RuntimeException('Enter the numeric GA4 property ID in Client Reports settings.');
        }
        $this->credentialFile = $credentialFile;
        $this->credentialJson = $credentialJson;
        $this->property = $property;
        $this->http = $http ?? 'wp_remote_post';
    }

    public static function credentials(string $path, ?string $json = null): array
    {
        // An explicitly configured JSON value takes precedence, including invalid values.
        // Never silently fall back to a different account when configuration is broken.
        $json = $json ?? self::readCredentialFile($path);
        if ($json === '' || strlen($json) > 32768) {
            throw new \RuntimeException('The Google service account JSON must be nonempty and no larger than 32 KB.');
        }
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['type'] ?? '') !== 'service_account'
            || !is_string($data['client_email'] ?? null)
            || !filter_var($data['client_email'], FILTER_VALIDATE_EMAIL)
            || !is_string($data['private_key'] ?? null)
            || !str_contains($data['private_key'], 'PRIVATE KEY')) {
            throw new \RuntimeException('The Google credential is not a valid service account JSON key.');
        }
        // Never trust token_uri or other outbound URLs supplied in credential JSON.
        return $data;
    }

    private static function readCredentialFile(string $path): string
    {
        $real = $path !== '' ? realpath($path) : false;
        if (!$real || !is_file($real) || !is_readable($real) || filesize($real) > 32768) {
            throw new \RuntimeException('The server credential file is missing or unreadable. Ask your website administrator to finish setup.');
        }
        // Resolve symlinks before checking every known public filesystem root.
        $roots = [ABSPATH, WP_CONTENT_DIR, $_SERVER['DOCUMENT_ROOT'] ?? ''];
        foreach ($roots as $root) {
            $resolved = $root !== '' ? realpath($root) : false;
            $configured = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            $lexicalRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
            if (($lexicalRoot !== '' && ($configured === $lexicalRoot || str_starts_with($configured, $lexicalRoot . DIRECTORY_SEPARATOR)))
                || ($resolved && ($real === $resolved || str_starts_with($real, rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)))) {
                throw new \RuntimeException('Store the Google credential file outside all public website directories.');
            }
        }
        return (string) file_get_contents($real);
    }

    private static function b64(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    private function token(): string
    {
        $credentials = self::credentials($this->credentialFile, $this->credentialJson);
        $key = 'oa_reports_token_' . hash('sha256', $credentials['client_email'] . $credentials['private_key']);
        $cached = get_transient($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        if (!function_exists('openssl_sign')) {
            throw new \RuntimeException('The server needs the PHP OpenSSL extension to connect to Google Analytics.');
        }
        $now = time();
        $claims = [
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now - 30,
            'exp' => $now + 3300,
        ];
        $unsigned = self::b64('{"alg":"RS256","typ":"JWT"}') . '.' . self::b64(wp_json_encode($claims));
        $signature = '';
        if (!@openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Google authentication could not be signed. Check the configured service account key.');
        }
        $response = $this->request('https://oauth2.googleapis.com/token', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . self::b64($signature)],
        ]);
        if (!is_string($response['access_token'] ?? null) || empty($response['expires_in'])) {
            throw new \RuntimeException('Google did not return a usable authentication token.');
        }
        set_transient($key, $response['access_token'], max(60, min(3000, (int) $response['expires_in'] - 120)));
        return $response['access_token'];
    }

    private function request(string $url, array $options): array
    {
        $response = ($this->http)($url, $options + [
            'timeout' => 25, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 16777216,
        ]);
        if (is_wp_error($response)) {
            throw new \RuntimeException('Google Analytics could not be reached. Please try again later.');
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $messages = [
                400 => 'Google rejected the report or authentication request. Check the property and service account configuration.',
                401 => 'Google authentication was rejected. Check the service account key and server clock.',
                403 => 'Google access was denied. Enable the Analytics Data API and grant this service account Viewer access to the selected property.',
                404 => 'Google could not find the configured Analytics property.',
                429 => 'Google Analytics is temporarily limiting requests. Please try again later.',
            ];
            throw new \RuntimeException($messages[$status] ?? 'Google Analytics is temporarily unavailable. Please try again later.');
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Google Analytics returned an unreadable response.');
        }
        return $data;
    }

    public function batch(array $requests): array
    {
        return $this->request('https://analyticsdata.googleapis.com/v1beta/properties/' . $this->property . ':batchRunReports', [
            'headers' => ['Authorization' => 'Bearer ' . $this->token(), 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['requests' => $requests]),
        ]);
    }
}
