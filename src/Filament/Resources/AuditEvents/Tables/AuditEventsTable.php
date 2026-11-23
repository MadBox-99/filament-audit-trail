<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\Tables;

use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Enums\AuditSource;
use Madbox99\FilamentAuditTrail\Filament\Exports\AuditEventExporter;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Support\AuditVisibility;
use Throwable;

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
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::scopedEvents()
                        ->whereNotNull('user_email')
                        ->where('user_email', 'like', '%'.$search.'%')
                        ->distinct()
                        ->orderBy('user_email')
                        ->limit(50)
                        ->pluck('user_email', 'user_email')
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) ? $value : null)
                    ->options(fn (): array => self::scopedEvents()
                        ->whereNotNull('user_email')
                        ->distinct()
                        ->orderBy('user_email')
                        ->limit(50)
                        ->pluck('user_email', 'user_email')
                        ->all()),
                SelectFilter::make('team_id')
                    ->label(__('audit-trail::audit-trail.columns.team'))
                    ->options(fn (): array => self::teamOptions())
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
                ExportAction::make()
                    ->exporter(AuditEventExporter::class)
                    ->visible(fn (): bool => self::exportsTableExists()),
            ]);
    }

    /**
     * @return Builder<AuditEvent>
     */
    private static function scopedEvents(): Builder
    {
        $user = auth()->user();
        $query = AuditEvent::query();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        /** @var Builder<AuditEvent> */
        return AuditVisibility::scope($query, $user, Filament::getTenant());
    }

    /**
     * Filament's export needs its `exports` table; without it the action would only fail.
     */
    private static function exportsTableExists(): bool
    {
        return once(function (): bool {
            try {
                $export = new Export;

                return $export->getConnection()->getSchemaBuilder()->hasTable($export->getTable());
            } catch (Throwable) {
                return false;
            }
        });
    }

    /**
     * @return array<int|string, string>
     */
    private static function teamOptions(): array
    {
        /** @var array<int, int|string> $ids */
        $ids = self::scopedEvents()
            ->whereNotNull('team_id')
            ->distinct()
            ->orderBy('team_id')
            ->pluck('team_id')
            ->all();

        $options = array_combine($ids, array_map(strval(...), $ids));

        try {
            /** @var string $teams */
            $teams = config('audit-trail.reconstruct.teams_table', 'teams');

            foreach (DB::table($teams)->whereIn('id', $ids)->pluck('name', 'id') as $id => $name) {
                if (is_string($name) && $name !== '') {
                    $options[$id] = $name;
                }
            }
        } catch (Throwable) {
            // No teams table or no name column: fall back to ids.
        }

        return $options;
    }
}
