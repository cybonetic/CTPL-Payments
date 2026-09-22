<?php

declare(strict_types=1);

namespace Ctpl\Payments\Webhooks\Events;

use Ctpl\Payments\Webhooks\PlatformEvent;

/** `payment.captured`. Dispatched alongside PaymentEventReceived. */
class PaymentCaptured
{
    public function __construct(public readonly PlatformEvent $event)
    {
    }
}
