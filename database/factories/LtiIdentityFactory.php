<?php

namespace Database\Factories;

use App\Models\LtiIdentity;
use App\Models\LtiPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

class LtiIdentityFactory extends Factory
{
    protected $model = LtiIdentity::class;

    public function definition(): array
    {
        return ['lti_platform_id' => LtiPlatform::factory(), 'subject' => fake()->uuid(), 'name' => fake()->name()];
    }
}
