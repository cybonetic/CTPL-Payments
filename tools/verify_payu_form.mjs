/*
 * The platform's real PayU payload, rendered by this SDK, read back out
 * of a browser's DOM.
 *
 * ------------------------------------------------------------------
 *  THE CHECK THAT WAS MISSING
 * ------------------------------------------------------------------
 *
 * PayU, to a customer, on a live install:
 *
 *   "Mandatory parameters which must be sent in the transaction are:
 *    key, txnid, amount, productinfo, firstname, email, phone, surl,
 *    furl, hash
 *    The parameters which you have actually sent in the transaction are:
 *    txnid, amount, productinfo, surl, hash, firstname, email
 *    Mandatory parameter missing from your transaction request are:
 *    key, phone."
 *
 * Both sides were green. The platform's adapter test asserted the fields
 * the adapter sent; this package's hosted-form test asserted the fields
 * of a payload written here by hand. Neither was ever compared with the
 * other, and `key` — which the platform puts in the hash and in
 * `public_key` but was not putting in the form — fell straight through
 * the gap.
 *
 * So this joins them: the ORCHESTRATOR's own adapter produces the
 * payload, this package renders the form, and a real browser reports
 * what `new FormData(form)` would actually post. Not the markup, not the
 * payload — the fields.
 *
 *   node tools/verify_payu_form.mjs [../ctpl-app]
 */
import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import { globSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const ORCHESTRATOR = process.argv[2] ?? '../ctpl-app';
const OUT = 'build/checkout-screens';

/** PayU's list, copied from its error page verbatim. */
const MANDATORY = [
    'key', 'txnid', 'amount', 'productinfo', 'firstname',
    'email', 'phone', 'surl', 'furl', 'hash',
];

/*
 * Instructions about the form, not fields in it. PayU does not sign
 * them, and the platform's own hosted page does not post them.
 */
const NOT_FIELDS = ['action', 'method', 'display_amount'];

const problems = [];
const note = (what) => { problems.push(what); console.log(`  !! ${what}`); };
const ok = (what) => console.log(`  ok  ${what}`);

(async () => {
    await mkdir(OUT, { recursive: true });

    if (!existsSync(path.join(ORCHESTRATOR, 'tools/payu_checkout_payload.php'))) {
        console.log(`!! no orchestrator at ${ORCHESTRATOR} — this check needs one.`);
        console.log('   Pass its path: node tools/verify_payu_form.mjs /path/to/ctpl-app');
        process.exit(1);
    }

    console.log('-- asking the orchestrator what PayU would really be sent --');

    const checkoutJson = execFileSync('php', ['tools/payu_checkout_payload.php'], {
        cwd: ORCHESTRATOR,
        encoding: 'utf8',
    });

    const checkout = JSON.parse(checkoutJson);

    if (checkout.type !== 'hosted') {
        note(`the orchestrator described PayU as [${checkout.type}], not [hosted]`);
        process.exit(1);
    }

    ok(`the orchestrator sent a [hosted] checkout with ${Object.keys(checkout.payload).length} payload keys`);

    console.log('\n-- rendering it with this package --');

    const html = execFileSync('php', ['tools/render_checkout.php'], {
        cwd: process.cwd(),
        input: JSON.stringify(checkout),
        encoding: 'utf8',
    });

    const chromeGlob = globSync('/opt/pw-browsers/chromium-*/chrome-linux/chrome').sort();
    const browser = await chromium.launch({
        ...(chromeGlob.length > 0 ? { executablePath: chromeGlob.at(-1) } : {}),
        args: ['--no-sandbox', '--disable-gpu'],
    });

    const context = await browser.newContext({ viewport: { width: 420, height: 780 } });
    const page = await context.newPage();

    const jsErrors = [];
    page.on('pageerror', e => jsErrors.push(e.message));

    /*
     * The form auto-submits, so the post is stopped at the door and its
     * body recorded. That is the actual evidence: not what the payload
     * held, not what the markup looked like, but what PayU would have
     * received.
     */
    let posted = null;

    await context.route('**', route => {
        const request = route.request();

        if (request.url().includes('payu')) {
            posted = {
                method: request.method(),
                url: request.url(),
                body: request.postData() ?? '',
            };

            return route.fulfill({ contentType: 'text/html', body: '<p>stopped</p>' });
        }

        return route.fulfill({ status: 204, body: '' });
    });

    await page.setContent(html, { waitUntil: 'load' });
    await page.waitForTimeout(800);

    await page.screenshot({ path: path.join(OUT, 'payu-form.png'), fullPage: true });
    await writeFile(path.join(OUT, 'payu-form.html'), html);

    await browser.close();

    console.log('\n-- what PayU would have received --');

    if (posted === null) {
        note('the form never posted anywhere near PayU');
        process.exit(1);
    }

    if (posted.method !== 'POST') {
        note(`the form was sent as ${posted.method}; PayU's hosted checkout is a POST`);
    } else {
        ok('posted, not redirected');
    }

    if (posted.url !== checkout.payload.action) {
        note(`posted to ${posted.url}, but the platform signed it for ${checkout.payload.action}`);
    } else {
        ok(`posted to the signed action, ${posted.url}`);
    }

    const sent = new URLSearchParams(posted.body);
    const names = [...sent.keys()];

    console.log(`     fields: ${names.join(', ')}`);

    const missing = MANDATORY.filter(f => (sent.get(f) ?? '').trim() === '');

    if (missing.length === 0) {
        ok(`all ${MANDATORY.length} parameters PayU calls mandatory are present and non-empty`);
    } else {
        note(`PayU would report these as missing: ${missing.join(', ')}`);
    }

    // The hash is over `key` too, so a form carrying a key that is not
    // the one signed for fails at PayU with a message about the key.
    if (sent.get('key') === checkout.public_key) {
        ok('the key in the form is the key the platform signed with');
    } else {
        note(`the form carries key=${sent.get('key')} and the platform signed with ${checkout.public_key}`);
    }

    // The udf slots are empty on purpose AND are part of the signed
    // string, so dropping them for being empty breaks the hash.
    const udfs = ['udf1', 'udf2', 'udf3', 'udf4', 'udf5'].filter(u => !names.includes(u));

    if (udfs.length === 0) {
        ok('the five udf slots are posted, empty ones included — they are in the hash');
    } else {
        note(`these signed fields were dropped for being empty: ${udfs.join(', ')}`);
    }

    const leaked = NOT_FIELDS.filter(f => names.includes(f));

    if (leaked.length === 0) {
        ok('and the form carries no instructions-about-the-form as fields');
    } else {
        note(`posted as form fields, which they are not: ${leaked.join(', ')}`);
    }

    if (jsErrors.length > 0) {
        note(`JavaScript errors: ${jsErrors.join('; ')}`);
    } else {
        ok('no JavaScript errors');
    }

    console.log(`\nForm and screenshot in ${OUT}`);

    if (problems.length > 0) {
        console.log(`\n${problems.length} problem(s):`);
        for (const p of problems) console.log(`  - ${p}`);
        process.exit(1);
    }

    console.log('\nPayU would accept the form this platform and this package produce together.');
})();
