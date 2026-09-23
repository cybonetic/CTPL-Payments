#!/usr/bin/env bash
#
# Prove this package works against a real orchestrator.
#
# ------------------------------------------------------------------
#  WHAT THIS RUNS THAT THE DEFAULT SUITE DOES NOT
# ------------------------------------------------------------------
#
# The default suite runs against Http::fake(), which answers with bodies
# this package wrote. It proves the SDK is consistent with itself. This
# proves it is consistent with the PLATFORM — a renamed field, a 201
# where the SDK expected a 200, a `checkout` block that moved, all pass a
# faked suite and fail on the first real payment.
#
# It needs an orchestrator running with a sandbox gateway, and it runs
# the platform's confirmation sweep in the background while the test
# waits: capture is confirmed by a server-side status query on a schedule
# (Rule R12), and on a dev box nothing is running cron — without it the
# SDK polls a payment nothing will ever settle.
#
#   tools/verify_sdk.sh [orchestrator-path] [base-url]
#
set -euo pipefail

ORCHESTRATOR="${1:-../ctpl-app}"
BASE="${2:-http://127.0.0.1:8000}"

cd "$(dirname "$0")/.."
PACKAGE="$PWD"

echo "== the unit and feature suites =="
vendor/bin/phpunit --testsuite "unit,feature"

if [ ! -d "$ORCHESTRATOR" ]; then
    echo
    echo "!! no orchestrator at $ORCHESTRATOR — the integration suite needs one."
    echo "   Pass its path: tools/verify_sdk.sh /path/to/ctpl-app"
    exit 1
fi

if ! curl -fsS -o /dev/null "$BASE/login" 2>/dev/null; then
    echo
    echo "!! nothing answering at $BASE — start it with: php artisan serve"
    exit 1
fi

echo
echo "== issuing a credential on the install under test =="
CREDS="$(cd "$ORCHESTRATOR" && php tools/seed_api_client.php)"

# NOT CTPL_PAYMENTS_URL — there is no such setting. The platform URL
# is a constant in the package; this override is read only when the
# app environment is local or testing, which testbench is.
export CTPL_PAYMENTS_BASE_URL_OVERRIDE="$BASE"
export CTPL_PAYMENTS_CLIENT_ID="$(echo "$CREDS" | sed -n 's/^client_id=//p')"
export CTPL_PAYMENTS_CLIENT_SECRET="$(echo "$CREDS" | sed -n 's/^client_secret=//p')"
export CTPL_TEST_AMOUNT="$(echo "$CREDS" | sed -n 's/^amount=//p')"
# So the signature cross-check can run the PLATFORM's own signer
# rather than this package agreeing with itself.
export CTPL_ORCHESTRATOR_PATH="$(cd "$ORCHESTRATOR" && pwd)"

echo "   client: $CTPL_PAYMENTS_CLIENT_ID"
echo "   amount: $CTPL_TEST_AMOUNT (must match what the sandbox status query reports)"

# The scheduled sweep, on a loop, for the length of the run. In
# production this is cron, every two minutes.
( cd "$ORCHESTRATOR" && while true; do
    php artisan payments:confirm-pending --older-than=0 --limit=20 >/dev/null 2>&1 || true
    sleep 2
  done ) &
SWEEP=$!
trap 'kill "$SWEEP" 2>/dev/null || true' EXIT

# The token is cached in a shared store so the run does not exhaust the
# token endpoint's per-IP limit. That also means a token issued to the
# PREVIOUS run's credential would be reused by this one — still valid,
# still authenticating, and nothing to do with the credential just
# issued. Cleared, so each run proves its own credentials work.
rm -rf "$PACKAGE"/vendor/orchestra/testbench-core/laravel/storage/framework/cache/data/* 2>/dev/null || true

echo
echo "== the integration suite, against $BASE =="
cd "$PACKAGE"
vendor/bin/phpunit --testsuite integration

echo
echo "the SDK works against this install"
