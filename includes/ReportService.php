<?php
namespace OcularAcumen\Reports;

final class ReportService
{
    private GoogleClient $google;
    private array $settings;
    private string $type;
    private string $dimension;
    private string $dimensionLabel;
    private array $metrics;
    private string $dailyMetric;
    private string $dailyField;
    private const METRICS = ['screenPageViews', 'activeUsers', 'screenPageViewsPerUser', 'userEngagementDuration', 'eventCount'];
    private const TRAFFIC_METRICS = ['sessions', 'engagedSessions', 'engagementRate', 'userEngagementDuration', 'eventsPerSession', 'eventCount', 'keyEvents', 'sessionKeyEventRate', 'totalRevenue'];
    private const PAGE_SIZE = 10000;
    private const MAX_ROWS = 50000;

    public function __construct(GoogleClient $google, array $settings, string $type = 'pages', string $group = 'channel')
    {
        if (!in_array($type, ['pages', 'traffic'], true) || !in_array($group, ['channel', 'sourceMedium'], true)) {
            throw new \InvalidArgumentException('Choose a supported report and grouping.');
        }
        $this->google = $google;
        $this->settings = $settings;
        $this->type = $type;
        $this->dimension = $type === 'pages' ? 'unifiedScreenName' : ($group === 'channel' ? 'sessionDefaultChannelGroup' : 'sessionSourceMedium');
        $this->dimensionLabel = $type === 'pages' ? 'Page title and screen name' : ($group === 'channel' ? 'Session default channel group' : 'Session source / medium');
        $this->metrics = $type === 'pages' ? self::METRICS : self::TRAFFIC_METRICS;
        $this->dailyMetric = $type === 'pages' ? 'screenPageViews' : 'sessions';
        $this->dailyField = $type === 'pages' ? 'views' : 'sessions';
    }

    public function identity(): string
    {
        return hash('sha256', wp_json_encode([$this->settings['property'], $this->settings['timezone'], $this->dimension, $this->metrics, 'all-users-v2']));
    }

    public function report(string $start, string $end, bool $force = false): array
    {
        Dates::validate($start, $end, $this->settings['timezone']);
        $key = 'oa_reports_data_' . hash('sha256', $this->identity() . $start . $end);
        $cached = get_transient($key);
        $latest = get_option('oa_reports_latest_month', []);
        if (!is_array($cached) && ($latest['identity'] ?? '') === $this->identity()
            && ($latest['data']['start'] ?? '') === $start && ($latest['data']['end'] ?? '') === $end) {
            $cached = $latest['data'];
        }
        if (!$force && is_array($cached) && ($cached['fetchedAt'] ?? 0) > time() - 21600) {
            return $cached + ['stale' => false];
        }
        $cooldown = 'oa_reports_cooldown_' . $this->identity();
        $failure = get_transient($cooldown);
        if ($failure) {
            if (is_array($cached)) {
                return $cached + ['stale' => true, 'notice' => 'Google could not be refreshed recently. Showing the last successful report for these dates. Try again in a minute.'];
            }
            throw new \RuntimeException('Google could not be refreshed recently. Please try again in a minute.');
        }
        $lock = 'oa_reports_lock_' . $this->identity();
        // add_option uses the unique option_name index, making acquisition atomic across requests.
        $lockedAt = get_option($lock, 0);
        if ($lockedAt && (int) $lockedAt < time() - 240) {
            delete_option($lock);
        }
        $ownsLock = add_option($lock, time(), '', false);
        if (!$ownsLock) {
            if (is_array($cached)) {
                return $cached + ['stale' => true, 'notice' => 'An update is running. Showing the last successful report for these dates.'];
            }
            throw new \RuntimeException('Another report is loading. Please try again in a moment.');
        }
        try {
            $data = $this->fetch($start, $end);
            set_transient($key, $data, 30 * DAY_IN_SECONDS);
            $previous = Dates::previousMonth($this->settings['timezone']);
            if ($this->type === 'pages' && $start === $previous['start'] && $end === $previous['end']) {
                update_option('oa_reports_latest_month', ['identity' => $this->identity(), 'data' => $data], false);
            }
            return $data + ['stale' => false];
        } catch (\RuntimeException $e) {
            set_transient($cooldown, true, 60);
            if (is_array($cached)) {
                return $cached + ['stale' => true, 'notice' => $e->getMessage() . ' Showing the last successful report for these dates.'];
            }
            throw $e;
        } finally {
            delete_option($lock);
        }
    }

    private function base(string $start, string $end): array
    {
        return [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'metrics' => array_map(static fn($name) => ['name' => $name], $this->metrics),
            'keepEmptyRows' => false,
        ];
    }

    private function fetch(string $start, string $end): array
    {
        $base = $this->base($start, $end);
        $pagesQuery = $base + [
            'dimensions' => [['name' => $this->dimension]],
            'orderBys' => [
                ['metric' => ['metricName' => $this->dailyMetric], 'desc' => true],
                ['dimension' => ['dimensionName' => $this->dimension], 'desc' => false],
            ],
            'limit' => (string) self::PAGE_SIZE,
        ];
        $dailyQuery = [
            'dateRanges' => $base['dateRanges'], 'dimensions' => [['name' => 'date']],
            'metrics' => [['name' => $this->dailyMetric]], 'limit' => '366',
            'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
        ];
        $response = $this->google->batch([$pagesQuery, $base, $dailyQuery]);
        if (count($response['reports'] ?? []) !== 3) {
            throw new \RuntimeException('Google returned an incomplete report. No saved report was replaced.');
        }
        [$pagesReport, $totalReport, $dailyReport] = $response['reports'];
        $warnings = [];
        foreach ($response['reports'] as $report) {
            $this->metadata($report, $warnings);
        }
        $rawRows = $pagesReport['rows'] ?? [];
        $rowCount = (int) ($pagesReport['rowCount'] ?? 0);
        if ($rowCount > self::MAX_ROWS) {
            throw new \RuntimeException('This range contains more than 50,000 rows. Choose a shorter date range.');
        }
        while (count($rawRows) < $rowCount) {
            $next = $this->google->batch([$pagesQuery + ['offset' => (string) count($rawRows)]]);
            $report = $next['reports'][0] ?? null;
            if (!is_array($report) || empty($report['rows']) || (int) ($report['rowCount'] ?? -1) !== $rowCount) {
                throw new \RuntimeException('Google returned incomplete or changing page results. Please try again.');
            }
            $this->metadata($report, $warnings);
            $this->headers($report, $this->metrics, $this->dimension);
            $rawRows = array_merge($rawRows, $report['rows']);
        }
        if (count($rawRows) !== $rowCount) {
            throw new \RuntimeException('The page count did not match the returned report.');
        }
        $this->headers($pagesReport, $this->metrics, $this->dimension);
        $this->headers($totalReport, $this->metrics);
        $this->headers($dailyReport, [$this->dailyMetric], 'date');
        $currency = (string) ($totalReport['metadata']['currencyCode'] ?? '');
        if ($this->type === 'traffic' && !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \RuntimeException('Google did not identify the reporting currency. Please try again.');
        }
        $rows = [];
        $seenTitles = [];
        foreach ($rawRows as $row) {
            if (!isset($row['dimensionValues'][0]['value'])) {
                throw new \RuntimeException('Google returned a page without its title.');
            }
            $title = (string) $row['dimensionValues'][0]['value'];
            if (isset($seenTitles[$title])) {
                throw new \RuntimeException('Google returned duplicate page rows. Please try again.');
            }
            $seenTitles[$title] = true;
            $rows[] = ['title' => $title] + $this->values($row);
        }
        $totalsRows = $totalReport['rows'] ?? [];
        if (count($totalsRows) > 1 || (int) ($totalReport['rowCount'] ?? 0) !== count($totalsRows) || (!$totalsRows && $rowCount > 0)) {
            throw new \RuntimeException('Google returned incomplete period totals.');
        }
        $totals = $this->values($totalsRows[0] ?? ['metricValues' => array_fill(0, count($this->metrics), ['value' => '0'])]);
        $daily = [];
        $byDay = [];
        if ((int) ($dailyReport['rowCount'] ?? 0) !== count($dailyReport['rows'] ?? [])) {
            throw new \RuntimeException('Google returned incomplete daily chart results.');
        }
        foreach (($dailyReport['rows'] ?? []) as $row) {
            $date = (string) ($row['dimensionValues'][0]['value'] ?? '');
            if (!preg_match('/^[0-9]{8}$/D', $date)) {
                throw new \RuntimeException('Google returned an invalid chart date.');
            }
            $date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
            Dates::day($date, Dates::timezone($this->settings['timezone']));
            if ($date < $start || $date > $end || isset($byDay[$date])) {
                throw new \RuntimeException('Google returned unexpected chart dates.');
            }
            $byDay[$date] = $this->number($row['metricValues'][0]['value'] ?? null);
        }
        $day = Dates::day($start, Dates::timezone($this->settings['timezone']));
        while ($day->format('Y-m-d') <= $end) {
            $date = $day->format('Y-m-d');
            // A successful complete GA4 query omits dates with zero recorded activity.
            $daily[] = ['date' => $date, $this->dailyField => $byDay[$date] ?? 0];
            $day = $day->modify('+1 day');
        }
        if (abs(array_sum(array_column($daily, $this->dailyField)) - $totals[$this->dailyField]) > 0.01) {
            $warnings[] = 'Google returned different totals for the daily chart and period summary. Each is shown as returned.';
        }
        return [
            'start' => $start, 'end' => $end, 'timezone' => $this->settings['timezone'],
            'fetchedAt' => time(), 'source' => 'Google Analytics 4', 'dimension' => $this->dimensionLabel,
            'type' => $this->type, 'dimensionApi' => $this->dimension, 'currency' => $currency,
            'totals' => $totals, 'rows' => $rows, 'daily' => $daily,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function metadata(array $report, array &$warnings): void
    {
        $meta = $report['metadata'] ?? [];
        if (($meta['timeZone'] ?? '') !== $this->settings['timezone']) {
            throw new \RuntimeException('The configured reporting timezone does not match the GA4 property. Update Client Reports settings to ' . sanitize_text_field($meta['timeZone'] ?? 'the property timezone') . '.');
        }
        if (!empty($meta['subjectToThresholding'])) {
            $warnings[] = 'Google may withhold some results because of reporting thresholds.';
        }
        if (!empty($meta['dataLossFromOtherRow'])) {
            $warnings[] = 'Google has grouped some results into an (other) row.';
        }
        if (!empty($meta['samplingMetadatas'])) {
            $warnings[] = 'Google returned sampled data for this date range.';
        }
        if (!empty($meta['schemaRestrictionResponse']['activeMetricRestrictions'])) {
            throw new \RuntimeException('Google access restrictions prevent this account from reading all required report metrics.');
        }
    }

    private function headers(array $report, array $metrics, ?string $dimension = null): void
    {
        if (array_column($report['metricHeaders'] ?? [], 'name') !== $metrics
            || array_column($report['dimensionHeaders'] ?? [], 'name') !== ($dimension ? [$dimension] : [])) {
            throw new \RuntimeException('Google returned unexpected report fields.');
        }
    }

    private function number($value, bool $allowNegative = false): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (!$allowNegative && (float) $value < 0)) {
            throw new \RuntimeException('Google returned an invalid metric value.');
        }
        return (float) $value;
    }

    private function values(array $row): array
    {
        if (count($row['metricValues'] ?? []) !== count($this->metrics)) {
            throw new \RuntimeException('Google returned incomplete metric values.');
        }
        $values = [];
        foreach ($row['metricValues'] as $index => $value) {
            $values[] = $this->number($value['value'] ?? null, $this->metrics[$index] === 'totalRevenue');
        }
        if ($this->type === 'traffic') {
            if ($values[2] > 1 || $values[7] > 1) {
                throw new \RuntimeException('Google returned an invalid session rate.');
            }
            return [
                'sessions' => $values[0], 'engagedSessions' => $values[1], 'engagementRate' => $values[2],
                'engagementSeconds' => $values[0] > 0 ? $values[3] / $values[0] : 0,
                'eventsPerSession' => $values[4], 'events' => $values[5], 'keyEvents' => $values[6],
                'sessionKeyEventRate' => $values[7], 'revenue' => $values[8],
            ];
        }
        return [
            'views' => $values[0], 'users' => $values[1], 'viewsPerUser' => $values[2],
            'engagementSeconds' => $values[1] > 0 ? $values[3] / $values[1] : 0,
            'events' => $values[4],
        ];
    }
}
