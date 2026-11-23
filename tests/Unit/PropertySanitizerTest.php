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

it('strips every password-like, private key and credential attribute by default', function (): void {
    $result = PropertySanitizer::sanitize([
        'smtp_password' => 'a',
        'password_confirmation' => 'b',
        'Password' => 'c',
        'ssh_private_key' => 'd',
        'aws_credentials' => 'e',
        'credential' => 'f',
        'name' => 'kept',
    ]);

    expect($result)->toBe(['name' => 'kept']);
});

it('redacts sensitive query parameters of a url', function (string $url, string $expected): void {
    expect(PropertySanitizer::redactUrl($url))->toBe($expected);
})->with([
    'none' => ['https://a.test/x', 'https://a.test/x'],
    'plain' => ['https://a.test/x?page=2&sort=name', 'https://a.test/x?page=2&sort=name'],
    'token' => ['https://a.test/x?token=abc&page=2', 'https://a.test/x?token=[redacted]&page=2'],
    'case' => ['https://a.test/x?Code=abc', 'https://a.test/x?Code=[redacted]'],
    'nested' => ['https://a.test/x?data%5Bpassword%5D=p', 'https://a.test/x?data%5Bpassword%5D=[redacted]'],
    'no value' => ['https://a.test/x?secret', 'https://a.test/x?secret'],
    'monkey' => ['https://a.test/x?monkey=1', 'https://a.test/x?monkey=1'],
]);
