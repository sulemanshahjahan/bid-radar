<?php

namespace App\Services\Opportunity;

use App\Models\BidSnapshot;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What was known about a project at one moment. Built in memory from the project's own
 * short-lived data (purged after 24 hours) and never stored.
 *
 * $asOf picks the moment: null means now (latest bid count); a time (e.g. scored_at) means
 * only the bid snapshot recorded at or before it is used, so a later observation can never
 * make an earlier recommendation look better (no look-ahead).
 */
final readonly class OpportunityInput
{
    /**
     * @param  array<string, mixed>  $details  budget, currency, exchange_rate, type, client (bid stats come from $bidCount)
     * @param  array<string, mixed>  $scoring  the existing scoring reply (category, estimated_hours, deliverables, ...)
     */
    public function __construct(
        public string $text,
        public array $details,
        public array $scoring,
        public ?int $bidCount,
        public ?Carbon $observedAt,
        public ?Carbon $postedAt,
    ) {}

    /** @param Collection<int, BidSnapshot>|null $snapshots this project's snapshots, if already loaded */
    public static function fromProject(Project $project, ?Carbon $asOf = null, ?Collection $snapshots = null): self
    {
        $details = $project->details ?? [];
        $snapshots ??= BidSnapshot::query()->where('project_id', $project->id)->get(['project_id', 'bid_count', 'recorded_at']);
        $snapshots = $snapshots->sortBy([['recorded_at', 'asc'], ['id', 'asc']]);

        if ($asOf !== null && $project->source === 'paste') {
            // Pasted bid counts are typed in with the brief, before scoring, and never refreshed.
            $bids = isset($details['bid_count']) ? (int) $details['bid_count'] : null;
            $observedAt = $project->created_at;
        } elseif ($asOf !== null) {
            $snap = $snapshots->filter(fn (BidSnapshot $s) => $s->recorded_at->lte($asOf))->last();
            $bids = $snap?->bid_count;
            $observedAt = $snap?->recorded_at;
        } else {
            $bids = isset($details['bid_count']) ? (int) $details['bid_count'] : null;
            $observedAt = $snapshots->last()?->recorded_at ?? $project->created_at;
        }

        return new self(
            text: trim($project->title."\n".$project->description."\n".implode(' | ', (array) ($details['skills'] ?? []))),
            details: $details,
            scoring: $project->scoring ?? [],
            bidCount: $bids,
            observedAt: $observedAt,
            // Pasted projects have no reliable posting time.
            postedAt: $project->source === 'freelancer' ? $project->posted_at : null,
        );
    }

    /**
     * A project described as an array (synthetic evaluation set, tests): title, description,
     * details, scoring, bids (observed count) and age_minutes (posting to observation).
     *
     * @param  array<string, mixed>  $case
     */
    public static function fromArray(array $case): self
    {
        $details = array_merge(['type' => 'fixed', 'currency' => 'USD', 'exchange_rate' => 1], (array) ($case['details'] ?? []));
        $observedAt = now();

        return new self(
            text: trim(($case['title'] ?? '')."\n".($case['description'] ?? '')."\n".implode(' | ', (array) ($details['skills'] ?? []))),
            details: $details,
            scoring: (array) ($case['scoring'] ?? []),
            bidCount: isset($case['bids']) ? (int) $case['bids'] : null,
            observedAt: $observedAt,
            postedAt: isset($case['age_minutes']) ? $observedAt->copy()->subMinutes((int) $case['age_minutes']) : null,
        );
    }

    /** Minutes from posting to the observation, when both are known. */
    public function ageMinutes(): ?int
    {
        return $this->postedAt !== null && $this->observedAt !== null
            ? max(0, (int) $this->postedAt->diffInMinutes($this->observedAt, true))
            : null;
    }
}
