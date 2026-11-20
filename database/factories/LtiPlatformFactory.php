<?php

namespace Database\Factories;

use App\Models\LtiPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

class LtiPlatformFactory extends Factory
{
    protected $model = LtiPlatform::class;

    public function definition(): array
    {
        return ['name' => 'Web A', 'issuer' => 'https://web-a.example.com', 'client_id' => 'hub-client', 'authorization_url' => 'https://web-a.example.com/authorize', 'jwks_url' => 'https://web-a.example.com/jwks', 'allowed_origins' => ['https://web-a.example.com'], 'active' => true];
    }
}
