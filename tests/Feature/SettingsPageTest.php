<?php

namespace Tests\Feature;

use App\Livewire\SettingsPage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    /** Every setting, each with a value that differs from config('bidradar.defaults'). */
    private function saved(): array
    {
        return [
            'target_skills' => ['WordPress', 'Laravel'],
            'exclude_keywords' => ['SEO', 'crypto'],
            'new_build_phrases' => ['from scratch'],
            'banned_phrases' => ['Dear Sir', 'synergy'],
            'min_budget_usd' => 40,
            'max_bid_count' => 120,
            'new_build_max_bid_count' => 80,
            'max_age_hours' => 12,
            'threshold_high' => 80,
            'threshold_medium' => 55,
            'quiet_alerts' => true,
            'scoring_model' => 'claude-sonnet-5-5',
            'scoring_effort' => 'medium',
            'drafting_model' => 'claude-haiku-5-5',
            'drafting_effort' => 'high',
            'daily_spend_cap_usd' => 3.5,
            'weekly_bid_cap' => 40,
            'polling_enabled' => true,
            'poll_interval_seconds' => 300,
            'paused' => true,
            'hourly_rate_usd' => 30,
            'floor_price_usd' => 35,
            'avg_bid_factor_low' => 0.85,
            'avg_bid_factor_high' => 1.1,
            'word_min' => 90,
            'word_max' => 160,
            'positioning' => 'I fix broken WooCommerce stores.',
            'stats_rating' => '4.9',
            'stats_reviews' => '120',
            'stats_completion' => '98%',
            'stats_on_time' => '97%',
            'services_offered' => 'WooCommerce fixes, speed, security',
            'services_not_offered' => 'SEO, logo design',
            'turnaround_notes' => "Fix: 1-2 days\nSpeed: 2-3 days",
            'style_notes' => 'Short and direct.',
        ];
    }

    private function stored(): array
    {
        return Setting::query()->pluck('value', 'key')->all();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['bidradar.freelancer.polling_enabled' => false]);
        $this->actingAs(User::factory()->create());
        app(Settings::class)->set($this->saved());
    }

    public function test_mount_loads_every_saved_setting_into_the_form(): void
    {
        $component = Livewire::test(SettingsPage::class);

        foreach ($this->saved() as $key => $value) {
            $expected = is_array($value) ? implode("\n", $value) : $value;
            $component->assertSet("form.$key", $expected);
        }
    }

    public function test_changing_one_field_keeps_every_other_field(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('form.hourly_rate_usd', 45)
            ->call('save')
            ->assertHasNoErrors();

        $expected = ['hourly_rate_usd' => 45] + $this->saved();
        $stored = $this->stored();
        foreach ($expected as $key => $value) {
            $this->assertEquals($value, $stored[$key] ?? null, "Setting [$key] changed but was not edited.");
        }
    }

    public function test_a_broken_form_state_does_not_wipe_saved_settings(): void
    {
        // The component state loses every key except the one the user edited.
        Livewire::test(SettingsPage::class)
            ->set('form', ['hourly_rate_usd' => 45])
            ->call('save');

        $stored = $this->stored();
        foreach ($this->saved() as $key => $value) {
            if ($key === 'hourly_rate_usd') {
                continue;
            }
            $this->assertEquals($value, $stored[$key] ?? null, "Setting [$key] was wiped.");
        }
    }

    public function test_blanking_many_fields_in_one_save_is_refused(): void
    {
        $component = Livewire::test(SettingsPage::class);
        foreach (['positioning', 'stats_rating', 'stats_reviews', 'services_offered', 'style_notes', 'turnaround_notes'] as $key) {
            $component->set("form.$key", '');
        }
        $component->call('save')->assertHasErrors('form');

        $this->assertEquals($this->saved(), array_intersect_key($this->stored(), $this->saved()));
    }

    public function test_clearing_a_single_text_field_on_purpose_still_saves(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('form.style_notes', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('', $this->stored()['style_notes']);
        $this->assertSame('I fix broken WooCommerce stores.', $this->stored()['positioning']);
    }

    public function test_polling_enabled_is_kept_exactly_as_saved_and_never_switched_on(): void
    {
        // Saved as true: saving another field must not change it.
        Livewire::test(SettingsPage::class)->set('form.word_min', 95)->call('save')->assertHasNoErrors();
        $this->assertTrue($this->stored()['polling_enabled']);

        // Saved as false and no written permission: it must not be turned on.
        app(Settings::class)->set(['polling_enabled' => false]);
        Livewire::test(SettingsPage::class)->set('form.polling_enabled', true)->call('save')->assertHasNoErrors();
        $this->assertFalse($this->stored()['polling_enabled']);
    }

    public function test_a_stored_null_falls_back_to_the_default_and_is_not_saved_back(): void
    {
        Setting::query()->where('key', 'hourly_rate_usd')->update(['value' => null]);

        Livewire::test(SettingsPage::class)
            ->assertSet('form.hourly_rate_usd', config('bidradar.defaults.hourly_rate_usd'))
            ->set('form.word_min', 95)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($this->stored()['hourly_rate_usd']);
        $this->assertSame(95, $this->stored()['word_min']);
    }

    /**
     * The production state: every setting except the fact bank stored as null.
     * Before the fix, numbers loaded empty, selects showed their first option
     * while holding null, and Save failed validation without showing why.
     */
    public function test_settings_stored_as_null_load_defaults_and_a_one_field_save_succeeds(): void
    {
        $factBank = ['positioning', 'stats_rating', 'stats_reviews', 'stats_completion', 'stats_on_time',
            'services_offered', 'services_not_offered', 'turnaround_notes', 'style_notes'];
        Setting::query()->whereNotIn('key', $factBank)->update(['value' => null]);
        app()->forgetInstance(Settings::class);
        $defaults = config('bidradar.defaults');

        Livewire::test(SettingsPage::class)
            ->assertSet('form.hourly_rate_usd', $defaults['hourly_rate_usd'])
            ->assertSet('form.scoring_model', $defaults['scoring_model'])
            ->assertSet('form.drafting_effort', $defaults['drafting_effort'])
            ->assertSet('form.target_skills', implode("\n", $defaults['target_skills']))
            ->assertSet('form.positioning', 'I fix broken WooCommerce stores.')
            ->set('form.word_min', 95)
            ->call('save')
            ->assertHasNoErrors();

        $stored = $this->stored();
        $this->assertSame(95, $stored['word_min']);
        foreach ($factBank as $key) {
            $this->assertSame($this->saved()[$key], $stored[$key], "Fact bank [$key] changed.");
        }
        // The app itself reads defaults, not null, for settings that are still stored as null.
        $this->assertSame($defaults['hourly_rate_usd'], app(Settings::class)->get('hourly_rate_usd'));
        $this->assertFalse(app(Settings::class)->get('polling_enabled'));
    }

    public function test_a_failed_save_lists_every_error_next_to_the_save_button(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('form.word_max', 50) // below word_min (90)
            ->set('form.scoring_model', '')
            ->call('save')
            ->assertHasErrors(['form.word_max', 'form.scoring_model'])
            ->assertSee('Not saved. Fix these first:')
            ->assertSee('The form.scoring model field is required.');

        $this->assertSame(160, $this->stored()['word_max']);
    }

    public function test_decimal_strings_from_number_inputs_are_saved_as_numbers(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('form.avg_bid_factor_high', '1.0')
            ->set('form.daily_spend_cap_usd', '2.75')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(1.0, $this->stored()['avg_bid_factor_high']);
        $this->assertSame(2.75, $this->stored()['daily_spend_cap_usd']);
    }
}
