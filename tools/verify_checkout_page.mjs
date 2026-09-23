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
 * Run after `composer install`:
 *
 *   node tools/verify_checkout_page.mjs
 */
import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import { globSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

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

const CASES = [
    {
        name: 'razorpay',
        wants: 'checkout.razorpay.com',
        checkout: {
            payment_session_id: 'ps_1', type: 'sdk', token: 'tok', public_key: 'rzp_test_1DP5mm',
            payload: {
                provider: 'RAZORPAY', gateway_order_id: 'order_Nq8xLm', amount: 125000,
                currency: 'INR', return_url: 'https://pay.cybonetic.com/api/v1/checkout/return/tok',
                display_amount: '₹1,250.00',
            },
        },
    },
    {
        name: 'cashfree',
        wants: 'sdk.cashfree.com',
        checkout: {
            payment_session_id: 'ps_2', type: 'sdk', token: 'tok', public_key: null,
            payload: {
                provider: 'CASHFREE', payment_session_id: 'session_xYz123',
                environment: 'sandbox', amount: 125000, currency: 'INR',
            },
        },
    },
    {
        /*
         * The one that must fetch NOTHING. Guessing a script here is a
         * broken checkout in front of somebody trying to pay.
         */
        name: 'unknown-provider',
        wants: null,
        expectStuck: true,
        checkout: {
            payment_session_id: 'ps_3', type: 'sdk', token: 'tok',
            payload: { provider: 'SOMEONE_NEW' },
        },
    },
    {
        name: 'qr',
        wants: null,
        checkout: {
            payment_session_id: 'ps_4', type: 'qr', token: 'tok',
            payload: {
                qr_image: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                display_amount: '₹1,250.00',
            },
        },
    },
    {
        name: 'intent-desktop',
        wants: null,
        checkout: {
            payment_session_id: 'ps_5', type: 'intent', token: 'tok',
            payload: {
                intent_url: 'upi://pay?pa=merchant@bank&am=1250.00',
                qr_image: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            },
        },
    },
];

(async () => {
    await mkdir(OUT, { recursive: true });

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

                return route.fulfill({
                    contentType: 'application/javascript',
                    body: 'window.Razorpay = function (o) { window.__opened = { who: "razorpay", options: o };'
                        + ' return { open: function () { window.__opened.open = true; } }; };',
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

        await page.setContent(html, { waitUntil: 'load' });
        await page.waitForTimeout(700);

        const state = await page.evaluate(() => ({
            opened: window.__opened || null,
            message: document.getElementById('ctpl-message')?.textContent?.trim() ?? '',
            error: document.getElementById('ctpl-error')?.textContent?.trim() ?? '',
            errorShown: getComputedStyle(document.getElementById('ctpl-error')).display !== 'none',
            hasQr: document.querySelector('img.qr') !== null,
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
            if (o.key === 'rzp_test_1DP5mm' && o.order_id === 'order_Nq8xLm') {
                ok('with the key and order the platform sent');
            } else {
                note(testCase.name, `opened with ${JSON.stringify(o)}`);
            }

            if (o.callback_url === 'https://pay.cybonetic.com/api/v1/checkout/return/tok') {
                ok('and the handback pointed at the platform, not at the application');
            } else {
                note(testCase.name, `the handback went to ${o.callback_url}`);
            }
        }

        if (testCase.name === 'cashfree') {
            if (state.opened?.who === 'cashfree' && state.opened.args?.paymentSessionId === 'session_xYz123') {
                ok('opened Cashfree with the session id it was given');
            } else {
                note(testCase.name, `Cashfree was not opened correctly: ${JSON.stringify(state.opened)}`);
            }

            if (state.opened?.config?.mode === 'sandbox') {
                ok('in the mode the platform named');
            } else {
                note(testCase.name, `opened in mode ${state.opened?.config?.mode}`);
            }
        }

        if (testCase.expectStuck) {
            if (state.errorShown && /does not know how to open/.test(state.error)) {
                ok('said so plainly instead of guessing');
            } else {
                note(testCase.name, `showed "${state.message}" / "${state.error}"`);
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
