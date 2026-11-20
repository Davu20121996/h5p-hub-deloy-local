<?php

namespace App\Lti;

use App\Models\H5pAttempt;
use App\Models\LtiPlatform;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PublishScore implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public const SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';

    public const CLAIM = 'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint';

    public function __construct(public int $attemptId) {}

    public static function trustedUrl(mixed $url, LtiPlatform $platform): bool
    {
        return is_string($url) && strlen($url) <= 2048 && Claims::validUrl($url)
            && Claims::validUrl($platform->issuer) && parse_url($url, PHP_URL_FRAGMENT) === null
            && Claims::origin($url) === Claims::origin($platform->issuer);
    }

    public static function tokenUrl(LtiPlatform $platform): ?string
    {
        $url = $platform->ags_token_url;

        return self::trustedUrl($url, $platform) ? $url : null;
    }

    public function handle(KeyStore $keys): void
    {
        $attempt = H5pAttempt::with('launch.deployment.platform', 'launch.deployment.tenant', 'launch.identity')->find($this->attemptId);
        if (! $attempt || ! $attempt->completed || $attempt->score === null || $attempt->max_score <= 0) {
            return;
        }

        $launch = $attempt->launch;
        $deployment = $launch->deployment;
        $platform = $deployment->platform;
        $tokenUrl = self::tokenUrl($platform);
        if (! $platform->active || ! $deployment->active || ! $deployment->tenant->active
            || $tokenUrl === null || ! self::trustedUrl($launch->ags_lineitem_url, $platform)) {
            return;
        }

        $assertion = $keys->sign([
            'iss' => $platform->client_id,
            'sub' => $platform->client_id,
            'aud' => $tokenUrl,
            'iat' => now()->timestamp,
            'exp' => now()->addMinutes(2)->timestamp,
            'jti' => (string) Str::uuid(),
            Claims::PREFIX.'deployment_id' => $deployment->deployment_id,
        ]);
        $http = Http::withoutRedirecting()->connectTimeout(3)->timeout(10);
        $token = (clone $http)->asForm()->acceptJson()->post($tokenUrl, [
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
            'scope' => self::SCOPE,
        ]);
        $accessToken = $token->json('access_token');
        if (! $token->successful() || ! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('AGS token request failed (HTTP '.$token->status().').');
        }

        [$lineitem, $query] = array_pad(explode('?', $launch->ags_lineitem_url, 2), 2, null);
        $scoresUrl = rtrim($lineitem, '/').'/scores'.($query === null ? '' : '?'.$query);
        $response = $http->withToken($accessToken)->contentType('application/vnd.ims.lis.v1.score+json')->post($scoresUrl, [
            'userId' => $launch->identity->subject,
            'timestamp' => $attempt->created_at->toISOString(),
            'scoreGiven' => (float) $attempt->score,
            'scoreMaximum' => (float) $attempt->max_score,
            'activityProgress' => 'Completed',
            'gradingProgress' => 'FullyGraded',
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('AGS score request failed (HTTP '.$response->status().').');
        }
    }
}
