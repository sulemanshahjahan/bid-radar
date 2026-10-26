<?php

namespace Tests\Feature;

use App\Jobs\DraftProject;
use App\Jobs\ScoreProject;
use App\Services\Settings;
use App\Services\Telegram\Telegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramMobileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bidradar.telegram.bot_token' => '123:abc', 'bidradar.telegram.chat_id' => '555']);
        app(Settings::class)->set(['turnaround_notes' => 'Fix: 1-2 days']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    /** @return list<array{text: string, silent: bool}> */
    private function messages(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))
            ->map(fn ($pair) => ['text' => $pair[0]['text'], 'silent' => $pair[0]['disable_notification']])
            ->values()->all();
    }

    private function highFitDrafted(int $bids)
    {
        $project = $this->makeProject(['source' => 'freelancer'], ['bid_count' => $bids], $this->scoringReply(['fit_score' => 92, 'category' => 'fix']));
        $project->forceFill(['fit_score' => 92])->save();
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');

        DraftProject::dispatchSync($project->id, alert: true);

        return $project;
    }

    public function test_a_top_project_sends_a_loud_alert_then_the_proposal_then_bid_and_delivery(): void
    {
        $this->highFitDrafted(3);

        $m = $this->messages();
        $this->assertCount(3, $m);
        $this->assertStringContainsString('92', $m[0]['text']);
        $this->assertFalse($m[0]['silent'], 'Top project: sound on');

        $this->assertSame(htmlspecialchars($this->draftReply()['proposal_text'], ENT_QUOTES | ENT_HTML5), $m[1]['text'], 'Proposal text only');
        $this->assertTrue($m[1]['silent']);

        $this->assertSame("Bid: <code>171</code> USD\nDelivery: <code>2</code> days", $m[2]['text']);
        $this->assertTrue($m[2]['silent']);
    }

    public function test_ten_or_more_bids_makes_even_a_high_fit_alert_silent(): void
    {
        $this->highFitDrafted(10);

        $this->assertTrue($this->messages()[0]['silent']);
    }

    public function test_medium_fit_quiet_alert_is_silent_with_no_copy_messages(): void
    {
        app(Settings::class)->set(['quiet_alerts' => true]);
        $project = $this->makeProject(['source' => 'freelancer'], ['bid_count' => 2]);
        $this->claude->reply($this->scoringReply(['fit_score' => 60]));

        ScoreProject::dispatchSync($project->id);

        $m = $this->messages();
        $this->assertCount(1, $m);
        $this->assertTrue($m[0]['silent']);
    }

    public function test_an_alert_without_a_draft_sends_no_copy_messages(): void
    {
        $project = $this->makeProject(['source' => 'freelancer', 'fit_score' => 90], ['bid_count' => 2, 'client' => ['payment_verified' => false]], $this->scoringReply(['fit_score' => 90]));

        app(Telegram::class)->projectAlert($project, note: 'Not drafted: client payment not verified. Open to draft anyway.');

        $this->assertCount(1, $this->messages());
    }

    public function test_missing_delivery_says_so_instead_of_a_number(): void
    {
        $project = $this->makeProject();
        $draft = $project->drafts()->create(['proposal_text' => 'x', 'bid_amount' => 300, 'currency' => 'USD']);

        $this->assertSame("Bid: <code>300</code> USD\nDelivery: not set (no turnaround for this category)", Telegram::bidText($draft));
    }
}
