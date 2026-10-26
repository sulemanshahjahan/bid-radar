<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'source', 'freelancer_id', 'url', 'title', 'description', 'details', 'scoring',
    'category', 'fit_score', 'status', 'ai_state', 'ai_error', 'auto_draft',
    'my_bid_amount', 'my_bid_currency', 'bid_count_at_bid', 'avg_bid_at_bid',
    'bid_placed_at', 'posted_at', 'purged_at',
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
        ];
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
        return $query->where(fn (Builder $q) => $q
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

    public function isBusy(): bool
    {
        return in_array($this->ai_state, ['queued', 'scoring', 'drafting'], true);
    }
}
