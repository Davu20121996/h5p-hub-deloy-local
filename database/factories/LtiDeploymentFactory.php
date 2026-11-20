<?php

namespace Database\Factories;

use App\Models\LtiDeployment;
use App\Models\LtiPlatform;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class LtiDeploymentFactory extends Factory
{
    protected $model = LtiDeployment::class;

    public function definition(): array
    {
        return ['lti_platform_id' => LtiPlatform::factory(), 'tenant_id' => Tenant::factory(), 'deployment_id' => fake()->uuid(), 'active' => true];
    }
}
