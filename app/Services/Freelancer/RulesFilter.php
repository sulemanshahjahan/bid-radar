<?php

namespace App\Services\Freelancer;

use App\Services\Settings;

/**
 * Plain-code filter that runs before any AI call (spec section 3).
 * Returns null when the project passes, or the reason it was skipped.
 */
class RulesFilter
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  array<string, mixed>  $mapped  Output of ProjectMapper::map() (or the same shape built from a pasted brief)
     * @param  bool  $requireSkill  Pasted projects without skills entered skip the target-skill check.
     */
    public function reject(array $mapped, ?int $now = null, bool $requireSkill = true): ?string
    {
        $d = $mapped['details'];
        $text = $mapped['title']."\n".$mapped['description'];
        $now ??= now()->getTimestamp();

        $targets = array_map('mb_strtolower', $this->settings->list('target_skills'));
        $skills = array_map('mb_strtolower', $d['skills']);
        if ($targets !== [] && ($requireSkill || $skills !== []) && array_intersect($skills, $targets) === []) {
            return 'No target skill';
        }

        if ($word = self::firstMatch($this->settings->list('exclude_keywords'), $text)) {
            return "Excluded keyword: {$word}";
        }

        // Unverified payment is a warning, not a skip: see paymentWarning(). Such projects are
        // scored and shown, but never drafted automatically (DraftGate).

        if (empty($d['budget_min']) && empty($d['budget_max'])) {
            return 'No budget';
        }

        $bids = (int) ($d['bid_count'] ?? 0);
        if ($bids >= $this->settings->int('max_bid_count')) {
            return "Too many bids ({$bids})";
        }

        if ($bids >= $this->settings->int('new_build_max_bid_count')
            && ($phrase = self::firstMatch($this->settings->list('new_build_phrases'), $text))) {
            return "New build with {$bids} bids ({$phrase})";
        }

        if ($mapped['posted_at'] !== null && $now - $mapped['posted_at'] > $this->settings->int('max_age_hours') * 3600) {
            return 'Posted more than '.$this->settings->int('max_age_hours').'h ago';
        }

        // Minimum budget applies to fixed-price work; compare the top of the range in USD.
        if ($d['type'] === 'fixed') {
            $usd = (float) max($d['budget_max'] ?? 0, $d['budget_min'] ?? 0) * (float) ($d['exchange_rate'] ?: 1);
            if ($usd < $this->settings->float('min_budget_usd')) {
                return sprintf('Budget too low ($%.0f USD)', $usd);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $details */
    public static function paymentWarning(array $details): ?string
    {
        return match ($details['client']['payment_verified'] ?? null) {
            true => null,
            null => 'client payment status unknown',
            default => 'client payment not verified',
        };
    }

    /** Case-insensitive, word-boundary match. Returns the first matching term. */
    public static function firstMatch(array $terms, string $text): ?string
    {
        foreach ($terms as $term) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $text)) {
                return $term;
            }
        }

        return null;
    }
}
