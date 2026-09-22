<?php

declare(strict_types=1);

namespace Ctpl\Payments;

use Ctpl\Payments\Client\Client;
use Ctpl\Payments\Data\Customer;
use Ctpl\Payments\Data\GatewayStatus;
use Ctpl\Payments\Data\Money;
use Ctpl\Payments\Data\PaymentLink;
use Ctpl\Payments\Data\PaymentMethod;
use Ctpl\Payments\Data\PaymentOrder;
use Ctpl\Payments\Data\Refund;
use Ctpl\Payments\Enums\PaymentMethodType;
use Ctpl\Payments\Exceptions\PaymentsException;
use Ctpl\Payments\Exceptions\UnresolvedAttempt;
use DateTimeInterface;

/**
 * Everything an application does with payments.
 *
 * ------------------------------------------------------------------
 *  THE THREE RULES THIS CLASS IS SHAPED BY
 * ------------------------------------------------------------------
 *
 * 1. A browser redirect is not confirmation of payment. `confirm()` asks
 *    the platform; nothing in this package infers an outcome from the
 *    customer having reached a URL.
 *
 * 2. `unresolved_attempt` means stop. `openAttempt()` lets that exception
 *    out untouched rather than swallowing or retrying it, and `confirm()`
 *    is what you use instead of retrying.
 *
 * 3. Amounts are minor-unit integers. `Money` is the only way to express
 *    one here, so a float never reaches the wire.
 *
 * The usual path is `pay()`, which does the first two calls together
 * because doing them separately buys nothing and forgetting the second
 * leaves an order nobody can pay.
 */
final class PaymentsManager
{
    public function __construct(private readonly Client $client)
    {
    }

    // -----------------------------------------------------------------
    // Taking a payment
    // -----------------------------------------------------------------

    /**
     * Create the invoice. No gateway is touched yet.
     *
     * `$reference` is your own handle for this payment and must be unique
     * per order — a booking id, an invoice number with a suffix. It is
     * what you will search by when a customer asks about a payment.
     *
     * `$idempotencyKey` defaults to being derived from that reference,
     * which is the right default: retrying this call after a timeout then
     * returns the order you already created rather than a second one. Pass
     * your own only if you have a better handle on the operation.
     *
     * @param array<string, mixed> $metadata Yours. Stored, returned, never interpreted.
     */
    public function createOrder(
        string $reference,
        Money $amount,
        ?Customer $customer = null,
        ?string $description = null,
        ?string $purpose = null,
        ?string $invoiceReference = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): PaymentOrder {
        $response = $this->client->mutate('POST', 'payment-orders', $idempotencyKey ?? $reference . ':order', array_filter([
            'external_reference' => $reference,
            'invoice_reference' => $invoiceReference,
            'amount' => $amount->minorUnits,
            'currency' => $amount->currency,
            'description' => $description,
            'purpose' => $purpose,
            'customer' => $customer?->toArray(),
            'metadata' => $metadata === [] ? null : $metadata,
        ], static fn (mixed $v): bool => $v !== null));

        return PaymentOrder::fromApi($response['data'], $response['checkout'] ?? null);
    }

    /**
     * Pick a gateway, talk to it, and get something to show the customer.
     *
     * Omit `$method` and the customer chooses on the gateway's own page,
     * which is what you want for a hosted checkout.
     *
     * @throws UnresolvedAttempt An earlier attempt may have charged the
     *         customer. Do NOT catch this to retry — call `confirm()`.
     */
    public function openAttempt(
        string|PaymentOrder $order,
        ?PaymentMethodType $method = null,
        ?string $idempotencyKey = null,
    ): PaymentOrder {
        $id = $this->idOf($order);

        $response = $this->client->mutate(
            'POST',
            "payment-orders/{$id}/attempts",
            $idempotencyKey ?? $id . ':attempt:' . ($this->attemptCountOf($order) + 1),
            $method === null ? [] : ['payment_method' => $method->value],
        );

        return PaymentOrder::fromApi($response['data'], $response['checkout'] ?? null);
    }

    /**
     * Create the invoice and open its first attempt.
     *
     * The common path, in one call, because an order with no attempt is
     * an order nobody can pay and there is no reason to have one lying
     * around. The returned order carries `checkout` — hand it to
     * `CheckoutResponder` and the customer is on their way.
     *
     * @param array<string, mixed> $metadata
     */
    public function pay(
        string $reference,
        Money $amount,
        ?Customer $customer = null,
        ?PaymentMethodType $method = null,
        ?string $description = null,
        array $metadata = [],
    ): PaymentOrder {
        $order = $this->createOrder(
            reference: $reference,
            amount: $amount,
            customer: $customer,
            description: $description,
            metadata: $metadata,
        );

        return $this->openAttempt($order, $method);
    }

    // -----------------------------------------------------------------
    // Confirming it
    // -----------------------------------------------------------------

    /** One look at a payment, right now. */
    public function order(string|PaymentOrder $order): PaymentOrder
    {
        $response = $this->client->get('payments/' . $this->idOf($order));

        return PaymentOrder::fromApi($response['data'], $response['checkout'] ?? null);
    }

    /**
     * Wait for a payment to settle, and return what it settled as.
     *
     * ------------------------------------------------------------------
     *  THE ONLY THING THAT TELLS YOU A PAYMENT HAPPENED
     * ------------------------------------------------------------------
     *
     * Not the customer arriving at your success URL: that means they
     * finished the checkout flow, and the URL is under their control.
     * Not a gateway's word at initiation, which the platform itself
     * refuses to believe. This asks the platform, which asks the gateway.
     *
     * Polls with exponential backoff to the configured budget and returns
     * the order however it ends — captured, failed, expired, or still
     * unsettled if the budget ran out. It does NOT throw on a failed
     * payment: a declined card is an outcome, not an error, and code that
     * has to catch an exception to show "your card was declined" reads
     * worse than code that asks `$order->isPaid()`.
     *
     * In a web request, give it a short budget or none at all and do the
     * waiting in a queued job — a customer holding a spinner for ninety
     * seconds is worse than an email two minutes later.
     */
    public function confirm(string|PaymentOrder $order, ?int $timeoutSeconds = null): PaymentOrder
    {
        $config = $this->client->config();
        $deadline = microtime(true) + ($timeoutSeconds ?? $config->confirmTimeoutSeconds);
        $delayMs = $config->confirmInitialDelayMs;

        $current = $this->order($order);

        while (! $current->isSettled() && microtime(true) < $deadline) {
            usleep($delayMs * 1000);

            $delayMs = min($delayMs * 2, $config->confirmMaxDelayMs);
            $current = $this->order($current->id);
        }

        return $current;
    }

    // -----------------------------------------------------------------
    // Refunds
    // -----------------------------------------------------------------

    /**
     * Refund a payment, in whole or in part.
     *
     * Omit `$amount` for the whole captured amount. Note that full and
     * partial refunds need different scopes on your credential — a
     * credential that may undo a booking is not necessarily one that may
     * return an arbitrary sum.
     *
     * Raising a refund is a request, not a payout. Watch `state`.
     */
    public function refund(
        string|PaymentOrder $order,
        ?Money $amount = null,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): Refund {
        $id = $this->idOf($order);

        $response = $this->client->mutate(
            'POST',
            "payments/{$id}/refunds",
            $idempotencyKey ?? $id . ':refund:' . ($amount?->minorUnits ?? 'full'),
            array_filter([
                'amount' => $amount?->minorUnits,
                'reason' => $reason,
            ], static fn (mixed $v): bool => $v !== null),
        );

        return Refund::fromApi($response['data']);
    }

    public function refundStatus(string|Refund $refund): Refund
    {
        $id = $refund instanceof Refund ? $refund->id : $refund;

        return Refund::fromApi($this->client->get("refunds/{$id}")['data']);
    }

    /** @return list<Refund> */
    public function refunds(string|PaymentOrder $order): array
    {
        $response = $this->client->get('payments/' . $this->idOf($order) . '/refunds');

        return array_map(
            static fn (array $r): Refund => Refund::fromApi($r),
            is_array($response['data'] ?? null) ? $response['data'] : [],
        );
    }

    // -----------------------------------------------------------------
    // Payment links
    // -----------------------------------------------------------------

    /**
     * A page a customer can be sent, that takes a payment.
     *
     * `$maxUses` decides what kind of link this is and there is no safe
     * default, so it is required:
     *
     *   1     an invoice for one named person. `$customer` is required
     *         with it, and the three contact fields on it are used to
     *         prefill the gateway's checkout.
     *   n     a link for whoever opens it, n times. A customer is REFUSED
     *         here, because everyone who opens it is a different person
     *         and they enter their own details at payment time.
     *   null  a standing page, until you withdraw it. Same rule.
     *
     * The returned link's `url` is the payable address and is returned
     * ONLY here. Store it now if you need it.
     */
    public function createLink(
        string $title,
        ?int $maxUses,
        ?Money $amount = null,
        ?Customer $customer = null,
        ?string $description = null,
        ?string $reference = null,
        ?Money $minimumAmount = null,
        ?Money $maximumAmount = null,
        ?DateTimeInterface $expiresAt = null,
        ?string $idempotencyKey = null,
    ): PaymentLink {
        $currency = $amount?->currency ?? $minimumAmount?->currency ?? 'INR';

        if ($amount === null && $minimumAmount === null) {
            throw new PaymentsException(
                'A payment link needs either a fixed amount or a minimum and maximum the customer may '
                . 'choose between. A link with neither has no price.',
                type: 'payment_link_invalid',
            );
        }

        if ($maxUses === 1 && $customer === null) {
            throw new PaymentsException(
                'A single-use link is an invoice for one named person, so it needs their name, email '
                . 'address and mobile number: the gateway prefills its checkout from them, and the payment '
                . 'is attributed to somebody rather than to nobody. For a link anyone may use, pass a '
                . 'different max_uses and no customer.',
                type: 'payment_link_invalid',
            );
        }

        if ($maxUses !== 1 && $customer !== null) {
            throw new PaymentsException(
                'A link that can be used more than once cannot carry one customer — everyone who opens it '
                . 'is a different person, and they enter their own details when they pay. Attaching one '
                . 'here would attribute all of their payments to that one person.',
                type: 'payment_link_invalid',
            );
        }

        $response = $this->client->mutate('POST', 'payment-links', $idempotencyKey ?? ($reference ?? $title) . ':link', array_filter([
            'title' => $title,
            'description' => $description,
            'reference' => $reference,
            'currency' => $currency,
            'amount' => $amount?->minorUnits,
            'minimum_amount' => $minimumAmount?->minorUnits,
            'maximum_amount' => $maximumAmount?->minorUnits,
            // `present` on the platform side: sent even when null, because
            // null means "a standing page" and omitting it means nothing.
            'max_uses' => $maxUses,
            'customer_name' => $customer?->name,
            'customer_email' => $customer?->email,
            'customer_phone' => $customer?->phone,
            'expires_at' => $expiresAt?->format(DATE_ATOM),
        ], static fn (mixed $v, string $k): bool => $v !== null || $k === 'max_uses', ARRAY_FILTER_USE_BOTH));

        return PaymentLink::fromApi($response['data']);
    }

    /**
     * Your links, newest first.
     *
     * Note that these do NOT carry the payable url — the address is shown
     * once, when the link is created.
     *
     * @return list<PaymentLink>
     */
    public function links(?string $reference = null, ?string $status = null): array
    {
        $response = $this->client->get('payment-links', array_filter([
            'reference' => $reference,
            'status' => $status,
        ], static fn (?string $v): bool => $v !== null));

        return array_map(
            static fn (array $l): PaymentLink => PaymentLink::fromApi($l),
            is_array($response['data'] ?? null) ? $response['data'] : [],
        );
    }

    public function link(string|PaymentLink $link): PaymentLink
    {
        $id = $link instanceof PaymentLink ? $link->id : $link;

        return PaymentLink::fromApi($this->client->get("payment-links/{$id}")['data']);
    }

    /** Stop a link taking further payments. Not a delete: the record stays. */
    public function withdrawLink(string|PaymentLink $link, string $reason, ?string $idempotencyKey = null): PaymentLink
    {
        $id = $link instanceof PaymentLink ? $link->id : $link;

        $response = $this->client->mutate('DELETE', "payment-links/{$id}", $idempotencyKey ?? $id . ':withdraw', [
            'reason' => $reason,
        ]);

        return PaymentLink::fromApi($response['data']);
    }

    // -----------------------------------------------------------------
    // Reference
    // -----------------------------------------------------------------

    /**
     * The method vocabulary, for building a picker.
     *
     * Worth calling at boot and caching rather than hard-coding: a list
     * in your code goes stale silently.
     *
     * @return list<PaymentMethod>
     */
    public function paymentMethods(): array
    {
        // One call, held in a variable. The first draft asked twice — once
        // in the `is_array` guard and once for the value — which doubled
        // every consumer's reference traffic against a rate-limited API
        // and would never have shown up as anything but a limit.
        $response = $this->client->get('payment-methods');

        return array_map(
            static fn (array $m): PaymentMethod => PaymentMethod::fromApi($m),
            is_array($response['data'] ?? null) ? $response['data'] : [],
        );
    }

    /**
     * Whether this merchant's gateways can take a payment right now.
     *
     * For a status page or to explain an outage — not to choose a
     * gateway. The platform does the choosing, and an integration that
     * second-guessed it would be a second system routing payments.
     *
     * @return list<GatewayStatus>
     */
    public function gatewayStatus(): array
    {
        $response = $this->client->get('gateways/status');

        return array_map(
            static fn (array $g): GatewayStatus => GatewayStatus::fromApi($g),
            is_array($response['data'] ?? null) ? $response['data'] : [],
        );
    }

    // -----------------------------------------------------------------

    public function client(): Client
    {
        return $this->client;
    }

    private function idOf(string|PaymentOrder $order): string
    {
        return $order instanceof PaymentOrder ? $order->id : $order;
    }

    private function attemptCountOf(string|PaymentOrder $order): int
    {
        return $order instanceof PaymentOrder ? $order->attemptCount : 0;
    }
}
