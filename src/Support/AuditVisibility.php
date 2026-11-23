<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Support;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Madbox99\FilamentAuditTrail\FilamentAuditTrailPlugin;

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

        // Fail closed: outside a panel with the plugin nobody is an admin.
        return false;
    }

    /**
     * Team-less rows a tenant member may see: only sign-in events, which belong to no team.
     * Any other team-less row (e.g. a change whose team could not be determined) may concern
     * another team of a multi-team user, so it is visible to its own user and admins only.
     */
    public const array TEAMLESS_VISIBLE_EVENTS = ['login', 'login_inferred', 'logout', 'login_failed'];

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

        $members = self::memberIds($tenant, $query->getModel()->getConnection()->getName());

        return $query->where(function (Builder $q) use ($tenant, $members): void {
            $q->where('team_id', $tenant->getKey())
                ->orWhere(function (Builder $nullTeam) use ($members): void {
                    $nullTeam->whereNull('team_id')
                        ->whereIn('event', self::TEAMLESS_VISIBLE_EVENTS)
                        ->whereIn('user_id', $members);
                });
        });
    }

    /**
     * The membership pivot: [table, team column, user column].
     *
     * @return array{string, string, string}
     */
    public static function pivot(): array
    {
        $table = config('audit-trail.visibility.team_user_table') ?? config('audit-trail.reconstruct.team_user_table', 'team_user');

        return [
            (string) $table,
            (string) config('audit-trail.visibility.team_foreign_key', 'team_id'),
            (string) config('audit-trail.visibility.user_foreign_key', 'user_id'),
        ];
    }

    /**
     * The tenant's current members, read on the user model's connection: a subquery when the
     * audit table shares that connection, otherwise the ids themselves.
     *
     * @return QueryBuilder|list<mixed>
     */
    private static function memberIds(Model $tenant, ?string $auditConnection): QueryBuilder|array
    {
        [$table, $teamKey, $userKey] = self::pivot();

        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $connection = (new $userModel)->getConnection();

        $members = $connection->table($table)->where($teamKey, $tenant->getKey())->select($userKey);

        if ($connection->getName() === $auditConnection) {
            return $members;
        }

        return array_values($members->pluck($userKey)->all());
    }
}
