<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Livewire;

use Filament\Actions\Contracts\HasActions;
use Illuminate\Database\Eloquent\Model;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Support\AuditRecorder;
use Throwable;

use function Livewire\on;

/**
 * Records Filament action "clicks" by listening to Livewire's `call` hook:
 * `mountAction` becomes an ActionMounted row, `callMountedAction` an ActionExecuted row.
 */
final class ActionCallHook
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function register(): void
    {
        // Livewire 4 fires: trigger('call', $component, $method, $params, $componentContext, $returnEarly, $metadata, $index).
        // Returning null keeps us out of the finisher chain, so the call itself is untouched.
        on('call', function (mixed $component, mixed $method, mixed $params): void {
            $this->handle($component, $method, $params);
        });
    }

    public function handle(mixed $component, mixed $method, mixed $params): void
    {
        try {
            if (! $component instanceof HasActions || ! is_string($method) || ! is_array($params)) {
                return;
            }

            match ($method) {
                'mountAction' => $this->recordMount($component, $params),
                'callMountedAction' => $this->recordExecute($component, $params),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<array-key, mixed>  $params
     */
    private function recordMount(HasActions $component, array $params): void
    {
        $name = $params[0] ?? $params['name'] ?? null;

        if (! is_string($name) || $name === '') {
            return;
        }

        $arguments = $params[1] ?? $params['arguments'] ?? [];
        $context = $params[2] ?? $params['context'] ?? [];

        $this->write(
            AuditEventType::ActionMounted,
            $component,
            $name,
            is_array($arguments) ? $arguments : [],
            is_array($context) ? $context : [],
        );
    }

    /**
     * @param  array<array-key, mixed>  $params
     */
    private function recordExecute(HasActions $component, array $params): void
    {
        // Read the stack before the call runs: executing the action unmounts it.
        $mounted = property_exists($component, 'mountedActions') ? $component->mountedActions : [];

        if (! is_array($mounted) || $mounted === []) {
            return;
        }

        $last = end($mounted);
        $name = is_array($last) ? ($last['name'] ?? null) : null;

        if (! is_string($name) || $name === '') {
            return;
        }

        $arguments = $params[0] ?? $params['arguments'] ?? [];
        $mountedArguments = $last['arguments'] ?? [];
        $context = $last['context'] ?? [];

        $this->write(
            AuditEventType::ActionExecuted,
            $component,
            $name,
            [
                ...(is_array($mountedArguments) ? $mountedArguments : []),
                ...(is_array($arguments) ? $arguments : []),
            ],
            is_array($context) ? $context : [],
        );
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @param  array<array-key, mixed>  $context
     */
    private function write(AuditEventType $type, HasActions $component, string $name, array $arguments, array $context): void
    {
        $subject = $this->resolveSubject($component, $context);

        $this->recorder->record($type, [
            'action' => mb_substr($name, 0, 255),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject instanceof Model ? (string) $subject->getKey() : null,
            'properties' => [
                'component' => $component::class,
                'arguments' => $arguments,
            ],
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    private function resolveSubject(HasActions $component, array $context): ?Model
    {
        try {
            $key = $context['recordKey'] ?? null;

            if ((is_string($key) || is_int($key)) && method_exists($component, 'getTableRecord')) {
                $record = $component->getTableRecord((string) $key);

                if ($record instanceof Model) {
                    return $record;
                }
            }

            if (method_exists($component, 'getRecord')) {
                $record = $component->getRecord();

                if ($record instanceof Model) {
                    return $record;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }
}
