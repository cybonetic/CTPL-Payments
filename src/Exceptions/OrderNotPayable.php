<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/** `order_not_payable`. Not retryable: sending the same request again gets the same answer. */
final class OrderNotPayable extends PaymentsException
{
}
