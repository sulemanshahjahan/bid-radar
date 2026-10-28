<div class="space-y-4" wire:loading.class="opacity-60">
    <div class="flex flex-wrap items-center gap-2">
        <h1 class="text-xl font-semibold">Opportunities</h1>
        <span class="rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-800">Shadow mode · experimental</span>
    </div>
    <p class="text-xs text-slate-500">
        A personal priority from your fact bank, skill rankings, budget, client and competition, next to the existing score.
        It does not change the Feed, alerts or drafting. Scores are priorities, not chances of winning.
        Scored projects from the last 24 hours only; nothing here is stored.
    </p>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        @foreach (['opportunity' => 'Sort: opportunity', 'existing' => 'Sort: existing score'] as $key => $label)
            <button wire:click="$set('sort', '{{ $key }}')" class="shrink-0 rounded-full border px-3 py-1.5 {{ $sort === $key ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-300 bg-white text-slate-600' }}">{{ $label }}</button>
        @endforeach
        <button wire:click="$toggle('shortlist')" class="shrink-0 rounded-full border px-3 py-1.5 {{ $shortlist ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-300 bg-white text-slate-600' }}">Shortlist (A + B)</button>
    </div>
    <label class="flex items-center gap-2 text-xs text-slate-500">
        <input type="checkbox" wire:click="$set('at', '{{ $atValue === 'decision' ? 'now' : 'decision' }}')" @checked($atValue === 'decision') class="rounded border-slate-300 text-teal-600">
        As at scoring time (bid count BidRadar had then, and the score it acted on; for fair comparison)
    </label>

    <p class="text-xs text-slate-500">
        {{ $total }} projects ·
        <span class="font-medium text-emerald-700">A {{ $classes['A'] ?? 0 }}</span> ·
        <span class="font-medium text-sky-700">B {{ $classes['B'] ?? 0 }}</span> ·
        <span>C {{ $classes['C'] ?? 0 }}</span>
        @if (($classes['error'] ?? 0) > 0) · <span class="text-red-600">{{ $classes['error'] }} not evaluated</span> @endif
        · Class thresholds are provisional.
    </p>

    @forelse ($rows as $row)
        @php($p = $row['project'])
        @php($o = $row['opportunity'])
        <a href="{{ route('projects.show', $p) }}" wire:navigate wire:key="o-{{ $p->id }}" class="card block transition hover:border-teal-300">
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
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                        @if ($o)
                            <span @class(['font-medium', 'text-emerald-700' => $o->class === 'A', 'text-sky-700' => $o->class === 'B', 'text-slate-600' => $o->class === 'C'])>{{ $o->classLabel() }}</span>
                        @else
                            <span class="text-red-600">Not evaluated</span>
                        @endif
                        @if ($p->category) · <span>{{ config('bidradar.categories.'.$p->category, 'Other') }}</span> @endif
                        · <span>{{ ($p->posted_at ?? $p->created_at)->diffForHumans(short: true) }}</span>
                        @php($move = ($existingRanks[$p->id] ?? 0) - ($newRanks[$p->id] ?? 0))
                        @if ($o && abs($move) >= 3)
                            <span class="{{ $move > 0 ? 'text-emerald-700' : 'text-amber-700' }}">{{ $move > 0 ? '▲' : '▼' }} {{ abs($move) }} places</span>
                        @endif
                    </div>
                    <p class="mt-1 truncate font-medium">{{ $p->displayTitle() }}</p>
                    @if ($o)
                        @if ($o->strengths)
                            <p class="mt-1 line-clamp-3 text-xs text-emerald-800">+ {{ implode(' · ', array_slice($o->strengths, 0, 3)) }}</p>
                        @endif
                        @if ($o->risks)
                            <p class="mt-0.5 line-clamp-2 text-xs text-amber-800">− {{ implode(' · ', array_slice($o->risks, 0, 2)) }}</p>
                        @endif
                        @if ($o->rules)
                            <p class="mt-0.5 text-xs text-violet-800">Rule: {{ $o->rules[0] }}</p>
                        @endif
                        <p class="mt-0.5 text-[11px] text-slate-500">{{ $o->action() }}@if ($o->unknowns) · {{ count($o->unknowns) }} unknown @endif</p>
                    @endif
                </div>
            </div>
        </a>
    @empty
        <div class="card text-center text-sm text-slate-500">
            {{ $shortlist && $total > 0 ? 'No A or B projects right now. Turn off the shortlist to see the rest.' : 'No scored projects in the last 24 hours.' }}
        </div>
    @endforelse
</div>
