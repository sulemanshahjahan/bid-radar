<?php

namespace Tests\Feature;

use App\Jobs\DraftProject;
use App\Jobs\ScoreProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DatabaseLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_file_sqlite_connection_waits_for_locks_and_uses_wal(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bidradar').'.sqlite';
        touch($file);
        config(['database.connections.locktest' => array_merge(config('database.connections.sqlite'), ['database' => $file])]);

        try {
            $db = DB::connection('locktest');
            $this->assertSame('wal', strtolower((string) $db->selectOne('PRAGMA journal_mode')->journal_mode));
            $this->assertSame(10000, (int) $db->selectOne('PRAGMA busy_timeout')->timeout);
            $this->assertSame('IMMEDIATE', config('database.connections.sqlite.transaction_mode'));
        } finally {
            DB::purge('locktest');
            foreach ([$file, $file.'-wal', $file.'-shm'] as $f) {
                @unlink($f);
            }
        }
    }

    public function test_a_scoring_job_that_dies_outside_handle_marks_the_project_failed(): void
    {
        $project = $this->makeProject(['ai_state' => 'queued']);

        (new ScoreProject($project->id))->failed(new RuntimeException('SQLSTATE[HY000]: General error: 5 database is locked'));

        $project->refresh();
        $this->assertSame('failed', $project->ai_state);
        $this->assertStringContainsString('database is locked', $project->ai_error);
    }

    public function test_a_drafting_job_that_dies_marks_the_project_failed_but_leaves_finished_ones_alone(): void
    {
        $drafting = $this->makeProject(['ai_state' => 'drafting']);
        $done = $this->makeProject(['ai_state' => 'drafted']);

        (new DraftProject($drafting->id))->failed(new RuntimeException('database is locked'));
        (new DraftProject($done->id))->failed(new RuntimeException('database is locked'));

        $this->assertSame('failed', $drafting->fresh()->ai_state);
        $this->assertSame('drafted', $done->fresh()->ai_state);
    }

    public function test_average_bid_is_shown_rounded(): void
    {
        $this->actingAs(User::factory()->create());
        $this->makeProject([], ['bid_avg' => 367.60416666667], $this->scoringReply());

        $this->get('/')->assertSee('avg 368')->assertDontSee('367.604');
    }
}
