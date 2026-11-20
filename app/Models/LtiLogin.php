<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LtiLogin extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(LtiPlatform::class, 'lti_platform_id');
    }
}
