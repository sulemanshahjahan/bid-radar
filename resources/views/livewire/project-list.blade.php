<div @if ($anyBusy) wire:poll.3s @elseif ($polling === 'on') wire:poll.30s @endif class="space-y-4">
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-xl font-semibold">Feed</h1>
        <a href="{{ route('paste') }}" wire:navigate class="btn-primary">+ Paste a project</a>
    </div>

    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
        <span @class([
            'rounded-full px-2 py-0.5 font-medium',
            'bg-emerald-100 text-emerald-800' => $polling === 'on',
            'bg-amber-100 text-amber-800' => in_array($polling, ['paused', 'backing off']),
            'bg-slate-200 text-slate-600' => $polling === 'off',
        ])>Live: {{ $polling }}</span>
        @if ($lastPoll) <span>checked {{ $lastPoll->diffForHumans(short: true) }}</span> · @endif
        <span>AI today: <span class="font-medium {{ $spentToday >= $cap ? 'text-red-600' : 'text-slate-700' }}">${{ number_format($spentToday, 3) }}</span> of ${{ number_format($cap, 2) }}</span>
        @if ($spentToday >= $cap) · <span class="font-medium text-red-600">cap reached, drafting paused</span> @endif
    </p>

    <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 text-sm">
        @foreach (['all' => 'All'] + \App\Models\Project::STATUSES as $key => $label)
            <button wire:click="$set('status', '{{ $key }}')"
                    class="shrink-0 rounded-full border px-3 py-1.5 {{ $status === $key ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-300 bg-white text-slate-600' }}">
                {{ $label }}
                @if ($key !== 'all' && ($counts[$key] ?? 0) > 0)
                    <span class="ml-1 opacity-70">{{ $counts[$key] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <label class="flex items-center gap-2 text-xs text-slate-500">
        <input type="checkbox" wire:model.live="showLow" class="rounded border-slate-300 text-teal-600">
        Show low-fit and filtered projects (score under {{ $medium }}, kept 24h)
    </label>

    @forelse ($projects as $project)
        @php($score = $project->fit_score)
        <a href="{{ route('projects.show', $project) }}" wire:navigate wire:key="p-{{ $project->id }}"
           class="card block transition hover:border-teal-300">
            <div class="flex items-start gap-3">
                <div @class([
                    'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-base font-semibold',
                    'bg-emerald-100 text-emerald-800' => $score !== null && $score >= $high,
                    'bg-amber-100 text-amber-800' => $score !== null && $score >= $medium && $score < $high,
                    'bg-slate-100 text-slate-500' => $score === null || $score < $medium,
                ])>
                    {{ $score ?? '…' }}
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                        @if ($project->category)
                            <span class="font-medium text-slate-700">{{ config('bidradar.categories.'.$project->category, 'Other') }}</span> ·
                        @endif
                        <span>{{ ($project->posted_at ?? $project->created_at)->diffForHumans(short: true) }}</span>
                        @if ($project->source === 'paste') <span class="text-slate-400">pasted</span> @endif
                        @if ($project->filter_reason) <span class="text-slate-400">filtered: {{ $project->filter_reason }}</span> @endif
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">{{ \App\Models\Project::STATUSES[$project->status] ?? $project->status }}</span>
                        @if ($project->isBusy())
                            <span class="animate-pulse text-teal-700">{{ $project->ai_state }}…</span>
                        @elseif (in_array($project->ai_state, ['failed', 'cap_reached']))
                            <span class="text-red-600">{{ $project->ai_state === 'failed' ? 'AI error' : 'cap reached' }}</span>
                        @endif
                        @if ($project->latestDraft?->check_failures)
                            <span class="text-red-600">⚠ checks</span>
                        @endif
                    </div>
                    <p class="mt-1 truncate font-medium">{{ $project->displayTitle() }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        @php($d = $project->details ?? [])
                        @if (! empty($d['budget_min']) || ! empty($d['budget_max']))
                            {{ $d['currency'] ?? 'USD' }} {{ (float) ($d['budget_min'] ?? 0) }}–{{ (float) ($d['budget_max'] ?? 0) }}{{ ($d['type'] ?? '') === 'hourly' ? '/hr' : '' }} ·
                        @endif
                        @isset($d['bid_count'])
                            {{ $d['bid_count'] }} bids{{ ! empty($d['bid_avg']) ? ' (avg '.(float) $d['bid_avg'].')' : '' }} ·
                        @endisset
                        @if (isset($d['client']['payment_verified']))
                            {{ $d['client']['payment_verified'] ? '✅ verified' : '❌ unverified' }}
                        @endif
                        @if (! empty($d['client']['rating'])) · ★{{ $d['client']['rating'] }} @endif
                        @if (! empty($d['client']['country'])) · {{ $d['client']['country'] }} @endif
                    </p>
                </div>
            </div>
        </a>
    @empty
        <div class="card text-center text-sm text-slate-500">
            No projects yet. <a href="{{ route('paste') }}" wire:navigate class="font-medium text-teal-700 underline">Paste one from Freelancer</a> to get a score and a draft.
        </div>
    @endforelse
</div>
