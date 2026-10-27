<?php

namespace App\Services\Drafting;

/**
 * Word ranges sized to the job. The target goes to the model; the limits are
 * what the post-check enforces: the Settings range, tightened so a small fix
 * stays short, and the client's own word limit when the brief sets one.
 */
class ProposalLength
{
    /** size => [target min, target max] */
    public const BANDS = [
        'quick' => [60, 100], // a fix of a few hours: say what is wrong, what you will do, when
        'small' => [80, 130],
        'medium' => [120, 180],
        'complex' => [160, 250],
    ];

    /** How far past the target maximum a draft may run before the check fails it. */
    private const SLACK = 1.25;

    /** @return array{size: string, target_min: int, target_max: int, min: int, max: int, client_limit: int|null} */
    public static function for(?int $hours, string $category, int $settingsMin, int $settingsMax, ?int $clientLimit = null): array
    {
        $size = self::size($hours, $category);
        [$targetMin, $targetMax] = self::BANDS[$size];

        // A quick fix may go under the Settings minimum: padding it out is what makes it read like a template.
        $min = $size === 'quick' ? min($settingsMin, $targetMin) : $settingsMin;
        $max = min($settingsMax, (int) ceil($targetMax * self::SLACK));

        if ($clientLimit !== null) {
            $max = min($max, $clientLimit);
            $min = min($min, (int) floor($clientLimit * 0.6));
        }

        $targetMax = min($targetMax, $max);
        $targetMin = max($min, min($targetMin, $targetMax - 20));

        return [
            'size' => $size,
            'target_min' => $targetMin,
            'target_max' => max($targetMin, $targetMax),
            'min' => min($min, $max),
            'max' => $max,
            'client_limit' => $clientLimit,
        ];
    }

    public static function size(?int $hours, string $category): string
    {
        if ($hours === null) {
            return in_array($category, ['fix', 'speed', 'security'], true) ? 'small' : 'medium';
        }

        return match (true) {
            $hours <= 3 => 'quick',
            $hours <= 8 => 'small',
            $hours <= 40 => 'medium',
            default => 'complex',
        };
    }
}
