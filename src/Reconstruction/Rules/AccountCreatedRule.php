<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction\Rules;

use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Reconstruction\EvidenceRecord;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;

/**
 * users.created_at → account_created, without an actor (in receiver apps the
 * account is made by the sync). On the publisher a verified registration is a
 * real sign-in: login_inferred at created_at and at email_verified_at.
 */
final class AccountCreatedRule implements Rule
{
    public function name(): string
    {
        return 'account_created';
    }

    public function collect(ReconstructionContext $ctx): iterable
    {
        $table = $ctx->userTable();
        $subjectType = $ctx->userModel()->getMorphClass();
        $publisher = (bool) config('audit-trail.reconstruct.publisher', false);

        foreach ($ctx->users() as $id => $user) {
            if ($user['created_at'] === null) {
                continue;
            }

            yield new EvidenceRecord(
                type: AuditEventType::AccountCreated,
                occurredAt: $user['created_at'],
                userId: null,
                userEmail: null,
                teamId: null,
                subjectType: $subjectType,
                subjectId: (string) $id,
                properties: [],
                evidence: ['rule' => $this->name(), 'table' => $table, 'id' => $id, 'column' => 'created_at'],
                evidenceKey: 'users:'.$id.':created',
            );

            if (! $publisher || $user['email_verified_at'] === null) {
                continue;
            }

            foreach ([
                ['registration', 'created_at', $user['created_at'], 'registration'],
                ['email_verification', 'email_verified_at', $user['email_verified_at'], 'verified'],
            ] as [$rule, $column, $at, $suffix]) {
                yield new EvidenceRecord(
                    type: AuditEventType::LoginInferred,
                    occurredAt: $at,
                    userId: $id,
                    userEmail: $user['email'],
                    teamId: null,
                    subjectType: null,
                    subjectId: null,
                    properties: [],
                    evidence: ['rule' => $rule, 'table' => $table, 'id' => $id, 'column' => $column],
                    evidenceKey: 'users:'.$id.':'.$suffix,
                    inferred: true,
                );
            }
        }
    }
}
