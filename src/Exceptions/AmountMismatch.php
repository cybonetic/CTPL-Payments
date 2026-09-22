<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/** `amount_mismatch`. Not retryable: sending the same request again gets the same answer. */
final class AmountMismatch extends PaymentsException
{
}
