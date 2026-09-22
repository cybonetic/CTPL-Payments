<?php

declare(strict_types=1);

namespace Ctpl\Payments\Webhooks\Events;

use Ctpl\Payments\Webhooks\PlatformEvent;

/** `refund.completed`. Dispatched alongside PaymentEventReceived. */
class RefundCompleted
{
    public function __construct(public readonly PlatformEvent $event)
    {
    }
}
