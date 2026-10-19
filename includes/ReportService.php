<?php
namespace OcularAcumen\Reports;

final class ReportService
{
    private GoogleClient $google;
    private array $settings;
    private const METRICS = ['screenPageViews', 'activeUsers', 'screenPageViewsPerUser', 'userEngagementDuration', 'eventCount'];
    private const PAGE_SIZE = 10000;
    private const MAX_ROWS = 50000;

    public function __construct(GoogleClient $google, array $settings)
    {
        $this->google = $google;
        $this->settings = $settings;
    }

    public function identity(): string
    {
        return hash('sha256', wp_json_encode([$this->settings['property'], $this->settings['timezone'], 'unifiedScreenName', 'all-users-v1']));
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
            if ($start === $previous['start'] && $end === $previous['end']) {
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
            'metrics' => array_map(static fn($name) => ['name' => $name], self::METRICS),
            'keepEmptyRows' => false,
        ];
    }

    private function fetch(string $start, string $end): array
    {
        $base = $this->base($start, $end);
        $pagesQuery = $base + [
            'dimensions' => [['name' => 'unifiedScreenName']],
            'orderBys' => [
                ['metric' => ['metricName' => 'screenPageViews'], 'desc' => true],
                ['dimension' => ['dimensionName' => 'unifiedScreenName'], 'desc' => false],
            ],
            'limit' => (string) self::PAGE_SIZE,
        ];
        $dailyQuery = [
            'dateRanges' => $base['dateRanges'], 'dimensions' => [['name' => 'date']],
            'metrics' => [['name' => 'screenPageViews']], 'limit' => '366',
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
            throw new \RuntimeException('This range contains more than 50,000 page titles. Choose a shorter date range.');
        }
        while (count($rawRows) < $rowCount) {
            $next = $this->google->batch([$pagesQuery + ['offset' => (string) count($rawRows)]]);
            $report = $next['reports'][0] ?? null;
            if (!is_array($report) || empty($report['rows']) || (int) ($report['rowCount'] ?? -1) !== $rowCount) {
                throw new \RuntimeException('Google returned incomplete or changing page results. Please try again.');
            }
            $this->metadata($report, $warnings);
            $this->headers($report, self::METRICS, 'unifiedScreenName');
            $rawRows = array_merge($rawRows, $report['rows']);
        }
        if (count($rawRows) !== $rowCount) {
            throw new \RuntimeException('The page count did not match the returned report.');
        }
        $this->headers($pagesReport, self::METRICS, 'unifiedScreenName');
        $this->headers($totalReport, self::METRICS);
        $this->headers($dailyReport, ['screenPageViews'], 'date');
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
        $totals = $totalsRows ? $this->values($totalsRows[0]) : [
            'views' => 0, 'users' => 0, 'viewsPerUser' => 0, 'engagementSeconds' => 0, 'events' => 0,
        ];
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
            // A successful complete GA4 query omits dates with zero recorded views.
            $daily[] = ['date' => $date, 'views' => $byDay[$date] ?? 0];
            $day = $day->modify('+1 day');
        }
        if (abs(array_sum(array_column($daily, 'views')) - $totals['views']) > 0.01) {
            $warnings[] = 'Google returned different totals for the daily chart and period summary. Each is shown as returned.';
        }
        return [
            'start' => $start, 'end' => $end, 'timezone' => $this->settings['timezone'],
            'fetchedAt' => time(), 'source' => 'Google Analytics 4', 'dimension' => 'Page title and screen name',
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
            $warnings[] = 'Google has grouped some page titles into an (other) row.';
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

    private function number($value): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            throw new \RuntimeException('Google returned an invalid metric value.');
        }
        return (float) $value;
    }

    private function values(array $row): array
    {
        if (count($row['metricValues'] ?? []) !== 5) {
            throw new \RuntimeException('Google returned incomplete metric values.');
        }
        $values = array_map(fn($v) => $this->number($v['value'] ?? null), $row['metricValues']);
        return [
            'views' => $values[0], 'users' => $values[1], 'viewsPerUser' => $values[2],
            'engagementSeconds' => $values[1] > 0 ? $values[3] / $values[1] : 0,
            'events' => $values[4],
        ];
    }
}
