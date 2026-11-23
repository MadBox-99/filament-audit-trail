<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction\Rules;

use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;

/**
 * A rule driven by configured tables, which reports the configured tables and
 * columns that do not exist (the rule skips them), so a typo is not a silent zero.
 */
interface ChecksConfiguration
{
    /**
     * @return list<array{table: string, column: string|null}> column null: the table itself is missing
     */
    public function configProblems(ReconstructionContext $ctx): array;
}
