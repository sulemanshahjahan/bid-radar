<?php

namespace Tests\Feature;

use App\Http\Controllers\AlertFeedController;
use App\Jobs\DraftProject;
use App\Jobs\ScoreProject;
use App\Models\ApiCall;
use App\Services\Drafting\Drafter;
use App\Services\Drafting\ProposalQuality;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Drafts the owner can bid with within a minute or two of an alert: scoring never waits
 * behind a draft, automatic drafts take one call at the alert effort, and the alert feed
 * lists a project again once its draft is written.
 */
class FastDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoring_and_drafting_run_on_their_own_queues(): void
    {
        Queue::fake();

        ScoreProject::dispatch(1);
        DraftProject::dispatch(1);

        Queue::assertPushedOn('scoring', ScoreProject::class);
        Queue::assertPushedOn('drafting', DraftProject::class);
        $this->assertSame('scoring', ScoreProject::QUEUE);
        $this->assertSame('drafting', DraftProject::QUEUE);
    }

    public function test_a_high_fit_project_is_drafted_as_a_fast_draft_on_the_drafting_queue(): void
    {
        Queue::fake();
        $project = $this->makeProject();
        $this->claude->reply($this->scoringReply(['fit_score' => 88]));

        app()->call([new ScoreProject($project->id), 'handle']); // run scoring itself; the fake queue catches the draft

        Queue::assertPushedOn('drafting', DraftProject::class, fn (DraftProject $job) => $job->projectId === $project->id && $job->fast);
    }

    public function test_a_fast_draft_takes_one_call_at_the_alert_effort_even_when_it_rates_itself_weak(): void
    {
        $weak = array_fill_keys(array_keys(ProposalQuality::CRITERIA), 3);
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(['self_review' => $weak]), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project, fast: true);

        $this->assertCount(1, $this->claude->requests, 'No quality revision for a fast draft.');
        $this->assertSame('low', $this->claude->requests[0]->effort);
        $this->assertSame(1, $draft->attempts);
        $this->assertNull($draft->strategy['revised']);
        $this->assertTrue($draft->strategy['fast']);
        $this->assertSame('low', $draft->strategy['effort']);
        $this->assertNull($draft->check_failures);
        $this->assertSame(1, ApiCall::query()->where('purpose', 'draft')->count());
    }

    public function test_the_alert_effort_is_a_setting(): void
    {
        app(Settings::class)->set(['alert_drafting_effort' => 'medium']);
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');

        app(Drafter::class)->draft($project, fast: true);

        $this->assertSame('medium', $this->claude->requests[0]->effort);
    }

    public function test_a_fast_draft_that_breaks_a_rule_is_still_retried_once(): void
    {
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude
            ->reply($this->draftReply(['proposal_text' => 'I guarantee it. Email me at a@b.com.']), 'claude-sonnet-5-5')
            ->reply($this->draftReply(), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project, fast: true);

        $this->assertCount(2, $this->claude->requests);
        $this->assertStringContainsString('Your previous draft failed these checks', $this->claude->requests[1]->userContent);
        $this->assertSame('checks', $draft->strategy['revised']);
        $this->assertNull($draft->check_failures);
    }

    public function test_a_fast_draft_uses_the_short_schema_and_the_alert_model(): void
    {
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(), 'claude-haiku-5-5');

        $draft = app(Drafter::class)->draft($project, fast: true);

        $request = $this->claude->requests[0];
        $this->assertSame('claude-haiku-5-5', $request->model);
        $this->assertArrayNotHasKey('self_review', $request->schema['properties']);
        $this->assertArrayNotHasKey('questions_to_ask_later', $request->schema['properties']);
        $this->assertArrayNotHasKey('hook_candidates', $request->schema['properties']['plan']['properties']);
        $this->assertStringStartsWith('FAST DRAFT', $request->userContent);
        $this->assertNotSame('', trim($draft->proposal_text));
    }

    public function test_a_tapped_draft_keeps_the_full_schema_and_the_drafting_model(): void
    {
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');

        app(Drafter::class)->draft($project);

        $request = $this->claude->requests[0];
        $this->assertSame('claude-sonnet-5-5', $request->model);
        $this->assertArrayHasKey('self_review', $request->schema['properties']);
        $this->assertStringStartsNotWith('FAST DRAFT', $request->userContent);
    }

    public function test_a_tapped_draft_keeps_the_quality_revision_and_the_drafting_effort(): void
    {
        $weak = array_fill_keys(array_keys(ProposalQuality::CRITERIA), 3);
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude
            ->reply($this->draftReply(['self_review' => $weak]), 'claude-sonnet-5-5')
            ->reply($this->draftReply(), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $this->assertCount(2, $this->claude->requests);
        $this->assertSame('medium', $this->claude->requests[0]->effort);
        $this->assertSame('quality', $draft->strategy['revised']);
        $this->assertFalse($draft->strategy['fast']);
    }

    public function test_the_draft_project_job_passes_fast_to_the_drafter(): void
    {
        $weak = array_fill_keys(array_keys(ProposalQuality::CRITERIA), 3);
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(['self_review' => $weak]), 'claude-sonnet-5-5');

        DraftProject::dispatchSync($project->id, fast: true);

        $this->assertCount(1, $this->claude->requests);
        $this->assertSame('drafted', $project->fresh()->ai_state);
    }

    public function test_try_drafts_can_time_fast_drafts(): void
    {
        config(['bidradar.claude.api_key' => 'test-key']);
        $weak = array_fill_keys(array_keys(ProposalQuality::CRITERIA), 3);
        $this->claude
            ->reply($this->scoringReply(['category' => 'fix', 'estimated_hours' => 2]))
            // The generic reply fails brief C's checks, so the hard-check retry still runs.
            ->reply($this->draftReply(['self_review' => $weak]), 'claude-sonnet-5-5')
            ->reply($this->draftReply(['self_review' => $weak]), 'claude-sonnet-5-5');

        $this->artisan('bidradar:try-drafts', ['--only' => 'C', '--fast' => true])
            ->expectsOutputToContain('call(s) (fast: low effort)') // the "draft time ... over N call(s)" line
            ->assertSuccessful();

        $this->assertSame('low', $this->claude->requests[1]->effort);
        $this->assertSame(2, ApiCall::query()->where('purpose', 'draft')->whereNotNull('duration_ms')->count());
    }

    public function test_the_feed_lists_a_project_again_once_its_draft_is_written(): void
    {
        $token = 'feed-token';
        app(Settings::class)->set([AlertFeedController::TOKEN_HASH => hash('sha256', $token)]);
        $this->travelTo(now()->subMinutes(10));
        $project = $this->makeProject(['source' => 'freelancer', 'url' => 'https://www.freelancer.com/projects/x', 'scored_at' => now()], [], $this->scoringReply());
        $this->travelBack();

        // First sight: scored inside the window, no draft yet.
        $first = $this->withToken($token)->get('/alerts/feed?since='.now()->subMinutes(15)->getTimestamp())->assertOk()->json('projects');
        $this->assertSame([$project->id], array_column($first, 'id'));
        $this->assertNull($first[0]['draft']);
        $this->assertNull($first[0]['draft_ready_at']);

        // The watcher moves its cursor past the scoring time: nothing new yet.
        $since = now()->subMinutes(5)->getTimestamp();
        $this->assertSame([], $this->withToken($token)->get('/alerts/feed?since='.$since)->assertOk()->json('projects'));

        // The draft lands: the project comes back with it.
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');
        DraftProject::dispatchSync($project->id, fast: true);

        $again = $this->withToken($token)->get('/alerts/feed?since='.$since)->assertOk()->json('projects');
        $this->assertSame([$project->id], array_column($again, 'id'));
        $this->assertSame(171, (int) $again[0]['draft']['bid_amount']);
        $this->assertStringStartsWith('A checkout that spins forever', $again[0]['draft']['text']);
        $this->assertGreaterThan($since, $again[0]['draft_ready_at']);

        // Still token-protected.
        $this->withToken('wrong')->get('/alerts/feed?since='.$since)->assertStatus(401);
    }
}
