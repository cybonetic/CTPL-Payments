<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/** `illegal_state_transition`. Not retryable: sending the same request again gets the same answer. */
final class IllegalStateTransition extends PaymentsException
{
}
