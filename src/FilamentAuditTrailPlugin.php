<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Madbox99\FilamentAuditTrail\Http\Middleware\RecordPageView;

final class FilamentAuditTrailPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'filament-audit-trail';
    }

    public function register(Panel $panel): void
    {
        $panel->middleware([RecordPageView::class]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
