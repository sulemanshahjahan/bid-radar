<?php

namespace App\Livewire;

use App\Jobs\DraftProject;
use App\Models\Draft;
use App\Models\Project;
use App\Models\WinningExample;
use App\Services\CostTracker;
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

    #[Locked]
    public ?int $loadedDraftId = null;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        $this->syncFromDraft();
        $this->myBid = $project->my_bid_amount ?? ($project->latestDraft?->bid_amount ?? '');
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

    public function draft(CostTracker $costs): void
    {
        $project = $this->project();
        if ($project->isBusy() || $project->latestDraft !== null) {
            return;
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

    public function markBidPlaced(): void
    {
        $this->validate(['myBid' => 'required|numeric|gt:0']);

        $project = $this->project();
        $project->update([
            'status' => 'bid_placed',
            'my_bid_amount' => $this->myBid,
            'my_bid_currency' => $project->latestDraft?->currency ?? $project->detail('currency', 'USD'),
            'bid_count_at_bid' => $project->detail('bid_count'),
            'avg_bid_at_bid' => $project->detail('bid_avg'),
            'bid_placed_at' => $project->bid_placed_at ?? now(),
        ]);
        $this->dispatch('copied', 'Marked as bid placed');
    }

    public function setStatus(string $status): void
    {
        abort_unless(in_array($status, ['new', 'drafted', 'skipped', 'won', 'lost'], true), 422);

        $project = $this->project();
        $project->update(['status' => $status]);

        // Learning loop: my final (edited) proposal becomes a winning example for its category.
        if ($status === 'won' && $project->latestDraft !== null) {
            WinningExample::query()->updateOrCreate(
                ['project_id' => $project->id],
                ['category' => $project->category ?? 'other', 'proposal_text' => $project->latestDraft->finalText()],
            );
        }
    }

    public function render(Settings $settings, CostTracker $costs)
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
