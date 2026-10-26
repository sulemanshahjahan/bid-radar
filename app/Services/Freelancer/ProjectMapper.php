<?php

namespace App\Services\Freelancer;

/**
 * Maps a Freelancer API project (+ its owner from result.users) to the
 * fields BidRadar stores. Field names follow developers.freelancer.com.
 */
class ProjectMapper
{
    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>|null  $owner
     * @return array<string, mixed>
     */
    public static function map(array $p, ?array $owner): array
    {
        $upgrades = array_keys(array_filter((array) ($p['upgrades'] ?? []), fn ($v) => $v === true));
        $history = data_get($owner, 'employer_reputation.entire_history', []);

        $client = array_filter([
            'payment_verified' => data_get($owner, 'status.payment_verified'),
            'rating' => isset($history['overall']) ? round((float) $history['overall'], 1) : null,
            'reviews' => $history['reviews'] ?? null,
            'projects' => $history['all'] ?? null, // unconfirmed meaning; best available "projects" count
            'country' => data_get($owner, 'location.country.name') ?? data_get($owner, 'location.country.code'),
        ], fn ($v) => $v !== null);

        $seo = (string) ($p['seo_url'] ?? '');

        return [
            'freelancer_id' => (int) $p['id'],
            'title' => (string) ($p['title'] ?? ''),
            'description' => (string) (($p['description'] ?? null) ?: ($p['preview_description'] ?? '')),
            'url' => $seo !== '' ? str_replace('{seo_url}', ltrim($seo, '/'), config('bidradar.freelancer.project_url')) : null,
            'posted_at' => isset($p['time_submitted']) ? (int) $p['time_submitted'] : null,
            'details' => [
                'type' => ($p['type'] ?? data_get($p, 'budget.project_type')) === 'hourly' ? 'hourly' : 'fixed',
                'budget_min' => self::num(data_get($p, 'budget.minimum')),
                'budget_max' => self::num(data_get($p, 'budget.maximum')),
                'currency' => strtoupper((string) (data_get($p, 'currency.code') ?: 'USD')),
                'exchange_rate' => (float) (data_get($p, 'currency.exchange_rate') ?: 1),
                'bid_count' => (int) data_get($p, 'bid_stats.bid_count', 0),
                'bid_avg' => self::num(data_get($p, 'bid_stats.bid_avg')),
                'skills' => array_values(array_filter(array_map(fn ($j) => $j['name'] ?? null, (array) ($p['jobs'] ?? [])))),
                'upgrades' => $upgrades,
                'owner_id' => isset($p['owner_id']) ? (int) $p['owner_id'] : null,
                'client' => $client,
            ],
        ];
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }
}
