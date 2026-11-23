<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction\Rules;

use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Models\Import;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Reconstruction\EvidenceRecord;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;

/**
 * Filament import and export runs: who started them and when.
 */
final class ImportExportRule implements Rule
{
    public function name(): string
    {
        return 'imports_exports';
    }

    public function collect(ReconstructionContext $ctx): iterable
    {
        $schema = $ctx->connection()->getSchemaBuilder();

        $sources = [
            'imports' => [AuditEventType::Import, Import::class, ['file_name', 'total_rows', 'importer']],
            'exports' => [AuditEventType::Export, Export::class, ['exporter', 'total_rows']],
        ];

        foreach ($sources as $table => [$type, $subjectType, $columns]) {
            if (! $schema->hasTable($table)) {
                continue;
            }

            $query = $ctx->connection()->table($table);

            // Filament's polymorphic user relationship: only rows owned by the user model count.
            if ($schema->hasColumn($table, 'user_type')) {
                $query->whereIn('user_type', $ctx->userMorphClasses());
            }

            foreach ($query->lazyById(500) as $row) {
                $row = (array) $row;
                $occurredAt = $ctx->parseTimestamp($row['created_at'] ?? null);

                if ($occurredAt === null || ! is_numeric($row['user_id'] ?? null)) {
                    continue;
                }

                $userId = (int) $row['user_id'];

                $properties = [];
                foreach ($columns as $column) {
                    $properties[$column] = $row[$column] ?? null;
                }

                yield new EvidenceRecord(
                    type: $type,
                    occurredAt: $occurredAt,
                    userId: $userId,
                    userEmail: $ctx->userEmail($userId),
                    teamId: null,
                    subjectType: $subjectType,
                    subjectId: (string) $row['id'],
                    properties: $properties,
                    evidence: ['rule' => $this->name(), 'table' => $table, 'id' => $row['id'], 'column' => 'created_at'],
                    evidenceKey: $table.':'.$row['id'],
                    rejection: array_key_exists($userId, $ctx->users()) ? null : 'unknown_user',
                );
            }
        }
    }
}
