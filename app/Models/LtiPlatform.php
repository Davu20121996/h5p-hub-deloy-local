<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LtiPlatform extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['allowed_origins' => 'array', 'active' => 'boolean'];
    }
}
