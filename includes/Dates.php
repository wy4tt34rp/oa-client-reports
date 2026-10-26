<?php
namespace OcularAcumen\Reports;

final class Dates
{
    public static function timezone(string $name): \DateTimeZone
    {
        try {
            return new \DateTimeZone($name);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Choose a valid reporting timezone.');
        }
    }

    public static function day(string $value, \DateTimeZone $timezone): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (!$day || $day->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Enter valid dates in YYYY-MM-DD format.');
        }
        return $day;
    }

    public static function validate(string $start, string $end, string $timezone, ?\DateTimeImmutable $now = null): array
    {
        $zone = self::timezone($timezone);
        $first = self::day($start, $zone);
        $last = self::day($end, $zone);
        $today = ($now ?? new \DateTimeImmutable('now', $zone))->setTimezone($zone)->setTime(0, 0);
        if ($first > $last) {
            throw new \InvalidArgumentException('The start date must be on or before the end date.');
        }
        if ($first < new \DateTimeImmutable('2005-01-01', $zone) || $last >= $today) {
            throw new \InvalidArgumentException('Choose dates from January 1, 2005 through yesterday.');
        }
        if ((int) $first->diff($last)->format('%a') > 365) {
            throw new \InvalidArgumentException('Choose a date range of 366 days or fewer.');
        }
        return ['start' => $start, 'end' => $end];
    }

    public static function previousQuarter(string $timezone, ?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new \DateTimeImmutable('now', self::timezone($timezone)))->setTimezone(self::timezone($timezone));
        $month = intdiv((int) $now->format('n') - 1, 3) * 3 + 1;
        $current = $now->setDate((int) $now->format('Y'), $month, 1)->setTime(0, 0);
        return ['start' => $current->modify('-3 months')->format('Y-m-d'), 'end' => $current->modify('-1 day')->format('Y-m-d')];
    }

    public static function previousMonth(string $timezone, ?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new \DateTimeImmutable('now', self::timezone($timezone)))->setTimezone(self::timezone($timezone));
        $first = $now->modify('first day of last month')->setTime(0, 0);
        return ['start' => $first->format('Y-m-d'), 'end' => $first->modify('last day of this month')->format('Y-m-d')];
    }
}
