@php
    $pct = fn ($v) => $v === null ? '—' : $v.'%';
@endphp
<div class="space-y-5">
    <h1 class="text-xl font-semibold">Stats</h1>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="card">
            <p class="text-xs text-slate-500">Bids this week</p>
            <p class="mt-1 text-2xl font-semibold {{ $thisWeek >= $weeklyCap ? 'text-red-600' : '' }}">{{ $thisWeek }} <span class="text-sm font-normal text-slate-500">/ {{ $weeklyCap }}</span></p>
        </div>
        <div class="card"><p class="text-xs text-slate-500">View rate</p><p class="mt-1 text-2xl font-semibold">{{ $pct($overall['view_rate']) }}</p></div>
        <div class="card"><p class="text-xs text-slate-500">Chat rate</p><p class="mt-1 text-2xl font-semibold">{{ $pct($overall['chat_rate']) }}</p></div>
        <div class="card"><p class="text-xs text-slate-500">Win rate</p><p class="mt-1 text-2xl font-semibold">{{ $pct($overall['win_rate']) }}</p></div>
    </div>
    <p class="-mt-2 text-xs text-slate-500">Across {{ $overall['bids'] }} bids placed. Rates count "Viewed: yes", "Chat started: yes" and Won out of all bids placed.</p>

    <section class="card">
        <h2 class="font-semibold">Speed, last 7 days <span class="text-xs font-normal text-slate-500">(median, alerted live projects)</span></h2>
        <dl class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($speed as $step)
                <div wire:key="speed-{{ $loop->index }}">
                    <dt class="text-xs text-slate-500">{{ $step['label'] }}</dt>
                    <dd class="text-lg font-semibold">{{ $step['median'] === null ? '—' : \App\Models\Project::duration($step['median']) }}</dd>
                    <dd class="text-[11px] text-slate-400">{{ $step['count'] }} projects</dd>
                </div>
            @endforeach
        </dl>
        <p class="mt-2 text-xs text-slate-500">"You bid" is when you tapped Bid placed in BidRadar, so it includes the time to bid on Freelancer.</p>
    </section>

    @foreach (['By category' => $byCategory, 'By bids on the project' => $byBucket, 'By opening of the draft (from prompt v2)' => $byHook] as $title => $rows)
        <section class="card">
            <h2 class="font-semibold">{{ $title }}</h2>
            @if (collect($rows)->sum('bids') === 0)
                <p class="mt-2 text-sm text-slate-500">No bids placed yet.</p>
            @else
                <div class="-mx-4 mt-2 overflow-x-auto px-4">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-slate-500">
                            <tr><th class="py-1 pr-3 font-medium">{{ ['Category', 'Bids', 'Opening'][$loop->index] }}</th><th class="py-1 pr-3 text-right font-medium">Bids</th><th class="py-1 pr-3 text-right font-medium">Viewed</th><th class="py-1 pr-3 text-right font-medium">Chat</th><th class="py-1 text-right font-medium">Won</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($rows as $key => $r)
                                <tr>
                                    <td class="py-1.5 pr-3">{{ $r['label'] ?? $key }}</td>
                                    <td class="py-1.5 pr-3 text-right">{{ $r['bids'] }}</td>
                                    <td class="py-1.5 pr-3 text-right">{{ $pct($r['view_rate']) }}</td>
                                    <td class="py-1.5 pr-3 text-right">{{ $pct($r['chat_rate']) }}</td>
                                    <td class="py-1.5 text-right">{{ $pct($r['win_rate']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($rows === $byBucket)
                    <p class="mt-2 text-xs text-slate-500">
                        Counts: {{ $bucketSources['observed'] }} observed by BidRadar (last count seen before you marked the bid{{ $bucketSources['median_lag'] !== null ? ', median '.\App\Models\Project::duration($bucketSources['median_lag']).' earlier' : '' }}),
                        {{ $bucketSources['typed'] }} typed by you when recording the outcome, {{ $bucketSources['none'] }} with neither (unknown). Observed counts are not the exact count at submission.
                    </p>
                @endif
            @endif
        </section>
    @endforeach

    <section class="card">
        <h2 class="font-semibold">Rank rebuild: wins in the last 30 days</h2>
        @forelse ($recentWins as $p)
            <a href="{{ route('projects.show', $p) }}" wire:navigate wire:key="win-{{ $p->id }}" class="mt-2 flex items-center justify-between gap-3 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50">
                <span class="min-w-0 truncate">{{ $p->displayTitle() }}</span>
                <span class="shrink-0 text-xs text-slate-500">
                    {{ config('bidradar.categories.'.$p->category, 'Other') }}
                    @if ($p->my_bid_amount) · {{ $p->my_bid_currency }} {{ (float) $p->my_bid_amount }} @endif
                    @if ($p->bid_count_observed !== null) · {{ $p->bid_count_observed }} bids seen by BidRadar @elseif ($p->bid_count_at_bid !== null) · {{ $p->bid_count_at_bid }} bids (typed) @endif
                    · {{ $p->won_at->format('M j') }}
                </span>
            </a>
        @empty
            <p class="mt-2 text-sm text-slate-500">No wins marked in the last 30 days.</p>
        @endforelse
    </section>
</div>
