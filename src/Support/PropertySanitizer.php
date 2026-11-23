<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Support;

final class PropertySanitizer
{
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
