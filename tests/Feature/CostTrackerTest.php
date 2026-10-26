<?php

namespace Tests\Feature;

use App\Jobs\DraftProject;
use App\Models\ApiCall;
use App\Services\CostTracker;
use App\Services\Drafting\Drafter;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cost_uses_per_model_prices_including_cache_tokens(): void
    {
        $costs = app(CostTracker::class);

        // Sonnet 5.5: $2 in, $10 out, $2.50 cache write, $0.10 cache read per MTok.
        $this->assertEqualsWithDelta(0.017, $costs->cost('claude-sonnet-5-5', 2000, 1000, 1000, 5000), 1e-9);
        // Haiku 5.5: $0.10 in, $0.50 out.
        $this->assertEqualsWithDelta(0.0002, $costs->cost('claude-haiku-5-5', 1000, 200), 1e-9);
        $this->assertSame(0.0, $costs->cost('unknown-model', 1000, 1000));
    }

    public function test_haiku_long_prompts_use_the_long_context_price(): void
    {
        $this->assertEqualsWithDelta(0.06, app(CostTracker::class)->cost('claude-haiku-5-5', 120_000, 0), 1e-9);
    }

    public function test_every_claude_call_is_logged_with_tokens_and_cost(): void
    {
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5', 3000, 500);

        (new DraftProject($project->id))->handle(app(Drafter::class), app(CostTracker::class));

        $call = ApiCall::query()->sole();
        $this->assertSame('claude', $call->provider);
        $this->assertSame('draft', $call->purpose);
        $this->assertSame('ok', $call->status);
        $this->assertSame(3000, $call->input_tokens);
        $this->assertEqualsWithDelta(0.011, (float) $call->cost_usd, 1e-6);
    }

    public function test_drafting_stops_when_the_daily_cap_is_reached(): void
    {
        app(Settings::class)->set(['daily_spend_cap_usd' => 2]);
        ApiCall::query()->create(['provider' => 'claude', 'purpose' => 'draft', 'status' => 'ok', 'cost_usd' => 2.01]);
        $project = $this->makeProject([], [], $this->scoringReply());

        DraftProject::dispatchSync($project->id);

        $this->assertSame('cap_reached', $project->fresh()->ai_state);
        $this->assertStringContainsString('cap reached', $project->fresh()->ai_error);
        $this->assertCount(0, $this->claude->requests);
        $this->assertSame(0, $project->drafts()->count());
    }

    public function test_spend_from_yesterday_does_not_count_toward_today(): void
    {
        ApiCall::query()->create(['provider' => 'claude', 'purpose' => 'draft', 'status' => 'ok', 'cost_usd' => 5])
            ->forceFill(['created_at' => now()->subDay()])->save();

        $this->assertFalse(app(CostTracker::class)->capReached());
    }

    public function test_a_project_is_not_drafted_twice_without_regenerate(): void
    {
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');

        DraftProject::dispatchSync($project->id);
        DraftProject::dispatchSync($project->id);

        $this->assertCount(1, $this->claude->requests);
        $this->assertSame(1, $project->drafts()->count());
    }
}
