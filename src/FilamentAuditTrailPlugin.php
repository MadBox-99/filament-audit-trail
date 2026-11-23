<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\AuditEventResource;
use Madbox99\FilamentAuditTrail\Http\Middleware\RecordPageView;

final class FilamentAuditTrailPlugin implements Plugin
{
    private ?Closure $adminUsing = null;

    private ?string $navigationGroup = null;

    public static function make(): self
    {
        return new self;
    }

    public static function defaultAdminCheck(Authenticatable $user): bool
    {
        return method_exists($user, 'isAdmin') && $user->isAdmin();
    }

    public function getId(): string
    {
        return 'filament-audit-trail';
    }

    public function adminUsing(Closure $callback): self
    {
        $this->adminUsing = $callback;

        return $this;
    }

    public function navigationGroup(?string $group): self
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup;
    }

    public function isAdmin(Authenticatable $user): bool
    {
        if ($this->adminUsing instanceof Closure) {
            return (bool) ($this->adminUsing)($user);
        }

        return self::defaultAdminCheck($user);
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([AuditEventResource::class])
            ->middleware([RecordPageView::class]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
