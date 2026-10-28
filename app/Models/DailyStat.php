<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One count for one UTC hour (bidradar:daily-stats); a day is the sum of its hours.
 * Counts only: no project IDs, titles or text. Metric "meta" holds bookkeeping:
 * counted_at (unix time), projects, and missed = 1 for an hour that could not be counted.
 */
#[Fillable(['date', 'hour', 'metric', 'key', 'value'])]
class DailyStat extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'hour' => 'integer',
            'value' => 'integer',
        ];
    }
}
