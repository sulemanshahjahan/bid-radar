<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id', 'version', 'proposal_text', 'edited_text', 'bid_amount', 'currency', 'is_hourly',
    'delivery_days', 'milestones', 'price_reason', 'questions_to_ask_later', 'check_failures',
    'instruction', 'attempts',
])]
class Draft extends Model
{
    protected function casts(): array
    {
        return [
            'bid_amount' => 'decimal:2',
            'is_hourly' => 'boolean',
            'milestones' => 'array',
            'questions_to_ask_later' => 'array',
            'check_failures' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The text to bid with: my edits if any, otherwise the generated proposal. */
    public function finalText(): string
    {
        return $this->edited_text ?? $this->proposal_text;
    }

    public function milestonesText(): string
    {
        return collect($this->milestones ?? [])
            ->map(fn ($m) => $m['title'].' - '.$this->currency.' '.number_format((float) $m['amount'], 2, '.', ''))
            ->implode("\n");
    }
}
