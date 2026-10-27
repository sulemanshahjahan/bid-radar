<?php

namespace App\Services\Drafting;

use App\Services\Settings;

/**
 * Decides the bid amount in code (never from the client's brief or the model).
 *
 * - Bids exist: average bid x factor (default 0.90-1.00, we use the midpoint).
 * - No bids yet: middle of the client's budget range.
 * - Never below the client's minimum budget, never below my floor.
 * - Hourly: my hourly rate, raised to the client's minimum if needed.
 * - Scope check (fixed): if the estimated hours x my hourly rate is more than
 *   bidradar.scope_warning_factor (2x) the price above, bid the scope price (capped at
 *   the client's budget maximum) and return a "budget too low for scope" warning.
 */
class PricingCalculator
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  array<string, mixed>  $details  Project details (type, budget_min/max, currency, exchange_rate, bid_count, bid_avg)
     * @param  int|null  $estimatedHours  Hours the scoring model estimated for the full scope
     * @return array{amount: float, currency: string, is_hourly: bool, minimum: float, reason: string, warning: string|null, profit_warning?: string|null, covered_hours?: int|null, reduce_scope?: bool}
     */
    public function price(array $details, ?int $estimatedHours = null): array
    {
        $currency = strtoupper((string) ($details['currency'] ?? 'USD'));
        $rate = (float) ($details['exchange_rate'] ?? 1) ?: 1.0; // USD per 1 unit of currency
        $min = (float) ($details['budget_min'] ?? 0);
        $max = (float) ($details['budget_max'] ?? 0);
        $bidCount = (int) ($details['bid_count'] ?? 0);
        $bidAvg = (float) ($details['bid_avg'] ?? 0);
        $hourly = ($details['type'] ?? 'fixed') === 'hourly';

        if ($hourly) {
            $myRate = $this->settings->float('hourly_rate_usd') / $rate;
            $amount = max($myRate, $min);
            $reason = match (true) {
                $max > 0 && $myRate > $max => 'My hourly rate; it is above the client\'s range',
                $myRate < $min => 'Client\'s minimum hourly rate (above my usual rate)',
                default => 'My hourly rate, within the client\'s range',
            };

            return $this->result($amount, $currency, true, max($min, $myRate), $reason);
        }

        $floor = $this->settings->float('floor_price_usd') / $rate;
        $low = $this->settings->float('avg_bid_factor_low');
        $high = $this->settings->float('avg_bid_factor_high');

        if ($bidCount > 0 && $bidAvg > 0) {
            $amount = $bidAvg * (($low + $high) / 2);
            $reason = sprintf('About %d%% of the average bid (%s %s)', round(($low + $high) / 2 * 100), $currency, self::fmt($bidAvg));
        } elseif ($min > 0 && $max > 0) {
            $amount = ($min + $max) / 2;
            $reason = 'Middle of the client\'s budget (no bids yet)';
        } else {
            $amount = max($min, $max);
            $reason = 'Client\'s stated budget';
        }

        $minimum = max($min, $floor);
        if ($amount < $minimum) {
            $amount = $minimum;
            $reason .= $minimum === $floor ? '; raised to my floor price' : '; raised to the client\'s minimum budget';
        }

        $warning = null;
        if ($estimatedHours !== null && $estimatedHours > 0) {
            $scope = $estimatedHours * $this->settings->float('hourly_rate_usd') / $rate;
            if ($scope > $amount * (float) config('bidradar.scope_warning_factor', 2)) {
                $warning = sprintf('Budget too low for scope: estimate %d hours = %s %s', $estimatedHours, $currency, self::fmt(ceil($scope)));
                // Don't just match the average: bid the scope price, capped at the client's budget maximum.
                $raised = $max > 0 ? min($scope, max($max, $amount)) : $scope;
                if ($raised > $amount) {
                    $amount = $raised;
                    $reason .= $raised < $scope ? '; raised to the client\'s budget maximum (scope is larger)' : '; raised to the scope estimate';
                }
            }
        }

        $result = $this->result($amount, $currency, false, $minimum, $reason, $warning);
        $result['reason'] .= $this->takeHome($result['amount'], $currency, $estimatedHours);

        return $result + $this->profitability($result['amount'], $currency, $rate, $max, $estimatedHours);
    }

    /**
     * Effective hourly rate check: take-home after the fee, divided by the estimated hours,
     * against my hourly rate. Never changes the bid; below the warn ratio it explains the
     * options (full-scope price, a smaller first phase that fits, or skip). Far below on a
     * larger job, the draft is told to offer that first phase instead of the full scope.
     *
     * @return array{profit_warning: string|null, profit_info: string|null, covered_hours: int|null, reduce_scope: bool}
     */
    public function profitability(float $amount, string $currency, float $rate, float $budgetMax, ?int $hours): array
    {
        $none = ['profit_warning' => null, 'profit_info' => null, 'covered_hours' => null, 'reduce_scope' => false];
        $target = $this->settings->float('hourly_rate_usd') / $rate;
        if ($hours === null || $hours <= 0 || $target <= 0) {
            return $none;
        }

        $keep = 1 - max(0, min(50, $this->settings->float('platform_fee_percent'))) / 100;
        $effective = $amount * $keep / $hours;
        if ($effective >= $target) {
            return $none;
        }
        if ($effective >= $target * (float) config('bidradar.profitability.warn_below', 0.5)) {
            // Under target but not far under: information only, nothing else changes.
            return ['profit_info' => sprintf(
                'Under your hourly target (information only): about %s %s/hour after the fee for %d hours, against your %s %s.',
                $currency, self::fmt(round($effective, 2)), $hours, $currency, self::fmt(round($target, 2)),
            )] + $none;
        }

        $fullScope = ceil($hours * $target / $keep);
        $covered = (int) floor($amount * $keep / $target);
        $warning = sprintf(
            'Below your hourly target: %s %s for about %d hours is %s %s/hour after the fee (target %s %s). Options: bid %s %s for the full scope%s, offer a first phase of about %d hours for %s %s, or skip it.',
            $currency, self::fmt($amount), $hours, $currency, self::fmt(round($effective, 2)), $currency, self::fmt(round($target, 2)),
            $currency, self::fmt($fullScope), $budgetMax > 0 && $fullScope > $budgetMax ? sprintf(' (above the client\'s budget of %s %s)', $currency, self::fmt($budgetMax)) : '',
            $covered, $currency, self::fmt($amount),
        );

        return [
            'profit_warning' => $warning,
            'profit_info' => null,
            'covered_hours' => $covered,
            'reduce_scope' => $hours > (int) config('bidradar.profitability.reduce_scope_min_hours', 16)
                && $effective < $target * (float) config('bidradar.profitability.reduce_scope_below', 0.25),
        ];
    }

    /** "; take-home USD 153 after the 10% fee, about USD 38/hour for 4 hours". Information only: never moves the bid. */
    private function takeHome(float $amount, string $currency, ?int $hours): string
    {
        $fee = max(0, min(50, $this->settings->float('platform_fee_percent')));
        $net = floor($amount * (1 - $fee / 100));
        $out = sprintf('; take-home %s %s after the %s%% fee', $currency, self::fmt($net), self::fmt($fee));

        return $hours !== null && $hours > 0
            ? $out.sprintf(', about %s %s/hour for %d hours', $currency, self::fmt(round($net / $hours)), $hours)
            : $out;
    }

    /** @return array{amount: float, currency: string, is_hourly: bool, minimum: float, reason: string, warning: string|null} */
    private function result(float $amount, string $currency, bool $hourly, float $minimum, string $reason, ?string $warning = null): array
    {
        // Whole units for fixed bids; hourly keeps cents. Round up so we never land under a minimum.
        $amount = $hourly ? ceil($amount * 100) / 100 : ceil($amount);

        return [
            'amount' => (float) $amount,
            'currency' => $currency,
            'is_hourly' => $hourly,
            'minimum' => round($minimum, 2),
            'reason' => $reason,
            'warning' => $warning,
        ];
    }

    /**
     * Scale milestone amounts so they add up exactly to the bid.
     *
     * @param  list<array{title: string, amount: float|int|string}>  $milestones
     * @return list<array{title: string, amount: float}>
     */
    public static function fitMilestones(array $milestones, float $bid): array
    {
        $milestones = array_values(array_filter($milestones, fn ($m) => isset($m['title']) && trim((string) $m['title']) !== ''));
        if ($milestones === []) {
            return [];
        }

        $total = array_sum(array_map(fn ($m) => max(0, (float) ($m['amount'] ?? 0)), $milestones));
        $count = count($milestones);
        $out = [];
        $running = 0.0;

        foreach ($milestones as $i => $m) {
            if ($i === $count - 1) {
                $amount = round($bid - $running, 2);
            } else {
                $share = $total > 0 ? max(0, (float) $m['amount']) / $total : 1 / $count;
                $amount = round($bid * $share, 0);
                $running += $amount;
            }
            $out[] = ['title' => trim((string) $m['title']), 'amount' => (float) $amount];
        }

        return $out;
    }

    private static function fmt(float $n): string
    {
        return floor($n) == $n ? (string) (int) $n : number_format($n, 2, '.', '');
    }
}
