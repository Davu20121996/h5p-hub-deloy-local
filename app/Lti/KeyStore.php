<?php

namespace App\Lti;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class KeyStore
{
    public function generate(): string
    {
        $directory = config('lti.key_directory');
        File::ensureDirectoryExists($directory, 0700);
        $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
        if ($resource === false || ! openssl_pkey_export($resource, $private)) {
            throw new RuntimeException('Cannot generate RSA key: '.openssl_error_string());
        }
        $details = openssl_pkey_get_details($resource);
        $kid = (string) Str::uuid();
        File::put("$directory/$kid.pem", $private);
        chmod("$directory/$kid.pem", 0600);
        File::put("$directory/$kid.json", json_encode([
            'kid' => $kid, 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ], JSON_THROW_ON_ERROR));
        File::put("$directory/active", $kid);

        return $kid;
    }

    public function jwks(): array
    {
        return ['keys' => array_map(fn ($file) => json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR),
            File::glob(config('lti.key_directory').'/*.json'))];
    }

    public function publicKey(): ?string
    {
        $directory = config('lti.key_directory');
        if (! File::exists("$directory/active")) {
            return null;
        }
        $kid = trim(File::get("$directory/active"));
        foreach ($this->jwks()['keys'] as $key) {
            if ($key['kid'] === $kid) {
                $details = openssl_pkey_get_details(JWK::parseKey($key)->getKeyMaterial());

                return $details['key'];
            }
        }

        return null;
    }

    public function sign(array $payload): string
    {
        $directory = config('lti.key_directory');
        if (! File::exists("$directory/active")) {
            throw new RuntimeException('Run php artisan lti:keys first.');
        }
        $kid = trim(File::get("$directory/active"));

        return JWT::encode($payload, File::get("$directory/$kid.pem"), 'RS256', $kid);
    }
}
