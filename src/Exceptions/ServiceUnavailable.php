<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/**
 * A `503` from the platform. Whether to retry depends on WHICH 503.
 *
 *   no_eligible_gateway   — no gateway could take this payment. Sometimes
 *                           transient (a breaker will close again), often
 *                           not (the merchant has no account for this
 *                           method). `rejections()` says why each candidate
 *                           was ruled out, and is the first thing to log.
 *   gateway_unavailable   — a gateway is misconfigured. The platform's
 *                           problem; report it rather than retrying around
 *                           it, because retrying cannot fix configuration.
 *   routing_misconfigured — likewise, and explicitly not transient.
 *
 * So only the first is retryable, and even then with real backoff: a
 * client that retries a `no_eligible_gateway` in a tight loop is adding
 * load to a platform that has just told it it has nothing to route to.
 */
final class ServiceUnavailable extends PaymentsException
{
    /** @return array<string, mixed> Why each candidate gateway was ruled out. */
    public function rejections(): array
    {
        $rejections = $this->details['rejections'] ?? [];

        return is_array($rejections) ? $rejections : [];
    }

    public function retryable(): bool
    {
        return $this->type === 'no_eligible_gateway';
    }
}
