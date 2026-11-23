<?php

declare(strict_types=1);

use Madbox99\FilamentAuditTrail\Support\PropertySanitizer;

it('strips sensitive keys recursively', function (): void {
    $result = PropertySanitizer::sanitize([
        'password' => 'x',
        'name' => 'a',
        'nested' => ['api_key' => 'k', 'ok' => 1],
        'two_factor_secret' => 's',
        'access_token' => 't',
    ]);

    expect($result)->toBe(['name' => 'a', 'nested' => ['ok' => 1]]);
});

it('strips extra hidden keys case-insensitively', function (): void {
    $result = PropertySanitizer::sanitize(['Pin' => '1', 'name' => 'a'], ['pin']);

    expect($result)->toBe(['name' => 'a']);
});

it('replaces oversized properties with a truncated marker', function (): void {
    $result = PropertySanitizer::sanitize(['body' => str_repeat('a', 20000)]);

    expect($result)->toBe(['_truncated' => true]);
});
