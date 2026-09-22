<?php

declare(strict_types=1);

namespace Ctpl\Payments\Webhooks\Events;

use Ctpl\Payments\Webhooks\PlatformEvent;

/** `payment.pending`. Dispatched alongside PaymentEventReceived. */
class PaymentPending
{
    public function __construct(public readonly PlatformEvent $event)
    {
    }
}
