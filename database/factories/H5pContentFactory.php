<?php

namespace Database\Factories;

use App\Models\H5pContent;
use App\Models\H5pLibrary;
use App\Models\LtiIdentity;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class H5pContentFactory extends Factory
{
    protected $model = H5pContent::class;

    public function definition(): array
    {
        return ['uuid' => fake()->uuid(), 'tenant_id' => Tenant::factory(), 'lti_identity_id' => LtiIdentity::factory(), 'h5p_library_id' => H5pLibrary::factory(), 'title' => 'Demo question', 'status' => 'published', 'parameters' => ['question' => 'Test?', 'answer' => true], 'metadata' => ['license' => 'U']];
    }
}
