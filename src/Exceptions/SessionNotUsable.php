<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/** `session_not_usable`. Not retryable: sending the same request again gets the same answer. */
final class SessionNotUsable extends PaymentsException
{
}
