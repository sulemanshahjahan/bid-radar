<div class="space-y-4" wire:loading.class="opacity-60">
    <div class="flex flex-wrap items-center gap-2">
        <h1 class="text-xl font-semibold">Opportunities</h1>
        <span class="rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-800">Shadow mode · experimental</span>
    </div>
    <p class="text-xs text-slate-500">
        A personal priority from your fact bank, skill rankings, budget, client and competition, next to the existing score.
        It does not change the Feed, alerts or drafting. Classes and scores are priorities, not chances of winning.
        Scored projects from the last 24 hours only; nothing here is stored.
    </p>

    <p class="text-sm">
        <span class="font-semibold">{{ ($classes['A'] ?? 0) + ($classes['B'] ?? 0) }} shortlisted</span>
        <span class="text-slate-600">(<span class="text-emerald-700">A {{ $classes['A'] ?? 0 }}</span> · <span class="text-sky-700">B {{ $classes['B'] ?? 0 }}</span>)</span>
        <span class="text-slate-500">/ {{ $total }} evaluated</span>
        @if (($classes['error'] ?? 0) > 0) <span class="text-xs text-red-600">· {{ $classes['error'] }} not evaluated</span> @endif
    </p>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        @foreach (\App\Livewire\OpportunityList::SORTS as $key => $label)
            <button wire:click="$set('sort', '{{ $key }}')" class="shrink-0 rounded-full border px-3 py-1.5 {{ $sort === $key ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-300 bg-white text-slate-600' }}">{{ $label }}</button>
        @endforeach
        <button wire:click="$toggle('shortlist')" class="shrink-0 rounded-full border px-3 py-1.5 {{ $shortlist ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-300 bg-white text-slate-600' }}">Shortlist only</button>
    </div>
    <label class="flex items-center gap-2 text-xs text-slate-500">
        <input type="checkbox" wire:click="$set('at', '{{ $atValue === 'decision' ? 'now' : 'decision' }}')" @checked($atValue === 'decision') class="rounded border-slate-300 text-teal-600">
        As at scoring time (bid count BidRadar had then, and the score it acted on; for fair comparison)
    </label>

    @forelse ($rows as $row)
        @php($p = $row['project'])
        @php($o = $row['opportunity'])
        @php($e = $o?->earnings())
        <div wire:key="o-{{ $p->id }}" class="card">
            <div class="flex items-start gap-3">
                <div class="flex shrink-0 flex-col items-center gap-1 text-center">
                    <div @class([
                        'flex h-11 w-11 items-center justify-center rounded-xl text-base font-semibold',
                        'bg-emerald-100 text-emerald-800' => $o?->class === 'A',
                        'bg-sky-100 text-sky-800' => $o?->class === 'B',
                        'bg-slate-100 text-slate-500' => $o === null || $o->class === 'C',
                    ])>{{ $o?->score ?? '–' }}</div>
                    <span class="text-[10px] leading-none text-slate-500">was {{ $row['existing'] ?? '–' }}</span>
                </div>
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                        @if ($o)
                            <span @class(['font-semibold', 'text-emerald-700' => $o->class === 'A', 'text-sky-700' => $o->class === 'B', 'text-slate-600' => $o->class === 'C'])>{{ $o->class }} · {{ $o->classLabel() }}</span>
                        @else
                            <span class="text-red-600">Not evaluated</span>
                        @endif
                        @if ($p->category) · <span>{{ config('bidradar.categories.'.$p->category, 'Other') }}</span> @endif
                        · <span>{{ ($p->posted_at ?? $p->created_at)->diffForHumans(short: true) }}</span>
                    </div>
                    <a href="{{ route('projects.show', $p) }}" wire:navigate class="block truncate font-medium hover:text-teal-700">{{ $p->displayTitle() }}</a>
                    @if ($o)
                        <p class="line-clamp-2 text-xs text-slate-700"><span class="font-medium">Why:</span> {{ $o->why() }}</p>
                        <div class="flex flex-wrap items-center gap-1.5 text-xs">
                            <span @class([
                                'rounded-full px-2 py-0.5 font-medium',
                                'bg-emerald-100 text-emerald-800' => $e['band'] === 'good',
                                'bg-amber-100 text-amber-800' => $e['band'] === 'fair',
                                'bg-red-100 text-red-800' => $e['band'] === 'poor',
                                'bg-slate-100 text-slate-600' => $e['band'] === 'unknown',
                            ])>
                                @if ($e['net'] === null)
                                    Earnings unknown
                                @else
                                    ≈ ${{ number_format($e['net'], 0) }}/h net · {{ (int) round($e['ratio'] * 100) }}% of target
                                @endif
                            </span>
                            @if ($e['hourly'] && $e['advertised'])
                                <span class="text-slate-500">advertised up to ${{ number_format($e['advertised'], 0) }}/h</span>
                            @endif
                            @if ($e['band'] === 'poor')
                                <span class="rounded-full bg-red-600 px-2 py-0.5 font-semibold text-white">Budget far below target</span>
                            @endif
                        </div>
                        @if ($risk = $o->topRisk())
                            <p class="line-clamp-1 text-xs text-amber-800"><span class="font-medium">Risk:</span> {{ $risk }}</p>
                        @endif
                        <p @class(['text-xs font-medium', 'text-red-700' => in_array($o->action(), [\App\Services\Opportunity\Opportunity::SKIP, \App\Services\Opportunity\Opportunity::RECONSIDER], true), 'text-slate-800' => ! in_array($o->action(), [\App\Services\Opportunity\Opportunity::SKIP, \App\Services\Opportunity\Opportunity::RECONSIDER], true)])>→ {{ $o->action() }}</p>

                        <details class="text-xs">
                            <summary class="cursor-pointer text-slate-500 hover:text-slate-700">Details</summary>
                            <div class="mt-2 space-y-2">
                                @if ($o->rules)
                                    <div><p class="font-medium text-violet-800">Rules applied</p>
                                        <ul class="list-disc pl-4 text-slate-700">@foreach ($o->rules as $line) <li>{{ $line }}</li> @endforeach</ul></div>
                                @endif
                                @if ($o->strengths)
                                    <div><p class="font-medium text-emerald-800">Strengths</p>
                                        <ul class="list-disc pl-4 text-slate-700">@foreach ($o->strengths as $line) <li>{{ $line }}</li> @endforeach</ul></div>
                                @endif
                                @if ($o->risks)
                                    <div><p class="font-medium text-amber-800">Risks</p>
                                        <ul class="list-disc pl-4 text-slate-700">@foreach ($o->risks as $line) <li>{{ $line }}</li> @endforeach</ul></div>
                                @endif
                                @if ($o->unknowns)
                                    <div><p class="font-medium text-slate-600">Unknown</p>
                                        <ul class="list-disc pl-4 text-slate-500">@foreach ($o->unknowns as $line) <li>{{ $line }}</li> @endforeach</ul></div>
                                @endif
                                <table class="w-full">
                                    @foreach ($o->factors as $key => $f)
                                        <tr wire:key="of-{{ $p->id }}-{{ $key }}" class="border-t border-slate-100">
                                            <td class="py-0.5 text-slate-600">{{ $f['label'] }}</td>
                                            <td class="py-0.5 text-right text-slate-500">{{ $f['level'] }}</td>
                                            <td class="py-0.5 text-right font-medium">{{ $f['points'] }}/{{ $f['weight'] }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                                @php($move = ($existingRanks[$p->id] ?? 0) - ($newRanks[$p->id] ?? 0))
                                <p class="text-slate-500">Existing score {{ $row['existing'] ?? '–' }} · rank {{ $existingRanks[$p->id] ?? '–' }} by existing score, {{ $newRanks[$p->id] ?? '–' }} by Opportunity score @if (abs($move) >= 3) ({{ $move > 0 ? 'up' : 'down' }} {{ abs($move) }}) @endif</p>
                            </div>
                        </details>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="card text-center text-sm text-slate-500">
            {{ $shortlist && $total > 0 ? 'No A or B projects right now. Turn off the shortlist to see the rest.' : 'No scored projects in the last 24 hours.' }}
        </div>
    @endforelse
</div>
