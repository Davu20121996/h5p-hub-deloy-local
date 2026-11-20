<?php

namespace App\Lti;

class Claims
{
    public const PREFIX = 'https://purl.imsglobal.org/spec/lti/claim/';

    public const DEEP = 'https://purl.imsglobal.org/spec/lti-dl/claim/';

    public static function get(array $claims, string $name, mixed $default = null): mixed
    {
        return $claims[self::PREFIX.$name] ?? $default;
    }

    public static function canAuthor(array $roles): bool
    {
        return count(array_intersect($roles, [
            'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor',
            'http://purl.imsglobal.org/vocab/lis/v2/membership#ContentDeveloper',
        ])) > 0;
    }

    public static function validUrl(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ($parts['scheme'] === 'https' || (config('lti.allow_http') && app()->environment('local', 'testing') && $parts['scheme'] === 'http'));
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? null;
        $suffix = $port && ! (($parts['scheme'] === 'https' && $port === 443) || ($parts['scheme'] === 'http' && $port === 80)) ? ':'.$port : '';

        return strtolower($parts['scheme'].'://'.$parts['host']).$suffix;
    }
}
