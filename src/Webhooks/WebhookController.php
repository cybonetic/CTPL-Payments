<?php

declare(strict_types=1);

namespace Ctpl\Payments\Webhooks;

use Ctpl\Payments\Webhooks\Events as E;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * The endpoint the platform POSTs events to.
 *
 * ------------------------------------------------------------------
 *  WHAT IT ANSWERS, AND WHY THAT MATTERS
 * ------------------------------------------------------------------
 *
 * A `2xx` means "I have this and will not need it again". The platform
 * retries anything else with backoff, which is correct and is also why
 * this must not answer `200` to an event it failed to handle — a lost
 * payment notification is worse than a duplicate one.
 *
 * So: a bad signature is `400` and is never retried usefully anyway; a
 * listener that throws becomes a `500` so the platform tries again; and
 * everything else is `200`.
 *
 * ------------------------------------------------------------------
 *  DUPLICATES ARE NORMAL, NOT AN ERROR
 * ------------------------------------------------------------------
 *
 * The platform retries deliveries it is not sure arrived, so the same
 * event WILL arrive twice — that is at-least-once delivery working as
 * designed, not a bug to report. This remembers event ids for a day and
 * answers `200` to a repeat without dispatching again.
 *
 * That is a convenience and NOT a guarantee: the cache can be cold, two
 * workers can race, and the window can be exceeded. Your listeners must
 * still be idempotent. Do the work against the payment order id, not
 * against "I received an event".
 */
final class WebhookController
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly ?CacheFactory $cache = null,
        private readonly ?LoggerInterface $log = null,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('ctpl-payments.webhooks', []);

        $secret = is_string($config['secret'] ?? null) ? $config['secret'] : '';

        if ($secret === '') {
            /*
             * Refused, not waved through.
             *
             * An endpoint that accepted unsigned events because it had no
             * secret configured is an endpoint anyone can post payment
             * notifications to, and the failure mode is silent: everything
             * works, including for the attacker. A 500 is the right answer
             * — it is the receiving application that is misconfigured, and
             * the platform will keep retrying until somebody fixes it.
             */
            $this->log?->error('ctpl-payments: a webhook arrived and no signing secret is configured. '
                . 'Set CTPL_PAYMENTS_WEBHOOK_SECRET. Events are not accepted unsigned: an unsigned payment '
                . 'event cannot be told from a forged one.');

            return new JsonResponse(['error' => 'webhook secret not configured'], 500);
        }

        // The RAW body, before anything decodes it. Re-encoding a decoded
        // array changes key order and number formatting, and the
        // signature stops matching.
        $raw = $request->getContent();

        $verdict = (new SignatureVerifier($secret, (int) ($config['tolerance_seconds'] ?? 300)))->verify(
            $raw,
            $request->header(SignatureVerifier::SIGNATURE_HEADER),
            $request->header(SignatureVerifier::TIMESTAMP_HEADER),
        );

        if ($verdict !== true) {
            $this->log?->warning('ctpl-payments: refused a webhook — ' . $verdict, [
                'event_id' => $request->header(SignatureVerifier::EVENT_ID_HEADER),
                'event_type' => $request->header(SignatureVerifier::EVENT_TYPE_HEADER),
            ]);

            // 400, not 401: there is nothing to authenticate against and
            // nothing for the sender to retry differently.
            return new JsonResponse(['error' => 'signature verification failed'], 400);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return new JsonResponse(['error' => 'body is not a JSON object'], 400);
        }

        $event = PlatformEvent::fromPayload($payload);

        if ($this->alreadySeen($event, $config)) {
            // 200: we have it. Saying anything else asks for it again.
            return new JsonResponse(['status' => 'duplicate', 'event_id' => $event->id]);
        }

        /*
         * Dispatched synchronously, and a throwing listener is allowed out.
         *
         * Catching here would turn a listener that failed into a `200`,
         * and a `200` tells the platform to stop retrying — so the event
         * would be lost, quietly, by the code that was trying to be
         * careful. Let it 500 and let the platform bring it back.
         *
         * Queue your listeners (`ShouldQueue`) if the work is slow. That
         * changes the guarantee — the platform then knows only that the
         * job was accepted — which is the trade to make deliberately.
         */
        $this->events->dispatch(new E\PaymentEventReceived($event));

        $typed = $this->typedEvent($event);

        if ($typed !== null) {
            $this->events->dispatch($typed);
        }

        return new JsonResponse(['status' => 'received', 'event_id' => $event->id]);
    }

    private function typedEvent(PlatformEvent $event): ?object
    {
        return match ($event->type) {
            'payment.captured' => new E\PaymentCaptured($event),
            'payment.failed' => new E\PaymentFailed($event),
            'payment.expired' => new E\PaymentExpired($event),
            'payment.pending' => new E\PaymentPending($event),
            'payment.unknown' => new E\PaymentUnknown($event),
            'payment.disputed' => new E\PaymentDisputed($event),
            'refund.completed' => new E\RefundCompleted($event),
            'refund.failed' => new E\RefundFailed($event),

            // A type this package predates still reaches
            // PaymentEventReceived, which is why that one exists.
            default => null,
        };
    }

    /** @param array<string, mixed> $config */
    private function alreadySeen(PlatformEvent $event, array $config): bool
    {
        if ($event->id === '' || $this->cache === null) {
            return false;
        }

        $store = $this->cache->store(
            is_string($config['dedupe_store'] ?? null) && $config['dedupe_store'] !== ''
                ? $config['dedupe_store']
                : null,
        );

        // `add` rather than `has` then `put`: atomic where the store
        // supports it, so two workers handed the same retry do not both
        // decide they are the first.
        return ! $store->add(
            'ctpl-payments:event:' . $event->id,
            true,
            (int) ($config['dedupe_ttl_seconds'] ?? 86400),
        );
    }
}
