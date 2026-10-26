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

        // Live search results hide owner_id and return no `users`; client details come
        // inline in project.owner_info (status, reputation, country). The users-map shape
        // from the docs is still supported in case a response includes it.
        $info = is_array($p['owner_info'] ?? null) ? $p['owner_info'] : [];
        $history = data_get($owner, 'employer_reputation.entire_history')
            ?? data_get($info, 'reputation.entire_history')
            ?? data_get($info, 'employer_reputation.entire_history')
            ?? (is_array($info['reputation'] ?? null) ? $info['reputation'] : []);
        $country = data_get($owner, 'location.country.name') ?? data_get($owner, 'location.country.code')
            ?? (is_array($info['country'] ?? null) ? ($info['country']['name'] ?? $info['country']['code'] ?? null) : ($info['country'] ?? null));
        $verified = data_get($owner, 'status.payment_verified') ?? data_get($info, 'status.payment_verified');

        $client = array_filter([
            'payment_verified' => is_bool($verified) ? $verified : null,
            'rating' => is_numeric($history['overall'] ?? null) ? round((float) $history['overall'], 1) : null,
            'reviews' => is_numeric($history['reviews'] ?? null) ? (int) $history['reviews'] : null,
            'projects' => is_numeric($history['all'] ?? null) ? (int) $history['all'] : null, // best available "projects" count
            'country' => is_string($country) && $country !== '' ? $country : null,
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
