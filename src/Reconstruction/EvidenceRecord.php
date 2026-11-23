<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;

/**
 * One event reconstructed from a real record. The evidence points at that record.
 */
final readonly class EvidenceRecord
{
    /**
     * @param  array<array-key, mixed>  $properties
     * @param  array<string, mixed>  $evidence  rule, table, id, column, note
     * @param  string|null  $rejection  set by a rule that found the record but knows it is no
     *                                  evidence of a person (e.g. `team_bootstrap`); the
     *                                  Reconstructor rejects it under this reason
     */
    public function __construct(
        public AuditEventType $type,
        public CarbonImmutable $occurredAt,
        public ?int $userId,
        public ?string $userEmail,
        public ?int $teamId,
        public ?string $subjectType,
        public ?string $subjectId,
        public array $properties,
        public array $evidence,
        public string $evidenceKey,
        public bool $inferred = false,
        public ?string $action = null,
        public ?string $rejection = null,
    ) {
        if (trim($evidenceKey) === '') {
            throw new InvalidArgumentException('An evidence record needs a non-empty evidence key.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'event' => $this->type,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s.v'),
            'user_id' => $this->userId,
            'user_email' => $this->userEmail,
            'team_id' => $this->teamId,
            'action' => $this->action,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'properties' => $this->properties === [] ? null : $this->properties,
            'inferred' => $this->inferred,
            'evidence' => $this->evidence,
            'evidence_key' => $this->evidenceKey,
        ];
    }
}
