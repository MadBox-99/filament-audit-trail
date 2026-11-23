<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Madbox99\FilamentAuditTrail\Filament\Exports\AuditEventExporter;
use Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\AuditEventResource;
use Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\Pages\ViewAuditEvent;
use Madbox99\FilamentAuditTrail\FilamentAuditTrailPlugin;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Support\AuditVisibility;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\Team;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\User;

function auditRow(array $attrs): AuditEvent
{
    $id = AuditEvent::query()->insertGetId($attrs + [
        'occurred_at' => now(), 'event' => 'login', 'source' => 'live', 'inferred' => false, 'created_at' => now(),
    ]);

    return AuditEvent::query()->findOrFail($id);
}

beforeEach(function (): void {
    $this->teamA = Team::create(['name' => 'A']);
    $this->teamB = Team::create(['name' => 'B']);
    $this->member = User::create(['name' => 'M', 'email' => 'm@corp.test']);
    $this->member->teams()->attach($this->teamA);
    $this->bMember = User::create(['name' => 'B', 'email' => 'b@corp.test']);
    $this->bMember->teams()->attach($this->teamB);
    $this->admin = User::create(['name' => 'Ad', 'email' => 'ad@corp.test', 'is_admin' => true]);
    $this->admin->teams()->attach([$this->teamA->id, $this->teamB->id]);

    $this->aEvent = auditRow(['team_id' => $this->teamA->id, 'user_id' => $this->member->id, 'user_email' => 'm@corp.test', 'event' => 'updated']);
    $this->bEvent = auditRow(['team_id' => $this->teamB->id, 'user_id' => $this->bMember->id, 'user_email' => 'b@corp.test', 'event' => 'updated']);
    $this->aLogin = auditRow(['team_id' => null, 'user_id' => $this->member->id, 'user_email' => 'm@corp.test', 'event' => 'login']);
    $this->bLogin = auditRow(['team_id' => null, 'user_id' => $this->bMember->id, 'user_email' => 'b@corp.test', 'event' => 'login']);
});

function listAs(User $user, Team $tenant)
{
    test()->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);

    return Livewire::test(ListAuditEvents::class);
}

it('shows every team to an admin', function (): void {
    listAs($this->admin, $this->teamA)
        ->assertCanSeeTableRecords([$this->aEvent, $this->bEvent, $this->aLogin, $this->bLogin]);
});

it('shows a non-admin the tenant events and NULL-team logins of tenant members only', function (): void {
    listAs($this->member, $this->teamA)
        ->assertCanSeeTableRecords([$this->aEvent, $this->aLogin])
        ->assertCanNotSeeTableRecords([$this->bEvent, $this->bLogin]);
});

it('returns 404 for another team event opened by URL', function (): void {
    $url = ViewAuditEvent::getUrl(['record' => $this->bEvent], panel: 'admin', tenant: $this->teamA);

    $this->actingAs($this->member)->get($url)->assertNotFound();
    $this->actingAs($this->member)->get(
        ViewAuditEvent::getUrl(['record' => $this->aEvent], panel: 'admin', tenant: $this->teamA)
    )->assertSuccessful();
});

it('is read-only', function (): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');

    expect(AuditEventResource::canCreate())->toBeFalse()
        ->and(AuditEventResource::canEdit($this->aEvent))->toBeFalse()
        ->and(AuditEventResource::canDelete($this->aEvent))->toBeFalse()
        ->and(AuditEventResource::getPages())->not->toHaveKeys(['create', 'edit']);

    listAs($this->admin, $this->teamA)->assertTableActionDoesNotExist('delete')->assertTableActionDoesNotExist('edit');
});

it('shows the team filter only to admins', function (): void {
    listAs($this->admin, $this->teamA)->assertTableFilterExists('team_id');
    listAs($this->member, $this->teamA)->assertTableFilterHidden('team_id');
});

it('limits a tenantless non-admin to own events', function (): void {
    $this->actingAs($this->member);
    Filament::setCurrentPanel('admin');

    $ids = AuditVisibility::scope(AuditEvent::query(), $this->member, null)->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([$this->aEvent->id, $this->aLogin->id]);
});

it('honours a custom adminUsing closure', function (): void {
    Filament::setCurrentPanel('admin');
    Filament::getCurrentPanel()->getPlugin('filament-audit-trail')->adminUsing(fn (User $u): bool => $u->email === 'm@corp.test');

    expect(AuditVisibility::isAdmin($this->member))->toBeTrue()
        ->and(AuditVisibility::isAdmin($this->admin))->toBeFalse();
});

it('labels login_inferred in hungarian', function (): void {
    app()->setLocale('hu');

    expect(auditRow(['event' => 'login_inferred', 'inferred' => true])->event->getLabel())->toBe('Belépés (levezetett)');
});

it('exports with the configured columns', function (): void {
    $columns = array_map(fn ($c) => $c->getName(), AuditEventExporter::getColumns());

    expect($columns)->toBe(['occurred_at', 'event', 'user_email', 'team_id', 'subject_type', 'subject_id', 'action', 'url', 'ip', 'source', 'inferred', 'evidence_key']);
});

it('does not leak other teams emails through the user filter', function (): void {
    $table = listAs($this->member, $this->teamA)->instance()->getTable();
    $filter = $table->getFilter('user_email');

    expect($filter->getFormField()->getOptions())->toHaveKey('m@corp.test')->not->toHaveKey('b@corp.test')
        ->and($filter->getFormField()->getSearchResults('corp'))->toHaveKey('m@corp.test')->not->toHaveKey('b@corp.test');

    $teamFilter = listAs($this->admin, $this->teamA)->instance()->getTable()->getFilter('team_id');
    expect($teamFilter->getFormField()->getOptions())->toBe([$this->teamA->id => 'A', $this->teamB->id => 'B']);
});

it('scopes the export query to the tenant for a non-admin', function (): void {
    $ids = listAs($this->member, $this->teamA)->instance()->getTableQueryForExport()->pluck('id')->all();

    expect($ids)->toContain($this->aEvent->id, $this->aLogin->id)
        ->not->toContain($this->bEvent->id)
        ->not->toContain($this->bLogin->id);
});

it('works under strict authorization', function (): void {
    $this->actingAs($this->member);
    Filament::setCurrentPanel('admin');

    expect(AuditEventResource::canViewAny())->toBeTrue()
        ->and(AuditEventResource::getViewAuthorizationResponse($this->aEvent)->allowed())->toBeTrue();
});

it('hides team-less non-login events of a multi-team user from the other teams', function (): void {
    $multi = User::create(['name' => 'Mu', 'email' => 'mu@corp.test']);
    $multi->teams()->attach([$this->teamA->id, $this->teamB->id]);
    $leak = auditRow(['team_id' => null, 'user_id' => $multi->id, 'user_email' => 'mu@corp.test', 'event' => 'updated', 'properties' => json_encode(['old' => ['title' => 'B secret']])]);
    $login = auditRow(['team_id' => null, 'user_id' => $multi->id, 'user_email' => 'mu@corp.test', 'event' => 'login']);

    listAs($this->member, $this->teamA)
        ->assertCanSeeTableRecords([$login])
        ->assertCanNotSeeTableRecords([$leak]);

    $this->actingAs($this->member)->get(
        ViewAuditEvent::getUrl(['record' => $leak], panel: 'admin', tenant: $this->teamA)
    )->assertNotFound();

    $ids = listAs($this->member, $this->teamA)->instance()->getTableQueryForExport()->pluck('id')->all();
    expect($ids)->toContain($login->id)->not->toContain($leak->id);
});

it('shows team-less login, inferred login, logout and failed login rows of tenant members', function (string $event): void {
    $row = auditRow(['team_id' => null, 'user_id' => $this->member->id, 'event' => $event]);

    listAs($this->member, $this->teamA)->assertCanSeeTableRecords([$row]);
})->with(['login', 'login_inferred', 'logout', 'login_failed']);

it('makes nobody admin without an adminUsing callback', function (): void {
    expect(FilamentAuditTrailPlugin::make()->isAdmin($this->admin))->toBeFalse();

    Filament::setCurrentPanel(null);
    expect(AuditVisibility::isAdmin($this->admin))->toBeFalse();
});

it('reads a custom membership pivot', function (): void {
    Schema::create('memberships', function (Blueprint $table): void {
        $table->foreignId('workspace_id');
        $table->foreignId('member_id');
    });
    DB::table('memberships')->insert(['workspace_id' => $this->teamA->id, 'member_id' => $this->member->id]);
    DB::table('team_user')->delete();
    config([
        'audit-trail.visibility.team_user_table' => 'memberships',
        'audit-trail.visibility.team_foreign_key' => 'workspace_id',
        'audit-trail.visibility.user_foreign_key' => 'member_id',
    ]);
    Filament::setCurrentPanel('admin');

    $ids = AuditVisibility::scope(AuditEvent::query(), $this->member, $this->teamA)->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([$this->aEvent->id, $this->aLogin->id]);
});

it('reads the membership pivot on the user connection when the audit table lives elsewhere', function (): void {
    config(['database.connections.audit' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    config(['audit-trail.connection' => 'audit']);
    DB::setDefaultConnection('audit'); // as the migrator does for a migration's getConnection()
    (require __DIR__.'/../../database/migrations/2026_11_23_000000_create_audit_events_table.php')->up();
    DB::setDefaultConnection('testing');
    $login = auditRow(['team_id' => null, 'user_id' => $this->member->id, 'event' => 'login']);
    $other = auditRow(['team_id' => null, 'user_id' => $this->bMember->id, 'event' => 'login']);
    Filament::setCurrentPanel('admin');

    $ids = AuditVisibility::scope(AuditEvent::query(), $this->member, $this->teamA)->pluck('id')->all();

    expect($ids)->toContain($login->id)->not->toContain($other->id);
});

it('falls back to the reconstruct pivot for visibility', function (): void {
    expect(AuditVisibility::pivot())->toBe(['team_user', 'team_id', 'user_id']);

    config(['audit-trail.reconstruct.team_user_table' => 'members']);
    expect(AuditVisibility::pivot())->toBe(['members', 'team_id', 'user_id']);
});

it('shows the export action with an exports table', function (): void {
    listAs($this->admin, $this->teamA)->assertActionVisible(TestAction::make('export')->table());
});

it('hides the export action without an exports table', function (): void {
    Schema::drop('exports');

    listAs($this->admin, $this->teamA)->assertActionHidden(TestAction::make('export')->table());
});

it('orders by occurred_at with millisecond precision, then by id, newest first', function (): void {
    $later = auditRow(['occurred_at' => '2026-01-01 10:00:00.200', 'user_id' => $this->admin->id]);
    $earlier = auditRow(['occurred_at' => '2026-01-01 10:00:00.100', 'user_id' => $this->admin->id]);
    $tieFirst = auditRow(['occurred_at' => '2026-01-01 09:00:00.000', 'user_id' => $this->admin->id]);
    $tieSecond = auditRow(['occurred_at' => '2026-01-01 09:00:00.000', 'user_id' => $this->admin->id]);

    listAs($this->admin, $this->teamA)->assertCanSeeTableRecords([$later, $earlier, $tieSecond, $tieFirst], inOrder: true);
});
