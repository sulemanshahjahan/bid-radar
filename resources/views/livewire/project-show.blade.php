@php
    $d = $project->details ?? [];
    $s = $project->scoring ?? [];
    $score = $project->fit_score;
    $currency = $draft?->currency ?? ($d['currency'] ?? 'USD');
@endphp

<div @if ($project->isBusy()) wire:poll.2s @endif class="space-y-4">
    <a href="{{ route('projects.index') }}" wire:navigate class="text-sm text-slate-500 hover:text-slate-800">← Projects</a>

    {{-- Header --}}
    <div class="card">
        <div class="flex items-start gap-3">
            <div @class([
                'flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl text-xl font-semibold',
                'bg-emerald-100 text-emerald-800' => $score !== null && $score >= $high,
                'bg-amber-100 text-amber-800' => $score !== null && $score >= $medium && $score < $high,
                'bg-slate-100 text-slate-500' => $score === null || $score < $medium,
            ])>{{ $score ?? '…' }}</div>
            <div class="min-w-0 flex-1">
                <p class="text-xs text-slate-500">
                    {{ $project->category ? config('bidradar.categories.'.$project->category) : 'Not scored yet' }}
                    · {{ $project->created_at->diffForHumans() }}
                </p>
                <h1 class="mt-0.5 text-lg font-semibold leading-snug">{{ $project->displayTitle() }}</h1>
                @if (! empty($s['reason']))
                    <p class="mt-1 text-sm text-slate-600">{{ $s['reason'] }}</p>
                @endif
                @if (! empty($s['adjustments']))
                    <p class="mt-1 text-xs text-slate-500">
                        Base score {{ $s['fit_score'] }}
                        @foreach ($s['adjustments'] as $a)
                            · <span class="{{ $a['points'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ $a['points'] > 0 ? '+' : '' }}{{ $a['points'] }} {{ $a['label'] }}</span>
                        @endforeach
                        → <span class="font-medium text-slate-700">{{ $score }}</span>
                    </p>
                @endif
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-xs text-slate-500">Budget</dt>
                <dd class="font-medium">
                    @if (! empty($d['budget_min']) || ! empty($d['budget_max']))
                        {{ $d['currency'] ?? 'USD' }} {{ (float) ($d['budget_min'] ?? 0) }}–{{ (float) ($d['budget_max'] ?? 0) }}{{ ($d['type'] ?? '') === 'hourly' ? '/hr' : '' }}
                    @else — @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Bids</dt>
                <dd class="font-medium">{{ $d['bid_count'] ?? '—' }}{{ ! empty($d['bid_avg']) ? ' · avg '.round((float) $d['bid_avg']) : '' }}</dd>
                @if ($bidsPerHour !== null)<dd class="text-xs text-slate-500">{{ $bidsPerHour }} bids/hr</dd>@endif
            </div>
            <div>
                <dt class="text-xs text-slate-500">Client</dt>
                <dd class="font-medium">
                    @if (isset($d['client']['payment_verified'])){{ $d['client']['payment_verified'] ? '✅ verified' : '❌ unverified' }}@else — @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Rating · Country</dt>
                <dd class="font-medium">{{ ! empty($d['client']['rating']) ? '★'.$d['client']['rating'] : '—' }} · {{ $d['client']['country'] ?? '—' }}</dd>
            </div>
        </dl>

        <div class="mt-4 flex gap-2">
            @if ($project->url)
                <a href="{{ $project->url }}" target="_blank" rel="noopener noreferrer" class="btn flex-1">Open on Freelancer ↗</a>
            @endif
            @if ($canRefresh)
                <button type="button" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh" class="btn shrink-0" title="Update bid count and average (one API call)">
                    <span wire:loading.remove wire:target="refresh">Refresh bids</span>
                    <span wire:loading wire:target="refresh">Refreshing…</span>
                </button>
            @endif
        </div>
        @error('refresh') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
        @if (! empty($d['suggested']))
            <p class="mt-2 text-xs text-slate-600">
                Suggested bid now: <span class="font-medium text-slate-800">{{ $d['suggested']['currency'] }} {{ (float) $d['suggested']['amount'] }}{{ $d['suggested']['is_hourly'] ? '/hr' : '' }}</span>
                ({{ $d['suggested']['reason'] }})
                @if ($draft && (float) $draft->bid_amount !== (float) $d['suggested']['amount']) · draft says {{ (float) $draft->bid_amount }} @endif
                · refreshed {{ \Illuminate\Support\Carbon::parse($d['suggested']['at'])->diffForHumans() }}
            </p>
            @if (! empty($d['suggested']['warning']))<p class="mt-1 text-xs text-amber-700">{{ $d['suggested']['warning'] }}</p>@endif
        @endif
    </div>

    {{-- Speed timeline --}}
    @php($timeline = $project->timeline())
    @if (count($timeline) > 1)
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 px-1 text-xs text-slate-500">
            <span class="font-medium text-slate-600">Timeline</span>
            @foreach ($timeline as $step)
                <span wire:key="tl-{{ $step['label'] }}" title="{{ $step['at']->format('H:i:s') }}">
                    @if ($step['seconds'] !== null)<span class="text-slate-400">→ +{{ \App\Models\Project::duration($step['seconds']) }}</span>@endif
                    {{ $step['label'] }}
                </span>
            @endforeach
        </div>
    @endif

    {{-- AI state --}}
    @if ($project->isBusy())
        <div class="card flex items-center gap-3 text-sm text-teal-800">
            <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3"/></svg>
            {{ match ($project->ai_state) { 'scoring' => 'Scoring the project…', 'drafting' => 'Writing the proposal…', default => 'Queued…' } }}
        </div>
    @endif
    @if ($project->ai_error)
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $project->ai_error }}</div>
    @endif
    @error('cap')
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $message }}</div>
    @enderror

    {{-- Test phrase + red flags --}}
    @if (! empty($s['client_test_phrase']))
        <div class="rounded-2xl border-2 border-amber-400 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-amber-900">⚠️ Client test phrase</p>
            <p class="mt-1 text-lg font-semibold text-amber-950">"{{ $s['client_test_phrase'] }}"</p>
            <p class="mt-1 text-xs text-amber-800">The proposal must include this exactly.</p>
        </div>
    @endif
    @if (! empty($s['red_flags']))
        <div class="rounded-2xl border border-red-200 bg-white p-4">
            <p class="text-sm font-semibold text-red-700">Red flags</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-slate-700">
                @foreach ($s['red_flags'] as $flag) <li>{{ $flag }}</li> @endforeach
            </ul>
        </div>
    @endif

    {{-- Opportunity Intelligence (shadow mode, display only) --}}
    @if ($opportunity)
        <details class="rounded-2xl border border-violet-200 bg-white p-4">
            <summary class="flex cursor-pointer flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                <span class="font-semibold">Opportunity {{ $opportunity->score }}</span>
                <span class="text-slate-500">· existing {{ $project->fit_score ?? '–' }}</span>
                <span @class(['font-medium', 'text-emerald-700' => $opportunity->class === 'A', 'text-sky-700' => $opportunity->class === 'B', 'text-slate-600' => $opportunity->class === 'C'])>· {{ $opportunity->classLabel() }}</span>
                <span class="rounded-full bg-violet-100 px-2 py-0.5 text-[11px] font-medium text-violet-800">shadow</span>
            </summary>
            <div class="mt-3 space-y-2 text-sm">
                <p class="text-xs text-slate-500">Experimental. A priority for your attention, not a chance of winning. It changes nothing in alerts or drafting.</p>
                <p><span class="font-medium">Suggested:</span> {{ $opportunity->action() }}</p>
                @if ($opportunity->rules)
                    <div><p class="font-medium text-violet-800">Rules applied</p>
                        <ul class="list-disc pl-5 text-slate-700">@foreach ($opportunity->rules as $line) <li>{{ $line }}</li> @endforeach</ul></div>
                @endif
                @if ($opportunity->strengths)
                    <div><p class="font-medium text-emerald-800">Why it stands out</p>
                        <ul class="list-disc pl-5 text-slate-700">@foreach ($opportunity->strengths as $line) <li>{{ $line }}</li> @endforeach</ul></div>
                @endif
                @if ($opportunity->risks)
                    <div><p class="font-medium text-amber-800">Risks</p>
                        <ul class="list-disc pl-5 text-slate-700">@foreach ($opportunity->risks as $line) <li>{{ $line }}</li> @endforeach</ul></div>
                @endif
                @if ($opportunity->proof)
                    <p><span class="font-medium">Relevant proof (fact bank):</span> {{ $opportunity->proof }}</p>
                @endif
                @if ($opportunity->unknowns)
                    <div><p class="font-medium text-slate-600">Unknown</p>
                        <ul class="list-disc pl-5 text-slate-500">@foreach ($opportunity->unknowns as $line) <li>{{ $line }}</li> @endforeach</ul></div>
                @endif
                <table class="w-full text-xs">
                    @foreach ($opportunity->factors as $key => $f)
                        <tr wire:key="of-{{ $key }}" class="border-t border-slate-100">
                            <td class="py-1 text-slate-600">{{ $f['label'] }}</td>
                            <td class="py-1 text-right text-slate-500">{{ $f['level'] }}</td>
                            <td class="py-1 text-right font-medium">{{ $f['points'] }}/{{ $f['weight'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </details>
    @endif

    {{-- Draft --}}
    @if ($draft)
        <div class="card space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Proposal <span class="text-xs font-normal text-slate-400">v{{ $draft->version }}{{ $versions > 1 ? ' of '.$versions : '' }}</span></h2>
                <span class="text-xs text-slate-500" x-data x-text="($wire.editedText.trim().split(/\s+/).filter(Boolean).length) + ' words'"></span>
            </div>

            @if ($filtered)
                <p class="rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900">{{ $filtered }}</p>
            @endif

            @if ($draft->warnings)
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    <ul class="list-disc space-y-0.5 pl-5">
                        @foreach ($draft->warnings as $warning) <li>{{ $warning }}</li> @endforeach
                    </ul>
                </div>
            @endif

            @if ($draft->needsReview())
                <div class="rounded-xl border border-red-300 bg-red-50 p-3 text-sm text-red-800">
                    <p class="font-semibold">Needs manual review: not ready to send</p>
                    <p class="mt-0.5">This draft still failed these checks after {{ $draft->attempts }} {{ $draft->attempts === 1 ? 'attempt' : 'attempts' }}. Fix them before bidding:</p>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach ($draft->check_failures as $failure) <li>{{ $failure }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <textarea wire:model.live.debounce.800ms="editedText" rows="12" class="field text-[15px] leading-relaxed"></textarea>
            <p class="-mt-2 text-xs text-slate-400">
                Your edits save automatically.
                @if ($draft->edited_text !== null) Edited. @endif
            </p>

            <button type="button" class="{{ $draft->needsReview() ? 'btn' : 'btn-primary' }} w-full py-3.5 text-base"
                    x-on:click="copyText($wire.editedText, 'Proposal copied')">{{ $draft->needsReview() ? 'Copy anyway (needs review)' : 'Copy proposal' }}</button>

            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <button type="button" class="btn flex-col py-2.5"
                        x-on:click="copyText(@js(rtrim(rtrim(number_format((float) $draft->bid_amount, 2, '.', ''), '0'), '.')), 'Bid amount copied')">
                    <span class="text-xs text-slate-500">Bid {{ $draft->is_hourly ? '(per hour)' : '' }}</span>
                    <span class="text-base">{{ $currency }} {{ rtrim(rtrim(number_format((float) $draft->bid_amount, 2, '.', ''), '0'), '.') }}</span>
                </button>
                <button type="button" class="btn flex-col py-2.5" @disabled(! $draft->delivery_days)
                        x-on:click="copyText(@js((string) $draft->delivery_days), 'Delivery days copied')">
                    <span class="text-xs text-slate-500">Delivery</span>
                    <span class="text-base">{{ $draft->delivery_days ? $draft->delivery_days.' days' : 'not set' }}</span>
                </button>
                @if (! empty($draft->milestones))
                    <button type="button" class="btn col-span-2 flex-col py-2.5 sm:col-span-1"
                            x-on:click="copyText(@js($draft->milestonesText()), 'Milestones copied')">
                        <span class="text-xs text-slate-500">Copy milestones</span>
                        <span class="text-base">{{ count($draft->milestones) }} milestones</span>
                    </button>
                @endif
            </div>

            @if (! empty($draft->milestones))
                <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm">
                    @foreach ($draft->milestones as $m)
                        <li class="flex justify-between gap-3 px-3 py-2"><span>{{ $m['title'] }}</span><span class="shrink-0 font-medium">{{ $currency }} {{ (float) $m['amount'] }}</span></li>
                    @endforeach
                </ul>
            @endif

            <p class="text-xs text-slate-500">Price: {{ $draft->price_reason }}</p>

            @php($strategy = $draft->strategy ?? [])
            @if (! empty($strategy['hook_type']) || isset($strategy['quality']))
                <details class="text-sm">
                    <summary class="cursor-pointer text-xs text-slate-500">
                        Opening: {{ \App\Services\Drafting\ProposalQuality::hookLabel($strategy['hook_type'] ?? null) ?? 'not recorded' }}
                        @isset($strategy['quality']) · self-review {{ $strategy['quality'] }}/100 @endisset
                        @if (($strategy['revised'] ?? null) === 'quality') · revised once @endif
                    </summary>
                    <div class="mt-2 space-y-2 text-xs text-slate-600">
                        <p class="text-slate-400">The self-review is the model rating its own draft, not a prediction of winning. Real results are on the Stats page.</p>
                        @if (! empty($strategy['recommendation'])) <p><span class="font-medium">Recommends:</span> {{ $strategy['recommendation'] }}</p> @endif
                        @if (! empty($strategy['proof'])) <p><span class="font-medium">Proof used:</span> {{ $strategy['proof'] }}</p> @endif
                        @if (! empty($strategy['instructions']))
                            <div>
                                <p class="font-medium">Client instructions</p>
                                <ul class="list-disc pl-5">
                                    @foreach ($strategy['instructions'] as $i) <li>{{ $i['instruction'] }}: {{ $i['how_met'] }}</li> @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </details>
            @endif

            @if (! empty($draft->questions_to_ask_later))
                <div class="text-sm">
                    <p class="font-medium text-slate-700">Ask after award</p>
                    <ul class="mt-1 list-disc pl-5 text-slate-600">
                        @foreach ($draft->questions_to_ask_later as $q) <li>{{ $q }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <form wire:submit="regenerate" class="flex gap-2 border-t border-slate-100 pt-4">
                <input type="text" wire:model="instruction" maxlength="250" class="field mt-0 flex-1" placeholder="Optional: shorter, more technical…">
                <button type="submit" class="btn shrink-0" @disabled($project->isBusy())>Regenerate</button>
            </form>
        </div>
    @elseif (! $project->isBusy() && $project->fit_score !== null)
        @if ($filtered)
            <div class="rounded-2xl border-2 border-amber-400 bg-amber-50 p-4 text-center">
                <p class="text-sm font-semibold text-amber-900">{{ $filtered }}</p>
                <p class="mt-1 text-xs text-amber-800">Not drafted automatically. Drafting spends AI credit.</p>
                <button wire:click="draft(true)" wire:confirm="This project was filtered out. Draft it anyway?" class="btn mt-3" @disabled($capReached)>Draft anyway</button>
                @if ($capReached)<p class="mt-2 text-xs text-red-600">Daily AI spend cap reached.</p>@endif
            </div>
        @else
            <div class="card text-center">
                <p class="text-sm text-slate-600">No draft yet.</p>
                <button wire:click="draft" class="btn-primary mt-3" @disabled($capReached)>Draft a proposal</button>
                @if ($capReached)<p class="mt-2 text-xs text-red-600">Daily AI spend cap reached.</p>@endif
            </div>
        @endif
    @endif

    {{-- Status --}}
    <div class="card space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold">Status</h2>
            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ \App\Models\Project::STATUSES[$project->status] ?? $project->status }}</span>
        </div>
        <form wire:submit="markBidPlaced" class="space-y-2">
            <div class="grid grid-cols-3 gap-2">
                <label class="block"><span class="label text-xs">My bid</span><input type="number" step="any" inputmode="decimal" wire:model="myBid" class="field" placeholder="171"></label>
                <label class="block"><span class="label text-xs">My rank</span><input type="number" inputmode="numeric" min="1" wire:model="myRank" class="field" placeholder="e.g. 12"></label>
                <label class="block"><span class="label text-xs">Total bids now</span><input type="number" inputmode="numeric" min="0" wire:model="totalBids" class="field" placeholder="e.g. 40"></label>
            </div>
            <button type="submit" class="btn-primary w-full">Bid placed</button>
        </form>
        @error('myBid') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
        @error('myRank') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
        @error('totalBids') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
        @foreach (['viewed' => 'Viewed', 'chat_started' => 'Chat started'] as $field => $label)
            <div class="flex items-center justify-between gap-2 text-sm">
                <span class="text-slate-700">{{ $label }}?</span>
                <div class="flex gap-1">
                    <button type="button" wire:click="setOutcome('{{ $field }}', true)" class="btn px-3 py-1 {{ $project->{$field} === true ? 'border-emerald-600 bg-emerald-50 text-emerald-700' : '' }}">Yes</button>
                    <button type="button" wire:click="setOutcome('{{ $field }}', false)" class="btn px-3 py-1 {{ $project->{$field} === false ? 'border-slate-700 bg-slate-100' : '' }}">No</button>
                    @if ($project->{$field} !== null)<button type="button" wire:click="setOutcome('{{ $field }}', null)" class="btn px-2 py-1 text-slate-400" title="Clear">×</button>@endif
                </div>
            </div>
        @endforeach
        <div class="grid grid-cols-3 gap-2">
            <button wire:click="setStatus('skipped')" class="btn {{ $project->status === 'skipped' ? 'border-slate-700' : '' }}">Skipped</button>
            <button wire:click="setStatus('won')" class="btn {{ $project->status === 'won' ? 'border-emerald-600 text-emerald-700' : '' }}">Won</button>
            <button wire:click="setStatus('lost')" class="btn {{ $project->status === 'lost' ? 'border-red-500 text-red-600' : '' }}">Lost</button>
        </div>
        @if ($project->bid_placed_at)
            <p class="text-xs text-slate-500">
                Bid {{ $project->my_bid_currency }} {{ (float) $project->my_bid_amount }} placed {{ $project->bid_placed_at->diffForHumans() }}@if ($project->my_bid_rank), ranked {{ $project->my_bid_rank }}@endif @if ($project->bid_count_at_bid !== null) of {{ $project->bid_count_at_bid }} bids (when recorded)@endif.
                @if ($project->bid_count_observed !== null && $project->bid_count_observed_at)
                    BidRadar last saw {{ $project->bid_count_observed }} bids {{ \App\Models\Project::duration((int) $project->bid_count_observed_at->diffInSeconds($project->bid_placed_at, true)) }} before you marked it.
                @endif
            </p>
        @endif
    </div>

    {{-- Brief --}}
    <div class="card space-y-3">
        <h2 class="font-semibold">Brief</h2>
        @if (! empty($s['brief_summary']))
            <p class="text-sm text-slate-700">{{ $s['brief_summary'] }}</p>
        @endif
        @if (! empty($s['client_requirements']))
            <div class="text-sm">
                <p class="font-medium text-slate-700">Client asks every proposal to</p>
                <ul class="mt-1 list-disc pl-5 text-slate-600">
                    @foreach ($s['client_requirements'] as $r) <li>{{ $r }}</li> @endforeach
                </ul>
            </div>
        @endif
        @if (! empty($s['client_questions']))
            <div class="text-sm">
                <p class="font-medium text-slate-700">Client's questions</p>
                <ul class="mt-1 list-disc pl-5 text-slate-600">
                    @foreach ($s['client_questions'] as $q) <li>{{ $q }}</li> @endforeach
                </ul>
            </div>
        @endif
        @if ($project->description)
            <details class="text-sm">
                <summary class="cursor-pointer text-teal-700">Full description</summary>
                <p class="mt-2 whitespace-pre-line text-slate-700">{{ $project->description }}</p>
            </details>
        @elseif ($project->purged_at)
            <p class="text-sm text-slate-500">Project details were deleted after 24 hours.</p>
        @endif
    </div>
</div>
