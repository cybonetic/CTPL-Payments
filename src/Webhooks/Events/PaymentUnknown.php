<?php

declare(strict_types=1);

namespace Ctpl\Payments\Webhooks\Events;

use Ctpl\Payments\Webhooks\PlatformEvent;

/** `payment.unknown`. Dispatched alongside PaymentEventReceived. */
class PaymentUnknown
{
    public function __construct(public readonly PlatformEvent $event)
    {
    }
}
