<?php

namespace App\Http\Controllers;

use App\Lti\Claims;
use App\Lti\KeyStore;
use App\Models\H5pContent;
use App\Models\LtiDeployment;
use App\Models\LtiPlatform;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DemoPlatformController extends Controller
{
    private function enabled(): void
    {
        abort_unless(app()->environment('local') && config('lti.demo_enabled'), 404);
    }

    public function index(): View
    {
        $this->enabled();
        $deployments = LtiDeployment::with('tenant', 'platform')->whereIn('deployment_id', ['demo-a', 'demo-b'])->get();

        return view('demo.index', compact('deployments'));
    }

    public function jwks(): JsonResponse
    {
        $this->enabled();

        return response()->json($this->withDemoKeys(fn ($keys) => $keys->jwks()));
    }

    public function authorize(Request $request, string $site): View
    {
        $this->enabled();
        abort_unless(in_array($site, ['a', 'b'], true), 404);
        $input = $request->validate([
            'client_id' => 'required|string', 'redirect_uri' => 'required|url', 'state' => 'required|string|max:128',
            'nonce' => 'required|string|max:128', 'login_hint' => 'required|string|max:100',
            'response_type' => 'required|in:id_token', 'response_mode' => 'required|in:form_post', 'scope' => 'required|in:openid', 'prompt' => 'required|in:none',
        ]);
        $platform = LtiPlatform::where('issuer', url('/demo/platform/'.$site))->where('client_id', $input['client_id'])->firstOrFail();
        abort_unless($input['redirect_uri'] === route('lti.launch'), 400);
        $deployment = LtiDeployment::where('lti_platform_id', $platform->id)->where('deployment_id', 'demo-'.$site)->firstOrFail();
        $trainer = $input['login_hint'] === 'trainer';
        $payload = [
            'iss' => $platform->issuer, 'aud' => $platform->client_id, 'sub' => ($trainer ? 'trainer-' : 'learner-').$site,
            'name' => ($trainer ? 'Trainer ' : 'Learner ').strtoupper($site), 'iat' => time(), 'exp' => time() + 300, 'nonce' => $input['nonce'],
            Claims::PREFIX.'version' => '1.3.0', Claims::PREFIX.'deployment_id' => $deployment->deployment_id,
            Claims::PREFIX.'target_link_uri' => route('lti.launch'),
            Claims::PREFIX.'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#'.($trainer ? 'Instructor' : 'Learner')],
            Claims::PREFIX.'context' => ['id' => 'demo-course-'.$site, 'title' => 'Demo course '.strtoupper($site)],
        ];
        if ($trainer) {
            $payload[Claims::PREFIX.'message_type'] = 'LtiDeepLinkingRequest';
            $payload[Claims::DEEP.'deep_linking_settings'] = [
                'deep_link_return_url' => url('/demo/platform/'.$site.'/return'),
                'accept_types' => ['ltiResourceLink'], 'accept_presentation_document_targets' => ['iframe', 'window'],
                'data' => 'demo-'.$site,
            ];
        } else {
            abort_unless(str_starts_with($input['login_hint'], 'learner:'), 400);
            $uuid = substr($input['login_hint'], 8);
            H5pContent::where('uuid', $uuid)->where('tenant_id', $deployment->tenant_id)->where('status', 'published')->firstOrFail();
            $payload[Claims::PREFIX.'message_type'] = 'LtiResourceLinkRequest';
            $payload[Claims::PREFIX.'resource_link'] = ['id' => 'demo-resource-'.$uuid];
            $payload[Claims::PREFIX.'custom'] = ['content_id' => $uuid];
        }
        $jwt = $this->withDemoKeys(fn ($keys) => $keys->sign($payload));

        return view('demo.authorize', ['jwt' => $jwt, 'state' => $input['state']]);
    }

    public function returned(Request $request, string $site): View
    {
        $this->enabled();
        $input = $request->validate(['JWT' => 'required|string|max:65536']);
        try {
            $claims = json_decode(json_encode(JWT::decode($input['JWT'], JWK::parseKeySet(app(KeyStore::class)->jwks(), 'RS256'))), true);
        } catch (\Throwable) {
            abort(401);
        }
        abort_unless(($claims['aud'] ?? null) === url('/demo/platform/'.$site)
            && ($claims['iss'] ?? null) === 'demo-client-'.$site
            && Claims::get($claims, 'deployment_id') === 'demo-'.$site
            && Claims::get($claims, 'message_type') === 'LtiDeepLinkingResponse', 401);
        $uuid = $claims[Claims::DEEP.'content_items'][0]['custom']['content_id'] ?? null;
        $deployment = LtiDeployment::where('deployment_id', 'demo-'.$site)->firstOrFail();
        $content = H5pContent::where('tenant_id', $deployment->tenant_id)->where('uuid', $uuid)->firstOrFail();

        return view('demo.returned', compact('content', 'deployment'));
    }

    public function withDemoKeys(callable $callback): mixed
    {
        $previous = config('lti.key_directory');
        config(['lti.key_directory' => storage_path('app/private/demo-platform-keys')]);
        try {
            return $callback(new KeyStore);
        } finally {
            config(['lti.key_directory' => $previous]);
        }
    }
}
