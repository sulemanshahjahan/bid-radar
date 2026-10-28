<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable([
    'source', 'freelancer_id', 'url', 'title', 'description', 'details', 'scoring',
    'category', 'fit_score', 'status', 'ai_state', 'ai_error', 'auto_draft',
    'my_bid_amount', 'my_bid_currency', 'bid_count_at_bid', 'avg_bid_at_bid',
    'bid_placed_at', 'posted_at', 'purged_at', 'filter_reason', 'alerted_at',
    'my_bid_rank', 'viewed', 'chat_started', 'won_at', 'scored_at', 'drafted_at',
    'bid_count_observed', 'bid_count_observed_at', 'decision',
])]
class Project extends Model
{
    public const STATUSES = [
        'new' => 'New',
        'drafted' => 'Drafted',
        'bid_placed' => 'Bid placed',
        'skipped' => 'Skipped',
        'won' => 'Won',
        'lost' => 'Lost',
    ];

    protected function casts(): array
    {
        return [
            // Project data from Freelancer (or pasted from it): encrypted, purged after 24h.
            'title' => 'encrypted',
            'description' => 'encrypted',
            'details' => 'encrypted:array',
            'scoring' => 'encrypted:array',
            'auto_draft' => 'boolean',
            'fit_score' => 'integer',
            'my_bid_amount' => 'decimal:2',
            'avg_bid_at_bid' => 'decimal:2',
            'bid_placed_at' => 'datetime',
            'posted_at' => 'datetime',
            'purged_at' => 'datetime',
            'alerted_at' => 'datetime',
            'won_at' => 'datetime',
            'scored_at' => 'datetime',
            'drafted_at' => 'datetime',
            'viewed' => 'boolean',
            'chat_started' => 'boolean',
            'my_bid_rank' => 'integer',
            'bid_count_at_bid' => 'integer',
            'bid_count_observed' => 'integer',
            'bid_count_observed_at' => 'datetime',
            // BidRadar's own score and alert decision (ScoreProject, Telegram). No Freelancer text; kept after the purge.
            'decision' => 'array',
        ];
    }

    /** Merge keys into the decision record. */
    public function recordDecision(array $values): void
    {
        $this->forceFill(['decision' => array_merge($this->decision ?? [], $values)])->save();
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(Draft::class);
    }

    public function latestDraft(): HasOne
    {
        return $this->hasOne(Draft::class)->latestOfMany('version');
    }

    public function scopeVisible(Builder $query, int $mediumThreshold): Builder
    {
        return $query->where('ai_state', '!=', 'filtered')->where(fn (Builder $q) => $q
            ->whereNull('fit_score')
            ->orWhere('fit_score', '>=', $mediumThreshold)
            ->orWhere('status', '!=', 'new'));
    }

    public function detail(string $key, mixed $default = null): mixed
    {
        return data_get($this->details ?? [], $key, $default);
    }

    public function score(string $key, mixed $default = null): mixed
    {
        return data_get($this->scoring ?? [], $key, $default);
    }

    public function displayTitle(): string
    {
        return $this->title ?: ($this->purged_at ? 'Project #'.$this->id.' (data purged)' : 'Untitled project');
    }

    /**
     * How fast this project moved: each step with the seconds since the step before it.
     * "You bid" is when Bid placed was tapped in BidRadar.
     *
     * @return list<array{label: string, at: Carbon, seconds: int|null}>
     */
    public function timeline(): array
    {
        $steps = array_filter([
            'Posted' => $this->source === 'freelancer' ? $this->posted_at : null,
            'Found' => $this->created_at,
            'Scored' => $this->scored_at,
            'Alerted' => $this->alerted_at,
            'Draft ready' => $this->drafted_at,
            'You bid' => $this->bid_placed_at,
        ]);

        $out = [];
        $previous = null;
        foreach ($steps as $label => $at) {
            $out[] = ['label' => $label, 'at' => $at, 'seconds' => $previous ? (int) $previous->diffInSeconds($at, true) : null];
            $previous = $at;
        }

        return $out;
    }

    /** 45s, 2m 05s, 3h 10m */
    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 90 => $seconds.'s',
            $seconds < 3600 => intdiv($seconds, 60).'m '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s',
            default => intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m',
        };
    }

    public function isBusy(): bool
    {
        return in_array($this->ai_state, ['queued', 'scoring', 'drafting'], true);
    }
}
