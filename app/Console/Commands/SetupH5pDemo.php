<?php

namespace App\Console\Commands;

use App\H5p\LibraryInstaller;
use App\H5p\Runtime;
use App\Lti\Claims;
use App\Lti\KeyStore;
use App\Models\H5pContent;
use App\Models\LtiDeployment;
use App\Models\LtiIdentity;
use App\Models\LtiLaunch;
use App\Models\LtiPlatform;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

class SetupH5pDemo extends Command
{
    protected $signature = 'h5p:demo';

    protected $description = 'Set up two local demo platforms and a small working H5P question';

    public function handle(): int
    {
        if (! app()->environment('local') || ! config('lti.demo_enabled')) {
            $this->error('Local only. Set LTI_DEMO_ENABLED=true and LTI_ALLOW_HTTP=true for local HTTP.');

            return self::FAILURE;
        }
        if (! File::exists(config('lti.key_directory').'/active')) {
            app(KeyStore::class)->generate();
        }
        $previous = config('lti.key_directory');
        config(['lti.key_directory' => storage_path('app/private/demo-platform-keys')]);
        try {
            if (! File::exists(config('lti.key_directory').'/active')) {
                (new KeyStore)->generate();
            }
        } finally {
            config(['lti.key_directory' => $previous]);
        }
        $package = storage_path('app/private/demo-library.h5p');
        File::ensureDirectoryExists(dirname($package));
        $zip = new ZipArchive;
        $zip->open($package, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (File::files(resource_path('examples/demo-library')) as $file) {
            $zip->addFile($file->getPathname(), 'Hub.DemoQuestion-1.0/'.$file->getFilename());
        }
        $zip->close();
        app(LibraryInstaller::class)->install($package);
        File::delete($package);
        foreach (['a', 'b'] as $site) {
            $tenant = Tenant::firstOrCreate(['slug' => 'demo-'.$site], ['name' => 'Demo đơn vị '.strtoupper($site)]);
            $platform = LtiPlatform::updateOrCreate(['issuer' => url('/demo/platform/'.$site), 'client_id' => 'demo-client-'.$site], [
                'name' => 'Demo Web '.strtoupper($site), 'authorization_url' => url('/demo/platform/'.$site.'/authorize'),
                'jwks_url' => rtrim(config('lti.demo_jwks_base') ?: url('/'), '/').'/demo/platform/'.$site.'/jwks',
                'allowed_origins' => [Claims::origin(url('/'))], 'active' => true,
            ]);
            $deployment = LtiDeployment::firstOrCreate(['lti_platform_id' => $platform->id, 'deployment_id' => 'demo-'.$site], ['tenant_id' => $tenant->id]);
            $identity = LtiIdentity::firstOrCreate(['lti_platform_id' => $platform->id, 'subject' => 'trainer-'.$site], ['name' => 'Trainer '.strtoupper($site)]);
            if (! H5pContent::where('tenant_id', $tenant->id)->exists()) {
                $launch = LtiLaunch::create(['id' => (string) Str::uuid(), 'lti_deployment_id' => $deployment->id, 'lti_identity_id' => $identity->id,
                    'message_type' => 'LtiDeepLinkingRequest', 'can_author' => true, 'roles' => [], 'expires_at' => now()->addHour()]);
                (new Runtime($launch))->save(null, 'Hub.DemoQuestion 1.0', ['question' => 'Đây là bài của đơn vị '.strtoupper($site).'?', 'answer' => true],
                    ['title' => 'Bài mẫu '.strtoupper($site), 'license' => 'U'], 'Bài mẫu '.strtoupper($site), 'published');
                $launch->delete();
            }
        }
        $this->info('Demo ready: '.url('/demo'));

        return self::SUCCESS;
    }
}
