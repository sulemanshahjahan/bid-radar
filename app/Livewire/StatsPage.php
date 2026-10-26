<?php

namespace App\Livewire;

use App\Models\Project;
use App\Services\Settings;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Outcomes of the bids I placed: view, chat and win rates by category and by
 * how many bids the project had, this week's bids against the cap, and recent wins.
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

    public function render(Settings $settings)
    {
        $bids = Project::query()->whereNotNull('bid_placed_at')->get();

        $byCategory = $bids->groupBy(fn (Project $p) => $p->category ?? 'other')
            ->map(fn ($group, $cat) => ['label' => config('bidradar.categories.'.$cat, ucfirst((string) $cat))] + self::rates($group))
            ->sortByDesc('bids');

        $byBucket = collect(array_keys(self::BUCKETS))->push('unknown')
            ->mapWithKeys(fn ($b) => [$b => self::rates($bids->filter(fn (Project $p) => self::bucket($p->bid_count_at_bid) === $b))])
            ->filter(fn ($r, $b) => $b !== 'unknown' || $r['bids'] > 0);

        return view('livewire.stats-page', [
            'overall' => self::rates($bids),
            'byCategory' => $byCategory,
            'byBucket' => $byBucket,
            'thisWeek' => $bids->filter(fn (Project $p) => $p->bid_placed_at->gte(now()->startOfWeek()))->count(),
            'weeklyCap' => $settings->int('weekly_bid_cap'),
            'recentWins' => Project::query()->where('status', 'won')->where('won_at', '>=', now()->subDays(30))->latest('won_at')->get(),
        ]);
    }
}
