<?php

namespace App\Http\Controllers;

use App\Lti\Claims;
use App\Lti\KeyStore;
use App\Lti\LaunchService;
use App\Models\LtiLaunch;
use App\Models\LtiLogin;
use App\Models\LtiPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LtiController extends Controller
{
    public function login(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'iss' => 'required|string|max:512', 'client_id' => 'sometimes|string|max:255',
            'login_hint' => 'required|string|max:2048', 'target_link_uri' => 'required|url|max:2048',
            'lti_message_hint' => 'sometimes|string|max:4096', 'lti_deployment_id' => 'sometimes|string|max:255',
        ]);
        $platforms = LtiPlatform::where('issuer', $input['iss'])->where('active', true)
            ->when(isset($input['client_id']), fn ($query) => $query->where('client_id', $input['client_id']))->get();
        abort_unless($platforms->count() === 1, 401, 'Unknown or ambiguous platform.');
        $platform = $platforms->first();
        $target = $input['target_link_uri'];
        $parts = parse_url($target);
        abort_unless(Claims::origin($target) === Claims::origin(route('lti.launch'))
            && ($parts['path'] ?? '') === parse_url(route('lti.launch'), PHP_URL_PATH)
            && ! isset($parts['fragment']) && ! isset($parts['user']) && ! isset($parts['pass']), 400, 'Invalid launch target.');
        $state = Str::random(64);
        $nonce = Str::random(64);
        $request->session()->put('lti_logins.'.hash('sha256', $state), true);
        LtiLogin::create([
            'lti_platform_id' => $platform->id, 'state_hash' => hash('sha256', $state), 'nonce_hash' => hash('sha256', $nonce),
            'target_link_uri' => $target, 'deployment_hint' => $input['lti_deployment_id'] ?? null,
            'expires_at' => now()->addSeconds(config('lti.login_lifetime')),
        ]);
        $parameters = array_filter([
            'scope' => 'openid', 'response_type' => 'id_token', 'response_mode' => 'form_post', 'prompt' => 'none',
            'client_id' => $platform->client_id, 'redirect_uri' => route('lti.launch'),
            'login_hint' => $input['login_hint'], 'lti_message_hint' => $input['lti_message_hint'] ?? null,
            'state' => $state, 'nonce' => $nonce,
        ], fn ($value) => $value !== null);
        $separator = str_contains($platform->authorization_url, '?') ? '&' : '?';

        return redirect()->away($platform->authorization_url.$separator.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986));
    }

    public function launch(Request $request, LaunchService $service): RedirectResponse
    {
        $input = $request->validate(['state' => 'required|string|max:128', 'id_token' => 'required|string|max:131072']);
        $stateHash = hash('sha256', $input['state']);
        abort_unless($request->session()->get('lti_logins.'.$stateHash) === true, 401, 'LTI login session is unavailable. Reopen the activity from your LMS.');
        $launch = $service->accept($input['state'], $input['id_token']);
        $request->session()->forget('lti_logins.'.$stateHash);
        $request->session()->regenerate();
        $request->session()->put('lti_launches.'.$launch->id, true);

        return redirect()->route('workspace.index', ['launch' => $launch->id], 303);
    }

    public function jwks(KeyStore $keys): JsonResponse
    {
        return response()->json($keys->jwks())->header('Cache-Control', 'public, max-age=300');
    }

    public function complete(Request $request, LtiLaunch $launch, KeyStore $keys): View
    {
        abort_unless($launch->can_author && $launch->message_type === 'LtiDeepLinkingRequest'
            && $launch->completed_at === null, 403);
        $data = $request->validate(['content_id' => 'required|uuid']);
        $content = $launch->content($data['content_id']);
        abort_unless($content->status === 'published', 422, 'Publish the content before selecting it.');
        $settings = $launch->deep_linking_settings;
        $platform = $launch->deployment->platform;
        $payload = [
            'iss' => $platform->client_id, 'aud' => $platform->issuer, 'iat' => time(), 'exp' => time() + 300,
            'nonce' => Str::random(40),
            Claims::PREFIX.'deployment_id' => $launch->deployment->deployment_id,
            Claims::PREFIX.'message_type' => 'LtiDeepLinkingResponse',
            Claims::PREFIX.'version' => '1.3.0',
            Claims::DEEP.'content_items' => [[
                'type' => 'ltiResourceLink', 'title' => $content->title,
                'url' => route('lti.launch', ['content_id' => $content->uuid]),
                'custom' => ['content_id' => $content->uuid],
                'presentation' => ['documentTarget' => in_array('iframe', $settings['accept_presentation_document_targets'], true) ? 'iframe' : 'window'],
            ]],
        ];
        if (array_key_exists('data', $settings)) {
            $payload[Claims::DEEP.'data'] = $settings['data'];
        }
        $jwt = $keys->sign($payload);
        $updated = LtiLaunch::whereKey($launch->id)->whereNull('completed_at')->update(['completed_at' => now()]);
        abort_unless($updated === 1, 409, 'Selection already submitted.');

        return view('lti.return', ['returnUrl' => $settings['deep_link_return_url'], 'jwt' => $jwt]);
    }
}
