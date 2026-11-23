<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Enums;

use Filament\Support\Contracts\HasLabel;

enum AuditSource: string implements HasLabel
{
    case Live = 'live';
    case Reconstructed = 'reconstructed';

    public function getLabel(): string
    {
        return __('audit-trail::audit-trail.sources.'.$this->value);
    }
}
