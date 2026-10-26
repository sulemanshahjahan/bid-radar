<?php

namespace App\Livewire;

use App\Models\Project;
use App\Services\CostTracker;
use App\Services\Settings;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Projects')]
class ProjectList extends Component
{
    #[Url]
    public string $status = 'all';

    #[Url]
    public bool $showLow = false;

    public function render(Settings $settings, CostTracker $costs)
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

        return view('livewire.project-list', [
            'projects' => $projects,
            'counts' => Project::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            'high' => $settings->int('threshold_high'),
            'medium' => $medium,
            'spentToday' => $costs->spentToday(),
            'cap' => $costs->dailyCap(),
            'anyBusy' => $projects->contains(fn (Project $p) => $p->isBusy()),
        ]);
    }
}
