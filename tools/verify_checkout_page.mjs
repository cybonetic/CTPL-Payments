/*
 * Open `ctpl-payments::checkout` in a real browser, once per checkout
 * type, and record what it actually does.
 *
 * ------------------------------------------------------------------
 *  WHY PHPUNIT CANNOT DO THIS
 * ------------------------------------------------------------------
 *
 * Both gateway CDN URLs are literals in that page and the choice is
 * made at runtime. A PHP assertion that a Razorpay checkout "does not
 * contain sdk.cashfree.com" is an assertion that can never pass — the
 * first version of `CheckoutPageTest` made exactly that mistake and
 * failed five times against a page that was working.
 *
 * What decides is which script the BROWSER fetches, so that is what is
 * recorded here: every outbound request is intercepted, the gateway
 * CDNs are stubbed with a fake `Razorpay` / `Cashfree` global (nothing
 * leaves this machine), and the page is then asked what it did.
 *
 * ------------------------------------------------------------------
 *  AND THE PAYLOADS COME FROM THE ORCHESTRATOR
 * ------------------------------------------------------------------
 *
 * They used to be written here. That is how a live defect got through:
 * the fixture had `return_url` in Razorpay's payload, because this file
 * put it there, and the page read `return_url`. The platform sends
 * Razorpay `callback_url`. So the page gave Razorpay nowhere to return
 * to, the customer paid, the modal closed, and the spinner ran forever —
 * with every check green, because they were checking this file against
 * itself.
 *
 * `tools/checkout_payload.php` in the orchestrator now produces them, as
 * `PaymentSessionResource` serialises them. A fixture nobody could have
 * got wrong, because nobody wrote it.
 *
 *   node tools/verify_checkout_page.mjs [../ctpl-app]
 */
import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import { globSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import path from 'node:path';

const ORCHESTRATOR = process.argv[2] ?? '../ctpl-app';
const OUT = 'build/checkout-screens';

const problems = [];
const note = (where, what) => {
    problems.push(`${where}: ${what}`);
    console.log(`  !! ${where}: ${what}`);
};
const ok = (what) => console.log(`  ok  ${what}`);

/*
 * The page is rendered by the package's own view, through the same
 * `CheckoutResponder::page()` an application calls — not by a copy of
 * the markup written here, which would prove only that this file agrees
 * with itself.
 */
function render(checkout) {
    return execFileSync('php', ['tools/render_checkout.php'], {
        cwd: process.cwd(),
        input: JSON.stringify(checkout),
        encoding: 'utf8',
    });
}

/**
 * The orchestrator's own adapter output, exactly as the API serialises
 * it — never a payload written here.
 */
function realCheckout(provider) {
    return JSON.parse(execFileSync('php', ['tools/checkout_payload.php', provider], {
        cwd: ORCHESTRATOR,
        encoding: 'utf8',
    }));
}

/*
 * `intent` and `qr` have no adapter producing them on this install yet,
 * so those two are still built here — and are marked as such, because a
 * hand-written fixture is a weaker check and should not be mistaken for
 * the others.
 */
function invented(overrides) {
    return { payment_session_id: 'ps_invented', token: 'tok', expires_in: 900, ...overrides };
}

function buildCases() {
    return [
        {
            name: 'razorpay',
            wants: 'checkout.razorpay.com',
            checkout: realCheckout('razorpay'),
        },
        {
            name: 'cashfree',
            wants: 'sdk.cashfree.com',
            checkout: realCheckout('cashfree'),
        },
        {
            /*
             * The one that must fetch NOTHING. Guessing a script here is
             * a broken checkout in front of somebody trying to pay.
             */
            name: 'unknown-provider',
            wants: null,
            expectStuck: true,
            checkout: invented({
                provider: 'SOMEONE_NEW',
                type: 'sdk',
                // A return URL, because every real payload has one and a
                // fixture without one cannot show whether the page
                // offers a way out.
                payload: {
                    provider: 'SOMEONE_NEW',
                    callback_url: 'https://pay.cybonetic.test/api/v1/checkout/return/tok',
                },
            }),
        },
        {
            /*
             * ------------------------------------------------------------------
             *  THE SILENT HANG
             * ------------------------------------------------------------------
             *
             * Razorpay's checkout does not refuse a missing publishable
             * key. It OPENS and sits on its own loading shield for ever
             * — no error, no callback, nothing the page can catch.
             * Verified by loading the real script with `public_key`
             * removed: the modal appears and never finishes.
             *
             * That is a payment stuck with no diagnosis, and it is the
             * shape of the report this case exists for. The page must
             * refuse before fetching the script.
             */
            name: 'razorpay-no-key',
            wants: null,
            expectStuck: true,
            stuckSays: /publishable key/i,
            checkout: (() => {
                const c = realCheckout('razorpay');
                c.public_key = null;

                return c;
            })(),
        },
        {
            /*
             * A script that never arrives, which is what a
             * content-security policy, a browser extension or an
             * unreachable CDN all look like from inside the page. None
             * of them raise anything catchable, so the only thing that
             * can save the customer is the clock.
             */
            name: 'script-never-arrives',
            wants: 'checkout.razorpay.com',
            expectStuck: true,
            stuckSays: /did not respond within/i,
            hangTheScript: true,
            checkout: realCheckout('razorpay'),
        },
        {
            /*
             * ------------------------------------------------------------------
             *  THE REPORTED STATE, EXACTLY
             * ------------------------------------------------------------------
             *
             * "When I make the payment and the razorpay returns with the
             *  success or failure response then it gets stuck at the
             *  spinner instead of returning to the checkout response
             *  that other gateways do."
             *
             * The gateway opens, leaves its container in the DOM, and
             * then tells the page nothing. The first watchdog looked for
             * a gateway element, found that container, decided all was
             * well and stayed silent — going quiet in the one situation
             * it existed for.
             *
             * The clock is faked, because the page waits five minutes
             * before giving up on a customer who might be typing a card
             * number, and a check that took five minutes is a check
             * people stop running.
             */
            name: 'gateway-opens-then-silence',
            wants: 'checkout.razorpay.com',
            expectStuck: true,
            stuckSays: /never told what happened/i,
            leaveContainer: true,
            fastForwardSeconds: [20, 400],
            checkout: realCheckout('razorpay'),
        },
        {
            name: 'qr',
            wants: null,
            checkout: invented({
                type: 'qr',
                payload: {
                    qr_image: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                    display_amount: '\u20b91,250.00',
                },
            }),
        },
        {
            name: 'intent-desktop',
            wants: null,
            checkout: invented({
                type: 'intent',
                payload: {
                    intent_url: 'upi://pay?pa=merchant@bank&am=1250.00',
                    qr_image: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                },
            }),
        },
    ];
}

(async () => {
    await mkdir(OUT, { recursive: true });

    if (!existsSync(path.join(ORCHESTRATOR, 'tools/checkout_payload.php'))) {
        console.log(`!! no orchestrator at ${ORCHESTRATOR} — the payloads come from its own adapters.`);
        console.log('   Pass its path: node tools/verify_checkout_page.mjs /path/to/ctpl-app');
        process.exit(1);
    }

    const CASES = buildCases();

    const chromeGlob = globSync('/opt/pw-browsers/chromium-*/chrome-linux/chrome').sort();
    const browser = await chromium.launch({
        ...(chromeGlob.length > 0 ? { executablePath: chromeGlob.at(-1) } : {}),
        args: ['--no-sandbox', '--disable-gpu'],
    });

    for (const testCase of CASES) {
        console.log(`\n-- ${testCase.name} --`);

        let html;

        try {
            html = render(testCase.checkout);
        } catch (e) {
            note(testCase.name, `the page would not render: ${String(e.stderr || e.message).slice(0, 300)}`);
            continue;
        }

        const context = await browser.newContext({ viewport: { width: 420, height: 780 } });
        const page = await context.newPage();

        if (testCase.fastForwardSeconds) {
            await page.clock.install();
        }

        const fetched = [];
        const jsErrors = [];
        page.on('pageerror', e => jsErrors.push(e.message));

        /*
         * ------------------------------------------------------------------
         *  ONE HANDLER, BECAUSE THE LAST ROUTE REGISTERED WINS
         * ------------------------------------------------------------------
         *
         * This was three `context.route()` calls: one for Razorpay, one
         * for Cashfree, and a catch-all. Playwright matches routes in
         * REVERSE registration order, so the catch-all won every time,
         * passed the gateway URLs through with `continue()`, and the
         * real Razorpay and Cashfree scripts were fetched over the
         * network — dragging in Sentry and Google Fonts, which is how
         * the mistake showed up at all. The stubs never ran, so every
         * assertion about what the page opened read `null` and reported
         * a working page as broken.
         *
         * One handler, no ordering to get wrong, and nothing leaves this
         * machine.
         */
        await context.route('**', route => {
            const url = route.request().url();

            if (url.startsWith('data:') || url.startsWith('about:') || url.startsWith('blob:')) {
                return route.continue();
            }

            if (url.includes('razorpay.com')) {
                fetched.push('checkout.razorpay.com');

                // Never answered, never refused — the page has to notice
                // by itself, which is the whole point of this case.
                if (testCase.hangTheScript) {
                    return new Promise(() => {});
                }

                // Opens, drops its container on the page, and then never
                // calls anything back — which is what was reported.
                if (testCase.leaveContainer) {
                    return route.fulfill({
                        contentType: 'application/javascript',
                        body: 'window.Razorpay = function (o) { window.__rzp = o; return { open: function () {'
                            + ' var d = document.createElement("div"); d.className = "razorpay-container";'
                            + ' document.body.appendChild(d); } }; };',
                    });
                }

                return route.fulfill({
                    contentType: 'application/javascript',
                    body: 'window.Razorpay = function (o) {'
                        + ' window.__rzp = o;'
                        + ' window.__opened = { who: "razorpay", options: o,'
                        + '   hasHandler: typeof o.handler === "function",'
                        + '   hasDismiss: typeof (o.modal && o.modal.ondismiss) === "function" };'
                        // A container left behind after the modal closes,
                        // exactly as the real script does — the thing that
                        // silenced the first watchdog.
                        + ' return { open: function () { window.__opened.open = true;'
                        + '   var d = document.createElement("div");'
                        + '   d.className = "razorpay-container"; document.body.appendChild(d); } }; };',
                });
            }

            if (url.includes('cashfree.com')) {
                fetched.push('sdk.cashfree.com');

                return route.fulfill({
                    contentType: 'application/javascript',
                    body: 'window.Cashfree = function (c) { window.__opened = { who: "cashfree", config: c };'
                        + ' return { checkout: function (a) { window.__opened.args = a; } }; };',
                });
            }

            fetched.push(new URL(url).host);

            return route.fulfill({ status: 204, body: '' });
        });

        /*
         * `domcontentloaded`, not `load`.
         *
         * The hanging-script case never fires `load` — that is the whole
         * point of it — and waiting for one turned a passing check into
         * a 30-second timeout that read like the page being broken.
         */
        await page.setContent(html, { waitUntil: 'domcontentloaded' });

        // The watchdog is on a 15-second clock, so the case that proves
        // it has to outlast it. Everything else settles in well under a
        // second.
        await page.waitForTimeout(testCase.hangTheScript ? 16_500 : 700);

        /*
         * TWO jumps, not one.
         *
         * The page re-arms its clock when the gateway UI is up, and a
         * timer created DURING a fast-forward is not run by that same
         * fast-forward. One jump fires the first watchdog and leaves the
         * second pending — which reads exactly like the page never
         * giving up, and cost a while to spot.
         */
        for (const seconds of testCase.fastForwardSeconds ?? []) {
            await page.clock.fastForward(seconds * 1000);
            await page.waitForTimeout(400);
        }

        const state = await page.evaluate(() => ({
            opened: window.__opened || null,
            message: document.getElementById('ctpl-message')?.textContent?.trim() ?? '',
            error: document.getElementById('ctpl-error')?.textContent?.trim() ?? '',
            errorShown: getComputedStyle(document.getElementById('ctpl-error')).display !== 'none',
            hasQr: document.querySelector('img.qr') !== null,
            hasWayOut: document.querySelector('a.btn') !== null,
            spinning: getComputedStyle(document.getElementById('ctpl-spin')).display !== 'none',
        }));

        const third = [...new Set(fetched)];

        if (testCase.wants === null) {
            if (third.length === 0) {
                ok('fetched nothing from any gateway');
            } else {
                note(testCase.name, `fetched ${third.join(', ')} when it should have fetched nothing`);
            }
        } else if (third.length === 1 && third[0] === testCase.wants) {
            ok(`fetched ${testCase.wants} and nothing else`);
        } else {
            note(testCase.name, `expected only ${testCase.wants}, fetched ${third.join(', ') || 'nothing'}`);
        }

        if (testCase.name === 'razorpay') {
            if (state.opened?.who === 'razorpay' && state.opened.open === true) {
                ok('opened the Razorpay checkout');
            } else {
                note(testCase.name, `Razorpay was never opened: ${JSON.stringify(state.opened)}`);
            }

            const o = state.opened?.options ?? {};
            const sent = testCase.checkout;

            if (o.key === sent.public_key && o.order_id === sent.payload.gateway_order_id) {
                ok('with the key and order the platform sent');
            } else {
                note(testCase.name, `opened with key=${o.key} order_id=${o.order_id}, `
                    + `platform sent key=${sent.public_key} order=${sent.payload.gateway_order_id}`);
            }

            /*
             * THE ONE THAT WAS WRONG.
             *
             * Razorpay calls it `callback_url`; Cashfree calls the same
             * thing `return_url`. The page read `return_url` for both, so
             * `redirect: true` had nothing to redirect to and a customer
             * who had paid was left on a spinner. Compared against what
             * the ORCHESTRATOR sent, under whatever name it uses.
             */
            const expected = sent.payload.callback_url ?? sent.payload.return_url;

            if (o.callback_url && o.callback_url === expected) {
                ok(`and the handback pointed at the platform (${o.callback_url})`);
            } else {
                note(testCase.name, `Razorpay was given callback_url=${o.callback_url}, `
                    + `but the platform's payload says ${expected}`);
            }

            /*
             * ------------------------------------------------------------------
             *  THE CALLBACKS ARE CALLED, NOT COUNTED
             * ------------------------------------------------------------------
             *
             * This used to assert `typeof o.handler === "function"` and
             * stop there — a check that a function EXISTS, which is not
             * a check that it works. It passed while the reported bug
             * was live: "razorpay returns with the success or failure
             * response then it gets stuck at the spinner".
             *
             * So each callback is now invoked the way Razorpay invokes
             * it, and what the page does next is recorded.
             */
            for (const [what, invoke] of [
                ['handler', `window.__rzp.handler({
                    razorpay_payment_id: 'pay_TEST1',
                    razorpay_order_id: 'order_TEST1',
                    razorpay_signature: 'sig_TEST1'
                })`],
                ['ondismiss', 'window.__rzp.modal.ondismiss()'],
            ]) {
                const fresh = await context.newPage();
                let posted = null;

                await fresh.route('**', r => {
                    const u = r.request().url();

                    if (u.includes('checkout/return')) {
                        posted = { method: r.request().method(), body: r.request().postData() ?? '' };

                        return r.fulfill({ contentType: 'text/html', body: '<title>PLATFORM</title>' });
                    }

                    if (u.includes('razorpay.com')) {
                        return r.fulfill({
                            contentType: 'application/javascript',
                            body: 'window.Razorpay = function (o) { window.__rzp = o;'
                                + ' return { open: function () {} }; };',
                        });
                    }

                    return u.startsWith('data:') ? r.continue() : r.fulfill({ status: 204, body: '' });
                });

                await fresh.setContent(html, { waitUntil: 'domcontentloaded' });
                await fresh.waitForTimeout(500);
                await fresh.evaluate(invoke).catch(() => {});
                await fresh.waitForTimeout(1200);

                if (posted === null) {
                    note(testCase.name, `Razorpay's ${what} left the customer on this page`);
                } else if (posted.method !== 'POST') {
                    note(testCase.name, `${what} handed back with ${posted.method}; the signature needs a POST`);
                } else {
                    ok(`${what} hands back to the platform with a POST`);

                    if (what === 'handler' && posted.body.includes('razorpay_signature')) {
                        ok('carrying the signature, so the platform can verify it');
                    } else if (what === 'handler') {
                        note(testCase.name, `the handback carried no signature: ${posted.body.slice(0, 80)}`);
                    }
                }

                await fresh.close();
            }
        }

        if (testCase.name === 'cashfree') {
            if (state.opened?.who === 'cashfree'
                && state.opened.args?.paymentSessionId === testCase.checkout.payload.payment_session_id) {
                ok('opened Cashfree with the session id it was given');
            } else {
                note(testCase.name, `Cashfree was not opened correctly: ${JSON.stringify(state.opened)}`);
            }

            if (state.opened?.config?.mode === testCase.checkout.payload.environment) {
                ok('in the mode the platform named');
            } else {
                note(testCase.name, `opened in mode ${state.opened?.config?.mode}`);
            }
        }

        if (testCase.expectStuck) {
            const says = testCase.stuckSays ?? /does not know how to open/;

            if (state.errorShown && says.test(state.error)) {
                ok(`said so plainly: "${state.error.slice(0, 70)}…"`);
            } else {
                note(testCase.name, `showed "${state.message}" / "${state.error}"`);
            }

            // A dead end is not an answer. The way on goes to the
            // platform, which looks the payment up server-side.
            if (state.hasWayOut) {
                ok('and offered a way back');
            } else {
                note(testCase.name, 'left the customer with nowhere to go');
            }

            // Never a claim about money, on any failure path.
            if (/No money has been taken/.test(state.error)) {
                ok('and said no money had been taken');
            } else {
                note(testCase.name, 'a handoff failure did not say that no money had been taken');
            }
        }

        if (testCase.name === 'qr' || testCase.name === 'intent-desktop') {
            if (state.hasQr) ok('rendered a code to scan');
            else note(testCase.name, 'no QR image was rendered');

            if (!state.spinning) ok('and stopped pretending something was loading');
            else note(testCase.name, 'the spinner is still running on a page that is waiting for a human');
        }

        if (jsErrors.length > 0) {
            note(testCase.name, `JavaScript errors: ${jsErrors.join('; ')}`);
        } else {
            ok('no JavaScript errors');
        }

        await page.screenshot({ path: path.join(OUT, `${testCase.name}.png`), fullPage: true });
        await writeFile(path.join(OUT, `${testCase.name}.html`), html);
        await context.close();
    }

    await browser.close();

    console.log(`\nScreens and markup in ${OUT}`);

    if (problems.length > 0) {
        console.log(`\n${problems.length} problem(s):`);
        for (const p of problems) console.log(`  - ${p}`);
        process.exit(1);
    }

    console.log('\nEvery checkout type opens the gateway it was routed to.');
})();
