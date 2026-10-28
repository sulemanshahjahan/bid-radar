<?php

namespace Tests\Feature;

use App\Livewire\ProjectList;
use App\Livewire\StatsPage;
use App\Models\ApiCall;
use App\Models\BidSnapshot;
use App\Models\DailyStat;
use App\Models\Draft;
use App\Models\Project;
use App\Models\User;
use App\Services\Backup\ScrubbedBackup;
use App\Services\Freelancer\Poller;
use App\Services\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;

/** VACUUM cannot run inside a transaction, so these tests migrate instead of using RefreshDatabase. */
class ScrubbedBackupTest extends TestCase
{
    use DatabaseMigrations;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00'));
        $this->storage = sys_get_temp_dir().'/bidradar-backup-test-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/app/backups');
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    /** A realistic mix: what the purge deletes, what it wipes, and what must survive. */
    private function seedData(): array
    {
        $user = User::factory()->create(['email' => 'owner@example.test', 'remember_token' => 'remember-me-token']);
        app(Settings::class)->set(['hourly_rate_usd' => 40, 'positioning' => 'WordPress and WooCommerce fixes']);

        $filtered = $this->makeProject(['source' => 'freelancer', 'freelancer_id' => 1, 'title' => 'SECRET-FILTERED', 'ai_state' => 'filtered', 'filter_reason' => 'Budget too low ($16 USD)']);
        $low = $this->makeProject(['source' => 'freelancer', 'freelancer_id' => 2, 'title' => 'SECRET-LOW'], [], $this->scoringReply(['fit_score' => 20]));
        $medium = $this->makeProject(['source' => 'freelancer', 'freelancer_id' => 3, 'title' => 'SECRET-MEDIUM', 'decision' => ['decision' => 'feed', 'score' => 60]], [], $this->scoringReply(['fit_score' => 60]));
        $drafted = $this->makeProject(['source' => 'freelancer', 'freelancer_id' => 4, 'title' => 'SECRET-DRAFTED', 'status' => 'drafted'], [], $this->scoringReply(['fit_score' => 30]));
        Draft::query()->create(['project_id' => $drafted->id, 'proposal_text' => 'My proposal text', 'bid_amount' => 150]);
        $bid = $this->makeProject(['source' => 'freelancer', 'freelancer_id' => 5, 'title' => 'SECRET-BID', 'status' => 'bid_placed', 'url' => 'https://www.freelancer.com/projects/php/x',
            'bid_placed_at' => now()->subHour(), 'my_bid_amount' => 171, 'my_bid_rank' => 12, 'bid_count_at_bid' => 40, 'bid_count_observed' => 33, 'viewed' => true], [], $this->scoringReply(['fit_score' => 80]));

        BidSnapshot::query()->create(['project_id' => $bid->id, 'bid_count' => 33, 'recorded_at' => now()]);
        ApiCall::query()->create(['provider' => 'claude', 'purpose' => 'score', 'project_id' => $low->id, 'status' => 'ok']);
        DailyStat::query()->create(['date' => '2026-10-27', 'hour' => 14, 'metric' => 'discovered', 'key' => 'total', 'value' => 9]);
        DB::table('sessions')->insert(['id' => 'sess1', 'payload' => 'x', 'last_activity' => 1]);
        DB::table('cache')->insert(['key' => 'k', 'value' => 'v', 'expiration' => 9999999999]);
        DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'database', 'queue' => 'default', 'payload' => 'SECRET-PAYLOAD', 'exception' => 'x']);

        return compact('user', 'filtered', 'low', 'medium', 'drafted', 'bid');
    }

    private function latestBackup(): string
    {
        return collect(File::glob(storage_path('app/backups/'.ScrubbedBackup::PREFIX.'*.sqlite')))->sort()->last();
    }

    private function pdo(string $path): PDO
    {
        return new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    // --- Creation and scrubbing -------------------------------------------------------

    public function test_backup_keeps_what_bidradar_keeps_long_term_and_drops_freelancer_project_data(): void
    {
        $p = $this->seedData();

        $this->artisan('bidradar:backup')->assertSuccessful();

        $db = $this->pdo($this->latestBackup());
        $this->assertSame('ok', $db->query('PRAGMA integrity_check')->fetchColumn());

        // Purge rules applied to every row regardless of age.
        $ids = array_map('intval', $db->query('SELECT id FROM projects ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame([$p['medium']->id, $p['drafted']->id, $p['bid']->id], $ids, 'Untouched filtered and low-fit projects are dropped');
        foreach (ScrubbedBackup::RESTRICTED_COLUMNS as $column) {
            $this->assertSame(0, (int) $db->query("SELECT count(*) FROM projects WHERE {$column} IS NOT NULL")->fetchColumn(), "projects.{$column}");
        }
        $this->assertSame(0, (int) $db->query('SELECT count(*) FROM projects WHERE purged_at IS NULL')->fetchColumn());
        foreach (ScrubbedBackup::EMPTIED_TABLES as $table) {
            $this->assertSame(0, (int) $db->query("SELECT count(*) FROM {$table}")->fetchColumn(), $table);
        }

        // Kept: owner account, settings, drafts, bid outcomes, decisions, counts, API log.
        $user = $db->query('SELECT email, password, remember_token FROM users')->fetch();
        $this->assertSame('owner@example.test', $user['email']);
        $this->assertNotEmpty($user['password']);
        $this->assertNull($user['remember_token']);
        $this->assertSame('40', $db->query("SELECT value FROM settings WHERE key = 'hourly_rate_usd'")->fetchColumn());
        $this->assertSame('My proposal text', $db->query('SELECT proposal_text FROM drafts')->fetchColumn());
        $bid = $db->query("SELECT url, freelancer_id, status, my_bid_amount, my_bid_rank, bid_count_at_bid, bid_count_observed, viewed FROM projects WHERE id = {$p['bid']->id}")->fetch();
        $this->assertEquals(['url' => 'https://www.freelancer.com/projects/php/x', 'freelancer_id' => 5, 'status' => 'bid_placed', 'my_bid_amount' => 171,
            'my_bid_rank' => 12, 'bid_count_at_bid' => 40, 'bid_count_observed' => 33, 'viewed' => 1], $bid, 'Bid outcomes kept');
        $this->assertSame('{"decision":"feed","score":60}', $db->query("SELECT decision FROM projects WHERE id = {$p['medium']->id}")->fetchColumn());
        $this->assertSame(9, (int) $db->query('SELECT value FROM daily_stats')->fetchColumn());
        $this->assertNull($db->query('SELECT project_id FROM api_calls')->fetchColumn(), 'Log row kept, link to the dropped project cleared');
    }

    public function test_no_restricted_bytes_survive_anywhere_in_the_file(): void
    {
        $this->seedData();
        $ciphertexts = DB::table('projects')->whereNotNull('title')->pluck('title')->merge(DB::table('projects')->whereNotNull('details')->pluck('details'));

        $this->artisan('bidradar:backup')->assertSuccessful();

        $bytes = file_get_contents($this->latestBackup());
        foreach ($ciphertexts as $cipher) {
            $this->assertFalse(str_contains($bytes, $cipher), 'Encrypted project data must not remain in free pages');
        }
        foreach (['Budget too low ($16 USD)', 'SECRET-PAYLOAD', 'remember-me-token'] as $plain) {
            $this->assertFalse(str_contains($bytes, $plain), $plain);
        }
    }

    public function test_the_live_database_is_not_changed(): void
    {
        $this->seedData();
        $before = DB::table('projects')->orderBy('id')->get()->toJson();

        $this->artisan('bidradar:backup')->assertSuccessful();

        $this->assertSame($before, DB::table('projects')->orderBy('id')->get()->toJson());
        $this->assertSame(1, BidSnapshot::query()->count());
        $this->assertSame(1, DB::table('sessions')->count());
    }

    // --- Retention and clean-up -------------------------------------------------------

    public function test_retention_keeps_the_newest_scrubbed_backups_and_never_touches_full_ones(): void
    {
        $dir = storage_path('app/backups');
        foreach (['2026-10-20-033000', '2026-10-21-033000', '2026-10-22-033000'] as $stamp) {
            File::put($dir.'/'.ScrubbedBackup::PREFIX.$stamp.'.sqlite', 'old');
        }
        File::put($dir.'/bidradar-2026-10-27-033004.sqlite', 'full');

        $this->artisan('bidradar:backup', ['--keep' => 2])
            ->expectsOutputToContain('1 full backup(s) still contain Freelancer project data')
            ->assertSuccessful();

        $names = collect(File::glob($dir.'/*.sqlite'))->map(fn ($f) => basename($f))->sort()->values()->all();
        $this->assertSame(['bidradar-2026-10-27-033004.sqlite', ScrubbedBackup::PREFIX.'2026-10-22-033000.sqlite', ScrubbedBackup::PREFIX.'2026-10-28-120000.sqlite'], $names);
    }

    public function test_temp_copies_are_removed_after_success_and_stale_ones_are_cleared(): void
    {
        $tmpDir = storage_path('app/backups/.tmp');
        File::ensureDirectoryExists($tmpDir);
        File::put($tmpDir.'/scrub-crashed.sqlite', 'full copy from a crashed run');
        touch($tmpDir.'/scrub-crashed.sqlite', now()->subHours(2)->getTimestamp());

        $this->artisan('bidradar:backup')->assertSuccessful();

        $this->assertSame([], File::glob($tmpDir.'/*'));
    }

    public function test_a_failed_backup_leaves_no_copy_behind(): void
    {
        $this->seedData();
        $this->app->bind(ScrubbedBackup::class, fn ($app) => new class($app->make(Settings::class)) extends ScrubbedBackup
        {
            public function verify(string $path): array
            {
                return ['simulated failure'];
            }
        });

        $this->artisan('bidradar:backup')->expectsOutputToContain('simulated failure')->assertFailed();

        $this->assertSame([], File::glob(storage_path('app/backups/*.sqlite')));
        $this->assertSame([], File::glob(storage_path('app/backups/.tmp/*')));
    }

    public function test_verify_rejects_a_full_copy_and_accepts_a_scrubbed_one(): void
    {
        $this->seedData();
        $full = storage_path('app/backups/full-copy.sqlite');
        DB::statement('VACUUM INTO ?', [$full]);

        $this->artisan('bidradar:backup', ['--verify' => $full])->expectsOutputToContain('projects.title set on')->assertFailed();

        $this->artisan('bidradar:backup')->assertSuccessful();
        $this->artisan('bidradar:backup', ['--verify' => $this->latestBackup()])->expectsOutputToContain('OK:')->assertSuccessful();
    }

    // --- Restoration ---------------------------------------------------------------------

    public function test_a_scrubbed_backup_restores_into_a_working_bidradar(): void
    {
        $this->seedData();
        $this->artisan('bidradar:backup')->assertSuccessful();
        $restored = storage_path('restored.sqlite');
        File::copy($this->latestBackup(), $restored);

        // Point the app at the restored file, as a restore on the server would.
        config(['database.connections.sqlite.database' => $restored]);
        DB::purge('sqlite');
        $this->app->forgetInstance(Settings::class);

        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        $ran = DB::table('migrations')->pluck('migration')->all();
        $files = array_map(fn ($f) => basename($f, '.php'), File::glob(database_path('migrations/*.php')));
        $this->assertSame([], array_values(array_diff($files, $ran)), 'Schema complete: no migration pending');

        $this->assertTrue(Auth::attempt(['email' => 'owner@example.test', 'password' => 'password']), 'Owner can log in');
        $this->assertSame(40, app(Settings::class)->int('hourly_rate_usd'));
        $this->assertSame('WordPress and WooCommerce fixes', app(Settings::class)->get('positioning'));

        $this->actingAs(User::query()->firstOrFail());
        Livewire::test(ProjectList::class)->assertOk();
        Livewire::test(StatsPage::class)->assertOk()->assertViewHas('overall', fn ($o) => $o['bids'] === 1);

        // Polling works on the restored database with an empty cache.
        Queue::fake();
        config(['bidradar.freelancer.polling_enabled' => true, 'bidradar.freelancer.oauth_token' => 'test-token']);
        Http::fake([
            'www.freelancer.com/api/projects/0.1/jobs/*' => Http::response(file_get_contents(base_path('tests/fixtures/freelancer/jobs.json'))),
            'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(file_get_contents(base_path('tests/fixtures/freelancer/active_projects.json'))),
        ]);
        $this->assertSame('ok', app(Poller::class)->poll(scheduled: false)['status']);
        $this->assertGreaterThan(3, Project::query()->count());
    }

    public function test_the_daily_backup_stays_scheduled(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'bidradar:backup'));

        $this->assertNotNull($event);
        $this->assertSame('30 3 * * *', $event->expression);
    }
}
