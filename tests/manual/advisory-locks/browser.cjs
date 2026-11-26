const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { chromium } = require('playwright');

async function main() {
    const [action, origin, product, checkout] = process.argv.slice(2);
    assert.equal(new URL(origin).hostname, '127.0.0.1');
    const browser = await chromium.launch();
    const page = await browser.newPage();
    const trace = [];
    const started = Date.now();
    page.on('response', response => {
        const url = new URL(response.url());
        if (url.origin === origin && ['document', 'xhr', 'fetch'].includes(response.request().resourceType())) {
            trace.push({ms: Date.now() - started, method: response.request().method(), path: url.pathname, status: response.status()});
        }
    });
    await page.route('**/*', route => {
        const url = new URL(route.request().url());
        return (url.origin === origin || url.protocol === 'data:') ? route.continue() : route.abort();
    });
    page.on('response', response => {
        if (response.status() >= 400)
            console.error('HTTP', response.status(), new URL(response.url()).pathname);
    });
    page.setDefaultTimeout(20000);
    try {
        await page.goto(origin + '/wp-login.php');
        // WordPress can refocus the username field after load. Set the native inputs
        // together so delayed focus cannot redirect the password into the username.
        await page.locator('#loginform').evaluate((form, credentials) => {
            form.querySelector('#user_login').value = credentials.login;
            form.querySelector('#user_pass').value = credentials.password;
        }, {login: action === 'activate' ? 'qa' : 'buyer', password: process.env.QA_PASSWORD});
        await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/wp-login.php')), page.locator('#wp-submit').click()]);
        if (action === 'activate') {
            await page.goto(origin + '/wp-admin/plugins.php');
            const row = page.locator('tr[data-slug="paycrypto-me-for-woocommerce"]');
            await row.locator('.activate a').click();
            await row.locator('.deactivate').waitFor();
            return {};
        }

        if (action.startsWith('order-pay') || action === 'closed') {
            const target = new URL(process.env.QA_ORDER_URL);
            assert.equal(target.origin, origin);
            await page.goto(target.href);
            if (action === 'closed') {
                await page.locator('.paycrypto-me-order-details__status-badge--paid').first().waitFor();
                assert.equal(await page.locator('.paycrypto-me-order-details__copy-address-button').count(), 0);
                return {};
            }
            const gateway = page.locator(action === 'order-pay' ? '#payment_method_paycrypto_me' : '#payment_method_paycrypto_me_lightning');
            if (await gateway.isVisible()) await gateway.check();
            await page.locator('#place_order').click();
        } else {
            await page.goto(origin + '/?add-to-cart=' + product, {waitUntil: 'networkidle'});
            await page.goto(origin + '/?page_id=' + process.env.QA_CART, {waitUntil: 'networkidle'});
            await page.goto(origin + '/?page_id=' + checkout);
            const submit = action === 'classic' ? '#place_order' : '.wc-block-components-checkout-place-order-button';
            await page.locator(submit).click();
        }
        await page.waitForURL(/order-received/);
        const address = await page.locator('.paycrypto-me-order-details__address').first().innerText();
        const match = page.url().match(/order-received(?:\/|=)(\d+)/);
        assert(match && address.trim());
        await page.locator('.paycrypto-me-order-details__address').first().screenshot({
            path: path.join(process.env.QA_EVIDENCE, action + '-address.png'),
        });
        return {order: match[1], address: address.trim()};
    } catch (failure) {
        if (new URL(page.url()).pathname !== '/wp-login.php') {
            await page.screenshot({path: path.join(process.env.QA_EVIDENCE, action + '-failure.png')});
        }
        throw failure;
    } finally {
        fs.writeFileSync(path.join(process.env.QA_EVIDENCE, action + '-' + new URL(origin).port + '-events.json'), JSON.stringify(trace));
        await browser.close();
    }
}

main().then(result => console.log(JSON.stringify(result))).catch(error => {
    console.error(error.message.replace(/key=\w+/g, 'key=[redacted]'));
    process.exitCode = 1;
});
