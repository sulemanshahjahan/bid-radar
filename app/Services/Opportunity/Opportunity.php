<?php

namespace App\Services\Opportunity;

/**
 * One shadow evaluation: an explainable 0-100 priority, its class and reasons.
 * A priority for the owner's attention, not a chance of winning. Never stored.
 */
final readonly class Opportunity
{
    public const CLASSES = [
        'A' => 'Strong specialist opportunity',
        'B' => 'Good opportunity',
        'C' => 'Lower priority',
    ];

    public const ACTIONS = [
        'A' => 'High priority for manual review',
        'B' => 'Review if time allows',
        'C' => 'Low priority',
    ];

    /**
     * @param  array<string, array{label: string, weight: int, value: float, points: int, level: string, unknown: bool, good: list<string>, risks: list<string>, notes: list<string>}>  $factors
     * @param  list<string>  $strengths
     * @param  list<string>  $risks
     * @param  list<string>  $unknowns
     * @param  list<string>  $tags  capability tags found in the project (brief and skill labels)
     * @param  list<string>  $rules  classification rules that promoted or restricted this project
     */
    public function __construct(
        public int $score,
        public string $class,
        public array $factors,
        public array $strengths,
        public array $risks,
        public array $unknowns,
        public ?string $proof,
        public string $experience,
        public array $tags,
        public array $rules = [],
    ) {}

    public function classLabel(): string
    {
        return self::CLASSES[$this->class];
    }

    public function action(): string
    {
        return self::ACTIONS[$this->class];
    }
}
