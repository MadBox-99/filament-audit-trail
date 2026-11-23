<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\Tables;

use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Enums\AuditSource;
use Madbox99\FilamentAuditTrail\Filament\Exports\AuditEventExporter;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Support\AuditVisibility;

final class AuditEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('audit-trail::audit-trail.columns.occurred_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('user_email')
                    ->label(__('audit-trail::audit-trail.columns.user'))
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('event')
                    ->label(__('audit-trail::audit-trail.columns.event'))
                    ->badge(),
                TextColumn::make('subject')
                    ->label(__('audit-trail::audit-trail.columns.subject'))
                    ->state(fn (AuditEvent $record): ?string => $record->subject_type === null
                        ? null
                        : class_basename($record->subject_type).'#'.$record->subject_id)
                    ->placeholder('-'),
                TextColumn::make('action')
                    ->label(__('audit-trail::audit-trail.columns.action'))
                    ->state(fn (AuditEvent $record): ?string => $record->action ?? $record->route)
                    ->placeholder('-'),
                TextColumn::make('ip')
                    ->label(__('audit-trail::audit-trail.columns.ip'))
                    ->placeholder('-'),
                TextColumn::make('source')
                    ->label(__('audit-trail::audit-trail.columns.source'))
                    ->badge()
                    ->description(fn (AuditEvent $record): ?string => $record->inferred
                        ? __('audit-trail::audit-trail.inferred')
                        : null),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('audit-trail::audit-trail.columns.event'))
                    ->options(AuditEventType::class),
                SelectFilter::make('source')
                    ->label(__('audit-trail::audit-trail.columns.source'))
                    ->options(AuditSource::class),
                SelectFilter::make('user_email')
                    ->label(__('audit-trail::audit-trail.columns.user'))
                    ->options(fn (): array => AuditEvent::query()
                        ->select('user_email')
                        ->whereNotNull('user_email')
                        ->distinct()
                        ->orderBy('user_email')
                        ->limit(200)
                        ->pluck('user_email', 'user_email')
                        ->all())
                    ->searchable(),
                SelectFilter::make('team_id')
                    ->label(__('audit-trail::audit-trail.columns.team'))
                    ->options(fn (): array => AuditEvent::query()
                        ->select('team_id')
                        ->whereNotNull('team_id')
                        ->distinct()
                        ->orderBy('team_id')
                        ->pluck('team_id', 'team_id')
                        ->all())
                    ->visible(fn (): bool => AuditVisibility::isAdmin(auth()->user())),
                Filter::make('occurred_between')
                    ->label(__('audit-trail::audit-trail.filters.date_range'))
                    ->schema([
                        DatePicker::make('from')->label(__('audit-trail::audit-trail.filters.from')),
                        DatePicker::make('until')->label(__('audit-trail::audit-trail.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('occurred_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('occurred_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->headerActions([
                ExportAction::make()->exporter(AuditEventExporter::class),
            ]);
    }
}
