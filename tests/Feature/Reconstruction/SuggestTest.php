<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Reconstruction\SchemaInspector;

beforeEach(function (): void {
    // Shaped like a candidate on purpose: only the exclusion list keeps it out.
    Schema::create('sessions', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->nullable();
        $table->foreignId('team_id')->nullable();
        $table->timestamps();
    });
});

it('finds tables with an actor column and tables with a team', function (): void {
    $inspector = app(SchemaInspector::class);

    expect($inspector->actorColumnCandidates())->toBe(['posts' => 'user_id'])
        ->and($inspector->teamTableCandidates())->toBe(['posts']);
});

it('honours models.exclude', function (): void {
    config(['audit-trail.models.exclude' => ['po*']]);

    expect(app(SchemaInspector::class)->actorColumnCandidates())->toBe([]);
});

it('prints the candidates with row counts and a config snippet, writing nothing', function (): void {
    $user = insertUser();
    $team = insertTeam();
    insertPost($team, $user, '2026-01-02 10:00:00');
    insertPost($team, null, '2026-01-02 11:00:00');

    $this->artisan('audit-trail:reconstruct', ['--suggest' => true])
        ->expectsTable(['Table', 'Column', 'Rows'], [['posts', 'user_id', 2]])
        ->expectsTable(['Table', 'Rows'], [['posts', 2]])
        ->expectsOutputToContain("'posts' => 'user_id',")
        ->doesntExpectOutputToContain('sessions')
        ->assertExitCode(0);

    expect(AuditEvent::query()->count())->toBe(0);
});

it('never suggests the spatie permission tables', function (): void {
    foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $name) {
        Schema::create($name, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('team_id')->nullable();
            $table->timestamps();
        });
    }

    $inspector = app(SchemaInspector::class);

    expect($inspector->actorColumnCandidates())->toBe(['posts' => 'user_id'])
        ->and($inspector->teamTableCandidates())->toBe(['posts']);
});
