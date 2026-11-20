<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class H5pLibrary extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'runnable' => 'boolean'];
    }

    public function definition(): array
    {
        return array_merge($this->data, ['libraryId' => $this->id, 'machineName' => $this->machine_name, 'majorVersion' => $this->major_version, 'minorVersion' => $this->minor_version, 'patchVersion' => $this->patch_version]);
    }
}
