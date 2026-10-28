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
 * - technical:   can I do this kind of work? The scoring model's base fit (its reading of the whole brief),
 *                capability areas named in the brief (Freelancer skill labels only count as uncertain),
 *                the scoring category and the "large or off-niche" flag.
 * - experience:  can I prove it? The closest fact-bank past project (families of related work count once)
 *                and owner-reported skill rankings for areas the brief names. Limited when technical fit is
 *                weak. Profile-wide stats (rating, reviews) are the same for every project: not used.
 * - client:      payment status, rating and history, client-side red flags (off-platform, suspicious,
 *                unpaid work, instructions aimed at bidders). Never country; no history is neutral.
 * - profit:      a conservative budget (lower quarter of the range) after the fee, over the estimated hours
 *                (which already include testing and handover), against the hourly target.
 * - competition: bid count observed at that moment and project age. Under ScoreAdjuster::FRESH_MINUTES it
 *                is too early to judge: neutral, unless bids are already pouring in.
 * - clarity:     deliverables, outside dependencies, vague or unrealistic red flags. A short fix brief is normal.
 *
 * Red flags are grouped by concern and each concern counts once, under one factor.
 *
 * Classes: A needs the A score, documented comparable work, a budget known to fit and competition not
 * already heavy. A project the scoring model rates under low_fit_below is capped at C, or at B when the
 * brief names a specialist area matching strong comparable work (the exception is listed in rules).
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

    /**
     * Red-flag concerns, checked in order (first match wins): concern => [factor, deduction, pattern].
     * "Email" alone is ordinary work (email templates, email marketing): only contact details or
     * contact outside Freelancer count as off-platform.
     */
    private const CONCERNS = [
        'off-platform contact' => ['client', 0.3, '/off[- ]?platform|outside (of )?freelancer|whatsapp|telegram|skype|personal (e-?mail|phone|contact)|(e-?mail|phone) (address|number)|contact (me|him|her|them|the client) (directly|on|via|at|outside)|direct contact/i'],
        'suspicious request' => ['client', 0.3, '/scam|fraud|suspicious|fake|phishing|illegal/i'],
        'unpaid work' => ['client', 0.2, '/free (work|sample|test|trial)|unpaid|without pay|spec work/i'],
        'instructions aimed at bidders' => ['client', 0.15, '/instructions? (aimed|to|for) (bidders|ai)|ignore (previous|all)|prompt injection/i'],
        'budget' => ['profit', 0.0, '/budget|underpric|low pay|cheap|price/i'],
        'vague requirements' => ['clarity', 0.15, '/vague|unclear|undefined|ambiguous|lacks? (detail|specific)|missing (detail|information)|not specified|few details/i'],
        'unrealistic expectations' => ['clarity', 0.15, '/unrealistic|(tight|short|aggressive) (deadline|timeline|timeframe|turnaround)/i'],
        'large scope' => ['clarity', 0.15, '/scope|large|complex|extensive|many (features|pages|requirements)/i'],
    ];

    private const CLIENT_FLAG_CAP = 0.5;

    private const CLARITY_FLAG_CAP = 0.3;

    private const FIX_LIKE = ['fix', 'security', 'speed', 'checkout_payment'];

    public function __construct(private readonly Settings $settings) {}

    public function evaluate(OpportunityInput $in, ProfileEvidence $profile): Opportunity
    {
        $weights = self::weights();
        $briefTags = Capabilities::tag($in->text);
        $skillTags = Capabilities::tag(implode(' | ', $in->skills()));
        $category = is_string($in->scoring['category'] ?? null) ? $in->scoring['category'] : null;
        $base = is_numeric($in->scoring['fit_score'] ?? null) ? (int) $in->scoring['fit_score'] : null;
        $concerns = $this->concerns((array) ($in->scoring['red_flags'] ?? []));
        $rules = [];

        $technical = $this->technical($briefTags, $skillTags, $category, $in->scoring, $base);
        $experience = $this->experience($briefTags, $skillTags, Capabilities::rankingTags($in->text), $category, $profile);
        $weakFit = $technical['value'] < 0.55 || ($base !== null && $base < (int) config('bidradar.opportunity.weak_fit_below'));
        if ($weakFit && $experience['value'] > $technical['value'] + 0.2) {
            $experience['value'] = $technical['value'] + 0.2;
            $rules[] = 'Experience credit limited: technical fit is weak, so past work counts for less.';
        }
        if ($experience['labels_only']) {
            $rules[] = 'Specialist match rests on Freelancer skill labels only: treated as uncertain.';
        }

        $factors = [
            'technical' => $technical,
            'experience' => $experience,
            'client' => $this->client((array) ($in->details['client'] ?? []), $concerns['client']),
            'profit' => $this->profit($in, $concerns['profit']),
            'competition' => $this->competition($in),
            'clarity' => $this->clarity($in->scoring, $category, $concerns['clarity']),
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

        $classA = (int) config('bidradar.opportunity.class_a');
        $specialistProof = in_array($experience['strength'], [ProfileEvidence::STRONG, ProfileEvidence::MODERATE], true);
        // A needs estimated net earnings of at least a_min_profit_ratio of the hourly target (lower-quarter budget).
        $profitOk = ! $out['profit']['unknown'] && $out['profit']['ratio'] !== null
            && $out['profit']['ratio'] >= (float) config('bidradar.opportunity.a_min_profit_ratio', 0.75);
        $competitionOk = $out['competition']['unknown'] || $out['competition']['value'] >= 0.4;
        $lowFit = $base !== null && $base < (int) config('bidradar.opportunity.low_fit_below');
        $class = match (true) {
            $score >= $classA && $specialistProof && $profitOk && $competitionOk && ! $lowFit => 'A',
            $score >= (int) config('bidradar.opportunity.class_b') => 'B',
            default => 'C',
        };
        if ($score >= $classA && $class !== 'A' && ! $lowFit) {
            array_unshift($rules, 'Not a strong specialist opportunity: '.match (true) {
                ! $specialistProof => 'no comparable past project is documented.',
                ! $profitOk => 'the budget is not known to fit the effort.',
                default => 'competition is already heavy.',
            });
        }
        if ($lowFit) {
            $exception = $experience['strength'] === ProfileEvidence::STRONG;
            $cap = $exception ? 'B' : 'C';
            if ($class < $cap) {
                $class = $cap;
            }
            array_unshift($rules, $exception
                ? "Low-fit exception: the scoring model rates it {$base}/100, but the brief names work that matches a comparable past project. Capped at B."
                : "Low technical fit: the scoring model rates it {$base}/100. Capped at C.");
        }

        return new Opportunity(
            score: $score,
            class: $class,
            factors: $out,
            strengths: array_merge(...array_values(array_map(fn ($f) => $f['good'], $out))),
            risks: array_merge(...array_values(array_map(fn ($f) => $f['risks'], $out))),
            unknowns: array_merge(...array_values(array_map(fn ($f) => $f['notes'], $out))),
            proof: $experience['proof'],
            experience: $experience['strength'],
            tags: array_values(array_unique([...$briefTags, ...$skillTags])),
            rules: $rules,
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
     * @param  list<string>  $briefTags
     * @param  list<string>  $skillTags
     * @param  array<string, mixed>  $scoring
     */
    private function technical(array $briefTags, array $skillTags, ?string $category, array $scoring, ?int $base): array
    {
        $f = self::factor();
        $briefSpecialist = Capabilities::specialist($briefTags);
        $labelSpecialist = array_values(array_diff(Capabilities::specialist($skillTags), $briefSpecialist));
        $domain = match (true) {
            $briefSpecialist !== [] => 1.0,
            $labelSpecialist !== [] => 0.75, // uncertain: only the skill labels say so
            $briefTags !== [] || $skillTags !== [] => 0.6,
            default => 0.2,
        };
        $task = $category === null ? 0.6 : (self::TASK_FIT[$category] ?? 0.5);
        $f['value'] = $base === null ? 0.6 * $domain + 0.4 * $task : 0.5 * $base / 100 + 0.3 * $domain + 0.2 * $task;

        if ($briefSpecialist !== []) {
            $f['good'][] = 'Specialist area named in the brief: '.self::labels($briefSpecialist);
        } elseif ($labelSpecialist !== []) {
            $f['notes'][] = 'Only Freelancer skill labels suggest '.self::labels($labelSpecialist).'; the brief does not confirm it.';
        } elseif ($briefTags === [] && $skillTags === []) {
            $f['risks'][] = 'No match with your capability areas';
        }
        if ($base !== null && $base >= 70) {
            $f['good'][] = "Scoring model fit {$base}/100";
        } elseif ($base !== null && $base < 40) {
            $f['risks'][] = "Scoring model fit only {$base}/100";
        }
        if (($scoring['large_vague_or_off_niche'] ?? null) === true) {
            $f['value'] *= 0.85;
            $f['risks'][] = 'Scoring flagged it as large or outside your services';
        }
        if ($category === null) {
            $f['notes'][] = 'Not scored yet: the type of work is unknown.';
        }

        return $f;
    }

    /**
     * @param  list<string>  $briefTags
     * @param  list<string>  $skillTags
     * @param  list<string>  $rankTags  specialist areas the brief establishes well enough for a ranking to count
     */
    private function experience(array $briefTags, array $skillTags, array $rankTags, ?string $category, ProfileEvidence $profile): array
    {
        $f = self::factor() + ['strength' => ProfileEvidence::NONE, 'proof' => null, 'labels_only' => false];
        $match = $profile->comparableProject($briefTags, $category, $skillTags);
        $f['strength'] = $match['strength'] ?? ProfileEvidence::NONE;
        $f['labels_only'] = $match['labels_only'] ?? false;
        // Documented comparable work outweighs rankings (0.75 vs 0.25).
        $past = match ($f['strength']) {
            ProfileEvidence::STRONG => 1.0,
            ProfileEvidence::MODERATE => 0.6,
            ProfileEvidence::UNCERTAIN => 0.45, // never proof for class A
            ProfileEvidence::WEAK => 0.25,
            default => 0.1,
        };

        if ($match !== null && ! in_array($f['strength'], [ProfileEvidence::WEAK, ProfileEvidence::UNCERTAIN], true)) {
            $f['good'][] = sprintf('%s past project: %s (shared: %s)%s',
                $f['strength'] === ProfileEvidence::STRONG ? 'Comparable' : 'Related',
                self::short($match['problem']), self::labels($match['shared']),
                $match['feedback'] ? ', with client feedback noted in your fact bank' : '');
            $f['proof'] = trim(self::short($match['problem'], 160).($match['result'] !== '' ? ' → '.self::short($match['result'], 160) : ''));
            if ($match['repeat']) {
                $past = min(1.0, $past + 0.1);
                $f['good'][] = 'Repeat-client trust documented for that work';
            }
        } elseif ($match !== null) {
            $f['notes'][] = ($match['labels_only'] ? 'Possible but unconfirmed overlap with documented work (skill labels only: ' : 'Only general overlap with documented work (')
                .self::labels($match['shared']).').';
        } elseif ($profile->pastProjectCount() === 0) {
            $f['unknown'] = true;
            $f['notes'][] = 'The fact bank has no past projects to compare with.';
        } else {
            $f['notes'][] = 'No comparable project in the fact bank (evidence unknown, not a lack of ability).';
        }

        // Rankings only for specialist areas the brief establishes with real context (Capabilities::rankingTags):
        // one best ranking, never several related ones. A ranking outside the top half earns nothing (and never
        // lowers documented work below having no ranking at all).
        $rank = $profile->bestRanking($rankTags);
        if ($rank !== null && $rank['top'] > 50) {
            $rank = null;
        }
        $rankValue = 0.1;
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

        $f['value'] = 0.75 * $past + 0.25 * $rankValue;

        return $f;
    }

    /**
     * @param  array<string, mixed>  $client
     * @param  array<string, array{deduction: float, flag: string}>  $concerns
     */
    private function client(array $client, array $concerns): array
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
            $value = 0.3;
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

        // Independent serious concerns each count, once, up to a cap.
        $value -= min(self::CLIENT_FLAG_CAP, array_sum(array_column($concerns, 'deduction')));
        foreach ($concerns as $concern => $c) {
            $f['risks'][] = self::concernLine($concern, $c['flag']);
        }

        if ($verified === null && $rating === null && $history === 0 && $concerns === []) {
            $f['unknown'] = true;
        }
        $f['value'] = $value;

        return $f;
    }

    /** @param array<string, array{deduction: float, flag: string}> $concerns */
    private function profit(OpportunityInput $in, array $concerns): array
    {
        // net: estimated take-home per hour (USD), ratio: net / target, advertised: the client's top hourly rate.
        $f = self::factor() + ['net' => null, 'ratio' => null, 'advertised' => null, 'basis' => null];
        $d = $in->details;
        $usd = (float) (($d['exchange_rate'] ?? 1) ?: 1);
        $min = (float) ($d['budget_min'] ?? 0) * $usd;
        $max = (float) ($d['budget_max'] ?? 0) * $usd;
        $target = $this->settings->float('hourly_rate_usd');
        $keep = 1 - max(0, min(50, $this->settings->float('platform_fee_percent'))) / 100;
        foreach ($concerns as $c) {
            $f['risks'][] = 'Red flag: '.self::short($c['flag']);
        }

        if ($target <= 0 || ($min <= 0 && $max <= 0)) {
            return self::unknown($f, 'Budget unknown: profitability not estimated.', $concerns !== []);
        }

        if (($d['type'] ?? 'fixed') === 'hourly') {
            // The advertised top rate is not take-home: estimate from the lower quarter of the range, after the fee.
            $f['basis'] = 'hourly';
            $f['advertised'] = round(max($min, $max), 2);
            $f['net'] = round(self::realisticBudget($min, $max) * $keep, 2);
            $f['ratio'] = round($f['net'] / $target, 3);
            $ratio = $f['ratio'];
            $f['value'] = $ratio >= 1 ? 0.9 : ($ratio >= 0.75 ? 0.6 : 0.3);
            $line = sprintf('Advertised up to USD %s/h; estimated net about USD %s/h after the fee (lower quarter of the range, not guaranteed); target USD %s/h',
                self::num(round($f['advertised'])), self::num(round($f['net'])), self::num($target));
            if ($ratio >= 0.75) {
                $f['good'][] = $line;
            } else {
                $f['risks'][] = $line;
            }

            return $f;
        }

        $hours = is_numeric($in->scoring['estimated_hours'] ?? null) && (int) $in->scoring['estimated_hours'] > 0 ? (int) $in->scoring['estimated_hours'] : null;
        if ($hours === null) {
            return self::unknown($f, 'Effort not estimated: profitability unknown.', $concerns !== []);
        }

        $budget = self::realisticBudget($min, $max);
        // The scoring model's hours already include testing, fixes and handover: no extra buffer.
        $effective = $budget * $keep / $hours;
        $ratio = $effective / $target;
        $f['basis'] = 'fixed';
        $f['net'] = round($effective, 2);
        $f['ratio'] = round($ratio, 3);
        $f['value'] = match (true) {
            $ratio >= 1 => 1.0,
            $ratio >= 0.75 => 0.8,
            $ratio >= 0.5 => 0.55,
            $ratio >= 0.25 => 0.3,
            default => 0.1,
        };
        $line = sprintf('Conservative budget USD %s (lower quarter of the range) ≈ USD %s/h after the %s%% fee for ~%dh; target USD %s/h',
            self::num(round($budget)), self::num(round($effective)), self::num(round((1 - $keep) * 100, 1)), $hours, self::num($target));
        if ($ratio >= 0.75) {
            $f['good'][] = $line;
        } elseif ($ratio < 0.5) {
            $f['risks'][] = 'Budget looks low for the effort: '.lcfirst($line);
        } else {
            $f['notes'][] = $line;
        }

        return $f;
    }

    /**
     * An evaluation assumption, not the price a client will accept: the lower quarter of the range
     * (min + 0.25 x (max - min)), or the one figure given.
     */
    public static function realisticBudget(float $min, float $max): float
    {
        $point = (float) config('bidradar.opportunity.budget_point', 0.25);

        return $min > 0 && $max > $min ? $min + $point * ($max - $min) : max($min, $max);
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

        if ($age !== null && $age < ScoreAdjuster::FRESH_MINUTES) {
            // Very fast early bidding usually meant 100+ bids later in the 28 Oct sample (crowding, not a win predictor).
            if ($bids >= 30 && $bids / max(1, $age) >= 10) {
                $f['value'] = 0.35;
                $f['risks'][] = "Bids arriving fast ({$seen}): likely to get crowded";

                return $f;
            }

            // Too early to tell how crowded it will get: neutral, neither full marks nor a penalty.
            return self::unknown($f, "Competition too early to judge ({$seen}).");
        }

        $f['value'] = match (true) {
            $bids <= 10 => 1.0,
            $bids <= 25 => 0.8,
            $bids <= 50 => 0.6,
            $bids <= 100 => 0.4,
            default => 0.25, // crowded is harder, not unwinnable
        };
        if ($bids <= 10) {
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
     * @param  array<string, array{deduction: float, flag: string}>  $concerns
     */
    private function clarity(array $scoring, ?string $category, array $concerns): array
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
                unset($concerns['large scope']); // already counted
            }
        }
        if ($dependencies >= 3) {
            $f['value'] -= 0.15;
            $f['risks'][] = "{$dependencies} outside dependencies (accounts, APIs, approvals)";
        }
        $f['value'] -= min(self::CLARITY_FLAG_CAP, array_sum(array_column($concerns, 'deduction')));
        foreach ($concerns as $concern => $c) {
            $f['risks'][] = self::concernLine($concern, $c['flag']);
        }

        return $f;
    }

    /**
     * Red flags grouped by underlying concern: one entry (the first flag) per concern, under one factor.
     * Flags matching no concern are kept on the project page by the existing red-flag box, not scored.
     *
     * @param  list<mixed>  $flags
     * @return array{client: array<string, array{deduction: float, flag: string}>, profit: array<string, array{deduction: float, flag: string}>, clarity: array<string, array{deduction: float, flag: string}>}
     */
    public static function concerns(array $flags): array
    {
        $out = ['client' => [], 'profit' => [], 'clarity' => []];
        foreach ($flags as $flag) {
            if (! is_string($flag) || trim($flag) === '') {
                continue;
            }
            foreach (self::CONCERNS as $concern => [$factor, $deduction, $pattern]) {
                if (preg_match($pattern, $flag)) {
                    $out[$factor][$concern] ??= ['deduction' => $deduction, 'flag' => trim($flag)];

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
    private static function unknown(array $f, string $note, bool $flagged = false): array
    {
        $f['value'] = $flagged ? 0.3 : 0.5;
        $f['unknown'] = true;
        $f['notes'][] = $note;

        return $f;
    }

    /** "Off-platform contact: Asks to move to WhatsApp"; just the concern when the flag says nothing more. */
    private static function concernLine(string $concern, string $flag): string
    {
        $flag = self::short($flag);

        return strcasecmp(rtrim($flag, '. '), $concern) === 0 ? ucfirst($concern) : ucfirst($concern).': '.$flag;
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
