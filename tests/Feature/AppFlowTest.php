<?php

namespace Tests\Feature;

use App\Jobs\DraftProject;
use App\Jobs\ScoreProject;
use App\Livewire\PasteProject;
use App\Livewire\ProjectShow;
use App\Livewire\SettingsPage;
use App\Models\PastProject;
use App\Models\Project;
use App\Models\User;
use App\Models\WinningExample;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppFlowTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    public function test_guests_are_sent_to_login_and_pages_are_noindex(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/settings')->assertRedirect('/login');

        $this->get('/login')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('noindex', false);
    }

    public function test_every_page_renders_for_the_owner(): void
    {
        $this->actingAs($this->owner());
        $project = $this->makeProject([], [], $this->scoringReply(['client_test_phrase' => 'PURPLE', 'red_flags' => ['Vague']]));

        $this->get('/')->assertOk()->assertSee('WooCommerce checkout failing after update');
        $this->get('/paste')->assertOk()->assertSee('Score and draft');
        $this->get('/settings')->assertOk()->assertSee('Fact bank')->assertSee('Locked off');
        $this->get(route('projects.show', $project))->assertOk()->assertSee('PURPLE')->assertSee('Vague')->assertSee('Draft a proposal');
    }

    public function test_there_is_no_registration_route(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_login_works_and_is_rate_limited(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com', 'password' => 'correct-horse-battery']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'me@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'me@example.com', 'password' => 'correct-horse-battery'])
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many attempts', session('errors')->first('email'));
        $this->assertGuest();

        $this->app['cache']->flush();
        $this->post('/login', ['email' => 'me@example.com', 'password' => 'correct-horse-battery'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_paste_mode_scores_and_drafts_a_project(): void
    {
        $this->actingAs($this->owner());
        $this->claude
            ->reply($this->scoringReply(['client_questions' => ['Which payment gateway?']]))
            ->reply($this->draftReply(), 'claude-sonnet-5-5');

        Livewire::test(PasteProject::class)
            ->set('title', 'WooCommerce checkout failing after update')
            ->set('description', 'After updating WooCommerce to 9.3 the checkout page spins forever and no orders come through. Stripe gateway.')
            ->set('budgetMin', 150)->set('budgetMax', 250)
            ->set('bidCount', 3)->set('bidAvg', 180)
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::query()->sole();
        $this->assertSame('drafted', $project->ai_state);
        $this->assertSame('drafted', $project->status);
        $this->assertSame(88, $project->fit_score);
        $this->assertSame('fix', $project->category);
        $this->assertSame('171.00', $project->latestDraft->bid_amount);

        $this->get(route('projects.show', $project))->assertOk()
            ->assertSee('Copy proposal')->assertSee('Which payment gateway?');
    }

    public function test_project_data_is_encrypted_at_rest(): void
    {
        $project = $this->makeProject(['description' => 'Secret brief text about a store']);

        $raw = \DB::table('projects')->where('id', $project->id)->first();

        $this->assertStringNotContainsString('Secret brief', $raw->description);
        $this->assertStringNotContainsString('WooCommerce', $raw->title);
        $this->assertStringNotContainsString('payment_verified', $raw->details);
        $this->assertSame('Secret brief text about a store', $project->fresh()->description);
    }

    public function test_low_score_without_always_draft_is_not_drafted(): void
    {
        $this->claude->reply($this->scoringReply(['fit_score' => 40]));
        $project = $this->makeProject(['auto_draft' => false]);

        ScoreProject::dispatchSync($project->id);

        $this->assertSame('scored', $project->fresh()->ai_state);
        $this->assertSame(0, $project->drafts()->count());
        $this->assertCount(1, $this->claude->requests);
    }

    public function test_edits_regenerate_and_status_tracking(): void
    {
        $this->actingAs($this->owner());
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');
        DraftProject::dispatchSync($project->id);

        $component = Livewire::test(ProjectShow::class, ['project' => $project])
            ->set('editedText', 'My own edited proposal text.');
        $this->assertSame('My own edited proposal text.', $project->latestDraft()->first()->edited_text);

        // Regenerate with an instruction creates version 2 and shows it in the editor.
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');
        $component->set('instruction', 'shorter')->call('regenerate');
        $this->assertSame(2, $project->drafts()->count());
        $this->assertStringContainsString('shorter', $this->claude->requests[1]->userContent);
        $component->call('$refresh')->assertSet('editedText', $this->draftReply()['proposal_text']);

        $component->set('myBid', 175)->call('markBidPlaced');
        $project->refresh();
        $this->assertSame('bid_placed', $project->status);
        $this->assertSame('175.00', $project->my_bid_amount);
        $this->assertSame(3, $project->bid_count_at_bid);

        // Won: my final text becomes a winning example for the category.
        $component->set('editedText', 'Final winning text.')->call('setStatus', 'won');
        $this->assertSame('won', $project->fresh()->status);
        $this->assertSame('Final winning text.', WinningExample::query()->sole()->proposal_text);
        $this->assertSame('fix', WinningExample::query()->sole()->category);
    }

    public function test_settings_save_and_polling_stays_locked_without_permission(): void
    {
        config(['bidradar.freelancer.polling_enabled' => false]);
        $this->actingAs($this->owner());

        Livewire::test(SettingsPage::class)
            ->set('form.daily_spend_cap_usd', 2)
            ->set('form.hourly_rate_usd', 25)
            ->set('form.positioning', 'WooCommerce and WordPress fixer.')
            ->set('form.banned_phrases', "As an experienced\nDear Sir")
            ->set('form.polling_enabled', true)
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(Settings::class);
        $this->assertEquals(2, $settings->get('daily_spend_cap_usd'));
        $this->assertSame('WooCommerce and WordPress fixer.', $settings->get('positioning'));
        $this->assertSame(['As an experienced', 'Dear Sir'], $settings->get('banned_phrases'));
        $this->assertFalse($settings->get('polling_enabled'));
        $this->assertFalse($settings->pollingActive());
    }

    public function test_fact_bank_goes_into_the_cached_part_of_the_drafting_prompt(): void
    {
        app(Settings::class)->set(['positioning' => 'I fix broken WooCommerce stores.', 'stats_rating' => '4.9']);
        PastProject::query()->create(['category' => 'fix', 'problem' => 'Checkout broken', 'what_i_did' => 'Fixed a plugin conflict']);
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');

        DraftProject::dispatchSync($project->id);

        $system = $this->claude->requests[0]->system;
        $this->assertTrue($system[1]['cache']);
        $this->assertStringContainsString('I fix broken WooCommerce stores.', $system[1]['text']);
        $this->assertStringContainsString('Fixed a plugin conflict', $system[1]['text']);
        $this->assertStringNotContainsString('checkout page spins', $system[1]['text'], 'Brief must stay out of the cached prefix.');
    }
}
