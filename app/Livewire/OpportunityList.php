<?php

namespace App\Livewire;

use App\Services\Opportunity\Opportunity;
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
    public const SORTS = ['recommended' => 'Recommended', 'opportunity' => 'Opportunity score', 'existing' => 'Existing score'];

    /** recommended: actionable first within each class (recommendedGroup), then highest Opportunity score. */
    #[Url]
    public string $sort = 'recommended';

    /**
     * Recommended order groups: 0 A actionable, 1 A with a budget warning (cannot happen while A needs 75% of the
     * target; kept in A, shown after actionable A, never reclassified), 2 B actionable, 3 B "Review only if scope or
     * price changes", 4 B "Skip at current budget", 5 C (score order), 6 not evaluated. Nothing is hidden.
     */
    public static function recommendedGroup(?Opportunity $o): int
    {
        if ($o === null) {
            return 6;
        }
        $action = $o->action();

        return match ($o->class) {
            'A' => in_array($action, [Opportunity::SKIP, Opportunity::RECONSIDER], true) ? 1 : 0,
            'B' => match ($action) {
                Opportunity::SKIP => 4,
                Opportunity::RECONSIDER => 3,
                default => 2,
            },
            default => 5,
        };
    }

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
            ->sortBy(fn ($r) => match ($this->sort) {
                'existing' => $existingRanks[$r['project']->id],
                'opportunity' => $newRanks[$r['project']->id],
                // Within a group the Opportunity ranking (score, then newer first).
                default => self::recommendedGroup($r['opportunity']) * 10000 + $newRanks[$r['project']->id],
            })
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
