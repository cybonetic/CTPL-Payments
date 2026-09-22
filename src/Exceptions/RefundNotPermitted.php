<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/** `refund_not_permitted`. Not retryable: sending the same request again gets the same answer. */
final class RefundNotPermitted extends PaymentsException
{
}
