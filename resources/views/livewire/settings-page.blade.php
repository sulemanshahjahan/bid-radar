<div class="space-y-5">
    <div>
        <h1 class="text-xl font-semibold">Settings</h1>
        <p class="mt-1 text-xs text-slate-500">
            AI spend: ${{ number_format($spentToday, 3) }} today · ${{ number_format($spentMonth, 2) }} this month
        </p>
    </div>

    <form wire:submit="save" class="space-y-5">
        {{-- Fact bank --}}
        <section class="card space-y-4">
            <div>
                <h2 class="font-semibold">Fact bank</h2>
                <p class="text-xs text-slate-500">The only facts the AI may use about you. Leave a field empty rather than guessing.</p>
            </div>
            <label class="block">
                <span class="label">Positioning (one paragraph)</span>
                <textarea wire:model="form.positioning" rows="4" class="field"></textarea>
            </label>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <label class="block"><span class="label">Rating</span><input type="text" wire:model="form.stats_rating" class="field" placeholder="4.9"></label>
                <label class="block"><span class="label">Reviews</span><input type="text" wire:model="form.stats_reviews" class="field" placeholder="120"></label>
                <label class="block"><span class="label">Completion</span><input type="text" wire:model="form.stats_completion" class="field" placeholder="98%"></label>
                <label class="block"><span class="label">On time</span><input type="text" wire:model="form.stats_on_time" class="field" placeholder="97%"></label>
            </div>
            <label class="block">
                <span class="label">Services I offer</span>
                <textarea wire:model="form.services_offered" rows="3" class="field"></textarea>
            </label>
            <label class="block">
                <span class="label">Services I don't offer</span>
                <textarea wire:model="form.services_not_offered" rows="2" class="field"></textarea>
            </label>
            <label class="block">
                <span class="label">Typical turnaround by category</span>
                <textarea wire:model="form.turnaround_notes" rows="3" class="field" placeholder="Fix: 1-2 days&#10;Speed: 2-3 days"></textarea>
                <span class="text-xs text-slate-500">Which categories you deliver, and the delivery used when there is no hours estimate. With an estimate, delivery is sized from the hours and these ranges are only a guide (the draft warns when it falls outside).</span>
            </label>
            <label class="block">
                <span class="label">Working hours a day for one project</span>
                <input type="number" step="0.5" wire:model="form.delivery_hours_per_day" class="field">
                @error('form.delivery_hours_per_day') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                <span class="text-xs text-slate-500">Estimated hours are divided by this to get working days; weekends are added for the calendar delivery.</span>
            </label>
            <label class="block">
                <span class="label">Availability</span>
                <textarea wire:model="form.availability_notes" rows="2" class="field" placeholder="e.g. Usually free to start the same day on small fixes."></textarea>
                <span class="text-xs text-slate-500">Drafts only say you can start today or deliver same day if it says so here.</span>
            </label>
            <label class="block">
                <span class="label">My Freelancer portfolio</span>
                <textarea wire:model="form.portfolio_notes" rows="3" class="field" placeholder="What your Freelancer profile portfolio shows, e.g. 3 WooCommerce stores built from Figma designs, 2 speed fixes. No links."></textarea>
                <span class="text-xs text-slate-500">When a client asks for links or samples, drafts point here and offer samples in Freelancer chat. Leave empty if your profile has no portfolio.</span>
            </label>
            <label class="block">
                <span class="label">Writing style notes</span>
                <textarea wire:model="form.style_notes" rows="3" class="field"></textarea>
            </label>
            <label class="block">
                <span class="label">Skill rankings from your profile (Opportunities page only)</span>
                <textarea wire:model="form.skill_rankings" rows="5" class="field font-mono text-xs" placeholder="CSS3 | 3&#10;HTML5 | 6"></textarea>
                <span class="text-xs text-slate-500">One per line: skill | top %. Used to rank opportunities, never sent to drafts.</span>
            </label>
            <label class="block">
                <span class="label">Rankings read on</span>
                <input type="date" wire:model="form.skill_rankings_as_of" class="field">
                @error('form.skill_rankings_as_of') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                <span class="text-xs text-slate-500">Empty or older than {{ config('bidradar.opportunity.ranking_stale_days') }} days: rankings count as weaker evidence.</span>
            </label>
        </section>

        {{-- Pricing --}}
        <section class="card space-y-4">
            <h2 class="font-semibold">Pricing (USD)</h2>
            <div class="grid grid-cols-2 gap-3">
                <label class="block"><span class="label">Hourly rate</span><input type="number" step="any" wire:model="form.hourly_rate_usd" class="field"> @error('form.hourly_rate_usd') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
                <label class="block"><span class="label">Fixed-price floor</span><input type="number" step="any" wire:model="form.floor_price_usd" class="field"> @error('form.floor_price_usd') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
                <label class="block"><span class="label">Avg-bid factor low</span><input type="number" step="0.01" wire:model="form.avg_bid_factor_low" class="field"> @error('form.avg_bid_factor_low') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
                <label class="block"><span class="label">Avg-bid factor high</span><input type="number" step="0.01" wire:model="form.avg_bid_factor_high" class="field"> @error('form.avg_bid_factor_high') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
                <label class="block"><span class="label">Freelancer fee %</span><input type="number" step="0.1" wire:model="form.platform_fee_percent" class="field"> @error('form.platform_fee_percent') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
            </div>
            <p class="text-xs text-slate-500">The fee only shows take-home pay and the hourly rate it works out to; it does not change the bid.</p>
        </section>

        {{-- Writing --}}
        <section class="card space-y-4">
            <h2 class="font-semibold">Writing rules</h2>
            <div class="grid grid-cols-2 gap-3">
                <label class="block"><span class="label">Min words</span><input type="number" wire:model="form.word_min" class="field"> @error('form.word_min') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
                <label class="block"><span class="label">Max words</span><input type="number" wire:model="form.word_max" class="field"> @error('form.word_max') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
            </div>
            <label class="block">
                <span class="label">Banned phrases (one per line)</span>
                <textarea wire:model="form.banned_phrases" rows="6" class="field font-mono text-xs"></textarea>
            </label>
        </section>

        {{-- Scoring + AI --}}
        <section class="card space-y-4">
            <h2 class="font-semibold">Scoring and AI</h2>
            <div class="grid grid-cols-2 gap-3">
                <label class="block"><span class="label">Auto-draft at score ≥</span><input type="number" wire:model="form.threshold_high" class="field"> @error('form.threshold_high') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
                <label class="block"><span class="label">Show at score ≥</span><input type="number" wire:model="form.threshold_medium" class="field"> @error('form.threshold_medium') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
                <label class="block">
                    <span class="label">Scoring model</span>
                    <select wire:model="form.scoring_model" class="field">@foreach ($models as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach</select>
                </label>
                <label class="block">
                    <span class="label">Scoring effort</span>
                    <select wire:model="form.scoring_effort" class="field">@foreach (['low', 'medium', 'high'] as $e)<option>{{ $e }}</option>@endforeach</select>
                </label>
                <label class="block">
                    <span class="label">Drafting model</span>
                    <select wire:model="form.drafting_model" class="field">@foreach ($models as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach</select>
                </label>
                <label class="block">
                    <span class="label">Drafting effort</span>
                    <select wire:model="form.drafting_effort" class="field">@foreach (['low', 'medium', 'high'] as $e)<option>{{ $e }}</option>@endforeach</select>
                </label>
                <label class="block"><span class="label">Daily AI spend cap ($)</span><input type="number" step="0.01" wire:model="form.daily_spend_cap_usd" class="field"> @error('form.daily_spend_cap_usd') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
                <label class="block"><span class="label">Weekly bid cap</span><input type="number" wire:model="form.weekly_bid_cap" class="field"> @error('form.weekly_bid_cap') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
                <label class="block"><span class="label">"Worth sponsoring" min budget ($)</span><input type="number" step="any" wire:model="form.sponsor_min_budget_usd" class="field"> @error('form.sponsor_min_budget_usd') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
            </div>
        </section>

        {{-- Filters (used by the live poller in Phase 2) --}}
        <section class="card space-y-4">
            <div>
                <h2 class="font-semibold">Project filters</h2>
                <p class="text-xs text-slate-500">Used by live alerts (Phase 2). Rules run before any AI call.</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <label class="block"><span class="label">Min budget (USD)</span><input type="number" step="any" wire:model="form.min_budget_usd" class="field"></label>
                <label class="block"><span class="label">Skip at bids ≥</span><input type="number" wire:model="form.max_bid_count" class="field"></label>
                <label class="block"><span class="label">New builds: skip at bids ≥</span><input type="number" wire:model="form.new_build_max_bid_count" class="field"></label>
                <label class="block"><span class="label">Max age (hours)</span><input type="number" wire:model="form.max_age_hours" class="field"></label>
            </div>
            <label class="block"><span class="label">Target skills (one per line)</span><textarea wire:model="form.target_skills" rows="6" class="field text-xs"></textarea></label>
            <label class="block"><span class="label">Exclude if title/description matches (one per line)</span><textarea wire:model="form.exclude_keywords" rows="6" class="field text-xs"></textarea></label>
            <label class="block"><span class="label">"New build" phrases (one per line)</span><textarea wire:model="form.new_build_phrases" rows="3" class="field text-xs"></textarea></label>
        </section>

        {{-- Alerts + polling --}}
        <section class="card space-y-3">
            <h2 class="font-semibold">Alerts and polling</h2>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="form.quiet_alerts" class="rounded border-slate-300 text-teal-600">
                Quiet Telegram alerts for medium-fit projects
            </label>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" wire:model="form.rank_rebuild" class="mt-0.5 rounded border-slate-300 text-teal-600">
                <span>Rank rebuild mode
                    <span class="block text-xs text-slate-500">+10 quick fixes (1-2 days), +10 clients rated 4.8+ with 3+ projects, -15 unverified payment, -20 price shoppers or budget under minimum, -20 large or off-niche work.</span>
                </span>
            </label>
            <label class="flex items-center gap-2 text-sm {{ $pollingAllowed ? '' : 'text-slate-400' }}">
                <input type="checkbox" wire:model="form.polling_enabled" class="rounded border-slate-300 text-teal-600" @disabled(! $pollingAllowed)>
                Poll Freelancer for new projects
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="form.paused" class="rounded border-slate-300 text-teal-600">
                Paused (same as Telegram /pause)
            </label>
            <label class="block max-w-40">
                <span class="label">Poll every (seconds)</span>
                <input type="number" min="30" max="3600" step="30" wire:model="form.poll_interval_seconds" class="field">
                @error('form.poll_interval_seconds') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            @unless ($pollingAllowed)
                <p class="rounded-lg bg-slate-100 px-3 py-2 text-xs text-slate-600">
                    Locked off. Polling needs written permission from Freelancer, then <code>FREELANCER_POLLING_ENABLED=true</code> in <code>.env</code>.
                </p>
            @endunless

            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg bg-slate-50 px-3 py-2 text-xs">
                <dt class="text-slate-500">Freelancer token</dt><dd>{{ $tokenSet ? 'set' : 'missing' }}</dd>
                <dt class="text-slate-500">Last poll</dt><dd>{{ $lastPoll ? $lastPoll->diffForHumans() : 'never' }}</dd>
                @if ($backoffUntil)
                    <dt class="text-slate-500">Backing off</dt><dd class="text-amber-700">until {{ date('H:i', $backoffUntil) }} (after an API error)</dd>
                @endif
                <dt class="text-slate-500">Skill IDs</dt>
                <dd>
                    @if ($skillIds === null) not looked up yet (first poll or <code>php artisan bidradar:skills</code>)
                    @else {{ collect($skillIds)->map(fn ($id, $n) => "$n $id")->implode(', ') ?: 'none matched' }} @endif
                    @if ($unmatchedSkills) <span class="block text-red-600">Not found on Freelancer: {{ implode(', ', $unmatchedSkills) }}</span> @endif
                </dd>
                <dt class="text-slate-500">Telegram</dt><dd>{{ $telegramReady ? 'configured' : 'missing bot token or chat ID' }}</dd>
            </dl>
        </section>

        <div class="sticky bottom-4 z-10">
            @php($formErrors = collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'form'))->flatten())
            @if ($formErrors->isNotEmpty())
                <div class="mb-2 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                    <p class="font-medium">Not saved. Fix these first:</p>
                    <ul class="mt-1 list-disc pl-5">@foreach ($formErrors as $message)<li>{{ $message }}</li>@endforeach</ul>
                </div>
            @endif
            <button type="submit" class="btn-primary w-full py-3 text-base shadow-lg">Save settings</button>
        </div>
    </form>

    {{-- Past projects --}}
    <section class="card space-y-4">
        <div>
            <h2 class="font-semibold">Past projects</h2>
            <p class="text-xs text-slate-500">From your own Freelancer.com history only. No client names.</p>
        </div>

        @foreach ($pastProjects as $p)
            <div wire:key="past-{{ $p->id }}" class="rounded-xl border border-slate-200 p-3 text-sm">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-slate-500">{{ config('bidradar.categories.'.$p->category) }}</span>
                    <div class="flex gap-3 text-xs">
                        <button wire:click="editPast({{ $p->id }})" class="text-teal-700">Edit</button>
                        <button wire:click="deletePast({{ $p->id }})" wire:confirm="Delete this past project?" class="text-red-600">Delete</button>
                    </div>
                </div>
                <p class="mt-1"><span class="text-slate-500">Problem:</span> {{ $p->problem }}</p>
                <p><span class="text-slate-500">Did:</span> {{ $p->what_i_did }}</p>
                @if ($p->result)<p><span class="text-slate-500">Result:</span> {{ $p->result }}</p>@endif
            </div>
        @endforeach

        <form wire:submit="savePast" class="space-y-3 rounded-xl bg-slate-50 p-3">
            <p class="text-sm font-medium">{{ $past['id'] ? 'Edit past project' : 'Add a past project' }}</p>
            <select wire:model="past.category" class="field">
                @foreach (config('bidradar.categories') as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <textarea wire:model="past.problem" rows="2" class="field" placeholder="Problem"></textarea>
            @error('past.problem') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <textarea wire:model="past.what_i_did" rows="2" class="field" placeholder="What I did"></textarea>
            @error('past.what_i_did') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <textarea wire:model="past.result" rows="2" class="field" placeholder="Result"></textarea>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">{{ $past['id'] ? 'Save changes' : 'Add' }}</button>
                @if ($past['id'])<button type="button" wire:click="resetPast" class="btn">Cancel</button>@endif
            </div>
        </form>
    </section>
</div>
