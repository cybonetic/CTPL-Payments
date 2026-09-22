<?php

declare(strict_types=1);

namespace Ctpl\Payments\Enums;

/**
 * The method vocabulary.
 *
 * Fetch `Payments::paymentMethods()` to build a picker rather than
 * hard-coding this list: what a given merchant can actually take depends
 * on their gateway configuration, and this enum is the vocabulary rather
 * than an availability check.
 */
enum PaymentMethodType: string
{
    case Upi = 'UPI';
    case Card = 'CARD';
    case NetBanking = 'NETBANKING';
    case Wallet = 'WALLET';
    case Emi = 'EMI';
    case BankTransfer = 'BANK_TRANSFER';
}
