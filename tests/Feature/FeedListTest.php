<?php

namespace Tests\Feature;

use App\Livewire\ProjectList;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FeedListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_the_newest_low_fit_project_shows_even_with_over_100_older_ones(): void
    {
        // #98: scored 27, newest, but sorted below 100+ older rows and cut off.
        foreach (range(1, 105) as $i) {
            Project::query()->create(['source' => 'freelancer', 'title' => "Older {$i}", 'ai_state' => 'scored', 'fit_score' => 40 + ($i % 50)])
                ->forceFill(['created_at' => now()->subMinutes(200 - $i)])->save();
        }
        Project::query()->create(['source' => 'freelancer', 'title' => 'Simple E-commerce Website Build', 'ai_state' => 'scored', 'fit_score' => 27]);

        Livewire::test(ProjectList::class)->set('showLow', true)
            ->assertSeeInOrder(['Simple E-commerce Website Build', 'Older 105']);
    }

    public function test_the_default_view_still_ranks_by_score(): void
    {
        Project::query()->create(['source' => 'freelancer', 'title' => 'Medium newer', 'ai_state' => 'scored', 'fit_score' => 60]);
        Project::query()->create(['source' => 'freelancer', 'title' => 'High older', 'ai_state' => 'scored', 'fit_score' => 90])
            ->forceFill(['created_at' => now()->subHour()])->save();

        Livewire::test(ProjectList::class)->assertSeeInOrder(['High older', 'Medium newer']);
    }

    public function test_purged_projects_show_only_when_i_acted_on_them(): void
    {
        Project::query()->create(['source' => 'freelancer', 'ai_state' => 'scored', 'fit_score' => 80, 'purged_at' => now()]);
        $bid = Project::query()->create(['source' => 'freelancer', 'ai_state' => 'scored', 'fit_score' => 80, 'purged_at' => now(), 'status' => 'bid_placed']);

        Livewire::test(ProjectList::class)->set('showLow', true)
            ->assertSee('Project #'.$bid->id.' (data purged)')
            ->assertDontSee('Project #'.($bid->id - 1).' (data purged)');
    }
}
