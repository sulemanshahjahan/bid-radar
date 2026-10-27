<?php

namespace App\Services\Drafting;

/**
 * Delivery days for a bid. The owner's "Typical turnaround by category" notes
 * (e.g. "Fix: 1-2 days", "New build: 3 weeks") say which categories they deliver
 * and how long they usually take. Never a guess: no matching line means no
 * delivery time.
 */
class Turnaround
{
    /** The upper end of the category's range, or null when no line matches. */
    public static function daysFor(string $notes, string $category): ?int
    {
        return self::range($notes, $category)[1] ?? null;
    }

    /**
     * Delivery sized to this job. With an hours estimate (which already includes
     * testing and handover) the range is advisory: working days = hours x buffer /
     * the owner's working hours a day, plus time for external dependencies on
     * jobs longer than a day, then converted to calendar days (weekends added).
     * Without an estimate, the top of the range, as before.
     *
     * @return array{days: int, working_days: int|null, dependency_days: int, range: array{0: int, 1: int}, basis: string, outside_range: string|null}|null
     */
    public static function plan(string $notes, string $category, ?int $hours, int $dependencies = 0, float $hoursPerDay = 6): ?array
    {
        $range = self::range($notes, $category);
        if ($range === null) {
            return null;
        }
        [$low, $high] = $range;

        if ($hours === null || $hours <= 0) {
            return ['days' => $high, 'working_days' => null, 'dependency_days' => 0, 'range' => $range, 'basis' => 'range', 'outside_range' => null];
        }

        $perDay = max(1, $hoursPerDay);
        $buffer = max(1, (float) config('bidradar.delivery.buffer', 1.25));
        $working = max(1, (int) ceil($hours * $buffer / $perDay));

        // A one-day job waits on nobody; longer jobs lose time to accounts, content and approvals.
        $dependencyDays = $working > 1 && $dependencies > 0 ? ($dependencies >= 3 ? 2 : 1) : 0;
        $working += $dependencyDays;

        $days = self::calendarDays($working);

        return [
            'days' => $days,
            'working_days' => $working,
            'dependency_days' => $dependencyDays,
            'range' => $range,
            'basis' => 'estimate',
            'outside_range' => $days > $high ? 'above' : ($days < $low ? 'below' : null),
        ];
    }

    /** Working days to calendar days: a weekend for every 5 working days (the start day is unknown, so assume it falls inside). */
    public static function calendarDays(int $workingDays): int
    {
        return $workingDays + 2 * intdiv(max(0, $workingDays), 5);
    }

    /** @return array{0: int, 1: int}|null [low, high] in days; a single figure gives [1, figure]. */
    public static function range(string $notes, string $category): ?array
    {
        $targets = array_filter([
            self::norm($category),
            self::norm((string) config('bidradar.categories.'.$category, '')),
        ]);

        foreach (preg_split('/\R/', $notes) as $line) {
            if (! preg_match('/^\s*(.+?)\s*(?::|=|\s-\s|\s–\s)\s*(\d+)(?:\s*(?:-|–|to)\s*(\d+))?\s*(days?|d|weeks?|w)?\b/iu', $line, $m)) {
                continue;
            }

            $label = self::norm($m[1]);
            if ($label === '' || ! self::matches($label, $targets)) {
                continue;
            }

            $factor = str_starts_with(strtolower($m[4] ?? ''), 'w') ? 7 : 1;
            $high = (int) (($m[3] ?? '') !== '' ? $m[3] : $m[2]) * $factor;
            $low = ($m[3] ?? '') !== '' ? (int) $m[2] * $factor : 1;
            if ($high <= 0) {
                return null;
            }

            return [max(1, min($low, $high)), $high];
        }

        return null;
    }

    /** @param list<string> $targets */
    private static function matches(string $label, array $targets): bool
    {
        foreach ($targets as $target) {
            if ($label === $target || str_contains($target, $label) || str_contains($label, $target)) {
                return true;
            }
        }

        return false;
    }

    private static function norm(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($text));
    }
}
