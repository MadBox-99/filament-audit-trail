<?php

declare(strict_types=1);

use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Models\Import;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\Post;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->user = User::create(['name' => 'A', 'email' => 'a@corp.test']);
    $this->actingAs($this->user);
});

function makePost(): Post
{
    return Post::create(['team_id' => 1, 'title' => 'a', 'secret_token' => 's']);
}

it('records a created model without sensitive attributes', function (): void {
    $post = makePost();

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe(AuditEventType::Created)
        ->and($row->subject_type)->toBe(Post::class)
        ->and($row->subject_id)->toBe((string) $post->id)
        ->and($row->properties['new']['title'])->toBe('a')
        ->and($row->properties['new'])->not->toHaveKey('secret_token')
        ->and($row->user_id)->toBe($this->user->id);
});

it('records only changed attributes on update', function (): void {
    $post = makePost();
    AuditEvent::query()->delete();

    $post->update(['title' => 'b']);

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe(AuditEventType::Updated)
        ->and($row->properties)->toBe(['old' => ['title' => 'a'], 'new' => ['title' => 'b']]);
});

it('records nothing when only updated_at changes', function (): void {
    $post = makePost();
    AuditEvent::query()->delete();

    $this->travel(5)->minutes();
    $post->touch();

    expect(AuditEvent::query()->count())->toBe(0);
});

it('records a deleted model', function (): void {
    $post = makePost();
    AuditEvent::query()->delete();

    $post->delete();

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe(AuditEventType::Deleted)
        ->and($row->subject_type)->toBe(Post::class)
        ->and($row->subject_id)->toBe((string) $post->id);
});

it('records nothing without an authenticated user', function (): void {
    auth()->guard('web')->logout();
    AuditEvent::query()->delete();

    makePost();

    expect(AuditEvent::query()->count())->toBe(0);
});

it('does not recurse on AuditEvent creation', function (): void {
    AuditEvent::query()->create(['event' => AuditEventType::PageView, 'occurred_at' => now()->format('Y-m-d H:i:s.v'), 'source' => 'live']);

    expect(AuditEvent::query()->where('event', 'created')->count())->toBe(0);
});

it('skips models excluded by table name pattern or class name', function (): void {
    config(['audit-trail.models.exclude' => ['pos*']]);
    makePost();
    expect(AuditEvent::query()->count())->toBe(0);

    config(['audit-trail.models.exclude' => [Post::class]]);
    makePost();
    expect(AuditEvent::query()->count())->toBe(0);
});

it('records an export instead of a created row', function (): void {
    Export::create(['file_disk' => 'local', 'exporter' => 'App\\Exporters\\X', 'total_rows' => 7, 'user_id' => $this->user->id]);

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe(AuditEventType::Export)
        ->and($row->properties['exporter'])->toBe('App\\Exporters\\X')
        ->and($row->properties['total_rows'])->toBe(7)
        ->and(AuditEvent::query()->where('event', 'created')->count())->toBe(0);
});

it('records an import instead of a created row', function (): void {
    Import::create(['file_name' => 'a.csv', 'file_path' => '/x/a.csv', 'importer' => 'App\\Importers\\X', 'total_rows' => 3, 'user_id' => $this->user->id]);

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe(AuditEventType::Import)
        ->and($row->properties['file_name'])->toBe('a.csv')
        ->and($row->properties['total_rows'])->toBe(3)
        ->and($row->properties['importer'])->toBe('App\\Importers\\X');
});
