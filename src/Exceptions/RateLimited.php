<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/**
 * `429 rate_limited`. Safe to retry, and the platform says when.
 *
 * The limits are per surface: order creation, status polling and refunds
 * have separate budgets so that a client polling hard during an incident
 * cannot starve its own ability to take payments. Being limited on one
 * therefore does not mean you are limited on the others.
 *
 * Honour `retryAfter()`. Retrying sooner is how a backlog becomes an
 * outage, and the header exists precisely so nobody has to guess.
 */
final class RateLimited extends PaymentsException
{
    public function __construct(
        string $message,
        string $type = 'rate_limited',
        ?int $status = 429,
        array $details = [],
        ?string $correlationId = null,
        private readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $type, $status, $details, $correlationId);
    }

    public function retryable(): bool
    {
        return true;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
