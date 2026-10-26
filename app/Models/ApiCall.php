<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One logged request to an external API (Claude or Freelancer). Never stores tokens/keys. */
#[Fillable([
    'provider', 'purpose', 'project_id', 'model', 'status', 'http_status', 'input_tokens',
    'output_tokens', 'cache_write_tokens', 'cache_read_tokens', 'cost_usd', 'duration_ms', 'error',
])]
class ApiCall extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:6',
        ];
    }
}
