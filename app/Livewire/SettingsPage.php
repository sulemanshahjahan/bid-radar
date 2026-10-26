<?php

namespace App\Livewire;

use App\Models\PastProject;
use App\Services\CostTracker;
use App\Services\Freelancer\FreelancerClient;
use App\Services\Freelancer\Poller;
use App\Services\Scoring\Scorer;
use App\Services\Settings;
use App\Services\Telegram\Telegram;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Settings')]
class SettingsPage extends Component
{
    /** Settings stored as a newline-separated list in the form. */
    private const LISTS = ['target_skills', 'exclude_keywords', 'new_build_phrases', 'banned_phrases'];

    private const NUMBERS = [
        'min_budget_usd', 'max_bid_count', 'new_build_max_bid_count', 'max_age_hours',
        'threshold_high', 'threshold_medium', 'daily_spend_cap_usd', 'weekly_bid_cap',
        'hourly_rate_usd', 'floor_price_usd', 'avg_bid_factor_low', 'avg_bid_factor_high',
        'word_min', 'word_max', 'poll_interval_minutes',
    ];

    private const BOOLEANS = ['quiet_alerts', 'polling_enabled', 'paused'];

    private const TEXTS = [
        'scoring_model', 'scoring_effort', 'drafting_model', 'drafting_effort',
        'positioning', 'stats_rating', 'stats_reviews', 'stats_completion', 'stats_on_time',
        'services_offered', 'services_not_offered', 'turnaround_notes', 'style_notes',
    ];

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array{id: int|null, category: string, problem: string, what_i_did: string, result: string} */
    public array $past = ['id' => null, 'category' => 'fix', 'problem' => '', 'what_i_did' => '', 'result' => ''];

    public function mount(Settings $settings): void
    {
        foreach ($settings->all() as $key => $value) {
            $this->form[$key] = in_array($key, self::LISTS, true) ? implode("\n", (array) $value) : $value;
        }
    }

    public function save(Settings $settings): void
    {
        $efforts = 'in:low,medium,high,xhigh,max';
        $this->validate([
            'form.min_budget_usd' => 'required|numeric|min:0',
            'form.max_bid_count' => 'required|integer|min:1',
            'form.new_build_max_bid_count' => 'required|integer|min:1',
            'form.max_age_hours' => 'required|integer|min:1|max:24',
            'form.poll_interval_minutes' => 'required|integer|min:2|max:60',
            'form.threshold_high' => 'required|integer|between:1,100|gt:form.threshold_medium',
            'form.threshold_medium' => 'required|integer|between:0,99',
            'form.daily_spend_cap_usd' => 'required|numeric|min:0|max:100',
            'form.weekly_bid_cap' => 'required|integer|min:1|max:500',
            'form.hourly_rate_usd' => 'required|numeric|gt:0',
            'form.floor_price_usd' => 'required|numeric|min:0',
            'form.avg_bid_factor_low' => 'required|numeric|between:0.5,1.5',
            'form.avg_bid_factor_high' => 'required|numeric|between:0.5,1.5|gte:form.avg_bid_factor_low',
            'form.word_min' => 'required|integer|min:30',
            'form.word_max' => 'required|integer|gt:form.word_min|max:400',
            'form.scoring_model' => 'required|string|in:'.implode(',', array_keys(config('bidradar.prices'))),
            'form.drafting_model' => 'required|string|in:'.implode(',', array_keys(config('bidradar.prices'))),
            'form.scoring_effort' => 'required|'.$efforts,
            'form.drafting_effort' => 'required|'.$efforts,
            'form.positioning' => 'nullable|string|max:3000',
            'form.services_offered' => 'nullable|string|max:3000',
            'form.services_not_offered' => 'nullable|string|max:3000',
            'form.turnaround_notes' => 'nullable|string|max:3000',
            'form.style_notes' => 'nullable|string|max:3000',
        ]);

        $values = [];
        foreach (self::LISTS as $key) {
            $values[$key] = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($this->form[$key] ?? ''))), 'strlen'));
        }
        foreach (self::NUMBERS as $key) {
            $values[$key] = (float) $this->form[$key] == (int) $this->form[$key] ? (int) $this->form[$key] : (float) $this->form[$key];
        }
        foreach (self::BOOLEANS as $key) {
            $values[$key] = (bool) ($this->form[$key] ?? false);
        }
        // Hard rule: polling stays off until FREELANCER_POLLING_ENABLED=true (written permission).
        if (config('bidradar.freelancer.polling_enabled') !== true) {
            $values['polling_enabled'] = false;
        }
        foreach (self::TEXTS as $key) {
            $values[$key] = trim((string) ($this->form[$key] ?? ''));
        }

        $settings->set($values);
        $this->dispatch('copied', 'Settings saved');
    }

    public function editPast(int $id): void
    {
        $p = PastProject::query()->findOrFail($id);
        $this->past = ['id' => $p->id, 'category' => $p->category, 'problem' => $p->problem, 'what_i_did' => $p->what_i_did, 'result' => (string) $p->result];
    }

    public function savePast(): void
    {
        $this->validate([
            'past.category' => 'required|in:'.implode(',', Scorer::CATEGORIES),
            'past.problem' => 'required|string|max:1000',
            'past.what_i_did' => 'required|string|max:1500',
            'past.result' => 'nullable|string|max:1000',
        ]);

        PastProject::query()->updateOrCreate(['id' => $this->past['id']], [
            'category' => $this->past['category'],
            'problem' => trim($this->past['problem']),
            'what_i_did' => trim($this->past['what_i_did']),
            'result' => trim($this->past['result']) ?: null,
        ]);

        $this->resetPast();
        $this->dispatch('copied', 'Past project saved');
    }

    public function deletePast(int $id): void
    {
        PastProject::query()->whereKey($id)->delete();
        if ($this->past['id'] === $id) {
            $this->resetPast();
        }
    }

    public function resetPast(): void
    {
        $this->past = ['id' => null, 'category' => 'fix', 'problem' => '', 'what_i_did' => '', 'result' => ''];
        $this->resetValidation();
    }

    public function render(CostTracker $costs, Poller $poller, FreelancerClient $freelancer, Telegram $telegram)
    {
        $lastPoll = Cache::get(Poller::LAST_SUCCESS);

        return view('livewire.settings-page', [
            'pastProjects' => PastProject::query()->orderBy('id')->get(),
            'models' => array_keys(config('bidradar.prices')),
            'pollingAllowed' => config('bidradar.freelancer.polling_enabled') === true,
            'spentToday' => $costs->spentToday(),
            'spentMonth' => $costs->spentThisMonth(),
            'skillIds' => $poller->cachedSkillIds(),
            'unmatchedSkills' => $poller->unmatchedSkills(),
            'lastPoll' => $lastPoll ? now()->setTimestamp($lastPoll) : null,
            'backoffUntil' => $freelancer->backoffUntil(),
            'tokenSet' => (bool) config('bidradar.freelancer.oauth_token'),
            'telegramReady' => $telegram->configured(),
        ]);
    }
}
