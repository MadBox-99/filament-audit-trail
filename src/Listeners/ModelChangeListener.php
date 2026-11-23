<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Listeners;

use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Support\AuditRecorder;
use Madbox99\FilamentAuditTrail\Support\PropertySanitizer;

final readonly class ModelChangeListener
{
    public function __construct(private AuditRecorder $recorder) {}

    /**
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $model instanceof Model || ! $this->shouldRecord($model)) {
            return;
        }

        $name = explode(': ', $event, 2)[0];

        match ($name) {
            'eloquent.created' => $this->created($model),
            'eloquent.updated' => $this->updated($model),
            'eloquent.deleted' => $this->subjectOnly(AuditEventType::Deleted, $model),
            'eloquent.restored' => $this->subjectOnly(AuditEventType::Restored, $model),
            default => null,
        };
    }

    private function created(Model $model): void
    {
        if ($model instanceof Export) {
            $this->recorder->record(AuditEventType::Export, [
                'properties' => ['exporter' => $model->exporter, 'total_rows' => $model->total_rows],
            ]);

            return;
        }

        if ($model instanceof Import) {
            $this->recorder->record(AuditEventType::Import, [
                'properties' => [
                    'file_name' => $model->file_name,
                    'total_rows' => $model->total_rows,
                    'importer' => $model->importer,
                ],
            ]);

            return;
        }

        $this->recorder->record(AuditEventType::Created, $this->subject($model) + [
            'properties' => ['new' => PropertySanitizer::sanitize($model->getAttributes(), $model->getHidden())],
        ]);
    }

    private function updated(Model $model): void
    {
        $changes = $model->getChanges();
        unset($changes[$model->getUpdatedAtColumn()]);

        if ($changes === []) {
            return;
        }

        $old = [];
        foreach (array_keys($changes) as $key) {
            $old[$key] = $model->getRawOriginal($key);
        }

        $hidden = $model->getHidden();

        $this->recorder->record(AuditEventType::Updated, $this->subject($model) + [
            'properties' => [
                'old' => PropertySanitizer::sanitize($old, $hidden),
                'new' => PropertySanitizer::sanitize($changes, $hidden),
            ],
        ]);
    }

    private function subjectOnly(AuditEventType $type, Model $model): void
    {
        $this->recorder->record($type, $this->subject($model));
    }

    /**
     * @return array{subject_type: string, subject_id: string}
     */
    private function subject(Model $model): array
    {
        return [
            'subject_type' => $model->getMorphClass(),
            'subject_id' => (string) $model->getKey(),
        ];
    }

    private function shouldRecord(Model $model): bool
    {
        if ($model instanceof AuditEvent) {
            return false;
        }

        if (auth()->guard((string) config('audit-trail.guard', 'web'))->user() === null) {
            return false;
        }

        foreach ((array) config('audit-trail.models.exclude', []) as $pattern) {
            // FNM_NOESCAPE: backslashes in class names must stay literal.
            if (fnmatch((string) $pattern, $model::class, FNM_NOESCAPE) || fnmatch((string) $pattern, $model->getTable(), FNM_NOESCAPE)) {
                return false;
            }
        }

        return true;
    }
}
