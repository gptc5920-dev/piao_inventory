<?php
/**
 * Calendar quarters (consistent schedule): Q1 Jan-Mar, Q2 Apr-Jun, Q3 Jul-Sep, Q4 Oct-Dec.
 */

declare(strict_types=1);

function osaeits_quarter_bounds(int $year, int $quarter): array
{
    $quarter = max(1, min(4, $quarter));
    $startMonth = ($quarter - 1) * 3 + 1;
    $start = sprintf('%04d-%02d-01', $year, $startMonth);
    $endMonth = $startMonth + 2;
    $lastDay = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $endMonth)));
    $end = sprintf('%04d-%02d-%02d', $year, $endMonth, $lastDay);
    return [$start, $end];
}

/** @return array{0: int, 1: int} year, quarter 1-4 */
function osaeits_current_quarter(?DateTimeInterface $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $m = (int)$now->format('n');
    $q = (int)ceil($m / 3);
    $y = (int)$now->format('Y');
    return [$y, $q];
}

function osaeits_quarter_label(int $year, int $quarter): string
{
    return 'Q' . $quarter . ' ' . $year;
}

/**
 * If the given inclusive date range matches a full calendar quarter, return [year, quarter]; else null.
 * @return array{0: int, 1: int}|null
 */
function osaeits_resolve_quarter_from_range(string $date_from, string $date_to): ?array
{
    if ($date_from === '' || $date_to === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        return null;
    }
    $y = (int)substr($date_from, 0, 4);
    for ($q = 1; $q <= 4; $q++) {
        [$s, $e] = osaeits_quarter_bounds($y, $q);
        if ($date_from === $s && $date_to === $e) {
            return [$y, $q];
        }
    }
    $y = (int)substr($date_to, 0, 4);
    if ($y !== (int)substr($date_from, 0, 4)) {
        for ($q = 1; $q <= 4; $q++) {
            [$s, $e] = osaeits_quarter_bounds($y, $q);
            if ($date_from === $s && $date_to === $e) {
                return [$y, $q];
            }
        }
    }
    return null;
}

function osaeits_report_generated_at(): string
{
    $tz = new DateTimeZone('Asia/Manila');
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s T');
}
