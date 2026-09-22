<?php

declare(strict_types=1);

namespace Ctpl\Payments\Enums;

enum PaymentLinkStatus: string
{
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Expired = 'EXPIRED';
    case Disabled = 'DISABLED';

    public function isOpen(): bool
    {
        return $this === self::Active;
    }
}
