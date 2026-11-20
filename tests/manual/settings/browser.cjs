// Read-only smoke test. Set QA_ADMIN and QA_PASSWORD, then run with the local site URL as argument.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

async function run() {
    const site = new URL(process.argv[2]);
    assert(['localhost', '127.0.0.1'].includes(site.hostname), 'Expected a local WordPress site.');
    assert(process.env.QA_ADMIN && process.env.QA_PASSWORD, 'Set QA_ADMIN and QA_PASSWORD.');
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        await page.goto(new URL('wp-login.php', site).href);
        await page.locator('#user_login').fill(process.env.QA_ADMIN);
        await page.locator('#user_pass').fill(process.env.QA_PASSWORD);
        await page.locator('#wp-submit').click();
        for (const gateway of ['paycrypto_me', 'paycrypto_me_lightning']) {
            await page.goto(new URL('wp-admin/admin.php?page=wc-settings&tab=checkout&section=' + gateway, site).href);
            const area = page.locator('.paycrypto-danger-area');
            const records = area.locator('[name="paycrypto_me_cleanup[payments]"]');
            const logs = area.locator('[name="paycrypto_me_cleanup[logs]"]');
            const warning = area.locator('.description');
            assert.equal(await records.getAttribute('aria-describedby'), await warning.getAttribute('id'));
            assert(await warning.getAttribute('id') && (await warning.innerText()).trim(), 'Payment cleanup needs a linked, nonempty warning.');
            assert.equal(await area.locator('#paycrypto-me-reset-derivation-index').count(), Number(gateway === 'paycrypto_me'));
            for (const key of ['hide_for_non_admin_users', 'debug_log']) {
                const field = page.locator('#woocommerce_' + gateway + '_' + key);
                assert(await field.isVisible());
                const precedesDangerArea = await field.evaluate(el =>
                    Boolean(el.compareDocumentPosition(document.querySelector('.paycrypto-danger-area')) & Node.DOCUMENT_POSITION_FOLLOWING)
                );
                assert(precedesDangerArea, key + ' belongs with the general settings.');
            }
            for (const width of [1280, 390]) {
                await page.setViewportSize({ width, height: 900 });
                const heading = await area.getByRole('heading').boundingBox();
                const legend = await area.locator('legend').boundingBox();
                assert(heading && legend && Math.abs(heading.x - legend.x) <= 2, 'Consent group is offset from the section heading.');
                for (const item of [logs, records, warning]) {
                    const box = await item.boundingBox();
                    assert(box && box.x >= 0 && box.x + box.width <= width + 1, 'Cleanup control or warning overflows the viewport.');
                }
            }
            if (gateway === 'paycrypto_me_lightning') {
                const prefix = 'woocommerce_paycrypto_me_lightning_';
                const node = value => page.locator('input[name="' + prefix + 'node_type"][value="' + value + '"]');
                const secret = page.locator('#' + prefix + 'btcpay_webhook_secret');
                const url = page.locator('#' + prefix + 'btcpay_url');
                const lndUrl = page.locator('#' + prefix + 'lnd_rest_url');
                const preview = page.locator('.paycrypto-webhook-preview');
                for (const width of [1280, 390]) {
                    await page.setViewportSize({ width, height: 900 });
                    await node('btcpay').check();
                    assert(await secret.isDisabled(), 'The webhook secret is a read-only preview.');
                    const field = await secret.boundingBox();
                    const connection = await url.boundingBox();
                    const copy = await preview.locator('p').boundingBox();
                    assert(field && connection && Math.abs(field.x - connection.x) <= 2, 'The secret and connection fields should share a column.');
                    assert(copy && copy.x + copy.width <= width + 1, 'Webhook preview copy overflows the viewport.');
                    await node('lnd_rest').check();
                    assert(await lndUrl.isVisible() && !(await secret.isVisible()), 'The secret is visible only for BTCPay.');
                    assert((await lndUrl.boundingBox()).y < (await preview.boundingBox()).y, 'lnd settings belong above the webhook preview.');
                }
            }
            console.log('Passed settings layout:', gateway);
        }
    } finally {
        await browser.close();
    }
}

run().catch(error => { console.error(error); process.exitCode = 1; });
