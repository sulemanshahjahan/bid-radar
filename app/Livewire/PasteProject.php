<?php

namespace App\Livewire;

use App\Jobs\ScoreProject;
use App\Models\Project;
use App\Services\Freelancer\RulesFilter;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Paste mode: paste a brief from Freelancer and get a score + draft.
 * Works with no Freelancer API access at all.
 */
#[Title('Paste a project')]
class PasteProject extends Component
{
    #[Validate('nullable|string|max:255')]
    public string $title = '';

    #[Validate('required|string|min:30|max:20000')]
    public string $description = '';

    #[Validate('nullable|url|max:500|starts_with:https://www.freelancer.com/,https://freelancer.com/')]
    public string $url = '';

    #[Validate('required|in:fixed,hourly')]
    public string $type = 'fixed';

    #[Validate('nullable|numeric|min:0')]
    public $budgetMin = '';

    #[Validate('nullable|numeric|min:0')]
    public $budgetMax = '';

    #[Validate('required|string|size:3')]
    public string $currency = 'USD';

    #[Validate('required|numeric|gt:0')]
    public $exchangeRate = 1;

    #[Validate('nullable|integer|min:0')]
    public $bidCount = '';

    #[Validate('nullable|numeric|min:0')]
    public $bidAvg = '';

    #[Validate('nullable|string|max:500')]
    public string $skills = '';

    public bool $paymentVerified = true;

    #[Validate('nullable|numeric|min:0|max:5')]
    public $clientRating = '';

    #[Validate('nullable|string|max:60')]
    public string $clientCountry = '';

    public bool $alwaysDraft = true;

    public function updatedCurrency(): void
    {
        $this->currency = strtoupper(trim($this->currency));
        if ($this->currency === 'USD') {
            $this->exchangeRate = 1;
        }
    }

    public function save(RulesFilter $rules)
    {
        $this->validate();

        $title = trim($this->title) !== '' ? trim($this->title) : mb_substr(strtok(trim($this->description), "\n"), 0, 120);
        $num = fn ($v) => $v === '' || $v === null ? null : (float) $v;

        $details = [
            'type' => $this->type,
            'budget_min' => $num($this->budgetMin),
            'budget_max' => $num($this->budgetMax),
            'currency' => strtoupper($this->currency),
            'exchange_rate' => (float) $this->exchangeRate,
            'bid_count' => $this->bidCount === '' ? null : (int) $this->bidCount,
            'bid_avg' => $num($this->bidAvg),
            'skills' => array_values(array_filter(array_map('trim', explode(',', $this->skills)))),
            'client' => array_filter([
                'payment_verified' => $this->paymentVerified,
                'rating' => $num($this->clientRating),
                'country' => trim($this->clientCountry) ?: null,
            ], fn ($v) => $v !== null),
        ];

        // Same project filters as live projects. A filtered paste is still saved, scored and
        // viewable, but it is not drafted unless I click "Draft anyway".
        $reason = $rules->reject([
            'title' => $title,
            'description' => trim($this->description),
            'posted_at' => now()->getTimestamp(),
            'details' => $details,
        ], requireSkill: $details['skills'] !== []);

        $project = Project::query()->create([
            'source' => 'paste',
            'url' => trim($this->url) ?: null,
            'title' => $title,
            'description' => trim($this->description),
            'details' => $details,
            'filter_reason' => $reason,
            'auto_draft' => $this->alwaysDraft,
            'ai_state' => 'queued',
            'posted_at' => now(),
        ]);

        ScoreProject::dispatch($project->id);

        return $this->redirectRoute('projects.show', $project, navigate: true);
    }

    public function render()
    {
        return view('livewire.paste-project');
    }
}
