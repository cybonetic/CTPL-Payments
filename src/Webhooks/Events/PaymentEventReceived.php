<?php

declare(strict_types=1);

namespace Ctpl\Payments\Webhooks\Events;

use Ctpl\Payments\Webhooks\PlatformEvent;

/**
 * Dispatched for EVERY verified event, whatever its type.
 *
 * Listen to this when you want one handler with a `match` inside it, or
 * when you care about an event type this package predates — a new type
 * still arrives here, where a type-specific class would not exist for it.
 *
 * The typed events below are dispatched as well, not instead.
 */
class PaymentEventReceived
{
    public function __construct(public readonly PlatformEvent $event)
    {
    }
}
