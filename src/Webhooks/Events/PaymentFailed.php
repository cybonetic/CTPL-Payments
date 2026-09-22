<?php

declare(strict_types=1);

namespace Ctpl\Payments\Webhooks\Events;

use Ctpl\Payments\Webhooks\PlatformEvent;

/** `payment.failed`. Dispatched alongside PaymentEventReceived. */
class PaymentFailed
{
    public function __construct(public readonly PlatformEvent $event)
    {
    }
}
