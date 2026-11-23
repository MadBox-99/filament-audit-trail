<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Enums;

use Filament\Support\Contracts\HasLabel;

enum AuditEventType: string implements HasLabel
{
    case Login = 'login';
    case LoginInferred = 'login_inferred';
    case Logout = 'logout';
    case LoginFailed = 'login_failed';
    case PageView = 'page_view';
    case ActionMounted = 'action_mounted';
    case ActionExecuted = 'action_executed';
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case Import = 'import';
    case Export = 'export';
    case AccountCreated = 'account_created';

    public function getLabel(): string
    {
        return __('audit-trail::audit-trail.events.'.$this->value);
    }
}
