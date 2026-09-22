<?php

declare(strict_types=1);

namespace Ctpl\Payments\Webhooks;

/**
 * Verify an event came from the platform, before anything reads it.
 *
 * ------------------------------------------------------------------
 *  THE ORDER OF OPERATIONS IS THE SECURITY PROPERTY
 * ------------------------------------------------------------------
 *
 * Verify, then parse. Never parse, then verify. A handler that decodes
 * the body to find the event type and only then checks the signature has
 * already run a JSON parser over an attacker's bytes and, worse, tends to
 * grow a branch that acts on what it found.
 *
 * The scheme, as the platform implements it:
 *
 *     signature = HMAC-SHA256(timestamp + "." + rawBody, secret)
 *     header    = X-CTPL-Signature: v1=<hex>
 *     timestamp = X-CTPL-Timestamp: <unix seconds>
 *
 * Three things are checked and each one matters:
 *
 *   the signature, with `hash_equals`, because `===` on a hex string
 *   leaks its answer through timing;
 *
 *   the timestamp, because a signature is valid for ever — a request
 *   captured today replays perfectly tomorrow unless somebody bounds it;
 *
 *   the RAW body, exactly as received. Re-encoding a decoded array
 *   changes key order and number formatting and the signature stops
 *   matching, which is the commonest reason an integrator's first
 *   webhook fails.
 */
final class SignatureVerifier
{
    public const SIGNATURE_HEADER = 'X-CTPL-Signature';

    public const TIMESTAMP_HEADER = 'X-CTPL-Timestamp';

    public const EVENT_ID_HEADER = 'X-CTPL-Event-Id';

    public const EVENT_TYPE_HEADER = 'X-CTPL-Event-Type';

    public const VERSION_HEADER = 'X-CTPL-Event-Version';

    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {
    }

    /** What the platform computed, so a test can produce a real delivery. */
    public static function sign(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * @return true|string `true`, or the reason it was refused.
     *
     * A string rather than an exception because the caller answers 400
     * either way and wants the reason in a log line; and because a
     * verifier that throws invites a `catch (\Throwable)` that swallows
     * it.
     */
    public function verify(string $rawBody, ?string $signature, ?string $timestamp, ?int $now = null): true|string
    {
        if ($signature === null || $signature === '') {
            return 'no signature header';
        }

        if ($timestamp === null || ! ctype_digit($timestamp)) {
            return 'no usable timestamp header';
        }

        $age = abs(($now ?? time()) - (int) $timestamp);

        if ($age > $this->toleranceSeconds) {
            return sprintf(
                'timestamp is %d seconds out, tolerance is %d — a signature is valid for ever, so an old '
                . 'delivery is a replay unless something bounds it',
                $age,
                $this->toleranceSeconds,
            );
        }

        // `v1=` is a version prefix, so the scheme can change without
        // every consumer having to be updated on the same day. An
        // unprefixed signature is accepted too: some HTTP clients and
        // proxies strip what looks like a parameter.
        $provided = str_starts_with($signature, 'v1=') ? substr($signature, 3) : $signature;

        $expected = self::sign($timestamp, $rawBody, $this->secret);

        if (! hash_equals($expected, $provided)) {
            return 'signature does not match';
        }

        return true;
    }
}
