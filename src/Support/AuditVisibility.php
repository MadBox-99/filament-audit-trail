<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Support;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Madbox99\FilamentAuditTrail\FilamentAuditTrailPlugin;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;

final class AuditVisibility
{
    public static function isAdmin(?Authenticatable $user): bool
    {
        if (! $user instanceof Authenticatable) {
            return false;
        }

        $panel = Filament::getCurrentPanel();

        if ($panel !== null && $panel->hasPlugin('filament-audit-trail')) {
            $plugin = $panel->getPlugin('filament-audit-trail');

            if ($plugin instanceof FilamentAuditTrailPlugin) {
                return $plugin->isAdmin($user);
            }
        }

        return FilamentAuditTrailPlugin::defaultAdminCheck($user);
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public static function scope(Builder $query, Authenticatable $user, ?Model $tenant): Builder
    {
        if (self::isAdmin($user)) {
            return $query;
        }

        if (! $tenant instanceof Model) {
            return $query->where('user_id', $user->getAuthIdentifier());
        }

        /** @var string $pivot */
        $pivot = config('audit-trail.reconstruct.team_user_table', 'team_user');

        return $query->where(function (Builder $q) use ($tenant, $pivot): void {
            $q->where('team_id', $tenant->getKey())
                ->orWhere(function (Builder $nullTeam) use ($tenant, $pivot): void {
                    $nullTeam->whereNull('team_id')
                        ->whereIn('user_id', AuditEvent::query()->getConnection()->table($pivot)->where('team_id', $tenant->getKey())->select('user_id'));
                });
        });
    }
}
