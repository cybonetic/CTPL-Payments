<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/** `insufficient_scope`. Not retryable: sending the same request again gets the same answer. */
final class InsufficientScope extends PaymentsException
{
}
