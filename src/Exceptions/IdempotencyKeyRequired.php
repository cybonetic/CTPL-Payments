<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/** `idempotency_key_required`. Not retryable: sending the same request again gets the same answer. */
final class IdempotencyKeyRequired extends PaymentsException
{
}
