<?php

declare(strict_types=1);

namespace Ctpl\Payments\Webhooks\Events;

use Ctpl\Payments\Webhooks\PlatformEvent;

/** `refund.failed`. Dispatched alongside PaymentEventReceived. */
class RefundFailed
{
    public function __construct(public readonly PlatformEvent $event)
    {
    }
}
