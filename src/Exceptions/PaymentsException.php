<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

use RuntimeException;

/**
 * Every failure this SDK raises, and the one question worth asking of it.
 *
 * ------------------------------------------------------------------
 *  `retryable()` IS THE POINT OF THIS CLASS HIERARCHY
 * ------------------------------------------------------------------
 *
 * The orchestrator's API returns a stable `error.type` slug on every
 * failure, and the whole reason it does is that the right reaction differs
 * wildly between them. Two of these mean "send it again in a moment". One
 * of them means "send it again and you may charge the customer twice".
 * An SDK that surfaced them as one `HttpException` with a status code
 * would be leaving its users to rediscover that distinction, and the way
 * they rediscover it is a double charge.
 *
 * So each type gets a class, each class answers `retryable()`, and the
 * caller's retry loop asks the exception rather than the status code.
 *
 * `correlationId` is on every one of them. It is what the platform's
 * operators search by, and it is the single most useful thing to put in
 * your own log line.
 */
class PaymentsException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public readonly string $type = 'unknown',
        public readonly ?int $status = null,
        public readonly array $details = [],
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct($message);
    }

    /**
     * May this request be sent again as it stands?
     *
     * `false` by default, deliberately. A new failure type added to the
     * platform and not yet mapped here arrives as this base class, and the
     * safe default for an unrecognised payment failure is to stop.
     */
    public function retryable(): bool
    {
        return false;
    }

    /** Seconds to wait before retrying, when the platform said. */
    public function retryAfter(): ?int
    {
        return null;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s [%s]%s: %s',
            static::class,
            $this->type,
            $this->correlationId === null ? '' : ' correlation=' . $this->correlationId,
            $this->getMessage(),
        );
    }
}
