<?php

declare(strict_types=1);

namespace Ctpl\Payments\Tests\Integration;

use Ctpl\Payments\Tests\TestCase;
use Ctpl\Payments\Webhooks\SignatureVerifier;
use PHPUnit\Framework\Attributes\Test;

/**
 * The webhook signature, checked against the PLATFORM's own signer.
 *
 * ------------------------------------------------------------------
 *  THE FEATURE TEST FOR THIS PROVES NOTHING ON ITS OWN
 * ------------------------------------------------------------------
 *
 * `tests/Feature/WebhookTest` signs its deliveries with
 * `SignatureVerifier::sign()` and then verifies them with
 * `SignatureVerifier::verify()` — the same class, agreeing with itself.
 * If the scheme were wrong in any way at all (the wrong separator, the
 * wrong order, the timestamp in milliseconds) both sides would be wrong
 * identically and every test would pass, right up until the first real
 * delivery from the platform was refused.
 *
 * So this computes the signature by running the ORCHESTRATOR's own
 * `EventSigner`, in its own process, and verifies THAT with the SDK. The
 * two implementations have to agree for this to pass, which is the only
 * form of the check worth having.
 *
 * Needs `CTPL_ORCHESTRATOR_PATH`. Skipped without it — an SDK consumer
 * does not have the platform's source, and should not need it.
 */
final class SignatureAgreesWithPlatformTest extends TestCase
{
    private const SECRET = 'whsec_cross_check_secret';

    private function orchestrator(): string
    {
        $path = (string) getenv('CTPL_ORCHESTRATOR_PATH');

        if ($path === '' || ! is_dir($path)) {
            $this->markTestSkipped('CTPL_ORCHESTRATOR_PATH is not set; run this through tools/verify_sdk.sh');
        }

        return $path;
    }

    /** Ask the platform's own signer, in the platform's own process. */
    private function platformSignature(string $timestamp, string $body): string
    {
        $script = sprintf(
            'require "vendor/autoload.php"; echo \Modules\Events\Services\EventSigner::sign(%s, %s, %s);',
            var_export($timestamp, true),
            var_export($body, true),
            var_export(self::SECRET, true),
        );

        $output = [];
        $status = 0;

        exec(sprintf(
            'cd %s && php -r %s 2>&1',
            escapeshellarg($this->orchestrator()),
            escapeshellarg($script),
        ), $output, $status);

        $this->assertSame(0, $status, 'could not run the platform signer: ' . implode("\n", $output));

        return trim(implode('', $output));
    }

    #[Test]
    public function the_sdk_accepts_a_signature_the_platform_produced(): void
    {
        $body = json_encode([
            'event' => 'payment.captured',
            'event_id' => 'evt_cross_1',
            'event_version' => 1,
            'occurred_at' => '2026-09-22T10:00:00+00:00',
            'source' => 'payment-orchestrator',
            'payment_order_id' => 'po_1',
            'amount' => 125000,
            'currency' => 'INR',
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) time();
        $theirs = $this->platformSignature($timestamp, $body);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $theirs, 'that is not a sha256 hex digest');

        // The SDK's own idea of the signature, for the comparison that
        // makes a failure readable.
        $this->assertSame(
            $theirs,
            SignatureVerifier::sign($timestamp, $body, self::SECRET),
            'the SDK and the platform compute different signatures for the same delivery',
        );

        $verdict = (new SignatureVerifier(self::SECRET))->verify($body, 'v1=' . $theirs, $timestamp);

        $this->assertTrue($verdict, is_string($verdict) ? $verdict : '');
    }

    #[Test]
    public function a_platform_signature_over_a_different_body_is_refused(): void
    {
        $timestamp = (string) time();
        $theirs = $this->platformSignature($timestamp, '{"event":"payment.captured"}');

        $verdict = (new SignatureVerifier(self::SECRET))->verify(
            '{"event":"payment.captured","amount":999999}',
            'v1=' . $theirs,
            $timestamp,
        );

        $this->assertSame('signature does not match', $verdict);
    }
}
