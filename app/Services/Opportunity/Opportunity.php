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

    /** Earnings far below the target override the class: the work may fit, the price does not. */
    public const SKIP = 'Skip at current budget';

    public const RECONSIDER = 'Review only if scope or price changes';

    /**
     * What to do next, from commercial viability and evidence as well as the class. Never a win prediction.
     */
    public function action(): string
    {
        $ratio = $this->earnings()['ratio'];

        return match (true) {
            $ratio !== null && $ratio < 0.25 => self::SKIP,
            $ratio !== null && $ratio < 0.5 => self::RECONSIDER,
            $this->class === 'B' && $ratio === null => 'Review: confirm budget and effort first',
            $this->class === 'B' && ! in_array($this->experience, [ProfileEvidence::STRONG, ProfileEvidence::MODERATE], true) => 'Review: specialist fit unconfirmed',
            default => self::ACTIONS[$this->class],
        };
    }

    /**
     * Estimated net earnings per hour against the target, for the card label.
     * band: good (at or above target), fair (50-99%), poor (under 50%), unknown.
     *
     * @return array{net: float|null, ratio: float|null, band: string, hourly: bool, advertised: float|null}
     */
    public function earnings(): array
    {
        $p = $this->factors['profit'] ?? [];
        $ratio = isset($p['ratio']) && ! ($p['unknown'] ?? false) ? (float) $p['ratio'] : null;

        return [
            'net' => $ratio === null ? null : (float) $p['net'],
            'ratio' => $ratio,
            'band' => match (true) {
                $ratio === null => 'unknown',
                $ratio >= 1 => 'good',
                $ratio >= 0.5 => 'fair',
                default => 'poor',
            },
            'hourly' => ($p['basis'] ?? null) === 'hourly',
            'advertised' => isset($p['advertised']) ? (float) $p['advertised'] : null,
        ];
    }

    /** One line on why the owner's documented experience matches, or that it does not (yet). */
    public function why(): string
    {
        $good = $this->factors['experience']['good'] ?? [];
        $match = collect($good)->first(fn ($l) => str_starts_with($l, 'Comparable past project') || str_starts_with($l, 'Related past project'));
        if ($match !== null) {
            return (string) preg_replace('/ \(shared: .*$/', '', $match);
        }
        $area = collect($this->factors['technical']['good'] ?? [])->first(fn ($l) => str_starts_with($l, 'Specialist area'));

        return match (true) {
            $this->experience === ProfileEvidence::UNCERTAIN => 'Possible match: only Freelancer skill labels suggest your specialist areas',
            $area !== null => $area.'; no comparable past project documented',
            default => 'No comparable past project documented',
        };
    }

    /**
     * The single most important risk, earnings aside (they have their own label): client concerns first,
     * then crowding, low technical fit, unverified payment, unclear requirements.
     */
    public function topRisk(): ?string
    {
        $risks = array_values(array_filter($this->risks, fn ($r) => ! str_starts_with($r, 'Budget looks low') && ! str_starts_with($r, 'Red flag: ')
            && ! str_starts_with($r, 'Advertised up to')));
        foreach (['/^(Off-platform|Suspicious|Unpaid|Instructions)/', '/^(Bids arriving fast|Crowded)/', '/^Scoring model fit only|outside your services/', '/payment not verified|low rating/i', '/^(Vague|Deliverables not clear|Unrealistic|Many separate|Large scope)/', '/./'] as $pattern) {
            foreach ($risks as $r) {
                if (preg_match($pattern, $r)) {
                    return $r;
                }
            }
        }

        return null;
    }
}
