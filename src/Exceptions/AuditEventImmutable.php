<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Exceptions;

use LogicException;

final class AuditEventImmutable extends LogicException
{
    public static function make(string $operation): self
    {
        return new self("Audit events are append-only; cannot {$operation} an audit event.");
    }
}
