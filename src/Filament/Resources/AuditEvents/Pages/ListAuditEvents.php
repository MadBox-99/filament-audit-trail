<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\Pages;

use Filament\Resources\Pages\ListRecords;
use Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\AuditEventResource;

final class ListAuditEvents extends ListRecords
{
    protected static string $resource = AuditEventResource::class;
}
