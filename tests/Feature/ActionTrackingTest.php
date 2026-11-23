<?php

declare(strict_types=1);

use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\Post;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\PostResource\Pages\EditPost;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\PostResource\Pages\ListPosts;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\Team;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\User;

beforeEach(function (): void {
    Exceptions::fake();

    $this->user = User::create(['name' => 'A', 'email' => 'a@corp.test']);
    $this->team = Team::create(['name' => 'T']);
    $this->user->teams()->attach($this->team);
    $this->actingAs($this->user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->team);
    Filament::bootCurrentPanel();

    $this->post = Post::create(['team_id' => $this->team->id, 'user_id' => $this->user->id, 'title' => 'Hello']);
});

function actionRows()
{
    return AuditEvent::query()
        ->whereIn('event', [AuditEventType::ActionMounted->value, AuditEventType::ActionExecuted->value])
        ->orderBy('id');
}

it('records mounting and executing a page action with the record as subject', function (): void {
    Livewire::test(EditPost::class, ['record' => $this->post->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Post::query()->whereKey($this->post->id)->exists())->toBeFalse();

    $mounted = actionRows()->where('event', AuditEventType::ActionMounted->value)->sole();
    $executed = actionRows()->where('event', AuditEventType::ActionExecuted->value)->sole();

    expect($mounted->action)->toBe('delete')
        ->and($mounted->subject_type)->toBe($this->post->getMorphClass())
        ->and($mounted->subject_id)->toBe((string) $this->post->id)
        ->and($mounted->properties['component'])->toBe(EditPost::class)
        ->and($mounted->team_id)->toBe($this->team->id)
        ->and($mounted->user_id)->toBe($this->user->id)
        ->and($executed->action)->toBe('delete')
        ->and($executed->subject_id)->toBe((string) $this->post->id)
        ->and($executed->properties['component'])->toBe(EditPost::class);

    Exceptions::assertNothingReported();
});

it('records a confirmed table record action with the row record as subject', function (): void {
    $component = Livewire::test(ListPosts::class)
        ->mountAction(TestAction::make('delete')->table($this->post));

    $mounted = actionRows()->where('event', AuditEventType::ActionMounted->value)->sole();

    expect($mounted->action)->toBe('delete')
        ->and($mounted->subject_type)->toBe($this->post->getMorphClass())
        ->and($mounted->subject_id)->toBe((string) $this->post->id)
        ->and($mounted->properties['component'])->toBe(ListPosts::class)
        ->and(actionRows()->where('event', AuditEventType::ActionExecuted->value)->count())->toBe(0);

    $component->callMountedAction();

    $executed = actionRows()->where('event', AuditEventType::ActionExecuted->value)->sole();

    expect(Post::query()->whereKey($this->post->id)->exists())->toBeFalse()
        ->and($executed->action)->toBe('delete')
        ->and($executed->subject_id)->toBe((string) $this->post->id)
        ->and($executed->properties['component'])->toBe(ListPosts::class);

    Exceptions::assertNothingReported();
});

it('records a modal-less action as mounted and executed inline', function (): void {
    Livewire::test(EditPost::class, ['record' => $this->post->getRouteKey()])
        ->callAction('approve');

    expect($this->post->fresh()->title)->toBe('Approved');

    $mounted = actionRows()->where('event', AuditEventType::ActionMounted->value)->sole();
    $executed = actionRows()->where('event', AuditEventType::ActionExecuted->value)->sole();

    expect($mounted->action)->toBe('approve')
        ->and($executed->action)->toBe('approve')
        ->and($executed->subject_id)->toBe((string) $this->post->id)
        ->and($executed->properties['executed_inline'])->toBeTrue();

    Exceptions::assertNothingReported();
});

it('does not record a modal-less action that fails validation as executed', function (): void {
    Livewire::test(EditPost::class, ['record' => $this->post->getRouteKey()])
        ->callAction('reject');

    expect(actionRows()->where('event', AuditEventType::ActionExecuted->value)->count())->toBe(0);
});

it('records nothing for a disabled action', function (): void {
    Livewire::test(EditPost::class, ['record' => $this->post->getRouteKey()])
        ->callAction('locked');

    expect($this->post->fresh()->title)->toBe('Hello')
        ->and(actionRows()->count())->toBe(0);
});

it('records nothing for an unknown action', function (): void {
    Livewire::test(EditPost::class, ['record' => $this->post->getRouteKey()])
        ->call('mountAction', 'whatever');

    expect(actionRows()->count())->toBe(0);
});

it('does not record execution when the action form fails validation', function (): void {
    Livewire::test(EditPost::class, ['record' => $this->post->getRouteKey()])
        ->callAction('rename', ['new_title' => ''])
        ->assertHasFormErrors(['new_title' => 'required']);

    expect($this->post->fresh()->title)->toBe('Hello')
        ->and(actionRows()->where('event', AuditEventType::ActionMounted->value)->count())->toBe(1)
        ->and(actionRows()->where('event', AuditEventType::ActionExecuted->value)->count())->toBe(0);
});

it('records execution when the action form is valid', function (): void {
    Livewire::test(EditPost::class, ['record' => $this->post->getRouteKey()])
        ->callAction('rename', ['new_title' => 'Renamed']);

    expect($this->post->fresh()->title)->toBe('Renamed')
        ->and(actionRows()->where('event', AuditEventType::ActionExecuted->value)->sole()->action)->toBe('rename');
});

it('sanitizes action arguments', function (): void {
    Livewire::test(EditPost::class, ['record' => $this->post->getRouteKey()])
        ->mountAction(DeleteAction::class, ['note' => 'x', 'api_token' => 'secret']);

    $mounted = actionRows()->where('event', AuditEventType::ActionMounted->value)->sole();

    expect($mounted->properties['arguments'])->toBe(['note' => 'x']);
});

it('does not record plain property updates', function (): void {
    Livewire::test(EditPost::class, ['record' => $this->post->getRouteKey()])
        ->set('data.title', 'Changed');

    expect(actionRows()->count())->toBe(0);
});

it('never breaks the action when recording fails', function (): void {
    config(['audit-trail.table' => 'missing_table']);

    Livewire::test(EditPost::class, ['record' => $this->post->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Post::query()->whereKey($this->post->id)->exists())->toBeFalse();
});
