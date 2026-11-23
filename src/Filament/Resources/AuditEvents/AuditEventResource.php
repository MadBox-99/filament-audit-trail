<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\Pages\ViewAuditEvent;
use Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\Schemas\AuditEventInfolist;
use Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\Tables\AuditEventsTable;
use Madbox99\FilamentAuditTrail\FilamentAuditTrailPlugin;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Support\AuditVisibility;

final class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getNavigationGroup(): ?string
    {
        $panel = Filament::getCurrentPanel();

        if ($panel !== null && $panel->hasPlugin('filament-audit-trail')) {
            $plugin = $panel->getPlugin('filament-audit-trail');

            if ($plugin instanceof FilamentAuditTrailPlugin) {
                return $plugin->getNavigationGroup();
            }
        }

        return null;
    }

    public static function getNavigationLabel(): string
    {
        return __('audit-trail::audit-trail.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('audit-trail::audit-trail.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('audit-trail::audit-trail.resource.plural_model_label');
    }

    public static function canViewAny(): bool
    {
        return auth()->user() !== null;
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return auth()->user() !== null ? Response::allow() : Response::deny();
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        // Visibility is enforced by getEloquentQuery() scoping; works with strictAuthorization().
        return auth()->user() !== null ? Response::allow() : Response::deny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * @return Builder<AuditEvent>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<AuditEvent> $query */
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        /** @var Builder<AuditEvent> */
        return AuditVisibility::scope($query, $user, Filament::getTenant());
    }

    public static function infolist(Schema $schema): Schema
    {
        return AuditEventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AuditEventsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEvents::route('/'),
            'view' => ViewAuditEvent::route('/{record}'),
        ];
    }
}
