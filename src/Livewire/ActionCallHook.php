<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Livewire;

use Closure;
use Error;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Support\AuditRecorder;
use Throwable;

use function Livewire\on;

/**
 * Records Filament action "clicks" through Livewire's `call` hook.
 *
 * The state is captured before the method runs, and the rows are written in the finisher.
 * Livewire runs the finisher only after the method has returned, and skips it when the
 * method throws. Livewire swallows a ValidationException, though (SupportValidation), so the finisher
 * also checks the mounted stack and the error bag. The rows therefore show what actually ran:
 * - `mountAction` that grew the mounted stack → ActionMounted (a modal opened);
 * - `mountAction` of an enabled, authorized, modal-less action that left the stack unchanged
 *   → ActionMounted + ActionExecuted (`executed_inline`), since Filament ran it inside mountAction;
 * - `callMountedAction` that shrank the stack → ActionExecuted (the action completed and unmounted).
 */
final class ActionCallHook
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function register(): void
    {
        // Livewire 4: trigger('call', $component, $method, $params, $componentContext, $returnEarly, $metadata, $index).
        on('call', fn (mixed $component, mixed $method, mixed $params): ?Closure => $this->handle($component, $method, $params));
    }

    public function handle(mixed $component, mixed $method, mixed $params): ?Closure
    {
        try {
            if (! $component instanceof HasActions || ! is_string($method) || ! is_array($params)) {
                return null;
            }

            return match ($method) {
                'mountAction' => $this->beforeMount($component, $params),
                'callMountedAction' => $this->beforeCall($component, $params),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $params
     */
    private function beforeMount(HasActions $component, array $params): ?Closure
    {
        $name = $params[0] ?? $params['name'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        $arguments = $params[1] ?? $params['arguments'] ?? [];
        $arguments = is_array($arguments) ? $arguments : [];
        $context = $params[2] ?? $params['context'] ?? [];
        $context = is_array($context) ? $context : [];

        $stackBefore = $this->stackSize($component);
        $action = $this->resolve($component, ['name' => $name, 'arguments' => $arguments, 'context' => $context]);

        if (! $action instanceof Action) {
            return null; // Unknown or unresolvable: Filament will unmount it without doing anything.
        }

        $runsInline = ! ($context['mountedFromUrl'] ?? false) && $this->isRunnable($action) && ! $this->opensModal($component, $action);
        $subject = $this->resolveSubject($component, $action, $context);
        $errorsBefore = $this->errors($component);

        return function () use ($component, $name, $arguments, $stackBefore, $runsInline, $subject, $errorsBefore): void {
            try {
                if ($this->stackSize($component) > $stackBefore) {
                    $this->write(AuditEventType::ActionMounted, $component, $name, $arguments, $subject);

                    return;
                }

                // Livewire swallows a ValidationException and only fills the error bag, so a new
                // error means the inline run did not complete.
                $errors = $this->errors($component);

                if ($runsInline && ($errors === [] || $errors === $errorsBefore)) {
                    $this->write(AuditEventType::ActionMounted, $component, $name, $arguments, $subject);
                    $this->write(AuditEventType::ActionExecuted, $component, $name, $arguments, $subject, ['executed_inline' => true]);
                }
            } catch (Throwable $e) {
                report($e);
            }
        };
    }

    /**
     * @param  array<array-key, mixed>  $params
     */
    private function beforeCall(HasActions $component, array $params): ?Closure
    {
        $mounted = $this->mountedActions($component);
        $last = end($mounted);
        $name = is_array($last) ? ($last['name'] ?? null) : null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        $mountedArguments = $last['arguments'] ?? [];
        $callArguments = $params[0] ?? $params['arguments'] ?? [];
        $arguments = [
            ...(is_array($mountedArguments) ? $mountedArguments : []),
            ...(is_array($callArguments) ? $callArguments : []),
        ];
        $context = is_array($last['context'] ?? null) ? $last['context'] : [];

        $stackBefore = count($mounted);
        $action = $this->safeResolve(
            fn (): mixed => method_exists($component, 'getMountedAction') ? $component->getMountedAction() : null,
        );
        $subject = $this->resolveSubject($component, $action instanceof Action ? $action : null, $context);

        return function () use ($component, $name, $arguments, $stackBefore, $subject): void {
            try {
                // Only a completed action is unmounted; halts, rejections and failed validation leave it on the stack.
                if ($this->stackSize($component) < $stackBefore) {
                    $this->write(AuditEventType::ActionExecuted, $component, $name, $arguments, $subject);
                }
            } catch (Throwable $e) {
                report($e);
            }
        };
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @param  array<string, mixed>  $extra
     */
    private function write(AuditEventType $type, HasActions $component, string $name, array $arguments, ?Model $subject, array $extra = []): void
    {
        $this->recorder->record($type, [
            'action' => mb_substr($name, 0, 255),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject instanceof Model ? (string) $subject->getKey() : null,
            'properties' => [
                'component' => $component::class,
                'arguments' => $arguments,
                ...$extra,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $new
     */
    private function resolve(HasActions $component, array $new): ?Action
    {
        // A mount while another action is open resolves as that action's child.
        return $this->safeResolve(fn (): mixed => $component->getAction([...$this->mountedActions($component), $new], false));
    }

    private function safeResolve(Closure $resolver): ?Action
    {
        try {
            $action = $resolver();

            return $action instanceof Action ? $action : null;
        } catch (ActionNotResolvableException) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function isRunnable(Action $action): bool
    {
        try {
            return ! $action->isDisabled() && $action->isAuthorized();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function opensModal(HasActions $component, Action $action): bool
    {
        if (! method_exists($component, 'mountedActionHasSchema')) {
            return true; // Not Filament's InteractsWithActions: do not claim an inline execution.
        }

        try {
            return $action->shouldOpenModal(
                checkForSchemaUsing: fn (Action $action): bool => $component->mountedActionHasSchema($action),
            );
        } catch (Throwable $e) {
            report($e);

            return true; // Unknown: do not claim an inline execution.
        }
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    private function resolveSubject(HasActions $component, ?Action $action, array $context): ?Model
    {
        $candidates = [
            fn (): mixed => $action?->getRecord(),
            function () use ($component, $context): mixed {
                $key = $context['recordKey'] ?? null;

                return (is_string($key) || is_int($key)) && method_exists($component, 'getTableRecord')
                    ? $component->getTableRecord((string) $key)
                    : null;
            },
            fn (): mixed => method_exists($component, 'getRecord') ? $component->getRecord() : null,
        ];

        foreach ($candidates as $candidate) {
            try {
                $record = $candidate();
            } catch (ModelNotFoundException) {
                continue; // Expected: the row is gone.
            } catch (Error $e) {
                // Expected: a page whose typed `$record` property was never initialized has no record.
                if (! str_contains($e->getMessage(), 'before initialization')) {
                    report($e);
                }

                continue;
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($record instanceof Model) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function mountedActions(HasActions $component): array
    {
        $mounted = property_exists($component, 'mountedActions') ? $component->mountedActions : [];

        return is_array($mounted) ? $mounted : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function errors(HasActions $component): array
    {
        try {
            return method_exists($component, 'getErrorBag') ? $component->getErrorBag()->toArray() : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function stackSize(HasActions $component): int
    {
        return count($this->mountedActions($component));
    }
}
