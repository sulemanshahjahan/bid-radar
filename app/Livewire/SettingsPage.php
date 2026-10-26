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
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
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
        'word_min', 'word_max', 'poll_interval_minutes', 'sponsor_min_budget_usd',
    ];

    private const BOOLEANS = ['quiet_alerts', 'polling_enabled', 'paused', 'rank_rebuild'];

    private const TEXTS = [
        'scoring_model', 'scoring_effort', 'drafting_model', 'drafting_effort',
        'positioning', 'stats_rating', 'stats_reviews', 'stats_completion', 'stats_on_time',
        'services_offered', 'services_not_offered', 'turnaround_notes', 'style_notes', 'portfolio_notes',
    ];

    /** More filled-in fields than this going blank in one save means the form state is broken, not edited. */
    private const MAX_BLANKED_PER_SAVE = 3;

    /** @var array<string, mixed> */
    public array $form = [];

    /** The form as loaded on mount (or last save). save() only writes fields that differ from it. */
    #[Locked]
    public array $original = [];

    /** @var array{id: int|null, category: string, problem: string, what_i_did: string, result: string} */
    public array $past = ['id' => null, 'category' => 'fix', 'problem' => '', 'what_i_did' => '', 'result' => ''];

    public function mount(Settings $settings): void
    {
        $defaults = config('bidradar.defaults');
        foreach ($settings->all() as $key => $value) {
            $value ??= $defaults[$key] ?? null; // a stored null must not hide the default
            $this->form[$key] = in_array($key, self::LISTS, true) ? implode("\n", (array) $value) : $value;
        }
        $this->original = $this->form;
    }

    public function save(Settings $settings): void
    {
        // Only fields the user changed are saved. A field missing from the form state keeps its saved value.
        $changed = [];
        foreach ($this->original as $key => $old) {
            // A checkbox must hold a real true/false. Anything else (e.g. "on" or "false" from a script)
            // is ignored, because PHP's (bool) would turn it into true.
            if (in_array($key, self::BOOLEANS, true) && array_key_exists($key, $this->form) && self::toBool($this->form[$key]) === null) {
                $this->form[$key] = $old;

                continue;
            }
            if (array_key_exists($key, $this->form) && $this->normalise($key, $this->form[$key]) !== $this->normalise($key, $old)) {
                $changed[$key] = $this->form[$key];
            }
        }
        $this->form = array_merge($this->original, $changed);

        $blanked = array_filter(array_keys($changed), fn (string $key) => ! in_array($key, self::BOOLEANS, true)
            && $this->isBlank($changed[$key]) && ! $this->isBlank($this->original[$key]));
        if (count($blanked) > self::MAX_BLANKED_PER_SAVE) {
            $this->addError('form', 'Nothing was saved: '.count($blanked).' filled-in fields would have been cleared at once. Reload the page and try again.');

            return;
        }

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
            'form.sponsor_min_budget_usd' => 'required|numeric|min:0',
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
            'form.portfolio_notes' => 'nullable|string|max:3000',
        ]);

        $values = [];
        foreach (array_keys($changed) as $key) {
            $value = $this->form[$key];
            if (in_array($key, self::LISTS, true)) {
                $values[$key] = $this->toList($value);
            } elseif (in_array($key, self::NUMBERS, true)) {
                $values[$key] = $this->toNumber($value);
            } elseif (in_array($key, self::BOOLEANS, true)) {
                $values[$key] = self::toBool($value);
            } elseif (in_array($key, self::TEXTS, true)) {
                $values[$key] = trim((string) $value);
            }
        }
        // Hard rule: polling is never switched ON without FREELANCER_POLLING_ENABLED=true (written permission).
        // Otherwise it keeps exactly its saved value.
        if (($values['polling_enabled'] ?? false) === true && config('bidradar.freelancer.polling_enabled') !== true) {
            unset($values['polling_enabled']);
            $this->form['polling_enabled'] = $this->original['polling_enabled'];
        }

        if ($values !== []) {
            $settings->set($values);
        }
        if (array_key_exists('polling_enabled', $values)) {
            Log::warning('polling_enabled changed on the Settings page', [
                'from' => (bool) $this->original['polling_enabled'],
                'to' => $values['polling_enabled'],
                'user_id' => auth()->id(),
                'ip' => request()->ip(),
            ]);
        }
        $this->original = $this->form;
        $this->dispatch('copied', 'Settings saved');
    }

    /** Compare form values the way they will be saved, so "25" and 25 or "1.0" and 1 count as unchanged. */
    private function normalise(string $key, mixed $value): mixed
    {
        if (in_array($key, self::LISTS, true)) {
            return $this->toList(is_array($value) ? implode("\n", $value) : $value);
        }
        if (in_array($key, self::NUMBERS, true)) {
            return is_numeric($value) ? (float) $value : trim((string) $value);
        }
        if (in_array($key, self::BOOLEANS, true)) {
            return self::toBool($value);
        }

        return trim((string) $value);
    }

    /** true/false for real booleans (and 1/0, "1"/"0", "true"/"false"); null for anything else. */
    private static function toBool(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'true' => true,
            $value === false, $value === 0, $value === '0', $value === 'false', $value === null => false,
            default => null,
        };
    }

    private function isBlank(mixed $value): bool
    {
        return is_array($value) ? $value === [] : trim((string) $value) === '';
    }

    /** @return list<string> */
    private function toList(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value)), 'strlen'));
    }

    private function toNumber(mixed $value): int|float
    {
        return (float) $value == (int) $value ? (int) $value : (float) $value;
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
