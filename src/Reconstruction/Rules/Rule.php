<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction\Rules;

use Madbox99\FilamentAuditTrail\Reconstruction\EvidenceRecord;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;

interface Rule
{
    public function name(): string;

    /**
     * Yields events backed by real records only. Filtering (placeholder
     * timestamps, seed accounts, account age, since/until) is done by the
     * Reconstructor, so rules report everything they find.
     *
     * @return iterable<EvidenceRecord>
     */
    public function collect(ReconstructionContext $ctx): iterable;
}
