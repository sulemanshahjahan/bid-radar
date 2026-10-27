<?php

namespace App\Console\Commands;

use App\Models\ApiCall;
use App\Models\Project;
use App\Services\CostTracker;
use App\Services\Drafting\Drafter;
use App\Services\Scoring\Scorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Runs evaluation briefs through real scoring and drafting with the current fact
 * bank, prints each proposal, and saves nothing except the API cost log (so the
 * daily cap still counts it). Used to compare prompt versions on the same briefs.
 */
#[Signature('bidradar:try-drafts {--only= : Comma-separated brief keys, e.g. A,B,C} {--file= : Briefs file (default tests/fixtures/proposals/briefs.php)} {--out= : Also write the results as JSON to this path}')]
#[Description('Score and draft the evaluation briefs with the real models, without saving projects or drafts')]
class TryDrafts extends Command
{
    public function handle(Scorer $scorer, Drafter $drafter, CostTracker $costs): int
    {
        $file = $this->option('file') ?: base_path('tests/fixtures/proposals/briefs.php');
        $briefs = require $file;
        if ($only = $this->option('only')) {
            $briefs = array_intersect_key($briefs, array_flip(array_map('trim', explode(',', $only))));
        }
        if (! config('bidradar.claude.api_key')) {
            $this->error('ANTHROPIC_API_KEY is not set.');

            return self::FAILURE;
        }

        $results = [];
        foreach ($briefs as $key => $brief) {
            if ($costs->capReached()) {
                $this->warn('Daily AI spend cap reached; stopping.');
                break;
            }

            $lastCall = (int) ApiCall::query()->max('id');
            DB::beginTransaction();
            try {
                $project = Project::query()->create([
                    'source' => 'paste',
                    'title' => $brief['title'],
                    'description' => $brief['description'],
                    'details' => array_merge(['type' => 'fixed', 'currency' => 'USD', 'exchange_rate' => 1, 'client' => ['payment_verified' => true]], $brief['details'] ?? []),
                    'ai_state' => 'scoring',
                ]);
                $scoring = $scorer->score($project);
                $project->update(['scoring' => $scoring, 'category' => $scoring['category'], 'fit_score' => $scoring['fit_score']]);
                $draft = $drafter->draft($project->fresh());
                $results[$key] = [
                    'name' => $brief['name'],
                    'expect' => $brief['expect'] ?? '',
                    'prompt_version' => $draft->prompt_version ?? 'v1',
                    'category' => $scoring['category'],
                    'estimated_hours' => $scoring['estimated_hours'],
                    'delivery_days' => $draft->delivery_days,
                    'bid' => (float) $draft->bid_amount,
                    'proposal' => $draft->proposal_text,
                    'words' => str_word_count($draft->proposal_text),
                    'check_failures' => $draft->check_failures ?? [],
                    'attempts' => $draft->attempts,
                    'strategy' => $draft->strategy ?? null,
                ];
            } catch (Throwable $e) {
                $results[$key] = ['name' => $brief['name'], 'error' => $e->getMessage()];
            } finally {
                $calls = ApiCall::query()->where('id', '>', $lastCall)->get()
                    ->map(fn (ApiCall $c) => array_merge(Arr::except($c->getAttributes(), ['id']), ['project_id' => null]))->all();
                DB::rollBack();
                // Keep the spend on record: the cap must still see it.
                if ($calls !== []) {
                    ApiCall::query()->insert($calls);
                }
            }

            $this->printResult($key, $results[$key]);
        }

        if ($out = $this->option('out')) {
            File::put($out, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info("Results written to {$out}");
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $r */
    private function printResult(string $key, array $r): void
    {
        $this->newLine();
        $this->line("<options=bold>[{$key}] {$r['name']}</>");
        if (isset($r['error'])) {
            $this->error('  '.$r['error']);

            return;
        }
        $this->line(sprintf('  %s · %s · est %s h · delivery %s · bid %s · %d words · %d attempt(s)',
            $r['prompt_version'], $r['category'], $r['estimated_hours'] ?? '?', $r['delivery_days'] ?? 'none', $r['bid'], $r['words'], $r['attempts']));
        if (! empty($r['strategy']['hook_type'])) {
            $this->line('  hook: '.$r['strategy']['hook_type'].' · self-review '.($r['strategy']['quality'] ?? '?').'/100');
        }
        $this->newLine();
        $this->line($r['proposal']);
        foreach ($r['check_failures'] as $failure) {
            $this->warn('  ! '.$failure);
        }
        $this->line('  <fg=gray>Look for: '.$r['expect'].'</>');
    }
}
