<?php

namespace Tests\Feature;

use App\Jobs\ScoreProject;
use App\Models\ApiCall;
use App\Models\Project;
use App\Services\Freelancer\Poller;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PollerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(1793000700));
        config([
            'bidradar.freelancer.polling_enabled' => true,
            'bidradar.freelancer.oauth_token' => 'test-token',
        ]);
        app(Settings::class)->set(['polling_enabled' => true]);
        Queue::fake();
    }

    private function fakeApi(int $activeStatus = 200): void
    {
        Http::fake([
            'www.freelancer.com/api/projects/0.1/jobs/*' => Http::response(file_get_contents(base_path('tests/fixtures/freelancer/jobs.json'))),
            'www.freelancer.com/api/projects/0.1/projects/active/*' => $activeStatus === 200
                ? Http::response(file_get_contents(base_path('tests/fixtures/freelancer/active_projects.json')))
                : Http::response(['status' => 'error', 'error_code' => 'AuthorisationExceptionCodes.RATE_LIMITED'], $activeStatus),
        ]);
    }

    public function test_nothing_is_requested_without_the_env_permission_flag(): void
    {
        config(['bidradar.freelancer.polling_enabled' => false]);
        Http::fake();

        $result = app(Poller::class)->poll();

        $this->assertSame('off', $result['status']);
        Http::assertNothingSent();
    }

    public function test_nothing_is_requested_when_switched_off_or_paused(): void
    {
        Http::fake();

        app(Settings::class)->set(['polling_enabled' => false]);
        $this->assertSame('off', app(Poller::class)->poll()['status']);

        app(Settings::class)->set(['polling_enabled' => true, 'paused' => true]);
        $this->assertSame('paused', app(Poller::class)->poll()['status']);

        Http::assertNothingSent();
    }

    public function test_poll_stores_new_projects_filters_them_and_queues_only_passing_ones(): void
    {
        $this->fakeApi();

        $result = app(Poller::class)->poll();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(7, Project::query()->count());
        $this->assertSame(3, Project::query()->where('ai_state', 'queued')->count(), '101, 103 (unverified, scored with a warning) and 106');
        $this->assertSame(4, Project::query()->where('ai_state', 'filtered')->count());
        Queue::assertPushed(ScoreProject::class, 3);

        $p = Project::query()->where('freelancer_id', 101)->sole();
        $this->assertSame('freelancer', $p->source);
        $this->assertSame('https://www.freelancer.com/projects/php/woocommerce-checkout-failing-after-update', $p->url);
        $this->assertTrue($p->detail('client.payment_verified'));
    }

    public function test_request_uses_the_documented_endpoint_params_and_auth_header(): void
    {
        $this->fakeApi();

        app(Poller::class)->poll();

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'projects/0.1/projects/active/')) {
                return false;
            }
            $query = urldecode(parse_url($request->url(), PHP_URL_QUERY));

            return $request->method() === 'GET'
                && $request->hasHeader('freelancer-oauth-v1', 'test-token')
                && str_contains($query, 'jobs[]=17')
                && str_contains($query, 'jobs[]=1234')
                && str_contains($query, 'full_description=true')
                && str_contains($query, 'user_employer_reputation=true')
                && str_contains($query, 'sort_field=time_updated');
        });
        Http::assertSentCount(2); // one jobs lookup + one search; the jobs lookup is cached
    }

    public function test_only_get_requests_are_ever_sent(): void
    {
        $this->fakeApi();

        app(Poller::class)->poll();

        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_known_projects_are_not_stored_twice(): void
    {
        $this->fakeApi();

        app(Poller::class)->poll();
        Carbon::setTestNow(now()->addMinutes(3));
        app(Poller::class)->poll();

        $this->assertSame(7, Project::query()->count());
        Queue::assertPushed(ScoreProject::class, 3);
    }

    public function test_interval_is_respected(): void
    {
        $this->fakeApi();

        app(Poller::class)->poll();
        Carbon::setTestNow(now()->addSeconds(30));

        $this->assertSame('waiting', app(Poller::class)->poll()['status']);
        Http::assertSentCount(2);
    }

    public function test_429_backs_off_exponentially_and_skips_the_cycle(): void
    {
        $this->fakeApi(429);

        $first = app(Poller::class)->poll();
        $this->assertSame('error', $first['status']);
        $this->assertStringContainsString('backing off 2 min', $first['message']);

        // During the backoff window no request is sent at all.
        Carbon::setTestNow(now()->addMinute());
        $this->assertSame('backoff', app(Poller::class)->poll()['status']);

        // Next failure doubles the wait.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertStringContainsString('backing off 4 min', app(Poller::class)->poll()['message']);

        $this->assertSame(0, Project::query()->count());
        $this->assertSame(2, ApiCall::query()->where('provider', 'freelancer')->where('http_status', 429)->count());
    }

    public function test_api_calls_are_logged_without_the_token(): void
    {
        $this->fakeApi();

        app(Poller::class)->poll();

        $this->assertSame(2, ApiCall::query()->where('provider', 'freelancer')->where('status', 'ok')->count());
        $this->assertFalse(ApiCall::query()->where('error', 'like', '%test-token%')->exists());
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->fakeApi();

        $result = app(Poller::class)->poll(scheduled: false, dryRun: true);

        $this->assertCount(7, $result['projects']);
        $this->assertSame(0, Project::query()->count());
        Queue::assertNothingPushed();
    }
}
