<?php

declare(strict_types=1);

namespace Ctpl\Payments\Tests\Feature;

use Ctpl\Payments\Tests\TestCase;
use Ctpl\Payments\Webhooks\Events as E;
use Ctpl\Payments\Webhooks\SignatureVerifier;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Receiving events, and refusing the ones that are not events.
 */
final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    /**
     * Read by `defineEnvironment`, which testbench re-runs on every
     * `refreshApplication()`. Setting the config directly and then
     * refreshing does not work — the refresh calls this again and puts
     * the original value back, which is how the "route is off" test below
     * first passed against a route that was very much on.
     */
    protected bool $webhooksEnabled = true;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ctpl-payments.webhooks.enabled', $this->webhooksEnabled);
        $app['config']->set('ctpl-payments.webhooks.secret', self::SECRET);
        $app['config']->set('cache.default', 'array');
    }

    /** @param array<string, mixed> $payload */
    private function deliver(array $payload, ?string $secret = null, ?int $timestamp = null): \Illuminate\Testing\TestResponse
    {
        // The RAW body, signed exactly as sent. Re-encoding a decoded
        // array changes key order and the signature stops matching —
        // which is the commonest reason a first webhook fails.
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $ts = (string) ($timestamp ?? time());

        return $this->call(
            'POST',
            '/ctpl/payments/events',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(SignatureVerifier::SIGNATURE_HEADER))
                    => 'v1=' . SignatureVerifier::sign($ts, $body, $secret ?? self::SECRET),
                'HTTP_' . str_replace('-', '_', strtoupper(SignatureVerifier::TIMESTAMP_HEADER)) => $ts,
            ],
            $body,
        );
    }

    /** @return array<string, mixed> */
    private function capturedEvent(string $id = 'evt_1'): array
    {
        return [
            'event' => 'payment.captured',
            'event_id' => $id,
            'event_version' => 1,
            'occurred_at' => '2026-09-22T10:00:00+00:00',
            'source' => 'payment-orchestrator',
            'application' => 'CLINIC',
            'payment_order_id' => 'po_1',
            'payment_attempt_id' => 'pa_1',
            'external_reference' => 'BOOK-4102',
            'amount' => 125000,
            'currency' => 'INR',
        ];
    }

    #[Test]
    public function a_signed_event_is_accepted_and_dispatched(): void
    {
        Event::fake([E\PaymentEventReceived::class, E\PaymentCaptured::class]);

        $this->deliver($this->capturedEvent())->assertOk()->assertJson(['status' => 'received']);

        // Both: the typed one for a focused listener, the generic one so
        // an event type this package predates still reaches somebody.
        Event::assertDispatched(E\PaymentCaptured::class, function (E\PaymentCaptured $e): bool {
            return $e->event->paymentOrderId() === 'po_1'
                && $e->event->externalReference() === 'BOOK-4102'
                && $e->event->amount()?->minorUnits === 125000;
        });

        Event::assertDispatched(E\PaymentEventReceived::class);
    }

    #[Test]
    public function a_forged_signature_is_refused_and_nothing_is_dispatched(): void
    {
        Event::fake();

        $this->deliver($this->capturedEvent(), secret: 'not-the-secret')
            ->assertStatus(400)
            ->assertJson(['error' => 'signature verification failed']);

        Event::assertNotDispatched(E\PaymentCaptured::class);
        Event::assertNotDispatched(E\PaymentEventReceived::class);
    }

    /**
     * A signature is valid for ever. Only the timestamp bounds a replay.
     */
    #[Test]
    public function a_correctly_signed_but_old_delivery_is_refused(): void
    {
        Event::fake();

        $this->deliver($this->capturedEvent(), timestamp: time() - 3600)->assertStatus(400);

        Event::assertNotDispatched(E\PaymentEventReceived::class);
    }

    #[Test]
    public function an_unsigned_request_is_refused(): void
    {
        Event::fake();

        $this->postJson('/ctpl/payments/events', $this->capturedEvent())->assertStatus(400);

        Event::assertNotDispatched(E\PaymentEventReceived::class);
    }

    /**
     * At-least-once delivery is the platform working as designed, not a
     * fault. The second copy is acknowledged and not dispatched again.
     */
    #[Test]
    public function the_same_event_delivered_twice_is_handled_once(): void
    {
        Event::fake([E\PaymentCaptured::class]);

        $this->deliver($this->capturedEvent('evt_dup'))->assertOk()->assertJson(['status' => 'received']);
        $this->deliver($this->capturedEvent('evt_dup'))->assertOk()->assertJson(['status' => 'duplicate']);

        Event::assertDispatchedTimes(E\PaymentCaptured::class, 1);
    }

    /**
     * An endpoint with no secret must refuse, not wave events through.
     *
     * Accepting unsigned events because none is configured is an endpoint
     * anyone can post payment notifications to, and everything keeps
     * working — including for the attacker.
     */
    #[Test]
    public function an_endpoint_with_no_secret_refuses_rather_than_trusting(): void
    {
        config()->set('ctpl-payments.webhooks.secret', null);
        Event::fake();

        $this->deliver($this->capturedEvent())->assertStatus(500);

        Event::assertNotDispatched(E\PaymentEventReceived::class);
    }

    /**
     * A listener that throws must NOT become a 200.
     *
     * A 200 tells the platform to stop retrying, so an event the
     * application failed to handle would be lost by the code that was
     * trying to be careful about it.
     */
    #[Test]
    public function a_failing_listener_is_not_acknowledged(): void
    {
        $this->withoutExceptionHandling();

        Event::listen(E\PaymentCaptured::class, function (): void {
            throw new \RuntimeException('my listener broke');
        });

        $this->expectException(\RuntimeException::class);

        $this->deliver($this->capturedEvent('evt_throw'));
    }

    #[Test]
    public function the_route_does_not_exist_until_it_is_switched_on(): void
    {
        // Re-booted with the flag off: the route is registered at boot,
        // so turning the config off afterwards cannot unregister it.
        $this->webhooksEnabled = false;
        $this->refreshApplication();

        $this->postJson('/ctpl/payments/events', [])->assertStatus(404);
    }
}
