<?php

namespace Tests\Feature;

use App\Jobs\DraftProject;
use App\Jobs\ScoreProject;
use App\Models\Project;
use App\Services\Settings;
use App\Services\Telegram\Telegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PurgeAndTelegramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bidradar.telegram.bot_token' => '123:secret-bot-token',
            'bidradar.telegram.chat_id' => '555',
            'bidradar.telegram.webhook_secret' => 'hook-secret',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    }

    // --- Purge -------------------------------------------------------------

    public function test_purge_wipes_project_data_older_than_24_hours_and_keeps_my_work(): void
    {
        $old = $this->makeProject(['status' => 'bid_placed', 'url' => 'https://www.freelancer.com/projects/x'], [], $this->scoringReply());
        $old->drafts()->create(['proposal_text' => 'My proposal', 'bid_amount' => 171]);
        $old->forceFill(['created_at' => now()->subHours(25)])->save();

        $fresh = $this->makeProject([], [], $this->scoringReply());

        $this->artisan('bidradar:purge')->assertSuccessful();

        $old->refresh();
        $this->assertNull($old->title);
        $this->assertNull($old->description);
        $this->assertNull($old->details);
        $this->assertNull($old->scoring);
        $this->assertNotNull($old->purged_at);
        $this->assertSame('https://www.freelancer.com/projects/x', $old->url);
        $this->assertSame(88, $old->fit_score);
        $this->assertSame('fix', $old->category);
        $this->assertSame('My proposal', $old->latestDraft->proposal_text);

        $this->assertNotNull($fresh->fresh()->description);
    }

    public function test_purge_deletes_old_filtered_and_low_fit_rows_entirely(): void
    {
        $filtered = $this->makeProject(['ai_state' => 'filtered', 'filter_reason' => 'No budget']);
        $low = $this->makeProject([], [], $this->scoringReply(['fit_score' => 20]));
        Project::query()->update(['created_at' => now()->subHours(25)]);

        $this->artisan('bidradar:purge')->assertSuccessful();

        $this->assertNull(Project::query()->find($filtered->id));
        $this->assertNull(Project::query()->find($low->id));
    }

    // --- Alerts ------------------------------------------------------------

    public function test_high_fit_live_project_is_drafted_then_alerted_to_my_chat_only(): void
    {
        $project = $this->makeProject(['source' => 'freelancer', 'url' => 'https://www.freelancer.com/projects/php/x']);
        $this->claude->reply($this->scoringReply(['fit_score' => 92, 'client_test_phrase' => 'PURPLE']))
            ->reply($this->draftReply(['proposal_text' => 'PURPLE. '.$this->draftReply()['proposal_text']]), 'claude-sonnet-5-5');

        ScoreProject::dispatchSync($project->id);

        $this->assertSame('drafted', $project->fresh()->ai_state);
        $this->assertNotNull($project->fresh()->alerted_at);
        Http::assertSent(function (Request $r) {
            return str_contains($r->url(), '/sendMessage')
                && $r['chat_id'] === '555'
                && str_contains($r['text'], '92')
                && str_contains($r['text'], 'Client test phrase')
                && $r['reply_markup']['inline_keyboard'][0][1]['url'] === 'https://www.freelancer.com/projects/php/x'
                && $r['disable_notification'] === false;
        });
    }

    public function test_alert_still_goes_out_when_the_cap_is_reached_and_says_so(): void
    {
        app(Settings::class)->set(['daily_spend_cap_usd' => 0]);
        $project = $this->makeProject(['source' => 'freelancer'], [], $this->scoringReply(['fit_score' => 90]));

        DraftProject::dispatchSync($project->id, alert: true);

        $this->assertSame('cap_reached', $project->fresh()->ai_state);
        Http::assertSent(fn (Request $r) => str_contains($r['text'] ?? '', 'cap reached'));
    }

    public function test_medium_fit_gets_a_quiet_alert_only_when_enabled(): void
    {
        $project = $this->makeProject(['source' => 'freelancer']);
        $this->claude->reply($this->scoringReply(['fit_score' => 60]));
        ScoreProject::dispatchSync($project->id);
        Http::assertNothingSent();

        app(Settings::class)->set(['quiet_alerts' => true]);
        $other = $this->makeProject(['source' => 'freelancer']);
        $this->claude->reply($this->scoringReply(['fit_score' => 60]));
        ScoreProject::dispatchSync($other->id);

        Http::assertSent(fn (Request $r) => $r['disable_notification'] === true);
        $this->assertSame(0, $other->drafts()->count());
    }

    public function test_pasted_projects_never_alert(): void
    {
        $project = $this->makeProject(['source' => 'paste', 'auto_draft' => true]);
        $this->claude->reply($this->scoringReply(['fit_score' => 95]))->reply($this->draftReply(), 'claude-sonnet-5-5');

        ScoreProject::dispatchSync($project->id);

        Http::assertNothingSent();
    }

    public function test_alert_text_escapes_client_html(): void
    {
        $project = $this->makeProject(['title' => '<b>Fix</b> & <script>'], [], $this->scoringReply());

        $text = Telegram::alertText($project);

        $this->assertStringContainsString('&lt;b&gt;Fix&lt;/b&gt; &amp; &lt;script&gt;', $text);
    }

    // --- Webhook / commands -------------------------------------------------

    private function webhook(array $message, string $secret = 'hook-secret')
    {
        return $this->postJson('/telegram/webhook', ['update_id' => 1, 'message' => $message], ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    public function test_webhook_rejects_a_wrong_secret(): void
    {
        $this->webhook(['chat' => ['id' => 555], 'text' => '/pause'], 'wrong')->assertForbidden();
        $this->assertFalse((bool) app(Settings::class)->get('paused'));
    }

    public function test_messages_from_other_chats_are_ignored(): void
    {
        $this->webhook(['chat' => ['id' => 999], 'text' => '/pause'])->assertOk();

        $this->assertFalse((bool) app(Settings::class)->get('paused'));
        Http::assertNothingSent();
    }

    public function test_pause_resume_and_today_commands(): void
    {
        $this->webhook(['chat' => ['id' => 555], 'text' => '/pause'])->assertOk();
        $this->assertTrue((bool) app(Settings::class)->get('paused'));

        $this->webhook(['chat' => ['id' => 555], 'text' => '/resume'])->assertOk();
        $this->assertFalse((bool) app(Settings::class)->get('paused'));

        $this->webhook(['chat' => ['id' => 555], 'text' => '/today'])->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r['text'] ?? '', 'Bids placed') && str_contains($r['text'], 'AI spend'));
    }

    public function test_bot_token_is_redacted_from_error_text(): void
    {
        $this->assertSame(
            'cURL error for https://api.telegram.org/bot[redacted]/sendMessage',
            Telegram::redact('cURL error for https://api.telegram.org/bot123:secret-bot-token/sendMessage'),
        );
    }
}
