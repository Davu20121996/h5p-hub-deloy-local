<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class H5pContent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'metadata' => 'array', 'dependencies' => 'array'];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(H5pLibrary::class, 'h5p_library_id');
    }
}
