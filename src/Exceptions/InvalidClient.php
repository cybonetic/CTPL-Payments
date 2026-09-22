<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/** `invalid_client`. Not retryable: sending the same request again gets the same answer. */
final class InvalidClient extends PaymentsException
{
}
