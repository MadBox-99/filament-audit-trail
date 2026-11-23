<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\Pages;

use Filament\Resources\Pages\ViewRecord;
use Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\AuditEventResource;

final class ViewAuditEvent extends ViewRecord
{
    protected static string $resource = AuditEventResource::class;
}
