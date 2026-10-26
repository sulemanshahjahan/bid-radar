<form wire:submit="save" class="space-y-4">
    <div>
        <h1 class="text-xl font-semibold">Paste a project</h1>
        <p class="mt-1 text-sm text-slate-500">Copy the brief from Freelancer and paste it here. BidRadar scores it, then drafts a proposal for you to copy.</p>
    </div>

    <div class="card space-y-4">
        <label class="block">
            <span class="label">Project title</span>
            <input type="text" wire:model="title" class="field" placeholder="Optional: first line of the brief is used if empty">
            @error('title') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
        </label>

        <label class="block">
            <span class="label">Project description <span class="text-red-600">*</span></span>
            <textarea wire:model="description" rows="9" class="field" placeholder="Paste the full brief here"></textarea>
            @error('description') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
        </label>

        <label class="block">
            <span class="label">Freelancer project link</span>
            <input type="url" wire:model="url" class="field" placeholder="https://www.freelancer.com/projects/...">
            @error('url') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
        </label>

        <label class="block">
            <span class="label">Skills</span>
            <input type="text" wire:model="skills" class="field" placeholder="WordPress, WooCommerce, PHP">
        </label>
    </div>

    <div class="card space-y-4">
        <div class="flex gap-2">
            @foreach (['fixed' => 'Fixed price', 'hourly' => 'Hourly'] as $value => $label)
                <label class="flex-1">
                    <input type="radio" wire:model.live="type" value="{{ $value }}" class="peer sr-only">
                    <span class="block cursor-pointer rounded-lg border border-slate-300 px-3 py-2 text-center text-sm peer-checked:border-teal-700 peer-checked:bg-teal-50 peer-checked:font-medium peer-checked:text-teal-800">{{ $label }}</span>
                </label>
            @endforeach
        </div>

        <div class="grid grid-cols-2 gap-3">
            <label class="block">
                <span class="label">Budget min {{ $type === 'hourly' ? '(per hour)' : '' }}</span>
                <input type="number" step="any" inputmode="decimal" wire:model="budgetMin" class="field">
                @error('budgetMin') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="label">Budget max</span>
                <input type="number" step="any" inputmode="decimal" wire:model="budgetMax" class="field">
                @error('budgetMax') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="label">Currency</span>
                <input type="text" maxlength="3" wire:model.blur="currency" class="field uppercase">
                @error('currency') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="label">1 {{ $currency ?: '???' }} = ? USD</span>
                <input type="number" step="any" inputmode="decimal" wire:model="exchangeRate" class="field" @disabled($currency === 'USD')>
                @error('exchangeRate') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="label">Bids so far</span>
                <input type="number" inputmode="numeric" wire:model="bidCount" class="field">
                @error('bidCount') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="label">Average bid</span>
                <input type="number" step="any" inputmode="decimal" wire:model="bidAvg" class="field">
                @error('bidAvg') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </div>

    <div class="card space-y-3">
        <p class="text-sm font-medium text-slate-700">Client</p>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="paymentVerified" class="rounded border-slate-300 text-teal-600"> Payment verified
        </label>
        <div class="grid grid-cols-2 gap-3">
            <label class="block">
                <span class="label">Rating (0–5)</span>
                <input type="number" step="0.1" inputmode="decimal" wire:model="clientRating" class="field">
            </label>
            <label class="block">
                <span class="label">Country</span>
                <input type="text" wire:model="clientCountry" class="field">
            </label>
        </div>
    </div>

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" wire:model="alwaysDraft" class="rounded border-slate-300 text-teal-600">
        Draft automatically if it passes the filters (score, bids, new build)
    </label>

    <button type="submit" class="btn-primary w-full py-3 text-base" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="save">Score and draft</span>
        <span wire:loading wire:target="save">Saving…</span>
    </button>
</form>
