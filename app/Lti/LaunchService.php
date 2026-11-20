<?php

namespace App\Lti;

use App\Models\H5pContent;
use App\Models\LtiDeployment;
use App\Models\LtiIdentity;
use App\Models\LtiLaunch;
use App\Models\LtiLogin;
use App\Models\LtiResourceLink;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class LaunchService
{
    public function __construct(private PlatformKeys $keys) {}

    public function accept(string $state, string $token): LtiLaunch
    {
        $login = LtiLogin::with('platform')->where('state_hash', hash('sha256', $state))
            ->whereNull('consumed_at')->where('expires_at', '>', now())->first();
        abort_unless($login && $login->platform->active, 401, 'Invalid or expired login state.');
        $platform = $login->platform;
        try {
            $parts = explode('.', $token);
            abort_unless(count($parts) === 3, 401);
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 32, JSON_THROW_ON_ERROR);
            abort_unless(($header['alg'] ?? null) === 'RS256' && is_string($header['kid'] ?? null), 401);
            $claims = json_decode(json_encode(JWT::decode($token, $this->keys->key($platform, $header['kid']))), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(401, 'Invalid LTI token.');
        }
        $aud = $claims['aud'] ?? null;
        abort_unless(($claims['iss'] ?? null) === $platform->issuer, 401, 'Issuer mismatch.');
        abort_unless($aud === $platform->client_id || (is_array($aud) && in_array($platform->client_id, $aud, true)), 401, 'Audience mismatch.');
        abort_if((is_array($aud) && count($aud) > 1 && ($claims['azp'] ?? null) !== $platform->client_id)
            || (isset($claims['azp']) && $claims['azp'] !== $platform->client_id), 401, 'Authorized party mismatch.');
        abort_unless(is_int($claims['iat'] ?? null) && is_int($claims['exp'] ?? null)
            && $claims['iat'] <= time() + 30 && $claims['iat'] >= time() - 600
            && $claims['exp'] > time() && $claims['exp'] > $claims['iat'], 401, 'Invalid token timestamps.');
        abort_unless(is_string($claims['nonce'] ?? null) && hash_equals($login->nonce_hash, hash('sha256', $claims['nonce'])), 401, 'Nonce mismatch.');
        abort_unless(is_string($claims['sub'] ?? null) && strlen($claims['sub']) > 0 && strlen($claims['sub']) <= 255, 401, 'Subject is required.');
        abort_unless(Claims::get($claims, 'version') === '1.3.0'
            && Claims::get($claims, 'target_link_uri') === $login->target_link_uri, 401, 'Invalid LTI version or target.');
        $deploymentId = Claims::get($claims, 'deployment_id');
        abort_unless(is_string($deploymentId) && ($login->deployment_hint === null || $login->deployment_hint === $deploymentId), 401);
        $deployment = LtiDeployment::with('tenant')->where('lti_platform_id', $platform->id)
            ->where('deployment_id', $deploymentId)->where('active', true)->first();
        abort_unless($deployment && $deployment->tenant->active, 403, 'Deployment is unavailable.');
        $roles = Claims::get($claims, 'roles');
        abort_unless(is_array($roles) && array_is_list($roles) && count($roles) <= 50
            && count(array_filter($roles, 'is_string')) === count($roles), 401, 'Invalid roles.');
        $type = Claims::get($claims, 'message_type');
        abort_unless(in_array($type, ['LtiResourceLinkRequest', 'LtiDeepLinkingRequest'], true), 400, 'Unsupported LTI message.');
        $localRole = DB::table('tenant_identity')->join('lti_identities', 'lti_identities.id', '=', 'tenant_identity.lti_identity_id')
            ->where('tenant_id', $deployment->tenant_id)->where('lti_platform_id', $platform->id)
            ->where('subject', $claims['sub'])->value('local_role');
        $canAuthor = $localRole === null ? Claims::canAuthor($roles) : in_array($localRole, ['teacher', 'tenant-admin'], true);
        $ags = $claims[PublishScore::CLAIM] ?? null;
        $lineitem = is_array($ags) ? ($ags['lineitem'] ?? null) : null;
        $scopes = is_array($ags) ? ($ags['scope'] ?? null) : null;
        $agsLineitemUrl = $type === 'LtiResourceLinkRequest' && ! $canAuthor && is_array($scopes)
            && in_array(PublishScore::SCOPE, $scopes, true) && PublishScore::trustedUrl($lineitem, $platform)
            ? $lineitem : null;
        $settings = null;
        $resourceId = null;
        $contentUuid = null;
        $contextId = Claims::get($claims, 'context')['id'] ?? null;
        abort_unless($contextId === null || (is_string($contextId) && strlen($contextId) <= 255), 400);
        if ($type === 'LtiDeepLinkingRequest') {
            abort_unless($canAuthor, 403, 'Author role required.');
            $settings = $claims[Claims::DEEP.'deep_linking_settings'] ?? [];
            abort_unless(is_array($settings) && is_array($settings['accept_types'] ?? null)
                && is_array($settings['accept_presentation_document_targets'] ?? null)
                && count(array_intersect(['iframe', 'window'], $settings['accept_presentation_document_targets'])) > 0
                && (! isset($settings['data']) || (is_string($settings['data']) && strlen($settings['data']) <= 16384)), 400, 'Invalid Deep Linking settings.');
            $returnUrl = $settings['deep_link_return_url'] ?? '';
            abort_unless(is_string($returnUrl) && Claims::validUrl($returnUrl)
                && in_array(Claims::origin($returnUrl), $platform->allowed_origins, true), 400, 'Invalid Deep Linking return URL.');
            abort_unless(in_array('ltiResourceLink', $settings['accept_types'] ?? [], true), 400, 'LTI resource links must be accepted.');
        } else {
            $resourceId = Claims::get($claims, 'resource_link')['id'] ?? null;
            abort_unless(is_string($resourceId) && strlen($resourceId) > 0 && strlen($resourceId) <= 255, 400, 'Resource link is required.');
            $contentUuid = Claims::get($claims, 'custom')['content_id'] ?? null;
            if ($contentUuid === null) {
                $query = parse_url($login->target_link_uri, PHP_URL_QUERY);
                parse_str($query ?? '', $parameters);
                $contentUuid = $parameters['content_id'] ?? null;
            }
            abort_unless($contentUuid === null || (is_string($contentUuid) && Str::isUuid($contentUuid)), 400, 'Invalid content ID.');
        }

        return DB::transaction(function () use ($login, $platform, $deployment, $claims, $roles, $type, $canAuthor, $contextId, $resourceId, $contentUuid, $settings, $agsLineitemUrl) {
            $consumed = LtiLogin::whereKey($login->id)->whereNull('consumed_at')->where('expires_at', '>', now())->update(['consumed_at' => now()]);
            abort_unless($consumed === 1, 401, 'Login has already been used.');
            $identity = LtiIdentity::updateOrCreate(['lti_platform_id' => $platform->id, 'subject' => $claims['sub']], [
                'name' => is_string($claims['name'] ?? null) ? Str::limit($claims['name'], 250, '') : null,
                'email' => is_string($claims['email'] ?? null) && filter_var($claims['email'], FILTER_VALIDATE_EMAIL) ? $claims['email'] : null,
            ]);
            $membership = ['tenant_id' => $deployment->tenant_id, 'lti_identity_id' => $identity->id];
            DB::table('tenant_identity')->insertOrIgnore($membership + [
                'roles' => json_encode($roles), 'first_access_at' => now(), 'last_access_at' => now(),
            ]);
            DB::table('tenant_identity')->where($membership)->whereNull('first_access_at')->update(['first_access_at' => now()]);
            DB::table('tenant_identity')->where($membership)->update(['roles' => json_encode($roles), 'last_access_at' => now()]);
            if ($type === 'LtiResourceLinkRequest') {
                $link = LtiResourceLink::where('lti_deployment_id', $deployment->id)->where('resource_link_id', $resourceId)->first();
                if ($link) {
                    $content = H5pContent::where('tenant_id', $deployment->tenant_id)->findOrFail($link->h5p_content_id);
                    abort_if($contentUuid !== null && $contentUuid !== $content->uuid, 403, 'Resource content cannot be rebound.');
                } else {
                    abort_unless($contentUuid !== null, 404, 'Resource has no content binding.');
                    $content = H5pContent::where('tenant_id', $deployment->tenant_id)->where('uuid', $contentUuid)->firstOrFail();
                    abort_unless($content->status === 'published', 403, 'Content is not published.');
                    LtiResourceLink::create(['lti_deployment_id' => $deployment->id, 'resource_link_id' => $resourceId,
                        'context_id' => $contextId, 'h5p_content_id' => $content->id]);
                }
                abort_unless(! $content->admin_hidden && ($content->status === 'published' || ($canAuthor && $content->lti_identity_id === $identity->id)), 403);
                $contentUuid = $content->uuid;
            }

            return LtiLaunch::create([
                'id' => (string) Str::uuid(), 'lti_deployment_id' => $deployment->id,
                'lti_identity_id' => $identity->id, 'message_type' => $type, 'roles' => $roles,
                'can_author' => $canAuthor, 'context_id' => $contextId, 'resource_link_id' => $resourceId,
                'content_uuid' => $contentUuid, 'deep_linking_settings' => $settings,
                'ags_lineitem_url' => $agsLineitemUrl,
                'expires_at' => now()->addSeconds(config('lti.launch_lifetime')),
            ]);
        });
    }
}
