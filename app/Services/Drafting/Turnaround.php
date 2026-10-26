<?php

namespace App\Services\Drafting;

/**
 * Reads delivery days from the owner's "Typical turnaround by category" notes,
 * e.g. "Fix: 1-2 days" or "New build: 3 weeks". Returns the upper end of the
 * range, or null when no line matches the category (never a guess).
 */
class Turnaround
{
    public static function daysFor(string $notes, string $category): ?int
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

            $days = (int) (($m[3] ?? '') !== '' ? $m[3] : $m[2]);
            if (str_starts_with(strtolower($m[4] ?? ''), 'w')) {
                $days *= 7;
            }

            return $days > 0 ? $days : null;
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
