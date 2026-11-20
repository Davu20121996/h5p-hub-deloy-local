<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class H5pAttempt extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function launch(): BelongsTo
    {
        return $this->belongsTo(LtiLaunch::class, 'lti_launch_id');
    }

    protected function casts(): array
    {
        return ['result' => 'array', 'completed' => 'boolean'];
    }
}
