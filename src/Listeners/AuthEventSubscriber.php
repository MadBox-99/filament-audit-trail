<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Events\Dispatcher;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Support\AuditRecorder;

final readonly class AuthEventSubscriber
{
    public function __construct(private AuditRecorder $recorder) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, $this->handleLogin(...));
        $events->listen(Logout::class, $this->handleLogout(...));
        $events->listen(Failed::class, $this->handleFailed(...));
    }

    public function handleLogin(Login $event): void
    {
        if ($this->isForeignGuard($event->guard)) {
            return;
        }

        $this->recorder->record(AuditEventType::Login, $this->userAttributes($event->user));
    }

    public function handleLogout(Logout $event): void
    {
        if ($this->isForeignGuard($event->guard)) {
            return;
        }

        $this->recorder->record(AuditEventType::Logout, $this->userAttributes($event->user));
    }

    public function handleFailed(Failed $event): void
    {
        if ($this->isForeignGuard($event->guard)) {
            return;
        }

        $email = $event->credentials['email'] ?? null;

        $this->recorder->record(AuditEventType::LoginFailed, [
            'user_id' => $event->user?->getAuthIdentifier(),
            'user_email' => is_string($email) ? mb_substr($email, 0, 255) : null,
        ]);
    }

    private function isForeignGuard(string $guard): bool
    {
        return $guard !== config('audit-trail.guard');
    }

    /**
     * @return array{user_id: mixed, user_email: string|null}
     */
    private function userAttributes(?Authenticatable $user): array
    {
        $email = is_object($user) ? ($user->email ?? null) : null;

        return [
            'user_id' => $user?->getAuthIdentifier(),
            'user_email' => is_string($email) ? $email : null,
        ];
    }
}
