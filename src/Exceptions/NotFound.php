<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/** `not_found`. Not retryable: sending the same request again gets the same answer. */
final class NotFound extends PaymentsException
{
}
