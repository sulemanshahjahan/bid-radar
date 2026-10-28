<?php

namespace App\Livewire;

use App\Services\Opportunity\ShadowRanking;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Opportunity Intelligence, shadow mode: the existing score next to the personal Opportunity
 * Priority Score for scored projects from the last 24 hours. Experimental: it changes nothing
 * in the Feed, alerts or drafting.
 */
#[Title('Opportunities (shadow)')]
class OpportunityList extends Component
{
    #[Url]
    public string $sort = 'opportunity';

    #[Url]
    public string $at = ShadowRanking::NOW;

    #[Url]
    public bool $shortlist = false;

    public function mount(): void
    {
        abort_unless(ShadowRanking::enabled(), 404);
    }

    public function render(ShadowRanking $ranking)
    {
        $at = $this->at === ShadowRanking::DECISION ? ShadowRanking::DECISION : ShadowRanking::NOW;
        $rows = collect($ranking->rows($at));

        $existingRanks = ShadowRanking::ranks($rows->mapWithKeys(fn ($r) => [$r['project']->id => $r['existing']])->all());
        $newRanks = ShadowRanking::ranks($rows->mapWithKeys(fn ($r) => [$r['project']->id => $r['opportunity']?->score])->all());

        $shown = $rows
            ->when($this->shortlist, fn ($c) => $c->filter(fn ($r) => in_array($r['opportunity']?->class, ['A', 'B'], true)))
            ->sortBy(fn ($r) => $this->sort === 'existing' ? $existingRanks[$r['project']->id] : $newRanks[$r['project']->id])
            ->take(60)
            ->values();

        return view('livewire.opportunity-list', [
            'rows' => $shown,
            'total' => $rows->count(),
            'classes' => $rows->countBy(fn ($r) => $r['opportunity']?->class ?? 'error'),
            'existingRanks' => $existingRanks,
            'newRanks' => $newRanks,
            'atValue' => $at,
        ]);
    }
}
