<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;

final class AuditEventExporter extends Exporter
{
    protected static ?string $model = AuditEvent::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('occurred_at'),
            ExportColumn::make('event')->state(fn (AuditEvent $record): string => $record->event->value),
            ExportColumn::make('user_email'),
            ExportColumn::make('team_id'),
            ExportColumn::make('subject_type'),
            ExportColumn::make('subject_id'),
            ExportColumn::make('action'),
            ExportColumn::make('url'),
            ExportColumn::make('ip'),
            ExportColumn::make('source')->state(fn (AuditEvent $record): string => $record->source->value),
            ExportColumn::make('inferred')->state(fn (AuditEvent $record): string => $record->inferred ? '1' : '0'),
            ExportColumn::make('evidence_key'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return __('audit-trail::audit-trail.export.completed', [
            'count' => number_format($export->successful_rows),
        ]);
    }
}
