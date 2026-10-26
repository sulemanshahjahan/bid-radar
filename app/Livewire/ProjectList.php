<?php

namespace App\Livewire;

use App\Models\Project;
use App\Services\CostTracker;
use App\Services\Freelancer\FreelancerClient;
use App\Services\Freelancer\Poller;
use App\Services\Settings;
use App\Services\SponsorBadge;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Feed')]
class ProjectList extends Component
{
    #[Url]
    public string $status = 'all';

    #[Url]
    public bool $showLow = false;

    public function render(Settings $settings, CostTracker $costs, FreelancerClient $freelancer, SponsorBadge $sponsor)
    {
        $medium = $settings->int('threshold_medium');

        $projects = Project::query()
            ->with('latestDraft')
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when(! $this->showLow, fn ($q) => $q->visible($medium))
            ->orderByRaw('fit_score IS NULL DESC')
            ->orderByDesc('fit_score')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $lastPoll = Cache::get(Poller::LAST_SUCCESS);

        return view('livewire.project-list', [
            'projects' => $projects,
            'sponsor' => $sponsor,
            'counts' => Project::query()->where('ai_state', '!=', 'filtered')->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            'high' => $high = $settings->int('threshold_high'),
            'medium' => $medium,
            'spentToday' => $costs->spentToday(),
            'cap' => $costs->dailyCap(),
            'anyBusy' => $projects->contains(fn (Project $p) => $p->isBusy()),
            'polling' => match (true) {
                ! $settings->pollingActive() => 'off',
                (bool) $settings->get('paused') => 'paused',
                $freelancer->backoffUntil() !== null => 'backing off',
                default => 'on',
            },
            'lastPoll' => $lastPoll ? now()->setTimestamp($lastPoll) : null,
        ]);
    }
}
