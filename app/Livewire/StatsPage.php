<?php

namespace App\Livewire;

use App\Models\Project;
use App\Services\Drafting\ProposalQuality;
use App\Services\Settings;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Outcomes of the bids I placed: view, chat and win rates by category and by
 * how many bids the project had and by the draft's opening hook, this week's bids
 * against the cap, and recent wins.
 */
#[Title('Stats')]
class StatsPage extends Component
{
    public const BUCKETS = ['0-10' => [0, 10], '11-20' => [11, 20], '21-40' => [21, 40], '41+' => [41, PHP_INT_MAX]];

    public static function bucket(?int $bids): string
    {
        if ($bids === null) {
            return 'unknown';
        }
        foreach (self::BUCKETS as $name => [$lo, $hi]) {
            if ($bids >= $lo && $bids <= $hi) {
                return $name;
            }
        }

        return 'unknown';
    }

    /**
     * @param  Collection<int, Project>  $bids
     * @return array{bids: int, view_rate: int|null, chat_rate: int|null, win_rate: int|null}
     */
    public static function rates(Collection $bids): array
    {
        $n = $bids->count();
        $pct = fn (int $k) => $n > 0 ? (int) round($k / $n * 100) : null;

        return [
            'bids' => $n,
            'view_rate' => $pct($bids->where('viewed', true)->count()),
            'chat_rate' => $pct($bids->where('chat_started', true)->count()),
            'win_rate' => $pct($bids->where('status', 'won')->count()),
        ];
    }

    /**
     * Median seconds for each step of live, alerted projects in the last 7 days.
     *
     * @return list<array{label: string, median: int|null, count: int}>
     */
    public static function speed(): array
    {
        $projects = Project::query()
            ->where('source', 'freelancer')
            ->whereNotNull('alerted_at')
            ->where('created_at', '>=', now()->subDays(7))
            ->get();

        $steps = [
            'Posted to found' => fn (Project $p) => $p->posted_at ? $p->posted_at->diffInSeconds($p->created_at, true) : null,
            'Found to alert' => fn (Project $p) => $p->created_at->diffInSeconds($p->alerted_at, true),
            'Alert to draft ready' => fn (Project $p) => $p->drafted_at ? $p->alerted_at->diffInSeconds($p->drafted_at, true) : null,
            'Alert to you bid' => fn (Project $p) => $p->bid_placed_at ? $p->alerted_at->diffInSeconds($p->bid_placed_at, true) : null,
        ];

        $out = [];
        foreach ($steps as $label => $fn) {
            $values = $projects->map($fn)->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v)->sort()->values();
            $out[] = ['label' => $label, 'median' => $values->isEmpty() ? null : (int) $values->median(), 'count' => $values->count()];
        }

        return $out;
    }

    public function render(Settings $settings)
    {
        $bids = Project::query()->whereNotNull('bid_placed_at')->with('latestDraft')->get();

        // Only drafts that recorded their opening (prompt v2 onwards); older bids are left out.
        $byHook = $bids->filter(fn (Project $p) => ! empty($p->latestDraft?->strategy['hook_type']))
            ->groupBy(fn (Project $p) => $p->latestDraft->strategy['hook_type'])
            ->map(fn ($group, $hook) => ['label' => ProposalQuality::hookLabel($hook) ?? $hook] + self::rates($group))
            ->sortByDesc('bids');

        $byCategory = $bids->groupBy(fn (Project $p) => $p->category ?? 'other')
            ->map(fn ($group, $cat) => ['label' => config('bidradar.categories.'.$cat, ucfirst((string) $cat))] + self::rates($group))
            ->sortByDesc('bids');

        $byBucket = collect(array_keys(self::BUCKETS))->push('unknown')
            ->mapWithKeys(fn ($b) => [$b => self::rates($bids->filter(fn (Project $p) => self::bucket($p->bid_count_at_bid) === $b))])
            ->filter(fn ($r, $b) => $b !== 'unknown' || $r['bids'] > 0);

        return view('livewire.stats-page', [
            'overall' => self::rates($bids),
            'speed' => self::speed(),
            'byCategory' => $byCategory,
            'byBucket' => $byBucket,
            'byHook' => $byHook,
            'thisWeek' => $bids->filter(fn (Project $p) => $p->bid_placed_at->gte(now()->startOfWeek()))->count(),
            'weeklyCap' => $settings->int('weekly_bid_cap'),
            'recentWins' => Project::query()->where('status', 'won')->where('won_at', '>=', now()->subDays(30))->latest('won_at')->get(),
        ]);
    }
}
