<?php

namespace App\Services\Opportunity;

use App\Services\ScoreAdjuster;
use App\Services\Settings;
use InvalidArgumentException;

/**
 * Opportunity Priority Score (shadow mode): how much a project deserves the owner's attention given
 * their documented experience, the budget, the client and the competition seen at that moment.
 * Deterministic, no AI call: it reuses the existing scoring reply and the fact bank.
 *
 * It is NOT a win probability and does not change scoring, filters, drafting or alerts.
 *
 * Each piece of evidence counts under one factor only:
 * - technical:   can I do this kind of work? Capability areas found (once each, not per keyword),
 *                the scoring category, the scoring model's "large or off-niche" flag.
 * - experience:  can I prove it? The closest fact-bank past project, and owner-reported skill rankings
 *                (weighted down when undated or old). Profile-wide stats (rating, reviews) are the same
 *                for every project, so they cannot rank one above another and are not used.
 * - client:      payment status, rating and history, off-platform or suspicious red flags. Never country.
 * - profit:      budget against estimated hours (+ testing and revisions) at the hourly target after the fee.
 * - competition: bid count observed at that moment, project age, very fast early bidding. Capped so a
 *                crowded project is never written off. Differentiation is credited under experience only.
 * - clarity:     deliverables, outside dependencies, vague or unrealistic red flags. A short fix brief is normal.
 *
 * A factor with no information gets a neutral 0.5 and is listed as unknown, except experience: no
 * documented match scores low (labelled "not documented", never "cannot do it").
 */
class OpportunityScorer
{
    public const FACTORS = [
        'technical' => 'Technical and scope match',
        'experience' => 'Verified experience',
        'client' => 'Client credibility',
        'profit' => 'Profitability and effort',
        'competition' => 'Competition and visibility',
        'clarity' => 'Requirements clarity',
    ];

    /** How squarely the scoring category sits in the owner's work. A new build is legitimate work, not a penalty. */
    private const TASK_FIT = [
        'fix' => 1.0, 'security' => 0.95, 'speed' => 0.95, 'checkout_payment' => 0.95,
        'maintenance' => 0.85, 'b2b_wholesale' => 0.7, 'new_build' => 0.7, 'other' => 0.5,
    ];

    /** Red flags are routed to one factor each (first match), so one flag never costs twice. */
    private const FLAG_ROUTES = [
        'client' => '/off[- ]?platform|outside (of )?freelancer|whatsapp|telegram|skype|e-?mail|phone|contact (me|him|her|them)|scam|suspicious|fraud|free (work|sample|test)|unpaid|instructions? (aimed|to) (bidders|ai)/i',
        'profit' => '/budget|underpric|low pay|cheap/i',
        'clarity' => '/vague|unclear|undefined|unrealistic|deadline|timeline|scope/i',
    ];

    private const FIX_LIKE = ['fix', 'security', 'speed', 'checkout_payment'];

    public function __construct(private readonly Settings $settings) {}

    public function evaluate(OpportunityInput $in, ProfileEvidence $profile): Opportunity
    {
        $weights = self::weights();
        $tags = Capabilities::tag($in->text);
        $category = is_string($in->scoring['category'] ?? null) ? $in->scoring['category'] : null;
        $flags = $this->routeFlags((array) ($in->scoring['red_flags'] ?? []));

        $experience = $this->experience($tags, $category, $profile);
        $factors = [
            'technical' => $this->technical($tags, $category, $in->scoring),
            'experience' => $experience,
            'client' => $this->client((array) ($in->details['client'] ?? []), $flags['client']),
            'profit' => $this->profit($in, $flags['profit']),
            'competition' => $this->competition($in),
            'clarity' => $this->clarity($in->scoring, $category, $flags['clarity']),
        ];

        $out = [];
        foreach ($factors as $key => $f) {
            $value = round(max(0.0, min(1.0, $f['value'])), 2);
            $out[$key] = [
                'label' => self::FACTORS[$key],
                'weight' => $weights[$key],
                'value' => $value,
                // The total is the sum of these whole points, so the breakdown always adds up.
                'points' => (int) round($weights[$key] * $value),
                'level' => $f['unknown'] ? 'unknown' : ($value >= 0.75 ? 'strong' : ($value >= 0.5 ? 'moderate' : 'weak')),
            ] + $f;
        }
        $score = max(0, min(100, array_sum(array_column($out, 'points'))));

        $specialistProof = in_array($experience['strength'], [ProfileEvidence::STRONG, ProfileEvidence::MODERATE], true);
        // A strong specialist opportunity also needs a budget known to fit and competition that is not already heavy.
        $profitOk = ! $out['profit']['unknown'] && $out['profit']['value'] >= 0.55;
        $competitionOk = $out['competition']['unknown'] || $out['competition']['value'] >= 0.4;
        $class = match (true) {
            $score >= (int) config('bidradar.opportunity.class_a') && $specialistProof && $profitOk && $competitionOk => 'A',
            $score >= (int) config('bidradar.opportunity.class_b') => 'B',
            default => 'C',
        };

        $unknowns = array_merge(...array_values(array_map(fn ($f) => $f['notes'], $out)));
        if ($score >= (int) config('bidradar.opportunity.class_a') && $class !== 'A') {
            $unknowns[] = 'Not marked as a strong specialist opportunity: '.match (true) {
                ! $specialistProof => 'no comparable past project is documented.',
                ! $profitOk => 'the budget for the effort is unknown or weak.',
                default => 'competition is already heavy.',
            };
        }

        return new Opportunity(
            score: $score,
            class: $class,
            factors: $out,
            strengths: array_merge(...array_values(array_map(fn ($f) => $f['good'], $out))),
            risks: array_merge(...array_values(array_map(fn ($f) => $f['risks'], $out))),
            unknowns: $unknowns,
            proof: $experience['proof'],
            experience: $experience['strength'],
            tags: $tags,
        );
    }

    /** @return array<string, int> factor => weight (sums to 100) */
    public static function weights(): array
    {
        $weights = array_map('intval', (array) config('bidradar.opportunity.weights'));
        if (array_keys($weights) != array_keys(self::FACTORS) || array_sum($weights) !== 100) {
            throw new InvalidArgumentException('bidradar.opportunity.weights must cover every factor and add up to 100.');
        }

        return $weights;
    }

    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $scoring
     */
    private function technical(array $tags, ?string $category, array $scoring): array
    {
        $f = self::factor();
        $specialist = Capabilities::specialist($tags);
        $domain = $specialist !== [] ? 1.0 : ($tags !== [] ? 0.65 : 0.2);
        $task = $category === null ? 0.6 : (self::TASK_FIT[$category] ?? 0.5);
        $f['value'] = 0.6 * $domain + 0.4 * $task;

        if ($specialist !== []) {
            $f['good'][] = 'Specialist area: '.self::labels($specialist);
        } elseif ($tags !== []) {
            $f['good'][] = 'General fit: '.self::labels($tags);
        } else {
            $f['risks'][] = 'No match with your capability areas';
        }
        if (($scoring['large_vague_or_off_niche'] ?? null) === true) {
            $f['value'] *= 0.75;
            $f['risks'][] = 'Scoring flagged it as large or outside your services';
        }
        if ($category === null) {
            $f['notes'][] = 'Not scored yet: the type of work is unknown.';
        }

        return $f;
    }

    /** @param list<string> $tags */
    private function experience(array $tags, ?string $category, ProfileEvidence $profile): array
    {
        $f = self::factor() + ['strength' => ProfileEvidence::NONE, 'proof' => null];
        $match = $profile->comparableProject($tags, $category);
        $f['strength'] = $match['strength'] ?? ProfileEvidence::NONE;
        $past = match ($f['strength']) {
            ProfileEvidence::STRONG => 1.0,
            ProfileEvidence::MODERATE => 0.65,
            ProfileEvidence::WEAK => 0.3,
            default => 0.15,
        };

        if ($match !== null && $f['strength'] !== ProfileEvidence::WEAK) {
            $what = sprintf('%s past project: %s (shared: %s)%s',
                $f['strength'] === ProfileEvidence::STRONG ? 'Comparable' : 'Related',
                self::short($match['problem']), self::labels($match['shared']),
                $match['feedback'] ? ', with client feedback noted in your fact bank' : '');
            $f['good'][] = $what;
            $f['proof'] = trim(self::short($match['problem'], 160).($match['result'] !== '' ? ' → '.self::short($match['result'], 160) : ''));
            if ($match['repeat']) {
                $past = min(1.0, $past + 0.1);
                $f['good'][] = 'Repeat-client trust documented for that work';
            }
        } elseif ($match !== null) {
            $f['notes'][] = 'Only general overlap with documented work ('.self::labels($match['shared']).').';
        } elseif ($profile->pastProjectCount() === 0) {
            $f['unknown'] = true;
            $f['notes'][] = 'The fact bank has no past projects to compare with.';
        } else {
            $f['notes'][] = 'No comparable project in the fact bank (evidence unknown, not a lack of ability).';
        }

        $rank = $profile->bestRanking($tags);
        $rankValue = 0.15;
        if ($rank !== null) {
            $rankValue = match (true) {
                $rank['top'] <= 5 => 1.0,
                $rank['top'] <= 10 => 0.8,
                $rank['top'] <= 15 => 0.6,
                $rank['top'] <= 25 => 0.4,
                default => 0.2,
            };
            $stale = (int) config('bidradar.opportunity.ranking_stale_days', 180);
            $current = $profile->rankingsCurrent($stale);
            if (! $current) {
                $rankValue *= 0.75;
            }
            if ($rank['top'] <= 15) {
                $f['good'][] = sprintf('%s ranking: top %s%% (%s)', $rank['skill'], self::num($rank['top']), match (true) {
                    $current => 'as of '.$profile->rankingsAsOf->toDateString(),
                    $profile->rankingsAsOf !== null => 'owner-reported '.$profile->rankingsAsOf->toDateString().', older than '.$stale.' days',
                    default => 'owner-reported, date unknown',
                });
            }
        }

        $f['value'] = 0.65 * $past + 0.35 * $rankValue;

        return $f;
    }

    /**
     * @param  array<string, mixed>  $client
     * @param  list<string>  $flags
     */
    private function client(array $client, array $flags): array
    {
        $f = self::factor();
        $verified = $client['payment_verified'] ?? null;
        $rating = is_numeric($client['rating'] ?? null) ? (float) $client['rating'] : null;
        $history = max((int) ($client['projects'] ?? 0), (int) ($client['reviews'] ?? 0));
        $value = 0.5;

        if ($verified === true) {
            $value += 0.15;
            $f['good'][] = 'Payment verified';
        } elseif ($verified === false) {
            $value = 0.2;
            $f['risks'][] = 'Client payment not verified';
        }

        if ($history >= 3 && $rating !== null && $rating >= 4.5) {
            $value += 0.3;
            $f['good'][] = 'Established client with a good rating';
        } elseif ($history >= 3 && $rating !== null && $rating < 4.0) {
            $value -= 0.25;
            $f['risks'][] = 'Client has a low rating';
        } elseif ($history === 0 && $rating === null) {
            // A new client is not a bad client: neutral, just less known.
            $f['notes'][] = 'Client history: none or not shown (new clients are not penalised).';
        }

        foreach ($flags as $flag) {
            $value -= 0.25;
            $f['risks'][] = 'Red flag: '.self::short($flag);
        }

        if ($verified === null && $rating === null && $history === 0 && $flags === []) {
            $f['unknown'] = true;
        }
        $f['value'] = $value;

        return $f;
    }

    /** @param list<string> $flags */
    private function profit(OpportunityInput $in, array $flags): array
    {
        $f = self::factor();
        $d = $in->details;
        $usd = (float) (($d['exchange_rate'] ?? 1) ?: 1);
        $min = (float) ($d['budget_min'] ?? 0) * $usd;
        $max = (float) ($d['budget_max'] ?? 0) * $usd;
        $target = $this->settings->float('hourly_rate_usd');
        $keep = 1 - max(0, min(50, $this->settings->float('platform_fee_percent'))) / 100;
        foreach ($flags as $flag) {
            $f['risks'][] = 'Red flag: '.self::short($flag);
        }

        if ($target <= 0 || ($min <= 0 && $max <= 0)) {
            return self::unknown($f, 'Budget unknown: profitability not estimated.', $flags);
        }

        if (($d['type'] ?? 'fixed') === 'hourly') {
            $top = max($min, $max);
            $ratio = $top / $target;
            $f['value'] = $ratio >= 1 ? 0.9 : ($ratio >= 0.75 ? 0.6 : 0.3);
            $line = sprintf('Hourly up to USD %s against your USD %s target', self::num(round($top)), self::num($target));
            if ($ratio >= 0.75) {
                $f['good'][] = $line;
            } else {
                $f['risks'][] = $line;
            }

            return $f;
        }

        $hours = is_numeric($in->scoring['estimated_hours'] ?? null) && (int) $in->scoring['estimated_hours'] > 0 ? (int) $in->scoring['estimated_hours'] : null;
        if ($hours === null) {
            return self::unknown($f, 'Effort not estimated: profitability unknown.', $flags);
        }

        // The middle of the range, not the top: the top is the most a client might pay, not what they will.
        $budget = $min > 0 && $max > 0 ? ($min + $max) / 2 : max($min, $max);
        $buffer = (float) config('bidradar.delivery.buffer', 1.25);
        $effective = $budget * $keep / ($hours * $buffer);
        $ratio = $effective / $target;
        $f['value'] = match (true) {
            $ratio >= 1 => 1.0,
            $ratio >= 0.75 => 0.8,
            $ratio >= 0.5 => 0.55,
            $ratio >= 0.25 => 0.3,
            default => 0.1,
        };
        $line = sprintf('Mid budget USD %s ≈ USD %s/h after the %s%% fee for ~%dh (+%d%% for testing and revisions); target USD %s/h',
            self::num(round($budget)), self::num(round($effective)), self::num(round((1 - $keep) * 100, 1)), $hours, (int) round(($buffer - 1) * 100), self::num($target));
        if ($ratio >= 0.75) {
            $f['good'][] = $line;
        } elseif ($ratio < 0.5) {
            $f['risks'][] = 'Budget looks low for the effort: '.lcfirst($line);
        } else {
            $f['notes'][] = $line;
        }

        return $f;
    }

    private function competition(OpportunityInput $in): array
    {
        $f = self::factor();
        if ($in->bidCount === null) {
            return self::unknown($f, 'Bid count not observed.');
        }

        $bids = $in->bidCount;
        $age = $in->ageMinutes();
        $seen = $age !== null ? sprintf('%d bids %s after posting', $bids, self::minutes($age)) : "{$bids} bids";
        $f['value'] = match (true) {
            $bids <= 10 => 1.0,
            $bids <= 25 => 0.8,
            $bids <= 50 => 0.6,
            $bids <= 100 => 0.4,
            default => 0.25, // crowded is harder, not unwinnable
        };

        // Very fast early bidding usually meant 100+ bids later in the 28 Oct sample (crowding, not a win predictor).
        $fresh = $age !== null && $age < ScoreAdjuster::FRESH_MINUTES;
        if ($fresh && $bids >= 30 && $bids / max(1, $age) >= 10) {
            $f['value'] = min($f['value'], 0.5);
            $f['risks'][] = "Bids arriving fast ({$seen}): likely to get crowded";
        } elseif ($bids <= 10) {
            $f['good'][] = "Low competition so far ({$seen})";
        } elseif ($bids > 50) {
            $f['risks'][] = "Crowded ({$seen}): harder to stand out, not unwinnable";
        } else {
            $f['notes'][] = 'Competition: '.$seen.'.';
        }

        if ($age !== null && $age >= 12 * 60) {
            $f['value'] *= 0.8;
            $f['risks'][] = 'Posted '.self::minutes($age).' before this observation: the client may already be choosing';
        } elseif ($age !== null && $age >= 6 * 60) {
            $f['value'] *= 0.9;
        }

        return $f;
    }

    /**
     * @param  array<string, mixed>  $scoring
     * @param  list<string>  $flags
     */
    private function clarity(array $scoring, ?string $category, array $flags): array
    {
        $f = self::factor();
        if ($scoring === []) {
            return self::unknown($f, 'Not scored yet: requirements not assessed.');
        }

        $deliverables = count((array) ($scoring['deliverables'] ?? []));
        $dependencies = count((array) ($scoring['dependencies'] ?? []));
        if (($scoring['quick_fix'] ?? null) === true) {
            $f['value'] = 0.9;
            $f['good'][] = 'Small, well-defined fix';
        } else {
            $f['value'] = match (true) {
                $deliverables === 0 => in_array($category, self::FIX_LIKE, true) ? 0.7 : 0.35, // short fix briefs are normal
                $deliverables <= 4 => 0.85,
                $deliverables <= 6 => 0.65,
                default => 0.45,
            };
            if ($deliverables === 0 && ! in_array($category, self::FIX_LIKE, true)) {
                $f['risks'][] = 'Deliverables not clear from the brief';
            } elseif ($deliverables > 6) {
                $f['risks'][] = "Many separate deliverables ({$deliverables})";
            }
        }
        if ($dependencies >= 3) {
            $f['value'] -= 0.15;
            $f['risks'][] = "{$dependencies} outside dependencies (accounts, APIs, approvals)";
        }
        foreach ($flags as $flag) {
            $f['value'] -= 0.2;
            $f['risks'][] = 'Red flag: '.self::short($flag);
        }

        return $f;
    }

    /**
     * @param  list<mixed>  $flags
     * @return array{client: list<string>, profit: list<string>, clarity: list<string>}
     */
    private function routeFlags(array $flags): array
    {
        $out = ['client' => [], 'profit' => [], 'clarity' => []];
        foreach ($flags as $flag) {
            if (! is_string($flag) || trim($flag) === '') {
                continue;
            }
            foreach (self::FLAG_ROUTES as $factor => $pattern) {
                if (preg_match($pattern, $flag)) {
                    $out[$factor][] = trim($flag);

                    break;
                }
            }
        }

        return $out;
    }

    /** @return array{value: float, unknown: bool, good: list<string>, risks: list<string>, notes: list<string>} */
    private static function factor(): array
    {
        return ['value' => 0.5, 'unknown' => false, 'good' => [], 'risks' => [], 'notes' => []];
    }

    /** Neutral value for missing information; a budget red flag still lowers it. */
    private static function unknown(array $f, string $note, array $flags = []): array
    {
        $f['value'] = $flags !== [] ? 0.3 : 0.5;
        $f['unknown'] = true;
        $f['notes'][] = $note;

        return $f;
    }

    /** @param list<string> $tags */
    private static function labels(array $tags): string
    {
        return implode(', ', array_map(Capabilities::label(...), array_slice($tags, 0, 3)));
    }

    private static function short(string $text, int $max = 90): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    private static function minutes(int $minutes): string
    {
        return $minutes < 90 ? $minutes.' min' : round($minutes / 60, 1).'h';
    }

    private static function num(float $n): string
    {
        return floor($n) == $n ? (string) (int) $n : number_format($n, 1, '.', '');
    }
}
