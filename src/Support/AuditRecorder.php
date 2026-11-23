<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Support;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Enums\AuditSource;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Throwable;

final class AuditRecorder
{
    private int $suspended = 0;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(AuditEventType $type, array $attributes = []): ?AuditEvent
    {
        try {
            if ($this->suspended > 0 || ! config('audit-trail.enabled') || ! config('audit-trail.events.'.$type->value)) {
                return null;
            }

            $attributes = [...$this->context(), ...$attributes];
            $attributes['event'] = $type;
            $attributes['source'] ??= AuditSource::Live;

            if (isset($attributes['properties']) && is_array($attributes['properties'])) {
                $attributes['properties'] = PropertySanitizer::sanitize($attributes['properties']);
            }

            if (isset($attributes['url']) && is_string($attributes['url'])) {
                $attributes['url'] = PropertySanitizer::redactUrl($attributes['url']);
            }

            return AuditEvent::query()->create($attributes);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordReconstructed(array $attributes): bool
    {
        try {
            $attributes['source'] = AuditSource::Reconstructed->value;
            $attributes['created_at'] = now()->format('Y-m-d H:i:s.v');

            if (($attributes['event'] ?? null) instanceof AuditEventType) {
                $attributes['event'] = $attributes['event']->value;
            }

            foreach (['properties', 'evidence'] as $key) {
                if (array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                    $attributes[$key] = json_encode($attributes[$key]);
                }
            }

            $model = new AuditEvent;

            return DB::connection($model->getConnectionName())
                ->table($model->getTable())
                ->insertOrIgnore($attributes) === 1;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    public function withoutRecording(callable $callback): mixed
    {
        $this->suspended++;

        try {
            return $callback();
        } finally {
            $this->suspended--;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        $request = request();
        $user = $this->safe(fn () => auth()->guard((string) config('audit-trail.guard', 'web'))->user());
        $sessionId = $this->safe(fn () => $request->hasSession() ? $request->session()->getId() : null);
        $email = is_object($user) ? ($user->email ?? null) : null;

        return [
            'occurred_at' => now()->format('Y-m-d H:i:s.v'),
            'user_id' => $user instanceof Authenticatable ? $user->getAuthIdentifier() : null,
            'user_email' => is_string($email) ? $email : null,
            'team_id' => $this->safe(fn () => Filament::getTenant()?->getKey()),
            'panel' => $this->safe(fn () => Filament::getCurrentPanel()?->getId()),
            'url' => $request->fullUrl(),
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
            'session_hash' => is_string($sessionId) && $sessionId !== '' ? hash('sha256', $sessionId) : null,
            'source' => AuditSource::Live,
        ];
    }

    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
