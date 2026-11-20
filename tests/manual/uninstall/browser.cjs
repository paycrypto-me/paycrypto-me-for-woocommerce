// Run against a disposable Podman WordPress instance. See README.md.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { chromium } = require('playwright');

const [container, baseZip, candidateZip] = process.argv.slice(2);
assert(container && baseZip && candidateZip, 'Expected container name, 0.4.3 ZIP and candidate ZIP.');
const slug = 'paycrypto-me-for-woocommerce';
const podman = (...args) => execFileSync('podman', args, { encoding: 'utf8' }).trim();
const wp = (...args) => podman('exec', '--user', 'www-data', container, 'wp', ...args);
const check = (...args) => wp('eval-file', '/tmp/pcm-uninstall-qa.php', ...args);

async function run() {
    assert.equal(wp('eval', 'echo defined("PAYCRYPTOME_DISPOSABLE_UNINSTALL_QA") && PAYCRYPTOME_DISPOSABLE_UNINSTALL_QA === true ? "yes" : "no";'), 'yes');
    const pluginDir = wp('eval', 'echo WP_PLUGIN_DIR;') + '/' + slug;
    for (const mount of JSON.parse(podman('inspect', container))[0].Mounts) {
        if (mount.Type === 'bind') {
            assert(!pluginDir.startsWith(mount.Destination + '/') && !mount.Destination.startsWith(pluginDir), 'Refusing a bind-mounted plugin tree.');
        }
    }
    const url = wp('option', 'get', 'siteurl');
    assert(['localhost', '127.0.0.1'].includes(new URL(url).hostname), 'Expected a local disposable site.');
    podman('cp', path.join(__dirname, 'fixtures.php'), container + ':/tmp/pcm-uninstall-qa.php');
    podman('cp', baseZip, container + ':/tmp/pcm-base.zip');
    podman('cp', candidateZip, container + ':/tmp/pcm-candidate.zip');
    wp('plugin', 'install', '/tmp/pcm-base.zip', '--force', '--activate');
    check('seed');
    wp('plugin', 'install', '/tmp/pcm-candidate.zip', '--force', '--activate');
    check('installed', 'keep', 'keep');
    wp('--user=' + process.env.QA_ADMIN, 'eval-file', '/tmp/pcm-uninstall-qa.php', 'check-consent');

    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        await page.goto(url + '/wp-login.php');
        await page.locator('#user_login').fill(process.env.QA_ADMIN);
        await page.locator('#user_pass').fill(process.env.QA_PASSWORD);
        await page.locator('#wp-submit').click();
        const settings = id => page.goto(url + '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=' + id);
        const field = key => page.locator('input[name="paycrypto_me_cleanup[' + key + ']"]');
        const save = page.locator('[name=save]');
        for (const logs of [false, true]) {
            for (const payments of [false, true]) {
                if (logs || payments) {
                    wp('plugin', 'install', '/tmp/pcm-candidate.zip', '--activate');
                    check('seed');
                }
                await settings('paycrypto_me_lightning');
                assert.equal(await field('logs').isChecked(), false);
                assert.equal(await field('payments').isChecked(), false);
                await field('logs').check();
                await field('payments').check();
                await save.click();
                await settings('paycrypto_me');
                assert(await field('logs').isChecked() && await field('payments').isChecked());
                await field('logs').setChecked(logs);
                await field('payments').setChecked(payments);
                if (await save.isEnabled()) await save.click();
                await settings('paycrypto_me_lightning');
                assert.equal(await field('logs').isChecked(), logs);
                assert.equal(await field('payments').isChecked(), payments);
                await page.goto(url + '/wp-admin/plugins.php');
                const row = page.locator('tr[data-slug="' + slug + '"]').first();
                await row.locator('.deactivate a').click();
                check('deactivated', 'keep', 'keep');
                if (logs && payments) wp('plugin', 'deactivate', 'woocommerce');
                page.once('dialog', dialog => dialog.accept());
                await row.locator('.delete a').click();
                await row.locator('.delete a').waitFor({state: 'detached'});
                assert.equal(wp('eval', 'echo is_dir(WP_PLUGIN_DIR . "/paycrypto-me-for-woocommerce") ? "present" : "deleted";'), 'deleted');
                if (logs && payments) wp('plugin', 'activate', 'woocommerce');
                check('uninstalled', logs ? 'erase' : 'keep', payments ? 'erase' : 'keep');
                console.log('Passed', {logs, payments});
            }
        }
    } finally {
        await browser.close();
    }
}

run().catch(error => { console.error(error); process.exitCode = 1; });
