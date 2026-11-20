<?php

namespace Database\Factories;

use App\Models\H5pLibrary;
use Illuminate\Database\Eloquent\Factories\Factory;

class H5pLibraryFactory extends Factory
{
    protected $model = H5pLibrary::class;

    public function definition(): array
    {
        return ['machine_name' => 'Hub.DemoQuestion', 'major_version' => 1, 'minor_version' => 0, 'patch_version' => 0, 'runnable' => true, 'data' => array_merge(json_decode(file_get_contents(resource_path('examples/demo-library/library.json')), true), ['path' => 'libraries/Hub.DemoQuestion-1.0', 'semantics' => file_get_contents(resource_path('examples/demo-library/semantics.json'))])];
    }
}
