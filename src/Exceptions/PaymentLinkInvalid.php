<?php

declare(strict_types=1);

namespace Ctpl\Payments\Exceptions;

/** `payment_link_invalid`. Not retryable: sending the same request again gets the same answer. */
final class PaymentLinkInvalid extends PaymentsException
{
}
