<?php

namespace App\Livewire;

use App\Jobs\DraftProject;
use App\Models\Draft;
use App\Models\Project;
use App\Models\WinningExample;
use App\Services\BidTracker;
use App\Services\CostTracker;
use App\Services\DraftGate;
use App\Services\Drafting\PricingCalculator;
use App\Services\Freelancer\FreelancerClient;
use App\Services\Freelancer\FreelancerException;
use App\Services\Settings;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ProjectShow extends Component
{
    #[Locked]
    public int $projectId;

    public string $editedText = '';

    public string $instruction = '';

    public $myBid = '';

    public $myRank = '';

    public $totalBids = '';

    #[Locked]
    public ?int $loadedDraftId = null;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        $this->syncFromDraft();
        $this->myBid = $project->my_bid_amount ?? ($project->latestDraft?->bid_amount ?? '');
        $this->myRank = $project->my_bid_rank ?? '';
        $this->totalBids = $project->bid_count_at_bid ?? $project->detail('bid_count', '');
    }

    public function project(): Project
    {
        return Project::query()->with('latestDraft')->findOrFail($this->projectId);
    }

    /** My edits are saved as I type. */
    public function updatedEditedText(): void
    {
        $draft = $this->project()->latestDraft;
        if ($draft === null) {
            return;
        }

        $text = trim($this->editedText);
        $draft->update(['edited_text' => $text === '' || $text === $draft->proposal_text ? null : $text]);
    }

    /** @param bool $anyway Set by the "Draft anyway" button on a filtered project. */
    public function draft(CostTracker $costs, DraftGate $gate, bool $anyway = false): void
    {
        $project = $this->project();
        if ($project->isBusy() || $project->latestDraft !== null) {
            return;
        }
        if (! $anyway && $gate->blockers($project) !== []) {
            return; // filtered: only "Draft anyway" may spend AI on it
        }
        if ($this->capBlocks($costs)) {
            return;
        }

        $project->update(['ai_state' => 'queued', 'ai_error' => null]);
        DraftProject::dispatch($project->id);
    }

    public function regenerate(CostTracker $costs): void
    {
        $this->validate(['instruction' => 'nullable|string|max:250']);

        $project = $this->project();
        if ($project->isBusy() || $this->capBlocks($costs)) {
            return;
        }

        $project->update(['ai_state' => 'queued', 'ai_error' => null]);
        DraftProject::dispatch($project->id, regenerate: true, instruction: trim($this->instruction) ?: null);
        $this->instruction = '';
    }

    /**
     * One API call: current bid count and average, then the adjusted score and the
     * suggested bid are recalculated (existing pricing rules; any draft is untouched).
     */
    public function refresh(FreelancerClient $client, BidTracker $bids, PricingCalculator $pricing): void
    {
        $project = $this->project();
        if (config('bidradar.freelancer.polling_enabled') !== true) {
            $this->addError('refresh', 'Refresh needs Freelancer API access (FREELANCER_POLLING_ENABLED=true).');

            return;
        }
        if ($project->purged_at !== null) {
            $this->addError('refresh', 'This project\'s data was deleted after 24 hours.');

            return;
        }

        $seo = $project->url ? trim((string) preg_replace('#^.*?/projects/#', '', (string) parse_url($project->url, PHP_URL_PATH)), '/') : '';

        try {
            $stats = match (true) {
                $project->freelancer_id !== null => $client->bidStats([(int) $project->freelancer_id], 'refresh')[(int) $project->freelancer_id] ?? null,
                $seo !== '' => $client->bidStatsBySeoUrl($seo),
                default => throw new FreelancerException('No Freelancer link on this project to refresh from.'),
            };
        } catch (FreelancerException $e) {
            $this->addError('refresh', $e->getMessage());

            return;
        }

        if ($stats === null) {
            $this->addError('refresh', 'Freelancer did not return this project (it may be closed).');

            return;
        }

        $bids->record($project, $stats['bid_count'], $stats['bid_avg']);
        $project->refresh();

        $hours = $project->score('estimated_hours');
        $price = $pricing->price($project->details ?? [], is_numeric($hours) ? (int) $hours : null);
        $details = $project->details;
        $details['suggested'] = $price + ['at' => now()->toIso8601String()];
        $project->forceFill(['details' => $details])->save();

        $this->dispatch('copied', 'Refreshed: '.$stats['bid_count'].' bids');
    }

    public function markBidPlaced(): void
    {
        $this->validate([
            'myBid' => 'required|numeric|gt:0',
            'myRank' => 'nullable|integer|min:1',
            'totalBids' => 'nullable|integer|min:0',
        ]);

        $project = $this->project();
        $project->update([
            'status' => 'bid_placed',
            'my_bid_amount' => $this->myBid,
            'my_bid_currency' => $project->latestDraft?->currency ?? $project->detail('currency', 'USD'),
            'my_bid_rank' => $this->myRank === '' || $this->myRank === null ? null : (int) $this->myRank,
            'bid_count_at_bid' => $this->totalBids === '' || $this->totalBids === null ? $project->detail('bid_count') : (int) $this->totalBids,
            'avg_bid_at_bid' => $project->detail('bid_avg'),
            'bid_placed_at' => $project->bid_placed_at ?? now(),
        ]);
        $this->dispatch('copied', 'Marked as bid placed');
    }

    /** "Viewed" and "Chat started": yes, no, or back to unknown (null). */
    public function setOutcome(string $field, ?bool $value): void
    {
        abort_unless(in_array($field, ['viewed', 'chat_started'], true), 422);

        $this->project()->update([$field => $value]);
    }

    public function setStatus(string $status): void
    {
        abort_unless(in_array($status, ['new', 'drafted', 'skipped', 'won', 'lost'], true), 422);

        $project = $this->project();
        $project->update(['status' => $status, 'won_at' => $status === 'won' ? ($project->won_at ?? now()) : null]);

        // Learning loop: my final (edited) proposal becomes a winning example for its category.
        if ($status === 'won' && $project->latestDraft !== null) {
            WinningExample::query()->updateOrCreate(
                ['project_id' => $project->id],
                ['category' => $project->category ?? 'other', 'proposal_text' => $project->latestDraft->finalText()],
            );
        }
    }

    public function render(Settings $settings, CostTracker $costs, DraftGate $gate)
    {
        $project = $this->project();

        // A new draft arrived (first draft or Regenerate): show it in the editor.
        if ($project->latestDraft?->id !== $this->loadedDraftId) {
            $this->syncFromDraft($project->latestDraft);
        }

        return view('livewire.project-show', [
            'project' => $project,
            'draft' => $project->latestDraft,
            'high' => $settings->int('threshold_high'),
            'medium' => $settings->int('threshold_medium'),
            'capReached' => $costs->capReached(),
            'filtered' => $gate->message($project),
            'bidsPerHour' => BidTracker::perHour($project),
            'canRefresh' => config('bidradar.freelancer.polling_enabled') === true && $project->purged_at === null && ($project->freelancer_id !== null || $project->url !== null),
            'versions' => $project->drafts()->count(),
        ])->title($project->displayTitle());
    }

    private function syncFromDraft(?Draft $draft = null): void
    {
        $draft ??= $this->project()->latestDraft;
        $this->loadedDraftId = $draft?->id;
        $this->editedText = $draft?->finalText() ?? '';
        if ($draft !== null && ($this->myBid === '' || $this->myBid === null)) {
            $this->myBid = $draft->bid_amount;
        }
    }

    private function capBlocks(CostTracker $costs): bool
    {
        if (! $costs->capReached()) {
            return false;
        }

        $this->addError('cap', sprintf('Daily AI spend cap reached ($%.2f). Raise it in Settings to draft today.', $costs->dailyCap()));

        return true;
    }
}
