<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Support;

final class PropertySanitizer
{
    /** Query parameter names whose value is never stored (case-insensitive). */
    private const array SENSITIVE_QUERY_NAMES = ['token', 'signature', 'code', 'password', 'secret', 'key'];

    /**
     * Replaces the value of sensitive query parameters with `[redacted]`: a parameter (or a
     * nested key of it) named token, signature, code, password, secret or key, or whose name
     * contains token, password or secret, or ends in `_key` (case-insensitive).
     */
    public static function redactUrl(string $url): string
    {
        $queryStart = strpos($url, '?');

        if ($queryStart === false) {
            return $url;
        }

        $fragmentStart = strpos($url, '#', $queryStart);
        $query = $fragmentStart === false ? substr($url, $queryStart + 1) : substr($url, $queryStart + 1, $fragmentStart - $queryStart - 1);
        $fragment = $fragmentStart === false ? '' : substr($url, $fragmentStart);

        $pairs = array_map(static function (string $pair): string {
            $equals = strpos($pair, '=');

            if ($equals === false) {
                return $pair;
            }

            $name = substr($pair, 0, $equals);

            return self::isSensitiveQueryName(urldecode($name)) ? $name.'=[redacted]' : $pair;
        }, explode('&', $query));

        return substr($url, 0, $queryStart + 1).implode('&', $pairs).$fragment;
    }

    private static function isSensitiveQueryName(string $name): bool
    {
        $segments = array_filter(preg_split('/[\[\]]+/', mb_strtolower($name)) ?: [], static fn (string $s): bool => $s !== '');

        foreach ($segments as $segment) {
            if (in_array($segment, self::SENSITIVE_QUERY_NAMES, true)
                || str_contains($segment, 'token')
                || str_contains($segment, 'password')
                || str_contains($segment, 'secret')
                || str_ends_with($segment, '_key')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $extraHidden
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $data, array $extraHidden = []): array
    {
        /** @var list<string> $configured */
        $configured = (array) config('audit-trail.redact', []);

        $patterns = array_map(
            static fn (string $pattern): string => mb_strtolower($pattern),
            [...$configured, ...$extraHidden],
        );

        $clean = self::strip($data, $patterns);

        $max = (int) config('audit-trail.max_properties_bytes', 16384);
        $json = json_encode($clean, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json === false || strlen($json) > $max) {
            return ['_truncated' => true];
        }

        return $clean;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $patterns
     * @return array<array-key, mixed>
     */
    private static function strip(array $data, array $patterns): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::matches($key, $patterns)) {
                continue;
            }

            $result[$key] = is_array($value) ? self::strip($value, $patterns) : $value;
        }

        return $result;
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function matches(string $key, array $patterns): bool
    {
        $key = mb_strtolower($key);

        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $key)) {
                return true;
            }
        }

        return false;
    }
}
