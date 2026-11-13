<?php

namespace Tests\Feature;

use App\Jobs\DraftProject;
use App\Jobs\ScoreProject;
use App\Models\Project;
use App\Services\Drafting\Drafter;
use App\Services\Scoring\Scorer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * A "database is locked" error (SQLite, one writer at a time) releases the job to run again
 * a few seconds later instead of failing the project. Any other error still fails at once.
 */
class LockRetryTest extends TestCase
{
    use RefreshDatabase;

    private static function lock(): QueryException
    {
        return new QueryException('sqlite', 'update "projects" set ...', [], new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
    }

    private function scorerThrowing(\Throwable $e): void
    {
        $scorer = Mockery::mock(Scorer::class);
        $scorer->shouldReceive('score')->andThrow($e);
        $this->app->instance(Scorer::class, $scorer);
    }

    public function test_lock_errors_are_recognised_through_wrapped_exceptions(): void
    {
        $this->assertTrue(ScoreProject::isDatabaseLock(self::lock()));
        $this->assertTrue(ScoreProject::isDatabaseLock(new RuntimeException('outer', 0, self::lock())));
        $this->assertFalse(ScoreProject::isDatabaseLock(new RuntimeException('Claude API timed out')));
        $this->assertFalse(ScoreProject::isDatabaseLock(null));
    }

    public function test_a_locked_database_releases_scoring_to_run_again(): void
    {
        $project = $this->makeProject();
        $this->scorerThrowing(self::lock());

        $job = (new ScoreProject($project->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(3);
        $job->assertNotFailed();
        $this->assertNotSame('failed', $project->fresh()->ai_state);
    }

    public function test_the_last_attempt_fails_the_project(): void
    {
        $project = $this->makeProject();
        $this->scorerThrowing(self::lock());

        $job = (new ScoreProject($project->id))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;
        app()->call([$job, 'handle']);

        $job->assertNotReleased();
        $job->assertFailed();
    }

    public function test_other_scoring_errors_still_fail_at_once_without_a_retry(): void
    {
        $project = $this->makeProject();
        $this->scorerThrowing(new RuntimeException('Claude API timed out'));

        $job = (new ScoreProject($project->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertNotReleased();
        $this->assertSame('failed', $project->fresh()->ai_state);
        $this->assertStringContainsString('Claude API timed out', $project->fresh()->ai_error);
    }

    public function test_a_locked_database_releases_drafting_to_run_again(): void
    {
        $project = $this->makeProject([], [], $this->scoringReply());
        $drafter = Mockery::mock(Drafter::class);
        $drafter->shouldReceive('draft')->andThrow(self::lock());
        $this->app->instance(Drafter::class, $drafter);

        $job = (new DraftProject($project->id, fast: true))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(3);
        $this->assertNotSame('failed', Project::query()->find($project->id)->ai_state);
    }
}
