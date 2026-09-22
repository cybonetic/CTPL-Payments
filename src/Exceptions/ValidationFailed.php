<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/** `validation_failed`. Not retryable: sending the same request again gets the same answer. */
final class ValidationFailed extends PaymentsException
{
}
