<?php

namespace App\Lti;

use App\Models\LtiPlatform;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PlatformKeys
{
    public function key(LtiPlatform $platform, string $kid): Key
    {
        $cacheKey = 'lti:jwks:'.$platform->id;
        $jwks = Cache::remember($cacheKey, 300, fn () => $this->fetch($platform));
        $key = $this->find($jwks, $kid);
        if ($key === null) {
            $jwks = $this->fetch($platform);
            Cache::put($cacheKey, $jwks, 300);
            $key = $this->find($jwks, $kid);
        }
        abort_unless($key !== null, 401, 'Unknown platform signing key.');

        return $key;
    }

    private function fetch(LtiPlatform $platform): array
    {
        abort_unless(Claims::validUrl($platform->jwks_url), 401, 'Invalid JWKS URL.');
        $response = Http::acceptJson()->connectTimeout(5)->timeout(30)
            ->withOptions(['allow_redirects' => false])->get($platform->jwks_url);
        abort_unless($response->successful() && strlen($response->body()) <= 262144, 401, 'Platform JWKS unavailable.');
        $data = $response->json();
        abort_unless(is_array($data) && is_array($data['keys'] ?? null), 401, 'Invalid platform JWKS.');

        return $data;
    }

    private function find(array $jwks, string $kid): ?Key
    {
        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (($jwk['kid'] ?? null) === $kid && ($jwk['kty'] ?? null) === 'RSA'
                && ($jwk['alg'] ?? 'RS256') === 'RS256' && ($jwk['use'] ?? 'sig') === 'sig') {
                return JWK::parseKey($jwk, 'RS256');
            }
        }

        return null;
    }
}
