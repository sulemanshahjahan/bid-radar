<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Opportunity\Capabilities;
use App\Services\Opportunity\Opportunity;
use App\Services\Opportunity\OpportunityInput;
use App\Services\Opportunity\OpportunityScorer;
use App\Services\Opportunity\ProfileEvidence;
use App\Services\Opportunity\ShadowRanking;
use App\Services\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Shadow-mode comparison of the existing ranking with the Opportunity Priority Score. Read-only:
 * nothing is written, no API is called. Live mode uses projects still inside the 24-hour window,
 * evaluated as at their scoring time (no later bid counts) against the score BidRadar acted on.
 * Prints IDs and categories; titles only with --titles.
 */
#[Signature('bidradar:opportunity-compare {--hours=24 : Window, at most 24} {--top=10} {--titles : Show project titles} {--synthetic : Use the synthetic set in tests/fixtures/opportunity instead of live projects}')]
#[Description('Compare the existing ranking with the shadow Opportunity score (read-only)')]
class OpportunityCompare extends Command
{
    public function handle(ShadowRanking $ranking, OpportunityScorer $scorer, Settings $settings): int
    {
        if (! ShadowRanking::enabled()) {
            $this->warn('Opportunity Intelligence is off (OPPORTUNITY_SHADOW=false).');

            return self::SUCCESS;
        }

        $rows = $this->option('synthetic') ? $this->synthetic($scorer, $settings) : $this->live($ranking);
        $rows = array_values(array_filter($rows, fn ($r) => $r['opportunity'] !== null));
        $n = count($rows);
        $this->info(($this->option('synthetic') ? 'SYNTHETIC set (not observed data)' : 'Live projects, as at scoring time').": {$n} evaluated");
        if ($n === 0) {
            return self::SUCCESS;
        }
        if (! $this->option('synthetic') && $n < 20) {
            $this->warn("Sample limitation: {$n} projects (fewer than the 20-30 wanted). Treat the comparison as indicative only.");
        }

        $existing = ShadowRanking::ranks(array_column($rows, 'existing', 'id'));
        $new = ShadowRanking::ranks(array_map(fn ($r) => $r['opportunity']->score, array_column($rows, null, 'id')));
        $byId = array_column($rows, null, 'id');
        $top = (int) $this->option('top');

        $this->newLine();
        $this->line("Top {$top}: existing ranking | opportunity ranking");
        $e = array_slice(array_keys($existing), 0, $top);
        $o = array_slice(array_keys($new), 0, $top);
        for ($i = 0; $i < min($top, $n); $i++) {
            $this->line(sprintf('%2d. %-38s | %s', $i + 1, $this->label($byId[$e[$i]], 'existing'), $this->label($byId[$o[$i]], 'opportunity')));
        }
        $this->line(sprintf('Overlap of the two top %d lists: %d', $top, count(array_intersect($e, $o))));

        $threshold = max(3, (int) round($n * 0.2));
        $groups = ['Promoted' => [], 'Demoted' => [], 'Similar' => []];
        foreach ($rows as $r) {
            $move = $existing[$r['id']] - $new[$r['id']];
            $groups[$move >= $threshold ? 'Promoted' : ($move <= -$threshold ? 'Demoted' : 'Similar')][] = [$r, $move];
        }
        foreach (['Promoted', 'Demoted'] as $group) {
            usort($groups[$group], fn ($a, $b) => abs($b[1]) <=> abs($a[1]));
            $this->newLine();
            $this->line("{$group} by {$threshold}+ places: ".count($groups[$group]));
            foreach (array_slice($groups[$group], 0, $top) as [$r, $move]) {
                $this->explain($r, $existing[$r['id']], $new[$r['id']]);
            }
        }
        $this->newLine();
        $this->line('Similar rank: '.count($groups['Similar']));

        $this->newLine();
        $this->line('Class by existing band (counts):');
        $high = $settings->int('threshold_high');
        $medium = $settings->int('threshold_medium');
        $cross = [];
        foreach ($rows as $r) {
            $band = match (true) {
                $r['existing'] === null => 'unscored', $r['existing'] >= $high => 'high', $r['existing'] >= $medium => 'medium', default => 'low'
            };
            $cross[$r['opportunity']->class][$band] = ($cross[$r['opportunity']->class][$band] ?? 0) + 1;
        }
        foreach (['A', 'B', 'C'] as $class) {
            $this->line(sprintf('  %s %-30s high %3d  medium %3d  low %3d', $class, Opportunity::CLASSES[$class], $cross[$class]['high'] ?? 0, $cross[$class]['medium'] ?? 0, $cross[$class]['low'] ?? 0));
        }

        $unknown = [];
        foreach ($rows as $r) {
            foreach ($r['opportunity']->factors as $key => $f) {
                if ($f['unknown']) {
                    $unknown[$key] = ($unknown[$key] ?? 0) + 1;
                }
            }
        }
        $this->line('Factors with missing information: '.($unknown === [] ? 'none' : implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($unknown), $unknown))));

        if (! $this->option('synthetic')) {
            $this->filtersReport($settings);
            $this->skillsReport($rows);
        }

        return self::SUCCESS;
    }

    /** @return list<array{id: int, existing: int|null, opportunity: Opportunity|null, project: Project|null, name: string, category: string|null}> */
    private function live(ShadowRanking $ranking): array
    {
        $hours = max(1, min(24, (int) $this->option('hours')));

        return array_map(fn ($r) => [
            'id' => $r['project']->id,
            'existing' => $r['existing'],
            'opportunity' => $r['opportunity'],
            'project' => $r['project'],
            'name' => $this->option('titles') ? (string) $r['project']->title : 'fl:'.($r['project']->freelancer_id ?? 'pasted'),
            'category' => $r['project']->category,
        ], $ranking->rows(ShadowRanking::DECISION, $hours));
    }

    private function synthetic(OpportunityScorer $scorer, Settings $settings): array
    {
        $set = require base_path('tests/fixtures/opportunity/cases.php');
        $profile = new ProfileEvidence($set['fact_bank'], ProfileEvidence::parseRankings($settings->list('skill_rankings')),
            ProfileEvidence::parseDate((string) $settings->get('skill_rankings_as_of', '')));
        $rows = [];
        $i = 0;
        foreach ($set['cases'] as $key => $case) {
            $rows[] = [
                'id' => ++$i,
                'existing' => $case['scoring']['fit_score'] ?? null, // stand-in for the existing score
                'opportunity' => $scorer->evaluate(OpportunityInput::fromArray($case), $profile),
                'project' => null,
                'name' => "{$key} {$case['name']}",
                'category' => $case['scoring']['category'] ?? null,
            ];
        }

        return $rows;
    }

    private function label(array $r, string $which): string
    {
        $score = $which === 'existing' ? ($r['existing'] ?? '–') : $r['opportunity']->score.' '.$r['opportunity']->class;

        return mb_strimwidth("#{$r['id']} [{$score}] ".($r['category'] ?? '?').' '.$r['name'], 0, 38, '…');
    }

    private function explain(array $r, int $from, int $to): void
    {
        $o = $r['opportunity'];
        $this->line(sprintf('  #%d %s (%s): existing %s rank %d -> opportunity %d (%s) rank %d', $r['id'], $r['name'], $r['category'] ?? '?',
            $r['existing'] ?? '–', $from, $o->score, $o->class, $to));
        $this->line('     factors: '.implode('  ', array_map(fn ($k, $f) => "{$k} {$f['points']}/{$f['weight']}", array_keys($o->factors), $o->factors)));
        foreach (array_slice($o->strengths, 0, 3) as $s) {
            $this->line('     + '.$s);
        }
        foreach (array_slice($o->risks, 0, 3) as $s) {
            $this->line('     - '.$s);
        }
    }

    /**
     * Projects stopped by an exclusion keyword are never scored, so only the deterministic signals apply:
     * specialist capability areas and fact-bank matches. Indicates possible false negatives, nothing more.
     */
    private function filtersReport(Settings $settings): void
    {
        $profile = ProfileEvidence::fromSettings($settings);
        $byKeyword = [];
        Project::query()->whereNull('purged_at')->where('ai_state', 'filtered')
            ->where('created_at', '>=', now()->subDay())->where('filter_reason', 'like', 'Excluded keyword:%')
            ->get()->each(function (Project $p) use (&$byKeyword, $profile) {
                $keyword = trim(substr((string) $p->filter_reason, strlen('Excluded keyword:')));
                $tags = Capabilities::tag($p->title."\n".$p->description."\n".implode(' | ', (array) $p->detail('skills', [])));
                $match = $profile->comparableProject($tags, null);
                $row = $byKeyword[$keyword] ?? ['total' => 0, 'specialist' => 0, 'evidence' => 0];
                $row['total']++;
                $row['specialist'] += Capabilities::specialist($tags) !== [] ? 1 : 0;
                $row['evidence'] += in_array($match['strength'] ?? null, [ProfileEvidence::STRONG, ProfileEvidence::MODERATE], true) ? 1 : 0;
                $byKeyword[$keyword] = $row;
            });

        $this->newLine();
        $this->line('Excluded by keyword in the window (possible false negatives; not scored, so indicative only):');
        if ($byKeyword === []) {
            $this->line('  none');
        }
        arsort($byKeyword);
        foreach ($byKeyword as $keyword => $row) {
            $this->line(sprintf('  %-22s %3d excluded, %3d with a specialist area, %3d with comparable fact-bank work', $keyword, $row['total'], $row['specialist'], $row['evidence']));
        }
    }

    /** Freelancer skills on A/B projects that are not target skills: what discovery already brings in through other skills. */
    private function skillsReport(array $rows): void
    {
        $targets = array_map('mb_strtolower', app(Settings::class)->list('target_skills'));
        $counts = [];
        foreach ($rows as $r) {
            if (in_array($r['opportunity']->class, ['A', 'B'], true)) {
                foreach ((array) $r['project']->detail('skills', []) as $skill) {
                    if (! in_array(mb_strtolower((string) $skill), $targets, true)) {
                        $counts[$skill] = ($counts[$skill] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($counts);
        $this->newLine();
        $this->line('Non-target skills on A/B projects already found (not incremental discovery):');
        $this->line('  '.($counts === [] ? 'none' : implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys(array_slice($counts, 0, 15, true)), array_slice($counts, 0, 15, true)))));
    }
}
