<?php
// Synthetic test data only. Excluded from the release ZIP.
namespace OcularAcumen\Reports\Tests;

final class FixtureTransport
{
    public array $requests = [];
    public string $mode = 'success';
    public int $pageCount = 68;
    public string $timezone = 'America/New_York';

    public function __invoke($url, $options): array
    {
        $this->requests[] = [$url, $options];
        if ($this->mode === 'quota') {
            return $this->response(['error' => ['message' => 'DO NOT EXPOSE RAW PROVIDER CONTENT']], 429);
        }
        if ($url === 'https://oauth2.googleapis.com/token') {
            return $this->response(['access_token' => 'local-fixture-token', 'expires_in' => 3600]);
        }
        $body = json_decode($options['body'], true);
        $reports = [];
        foreach ($body['requests'] as $query) {
            $reports[] = $this->makeReport($query);
        }
        if ($this->mode === 'incomplete') {
            array_pop($reports);
        }
        return $this->response(['reports' => $reports]);
    }

    private function response(array $body, int $status = 200): array
    {
        return ['response' => ['code' => $status], 'headers' => [], 'body' => json_encode($body)];
    }

    private function trafficReport(array $query, array $report): array
    {
        $dimension = $query['dimensions'][0]['name'] ?? null;
        $metrics = [
            [100, 90, .9, 3000, 5, 500, 8, .05, 0],
            [60, 30, .5, 600, 2, 120, 3, .05, -12.5],
            [40, 10, .25, 400, 2, 80, 1, .025, 25],
        ];
        $total = [200, 130, .65, 4000, 3.5, 700, 12, .045, 12.5];
        $values = static fn($numbers) => array_map(static fn($v) => ['value' => (string) $v], $numbers);
        if ($dimension === 'date') {
            $start = new \DateTimeImmutable($query['dateRanges'][0]['startDate']);
            $days = (int) $start->diff(new \DateTimeImmutable($query['dateRanges'][0]['endDate']))->format('%a') + 1;
            $report['rows'] = [];
            for ($i = 0; $i < $days; $i++) {
                $report['rows'][] = ['dimensionValues' => [['value' => $start->modify('+' . $i . ' days')->format('Ymd')]], 'metricValues' => $values([intdiv(200, $days) + ($i < 200 % $days ? 1 : 0)])];
            }
        } elseif ($dimension) {
            $titles = $dimension === 'sessionSourceMedium' ? ['google / organic', '(direct) / (none)', 'example.test / referral'] : ['Organic Search', 'Direct', 'Referral'];
            $report['rows'] = [];
            foreach ($metrics as $i => $numbers) {
                $report['rows'][] = ['dimensionValues' => [['value' => $titles[$i]]], 'metricValues' => $values($numbers)];
            }
        } else {
            $report['rows'] = [['metricValues' => $values($total)]];
        }
        $report['rowCount'] = count($report['rows']);
        if ($this->mode === 'traffic-invalid-rate' && !$dimension) $report['rows'][0]['metricValues'][2]['value'] = '65';
        if ($this->mode === 'traffic-missing-currency') unset($report['metadata']['currencyCode']);
        return $report;
    }

    private function makeReport(array $query): array
    {
        $dimension = $query['dimensions'][0]['name'] ?? null;
        $headers = $query['metrics'];
        $report = [
            'dimensionHeaders' => $query['dimensions'] ?? [], 'metricHeaders' => $headers,
            'metadata' => ['timeZone' => $this->mode === 'timezone' ? 'UTC' : $this->timezone, 'currencyCode' => 'USD'],
        ];
        if ($this->mode === 'threshold') {
            $report['metadata']['subjectToThresholding'] = true;
        }
        if ($this->mode === 'restricted') {
            $report['metadata']['schemaRestrictionResponse']['activeMetricRestrictions'] = [['metricName' => 'activeUsers']];
        }
        if ($this->mode === 'empty') {
            return $report + ['rowCount' => 0];
        }
        if (($query['metrics'][0]['name'] ?? '') === 'sessions') {
            return $this->trafficReport($query, $report);
        }
        if ($this->pageCount > 50000) {
            return $report + ['rowCount' => $this->pageCount, 'rows' => []];
        }
        $titles = [
            'Home | Example Client', 'Locations | Example Client', 'Services | Example Client', 'Contact | Example Client',
            'About our team', 'News and resources', 'Careers', 'Frequently asked questions',
            'Servicios en español', '=HYPERLINK("https://example.invalid","test")', '<script>alert("test")</script>',
        ];
        $rows = [];
        $rangeDays = (int) (new \DateTimeImmutable($query['dateRanges'][0]['startDate']))->diff(new \DateTimeImmutable($query['dateRanges'][0]['endDate']))->format('%a') + 1;
        for ($i = 0; $i < $this->pageCount; $i++) {
            $views = $i === $this->pageCount - 1 ? 0 : max(1, (int) floor((850 - $i * 13) * $rangeDays / 30));
            $users = $views ? max(1, (int) floor($views / 1.5)) : 2;
            $rows[] = [
                'dimensionValues' => [['value' => $titles[$i] ?? 'Example page ' . ($i + 1)]],
                'metricValues' => array_map(static fn($v) => ['value' => (string) $v], [$views, $users, $views / $users, $users * (15 + $i % 50), max(2, $views * 3)]),
            ];
        }
        $totalViews = array_sum(array_map(static fn($row) => (int) $row['metricValues'][0]['value'], $rows));
        $totalEvents = array_sum(array_map(static fn($row) => (int) $row['metricValues'][4]['value'], $rows));
        if ($dimension === 'unifiedScreenName') {
            $offset = (int) ($query['offset'] ?? 0);
            $report['rowCount'] = count($rows);
            $report['rows'] = array_slice($rows, $offset, (int) ($query['limit'] ?? 10000));
        } elseif ($dimension === 'date') {
            $start = new \DateTimeImmutable($query['dateRanges'][0]['startDate']);
            $end = new \DateTimeImmutable($query['dateRanges'][0]['endDate']);
            $count = (int) $start->diff($end)->format('%a') + 1;
            $base = (int) floor($totalViews / $count);
            $remainder = $totalViews % $count;
            $report['rows'] = [];
            for ($i = 0; $i < $count; $i++) {
                $value = $base + ($i < $remainder ? 1 : 0);
                // Deterministic variation preserves the full-period total.
                if ($i + 1 < $count && $i % 2 === 0) $value += 80;
                if ($i % 2 === 1) $value -= 80;
                $report['rows'][] = ['dimensionValues' => [['value' => $start->modify('+' . $i . ' days')->format('Ymd')]], 'metricValues' => [['value' => (string) max(0, $value)]]];
            }
            $report['rowCount'] = count($report['rows']);
        } else {
            $activeUsers = max(1, (int) floor(9500 * $rangeDays / 30));
            $report['rowCount'] = 1;
            $report['rows'] = [['metricValues' => array_map(static fn($v) => ['value' => (string) $v], [$totalViews, $activeUsers, $totalViews / $activeUsers, $activeUsers * 37, $totalEvents])]];
        }
        if ($this->mode === 'invalid' && isset($report['rows'][0]['metricValues'][0])) {
            $report['rows'][0]['metricValues'][0]['value'] = '-1';
        }
        if ($this->mode === 'partial-daily' && $dimension === 'date') {
            array_pop($report['rows']);
        }
        if ($this->mode === 'duplicate-title' && $dimension === 'unifiedScreenName') {
            $report['rows'][1]['dimensionValues'] = $report['rows'][0]['dimensionValues'];
        }
        return $report;
    }
}
