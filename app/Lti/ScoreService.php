<?php

namespace App\Lti;

use App\Models\LtiLaunch;
use App\Models\LtiResourceLink;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ScoreService
{
    public const ENDPOINT = 'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint';

    public const SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';

    public function __construct(private KeyStore $keys) {}

    public static function isPlatformUrl(string $url, string $issuer): bool
    {
        return Claims::validUrl($url) && Claims::origin($url) === Claims::origin($issuer)
            && ! str_contains($url, '#') && parse_url($url, PHP_URL_QUERY) === null;
    }

    public function submission(LtiLaunch $launch): ?object
    {
        return DB::table('lti_submissions')->where($this->submissionKey($launch))->first();
    }

    /** @param  array{timestamp: string, score?: float|int|null, maximum?: float|int|null}|null  $result */
    public function submit(LtiLaunch $launch, ?array $result): bool
    {
        $key = $this->submissionKey($launch);
        if ($result !== null) {
            DB::table('lti_submissions')->insertOrIgnore($key + [
                'pending_result' => Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR)),
            ]);
        }

        $lock = 'lti:score-delivery:'.$key['lti_resource_link_id'].':'.$key['lti_identity_id'];

        return Cache::lock($lock, 30)->get(function () use ($key, $launch): bool {
            $submission = DB::table('lti_submissions')->where($key);
            $saved = $submission->first();
            abort_unless($saved, 404);

            if ($saved->delivered_at === null) {
                $result = json_decode(Crypt::decryptString($saved->pending_result), true, flags: JSON_THROW_ON_ERROR);
                $this->send($launch, $result);
                $submission->update(['pending_result' => null, 'delivered_at' => now()]);
            }

            return true;
        });
    }

    /** @return array{lti_resource_link_id: int, lti_identity_id: int} */
    private function submissionKey(LtiLaunch $launch): array
    {
        $resource = LtiResourceLink::where('lti_deployment_id', $launch->lti_deployment_id)
            ->where('resource_link_id', $launch->resource_link_id)->firstOrFail();

        return ['lti_resource_link_id' => $resource->id, 'lti_identity_id' => $launch->lti_identity_id];
    }

    /** @param  array{timestamp: string, score?: float|int|null, maximum?: float|int|null}  $result */
    private function send(LtiLaunch $launch, array $result): void
    {
        $platform = $launch->deployment->platform;
        $tokenUrl = $platform->ags_token_url;
        $lineItem = $launch->ags_lineitem_url;
        abort_unless($tokenUrl && $lineItem && self::isPlatformUrl($tokenUrl, $platform->issuer)
            && self::isPlatformUrl($lineItem, $platform->issuer), 403);

        $assertion = $this->keys->sign([
            'iss' => $platform->client_id, 'sub' => $platform->client_id, 'aud' => $tokenUrl,
            'iat' => time(), 'exp' => time() + 120, 'jti' => (string) Str::uuid(),
            Claims::PREFIX.'deployment_id' => $launch->deployment->deployment_id,
        ]);
        $http = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false]);

        $score = [
            'userId' => $launch->identity->subject, 'timestamp' => $result['timestamp'],
            'activityProgress' => 'Completed', 'gradingProgress' => 'NotReady',
        ];
        if (isset($result['score'], $result['maximum'])) {
            $score = array_replace($score, [
                'scoreGiven' => (float) $result['score'], 'scoreMaximum' => (float) $result['maximum'],
                'gradingProgress' => 'FullyGraded',
            ]);
        }

        try {
            $response = $http->asForm()->acceptJson()->post($tokenUrl, [
                'grant_type' => 'client_credentials', 'scope' => self::SCOPE,
                'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'client_assertion' => $assertion,
            ]);
            $token = $response->json('access_token');
            abort_unless($response->successful() && is_string($token) && $token !== '', 502, 'Client token service unavailable.');

            $response = $http->withToken($token)->asJson()
                ->contentType('application/vnd.ims.lis.v1.score+json')->post(rtrim($lineItem, '/').'/scores', $score);
            abort_unless($response->successful(), 502, 'Client score service rejected the result.');
        } catch (ConnectionException) {
            abort(502, 'Client score service unavailable.');
        }
    }
}
