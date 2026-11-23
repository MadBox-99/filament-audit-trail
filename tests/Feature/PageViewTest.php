<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Http\Middleware\RecordPageView;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\Team;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\User;
use Symfony\Component\HttpFoundation\Response;

function pageViewFixture(): array
{
    $user = User::create(['name' => 'A', 'email' => 'a@corp.test']);
    $team = Team::create(['name' => 'T']);
    $user->teams()->attach($team);

    return [$user, $team];
}

function pageViews()
{
    return AuditEvent::query()->where('event', AuditEventType::PageView->value);
}

it('records a page view for an authenticated tenant request', function (): void {
    [$user, $team] = pageViewFixture();

    $this->actingAs($user)->get('/admin/'.$team->getKey())->assertSuccessful();

    $row = pageViews()->sole();
    expect($row->panel)->toBe('admin')
        ->and($row->team_id)->toBe($team->id)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->method)->toBe('GET');
});

it('does not record page views for guests', function (): void {
    [, $team] = pageViewFixture();

    $this->get('/admin/'.$team->getKey())->assertRedirect();

    expect(pageViews()->count())->toBe(0);
});

it('does not record livewire requests', function (): void {
    [$user] = pageViewFixture();

    $response = $this->actingAs($user)->postJson(
        app(HandleRequests::class)->getUpdateUri(),
        ['components' => [['snapshot' => '{}', 'updates' => [], 'calls' => []]]],
        ['X-Livewire' => 'true'],
    );

    // The status is a 404 from Livewire's own snapshot validation, so prove the router matched instead.
    expect(request()->route()?->getName())->toBe('default-livewire.update')
        ->and($response->getStatusCode())->not->toBe(405)
        ->and(pageViews()->count())->toBe(0);
});

it('does not record non-GET requests even when the response is 2xx', function (): void {
    [$user, $team] = pageViewFixture();
    $this->actingAs($user);

    // The panel has no POST route that answers 2xx, so drive the middleware directly.
    $response = app(RecordPageView::class)->handle(
        Request::create('/admin/'.$team->getKey(), 'POST'),
        fn (): Response => response('ok'),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and(pageViews()->count())->toBe(0);
});

it('does not record a non-livewire GET carrying the X-Livewire header', function (): void {
    [$user, $team] = pageViewFixture();

    $this->actingAs($user)->get('/admin/'.$team->getKey(), ['X-Livewire' => 'true']);

    expect(pageViews()->count())->toBe(0);
});

it('does not record 404 responses', function (): void {
    [$user, $team] = pageViewFixture();

    $this->actingAs($user)->get('/admin/'.$team->getKey().'/nope')->assertNotFound();

    expect(pageViews()->count())->toBe(0);
});

it('does not record excluded paths', function (): void {
    [$user, $team] = pageViewFixture();
    config(['audit-trail.page_views.exclude_paths' => ['admin/*']]);

    $this->actingAs($user)->get('/admin/'.$team->getKey())->assertSuccessful();

    expect(pageViews()->count())->toBe(0);
});
