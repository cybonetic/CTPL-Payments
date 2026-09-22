<?php

declare(strict_types=1);

namespace Ctpl\Payments\Webhooks\Events;

use Ctpl\Payments\Webhooks\PlatformEvent;

/** `payment.disputed`. Dispatched alongside PaymentEventReceived. */
class PaymentDisputed
{
    public function __construct(public readonly PlatformEvent $event)
    {
    }
}
