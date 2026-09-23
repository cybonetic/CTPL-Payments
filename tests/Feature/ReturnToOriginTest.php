<?php

declare(strict_types=1);

namespace Ctpl\Payments\Tests\Feature;

use Ctpl\Payments\Data\Money;
use Ctpl\Payments\PaymentsManager;
use Ctpl\Payments\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Sending the platform the page the payment started on.
 *
 * ------------------------------------------------------------------
 *  WHY THE SDK HAS TO SAY IT
 * ------------------------------------------------------------------
 *
 * This package runs on the application's own server, so the `Origin`
 * of the call it makes to the platform is that server — not the
 * customer's browser. The platform therefore cannot work out which page
 * a payment came from; only the code rendering that page can, and this
 * is where it says so.
 */
final class ReturnToOriginTest extends TestCase
{
    private function stubOrder(): void
    {
        Http::fake([
            '*/auth/token' => Http::response($this->tokenResponse()),
            '*/payment-orders' => Http::response([
                'data' => [
                    'payment_order_id' => 'po_1', 'external_reference' => 'R-1', 'status' => 'CREATED',
                    'amount' => 125000, 'currency' => 'INR', 'amount_paid' => 0, 'amount_refunded' => 0,
                ],
            ], 201),
        ]);
    }

    private function onPage(string $url): void
    {
        $this->app->instance('request', Request::create($url, 'GET'));
    }

    private function sentReturnUrl(): mixed
    {
        $body = null;

        foreach (Http::recorded() as [$request]) {
            // The CREATE call, not the attempt that follows it — both
            // URLs contain "payment-orders", and matching loosely would
            // read the attempt's empty body and report "nothing sent"
            // for a request that sent it.
            if (str_ends_with($request->url(), '/payment-orders')) {
                $body = $request->data();
            }
        }

        return $body['return_url'] ?? null;
    }

    // -----------------------------------------------------------------

    #[Test]
    public function it_sends_nothing_when_the_setting_is_off(): void
    {
        config()->set('ctpl-payments.return_to_origin', false);
        $this->stubOrder();
        $this->onPage('https://shop.example.com/invoices/42');

        $this->app->make(PaymentsManager::class)->createOrder('R-1', Money::minor(125000));

        $this->assertNull($this->sentReturnUrl(), 'a return URL was sent with the setting off');
    }

    #[Test]
    public function it_sends_the_current_page_when_the_setting_is_on(): void
    {
        config()->set('ctpl-payments.return_to_origin', true);
        $this->stubOrder();
        $this->onPage('https://shop.example.com/invoices/42?from=email');

        $this->app->make(PaymentsManager::class)->createOrder('R-1', Money::minor(125000));

        $this->assertSame('https://shop.example.com/invoices/42?from=email', $this->sentReturnUrl());
    }

    /**
     * Plain http is never sent.
     *
     * The URL comes back carrying a payment order id and is followed by
     * a browser belonging to somebody who has just paid. There is no
     * version of that worth doing over http, and a local development
     * URL silently becoming the destination of a production payment is
     * the specific accident this prevents.
     */
    #[Test]
    public function a_plain_http_page_is_not_sent(): void
    {
        config()->set('ctpl-payments.return_to_origin', true);
        $this->stubOrder();
        $this->onPage('http://localhost:8000/invoices/42');

        $this->app->make(PaymentsManager::class)->createOrder('R-1', Money::minor(125000));

        $this->assertNull($this->sentReturnUrl());
    }

    /** An explicit URL always wins — a modal, a queued job, a retry. */
    #[Test]
    public function an_explicit_return_url_overrides_the_setting(): void
    {
        config()->set('ctpl-payments.return_to_origin', true);
        $this->stubOrder();
        $this->onPage('https://shop.example.com/invoices/42');

        $this->app->make(PaymentsManager::class)->createOrder(
            'R-1',
            Money::minor(125000),
            returnUrl: 'https://shop.example.com/thanks',
        );

        $this->assertSame('https://shop.example.com/thanks', $this->sentReturnUrl());
    }

    /** And an explicit URL works whether or not the setting is on. */
    #[Test]
    public function an_explicit_return_url_needs_no_setting(): void
    {
        config()->set('ctpl-payments.return_to_origin', false);
        $this->stubOrder();
        $this->onPage('https://shop.example.com/invoices/42');

        $this->app->make(PaymentsManager::class)->createOrder(
            'R-1',
            Money::minor(125000),
            returnUrl: 'https://shop.example.com/thanks',
        );

        $this->assertSame('https://shop.example.com/thanks', $this->sentReturnUrl());
    }

    /** `pay()` carries it too, since that is the call most people make. */
    #[Test]
    public function pay_carries_the_origin_as_well(): void
    {
        config()->set('ctpl-payments.return_to_origin', true);

        Http::fake([
            '*/auth/token' => Http::response($this->tokenResponse()),
            '*/payment-orders/*/attempts' => Http::response([
                'data' => [
                    'payment_order_id' => 'po_1', 'external_reference' => 'R-1', 'status' => 'INITIATED',
                    'amount' => 125000, 'currency' => 'INR', 'amount_paid' => 0, 'amount_refunded' => 0,
                ],
            ], 201),
            '*/payment-orders' => Http::response([
                'data' => [
                    'payment_order_id' => 'po_1', 'external_reference' => 'R-1', 'status' => 'CREATED',
                    'amount' => 125000, 'currency' => 'INR', 'amount_paid' => 0, 'amount_refunded' => 0,
                ],
            ], 201),
        ]);

        $this->onPage('https://shop.example.com/rooms/12/checkout');

        $this->app->make(PaymentsManager::class)->pay('R-1', Money::minor(125000));

        $this->assertSame('https://shop.example.com/rooms/12/checkout', $this->sentReturnUrl());
    }
}
