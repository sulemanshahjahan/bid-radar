<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One daily count (bidradar:daily-stats). Counts only: no project IDs, titles or text. */
#[Fillable(['date', 'metric', 'key', 'value'])]
class DailyStat extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value' => 'integer',
        ];
    }
}
