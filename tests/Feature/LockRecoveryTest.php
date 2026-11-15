<?php

namespace Tests\Feature;

use App\Jobs\DraftProject;
use App\Jobs\ScoreProject;
use App\Services\LockRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A project that failed only because SQLite was locked (outside the job's own retry, e.g. while the
 * worker reserved the job row) is queued again by the watchdog instead of being lost.
 */
class LockRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const LOCK = 'Scoring failed: SQLSTATE[HY000]: General error: 5 database is locked (Connection: sqlite, SQL: update "jobs" set "reserved_at" = ...)';

    public function test_a_recent_lock_failure_is_scored_again(): void
    {
        Queue::fake();
        $project = $this->makeProject(['ai_state' => 'failed', 'ai_error' => self::LOCK]);

        $this->assertSame([$project->id], app(LockRecovery::class)->requeue());

        Queue::assertPushed(ScoreProject::class, fn ($job) => $job->projectId === $project->id);
        $this->assertSame('queued', $project->fresh()->ai_state);
        $this->assertNull($project->fresh()->ai_error);
    }

    public function test_a_lock_failure_while_drafting_only_redrafts(): void
    {
        Queue::fake();
        $project = $this->makeProject(['ai_state' => 'failed', 'alerted_at' => now(),
            'ai_error' => 'Drafting failed: SQLSTATE[HY000]: General error: 5 database is locked']);

        app(LockRecovery::class)->requeue();

        Queue::assertNotPushed(ScoreProject::class);
        Queue::assertPushed(DraftProject::class, fn ($job) => $job->projectId === $project->id && $job->fast && $job->alert);
    }

    public function test_other_failures_and_old_projects_are_left_alone(): void
    {
        Queue::fake();
        $this->makeProject(['ai_state' => 'failed', 'ai_error' => 'Scoring failed: Claude API timed out']);
        $old = $this->makeProject(['ai_state' => 'failed', 'ai_error' => self::LOCK]);
        $old->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

        $this->assertSame([], app(LockRecovery::class)->requeue());
        Queue::assertNothingPushed();
    }

    public function test_requeues_stop_after_the_limit(): void
    {
        Queue::fake();
        $project = $this->makeProject(['ai_state' => 'failed', 'ai_error' => self::LOCK]);

        for ($i = 0; $i < LockRecovery::MAX_REQUEUES + 2; $i++) {
            $project->newQuery()->whereKey($project->id)->update(['ai_state' => 'failed', 'ai_error' => self::LOCK]);
            app(LockRecovery::class)->requeue();
        }

        Queue::assertPushed(ScoreProject::class, LockRecovery::MAX_REQUEUES);
        $this->assertSame('failed', $project->fresh()->ai_state);
    }

    public function test_the_watchdog_runs_the_recovery(): void
    {
        Queue::fake();
        $project = $this->makeProject(['ai_state' => 'failed', 'ai_error' => self::LOCK]);

        $this->artisan('bidradar:watchdog')->assertSuccessful();

        Queue::assertPushed(ScoreProject::class, fn ($job) => $job->projectId === $project->id);
    }
}
