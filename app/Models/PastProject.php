<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A summary the owner wrote of their own past Freelancer.com work.
 * Part of the fact bank. Never contains client names.
 */
#[Fillable(['category', 'problem', 'what_i_did', 'result'])]
class PastProject extends Model
{
    //
}
