<?php

declare(strict_types=1);

namespace Ctpl\Payments\Console;

use Ctpl\Payments\Exceptions\PaymentsException;
use Ctpl\Payments\PaymentsManager;
use Illuminate\Console\Command;

/**
 * Is this application configured to take payments?
 *
 * The first thing to run after adding the package, and the first thing to
 * run when something is wrong — it separates "the credentials are wrong"
 * from "the platform is down" from "this application cannot reach it",
 * which from inside a failing checkout all look identical.
 */
final class PingCommand extends Command
{
    protected $signature = 'ctpl:ping';

    protected $description = 'Check that this application can reach the payment orchestrator and authenticate.';

    public function handle(PaymentsManager $payments): int
    {
        // The constant, not a setting — there is no setting. Printed
        // because "which host am I even talking to" is the first thing
        // somebody wants confirmed when a payment will not go through.
        $this->line('Reaching ' . \Ctpl\Payments\Client\Config::PLATFORM_URL . ' …');

        try {
            // Two calls on purpose. The first proves the credential can
            // get a token; the second proves the token carries a scope
            // and the platform will answer a real question with it. A
            // credential that authenticates and is scoped to nothing is a
            // credential that fails on its first payment instead.
            $methods = $payments->paymentMethods();
            $gateways = $payments->gatewayStatus();
        } catch (PaymentsException $e) {
            $this->components->error($e->getMessage());

            if ($e->correlationId !== null) {
                $this->line('  correlation id: ' . $e->correlationId);
                $this->line('  Quote that to the platform team; it is what they search by.');
            }

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Authenticated. %d payment method(s) available.',
            count($methods),
        ));

        $accepting = array_filter($gateways, static fn ($g): bool => $g->acceptingPayments);

        $this->components->twoColumnDetail(
            'Gateways accepting payments',
            sprintf('%d of %d', count($accepting), count($gateways)),
        );

        foreach ($gateways as $gateway) {
            $this->components->twoColumnDetail(
                '  ' . $gateway->provider . ' · ' . $gateway->environment,
                $gateway->acceptingPayments ? '<fg=green>yes</>' : '<fg=yellow>no — ' . ($gateway->reason ?? 'unknown') . '</>',
            );
        }

        if ($accepting === []) {
            $this->newLine();
            $this->components->warn(
                'No gateway is accepting payments for this merchant right now, so an attempt would be '
                . 'refused with no_eligible_gateway. That is the platform\'s to resolve, not yours.',
            );
        }

        return self::SUCCESS;
    }
}
