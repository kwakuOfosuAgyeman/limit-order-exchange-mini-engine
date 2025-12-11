<?php

namespace App\Enums;

enum SecurityAction: string
{
    case LOGGED = 'logged';
    case THROTTLED = 'throttled';
    case BLOCKED = 'blocked';
    case ACCOUNT_FLAGGED = 'account_flagged';
    case ACCOUNT_SUSPENDED = 'account_suspended';

    public function label(): string
    {
        return match ($this) {
            self::LOGGED => 'Logged Only',
            self::THROTTLED => 'Request Throttled',
            self::BLOCKED => 'Request Blocked',
            self::ACCOUNT_FLAGGED => 'Account Flagged',
            self::ACCOUNT_SUSPENDED => 'Account Suspended',
        };
    }
}
