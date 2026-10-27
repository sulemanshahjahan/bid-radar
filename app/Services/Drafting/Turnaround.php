<?php

namespace App\Services\Drafting;

/**
 * Reads delivery days from the owner's "Typical turnaround by category" notes,
 * e.g. "Fix: 1-2 days" or "New build: 3 weeks". Never a guess: no matching
 * line means no delivery time.
 */
class Turnaround
{
    /** The upper end of the category's range, or null when no line matches. */
    public static function daysFor(string $notes, string $category): ?int
    {
        return self::range($notes, $category)[1] ?? null;
    }

    /**
     * Delivery days sized to this job: the scoring estimate of hours (which already
     * includes testing and handover) with a small buffer, in working days, kept
     * inside the category's range. A single figure ("Checkout: 4 days") is the
     * most it takes, so a small job can come in under it. Without an estimate,
     * the upper end of the range.
     */
    public static function estimate(string $notes, string $category, ?int $hours): ?int
    {
        $range = self::range($notes, $category);
        if ($range === null) {
            return null;
        }
        [$low, $high] = $range;
        if ($hours === null || $hours <= 0) {
            return $high;
        }

        $perDay = max(1, (float) config('bidradar.delivery.hours_per_day', 6));
        $buffer = max(1, (float) config('bidradar.delivery.buffer', 1.25));
        $days = (int) ceil($hours * $buffer / $perDay);

        return max($low, min($high, $days));
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
