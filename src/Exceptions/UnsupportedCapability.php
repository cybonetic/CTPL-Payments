<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/** `unsupported_capability`. Not retryable: sending the same request again gets the same answer. */
final class UnsupportedCapability extends PaymentsException
{
}
