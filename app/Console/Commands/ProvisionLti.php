<?php

namespace App\Console\Commands;

use App\Lti\Claims;
use App\Lti\ScoreService;
use App\Models\LtiDeployment;
use App\Models\LtiPlatform;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProvisionLti extends Command
{
    protected $signature = 'lti:register {file : Path to registration JSON}';

    protected $description = 'Register or update a trusted platform and map its deployment to a tenant';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error('Registration file not found.');

            return self::FAILURE;
        }
        try {
            $input = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            $data = Validator::make($input, [
                'tenant_slug' => 'required|alpha_dash|max:100', 'tenant_name' => 'required|string|max:255',
                'name' => 'required|string|max:255', 'issuer' => 'required|url|max:512', 'client_id' => 'required|string|max:255',
                'authorization_url' => 'required|url', 'jwks_url' => 'required|url',
                'ags_token_url' => 'sometimes|nullable|url|max:2048',
                'deployment_id' => 'required|string|max:255', 'allowed_origins' => 'required|array|min:1',
                'allowed_origins.*' => 'required|url',
                'tenant_active' => 'sometimes|boolean', 'platform_active' => 'sometimes|boolean', 'deployment_active' => 'sometimes|boolean',
            ])->validate();
            foreach (['issuer', 'authorization_url', 'jwks_url'] as $key) {
                if (! Claims::validUrl($data[$key])) {
                    throw new \InvalidArgumentException("$key must use HTTPS (local HTTP requires LTI_ALLOW_HTTP=true).");
                }
            }
            foreach ($data['allowed_origins'] as $origin) {
                if (! Claims::validUrl($origin) || rtrim($origin, '/') !== Claims::origin($origin)) {
                    throw new \InvalidArgumentException('Allowed origins must be exact origins without paths or wildcards.');
                }
            }
            if (isset($data['ags_token_url']) && ! ScoreService::isPlatformUrl($data['ags_token_url'], $data['issuer'])) {
                throw new \InvalidArgumentException('AGS token URL must use the issuer origin and omit query and fragment.');
            }
            DB::transaction(function () use ($data) {
                $tenant = Tenant::firstOrCreate(['slug' => $data['tenant_slug']], ['name' => $data['tenant_name']]);
                $tenant->update(['name' => $data['tenant_name']] + (array_key_exists('tenant_active', $data) ? ['active' => $data['tenant_active']] : []));
                $platform = LtiPlatform::updateOrCreate(['issuer' => $data['issuer'], 'client_id' => $data['client_id']], [
                    'name' => $data['name'], 'authorization_url' => $data['authorization_url'], 'jwks_url' => $data['jwks_url'],
                    'allowed_origins' => array_map(fn ($origin) => Claims::origin($origin), $data['allowed_origins']),
                ] + (array_key_exists('platform_active', $data) ? ['active' => $data['platform_active']] : [])
                    + array_intersect_key($data, ['ags_token_url' => true]));
                $deployment = LtiDeployment::firstOrCreate([
                    'lti_platform_id' => $platform->id, 'deployment_id' => $data['deployment_id'],
                ], ['tenant_id' => $tenant->id]);
                if ($deployment->tenant_id !== $tenant->id) {
                    throw new \InvalidArgumentException('Existing deployment cannot be reassigned to a different tenant.');
                }
                if (array_key_exists('deployment_active', $data)) {
                    $deployment->update(['active' => $data['deployment_active']]);
                }
            });
            $this->info('Registration saved.');
            $this->line('Login URL: '.route('lti.login'));
            $this->line('Launch / redirect URL: '.route('lti.launch'));
            $this->line('Deep Linking target URL: '.route('lti.launch'));
            $this->line('Public JWKS: '.route('lti.jwks'));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
