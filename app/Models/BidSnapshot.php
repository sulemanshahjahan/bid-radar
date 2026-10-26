<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['project_id', 'bid_count', 'bid_avg', 'recorded_at'])]
class BidSnapshot extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'bid_avg' => 'decimal:2',
        ];
    }
}
