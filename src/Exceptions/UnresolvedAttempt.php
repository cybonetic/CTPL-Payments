<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/**
 * `409 unresolved_attempt` — the most important exception in this package.
 *
 * ------------------------------------------------------------------
 *  DO NOT RETRY. THAT IS THE WHOLE MESSAGE.
 * ------------------------------------------------------------------
 *
 * An earlier attempt on this order reached a gateway and then timed out.
 * The orchestrator does not know whether the customer was charged, and
 * until it does it will not let anything open a second attempt — because
 * charging a second gateway while the first may still succeed is how one
 * person pays twice for one thing.
 *
 * It is a `409`, and the instinct on a 409 is to back off and try again.
 * That instinct is wrong here and only here, which is why this carries a
 * class of its own and answers `retryable()` with `false` in a hierarchy
 * where `RateLimited` answers `true`.
 *
 * What to do instead: poll the order. The platform resolves it by asking
 * the gateway directly, usually within ten minutes, and `can_retry` turns
 * `true` on the order when opening another attempt is safe. `Payments::confirm()`
 * does exactly this wait for you.
 */
final class UnresolvedAttempt extends PaymentsException
{
    public function paymentOrderId(): ?string
    {
        $id = $this->details['payment_order_id'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function paymentAttemptId(): ?string
    {
        $id = $this->details['payment_attempt_id'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function retryable(): bool
    {
        return false;
    }
}
