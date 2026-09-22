<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/**
 * `409 request_in_progress` — your earlier request with this idempotency
 * key has not finished yet.
 *
 * Retryable, and the only 409 that is. Wait and send the same request
 * with the same key: when the first one lands you get its response, which
 * is the entire point of the key.
 *
 * Usually means two of your own workers picked up the same job.
 */
final class RequestInProgress extends PaymentsException
{
    public function retryable(): bool
    {
        return true;
    }

    public function retryAfter(): ?int
    {
        return 1;
    }
}
