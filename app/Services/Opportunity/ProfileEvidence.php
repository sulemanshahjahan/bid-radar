<?php

namespace App\Services\Opportunity;

use App\Models\PastProject;
use App\Services\Settings;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * What the owner can prove: past projects from the fact bank (written by the owner from their
 * own Freelancer history) and owner-reported skill rankings with the date they were read.
 * Nothing here is inferred or invented: no fact-bank match means "no documented evidence",
 * which is reported as unknown, never as "cannot do it".
 */
final class ProfileEvidence
{
    public const STRONG = 'strong';

    public const MODERATE = 'moderate';

    public const WEAK = 'weak';

    public const NONE = 'none';

    /** @var list<array{category: string, problem: string, what_i_did: string, result: string, tags: list<string>}> */
    private array $past = [];

    /**
     * @param  list<array{category?: string|null, problem?: string|null, what_i_did?: string|null, result?: string|null}>  $pastProjects
     * @param  list<array{skill: string, top: float, tags: list<string>}>  $rankings
     */
    public function __construct(array $pastProjects, public readonly array $rankings, public readonly ?Carbon $rankingsAsOf)
    {
        foreach ($pastProjects as $p) {
            $category = (string) ($p['category'] ?? 'other');
            $text = implode("\n", [$p['problem'] ?? '', $p['what_i_did'] ?? '', $p['result'] ?? '']);
            $tags = Capabilities::tag($text);
            if (isset(Capabilities::CATEGORY_TAGS[$category]) && ! in_array(Capabilities::CATEGORY_TAGS[$category], $tags, true)) {
                $tags[] = Capabilities::CATEGORY_TAGS[$category];
            }
            $this->past[] = [
                'category' => $category,
                'problem' => trim((string) ($p['problem'] ?? '')),
                'what_i_did' => trim((string) ($p['what_i_did'] ?? '')),
                'result' => trim((string) ($p['result'] ?? '')),
                'tags' => $tags,
            ];
        }
    }

    public static function fromSettings(Settings $settings): self
    {
        $past = PastProject::query()->orderBy('id')->get(['category', 'problem', 'what_i_did', 'result'])->toArray();

        return new self($past, self::parseRankings($settings->list('skill_rankings')), self::parseDate((string) $settings->get('skill_rankings_as_of', '')));
    }

    /**
     * "CSS3 | 3" or "CSS3 | Top 3%" per line. Lines without a percentage are ignored.
     *
     * @param  list<string>  $lines
     * @return list<array{skill: string, top: float, tags: list<string>}>
     */
    public static function parseRankings(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) !== 2 || $parts[0] === '' || ! preg_match('/(\d+(?:\.\d+)?)/', $parts[1], $m)) {
                continue;
            }
            $top = (float) $m[1];
            if ($top <= 0 || $top > 100) {
                continue;
            }
            $out[] = ['skill' => $parts[0], 'top' => $top, 'tags' => Capabilities::tag($parts[0])];
        }

        return $out;
    }

    public static function parseDate(string $value): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    public function pastProjectCount(): int
    {
        return count($this->past);
    }

    /**
     * The fact-bank project most like this one. Shared specialist areas count 2, shared general areas 1,
     * the same scoring category 1. Strong needs a shared specialist area and 4+ points; moderate a shared
     * specialist area; weak only general overlap (e.g. both WordPress), which proves little.
     *
     * @param  list<string>  $projectTags
     * @return array{strength: string, shared: list<string>, problem: string, result: string, feedback: bool, repeat: bool}|null
     */
    public function comparableProject(array $projectTags, ?string $category): ?array
    {
        $best = null;
        foreach ($this->past as $p) {
            $shared = array_values(array_intersect($projectTags, $p['tags']));
            if ($shared === []) {
                continue;
            }
            $specialist = Capabilities::specialist($shared);
            $points = 2 * count($specialist) + (count($shared) - count($specialist)) + ($category !== null && $category === $p['category'] ? 1 : 0);
            if ($best === null || $points > $best['points']) {
                $best = ['points' => $points, 'specialist' => $specialist, 'shared' => $shared, 'p' => $p];
            }
        }
        if ($best === null) {
            return null;
        }

        $text = $best['p']['what_i_did']."\n".$best['p']['result'];

        return [
            'strength' => match (true) {
                $best['specialist'] !== [] && $best['points'] >= 4 => self::STRONG,
                $best['specialist'] !== [] => self::MODERATE,
                default => self::WEAK,
            },
            'shared' => $best['shared'],
            'problem' => $best['p']['problem'],
            'result' => $best['p']['result'],
            // Only what the owner wrote: feedback or a repeat client is mentioned, never assumed.
            'feedback' => (bool) preg_match('/\b(positive|excellent|great|happy|praised|5[- ]star|five[- ]star|feedback|review)\b/i', $text),
            'repeat' => (bool) preg_match('/\b(repeat|returning)\s+client\b|\bhired (me )?again\b|\brehired\b/i', $best['p']['problem']."\n".$text),
        ];
    }

    /**
     * The best (lowest top %) owner-reported ranking for a skill this project needs.
     *
     * @param  list<string>  $projectTags
     * @return array{skill: string, top: float, tag: string}|null
     */
    public function bestRanking(array $projectTags): ?array
    {
        $best = null;
        foreach ($this->rankings as $r) {
            $tags = array_intersect($r['tags'], $projectTags);
            if ($tags !== [] && ($best === null || $r['top'] < $best['top'])) {
                $best = ['skill' => $r['skill'], 'top' => $r['top'], 'tag' => reset($tags)];
            }
        }

        return $best;
    }

    /** Rankings with no date, or read more than $staleDays ago, count as dated evidence (weighted down). */
    public function rankingsCurrent(int $staleDays): bool
    {
        return $this->rankingsAsOf !== null && $this->rankingsAsOf->diffInDays(now(), true) <= $staleDays;
    }
}
