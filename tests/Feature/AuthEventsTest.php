<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\User;

it('records a login with the user', function (): void {
    $user = User::create(['name' => 'A', 'email' => 'a@corp.test']);

    Auth::login($user);

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe(AuditEventType::Login)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->user_email)->toBe('a@corp.test');
});

it('records a logout with the user', function (): void {
    $user = User::create(['name' => 'A', 'email' => 'a@corp.test']);
    Auth::login($user);
    AuditEvent::query()->delete();

    Auth::logout();

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe(AuditEventType::Logout)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->user_email)->toBe('a@corp.test');
});

it('records a failed login without the password', function (): void {
    $user = User::create(['name' => 'A', 'email' => 'a@corp.test', 'password' => Hash::make('jo')]);

    expect(Auth::attempt(['email' => $user->email, 'password' => 'rossz']))->toBeFalse();

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe(AuditEventType::LoginFailed)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->user_email)->toBe('a@corp.test')
        ->and(json_encode($row->getAttributes()))->not->toContain('rossz');
});

it('records a failed login for an unknown user with null user id', function (): void {
    Auth::attempt(['email' => 'nobody@corp.test', 'password' => 'rossz']);

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe(AuditEventType::LoginFailed)
        ->and($row->user_id)->toBeNull()
        ->and($row->user_email)->toBe('nobody@corp.test');
});

it('ignores events of other guards', function (): void {
    config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);
    $user = User::create(['name' => 'A', 'email' => 'a@corp.test']);

    Auth::guard('api')->login($user);

    expect(AuditEvent::query()->count())->toBe(0);
});

it('truncates a typed email longer than the column', function (): void {
    Auth::attempt(['email' => str_repeat('é', 300).'@corp.test', 'password' => 'rossz']);

    expect(mb_strlen((string) AuditEvent::query()->sole()->user_email))->toBe(255);
});
