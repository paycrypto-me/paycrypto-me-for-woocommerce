"""Disposable WordPress stores on a single MySQL server. Requires Podman."""
import argparse
import contextlib
import hashlib
import json
import os
import pathlib
import secrets
import selectors
import subprocess
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor

ROOT = pathlib.Path.cwd()
QA = ROOT / 'tests/manual/advisory-locks'
SLUG = 'paycrypto-me-for-woocommerce'
PLUGIN = '/var/www/html/wp-content/plugins/' + SLUG
IMAGE = os.getenv('QA_WP_IMAGE', 'localhost/paycrypto-plugin-release-tools:0.4.3')
run_id = 'pcm-' + secrets.token_hex(4)
evidence = ROOT / 'releases/advisory-locks' / run_id
containers = []
password = secrets.token_hex(16)


def run(*args, **kwargs):
    return subprocess.run(list(map(str, args)), check=True, **kwargs)


def output(*args):
    return run(*args, stdout=subprocess.PIPE, text=True).stdout.strip()


def wp(site, *args):
    return output('podman', 'exec', site, 'wp', '--allow-root', *args)


def start(suffix, *args):
    name = run_id + '-' + suffix
    run('podman', 'run', '-d', '--name', name, '--network', run_id, *args)
    containers.append(name)
    return name


def wait(check):
    deadline = time.monotonic() + 120
    while not check():
        if time.monotonic() >= deadline:
            raise RuntimeError('Service readiness deadline exceeded')
        time.sleep(0.5)


def ready(*args):
    return subprocess.run(args, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0


def suite(site, config):
    with (evidence / (config + '.log')).open('w') as log:
        run('podman', 'exec', '-w', PLUGIN, site, 'php', 'vendor/bin/phpunit', '-c', config,
            stdout=log, stderr=subprocess.STDOUT)
    print((evidence / (config + '.log')).read_text().splitlines()[-1], flush=True)


def store(db, suffix, database, prefix, source=None):
    plugin_cache = os.getenv('QA_PLUGIN_CACHE')
    mounts = []
    if source:
        fixture = ROOT / 'tests/manual/payment-status-projection/pcm-payment-status-qa/pcm-payment-status-qa.php'
        mounts += ['-v', str(fixture) + ':/qa-btcpay.php:ro',
                   '-v', str(QA / 'nodes.php') + ':/var/www/html/wp-content/mu-plugins/nodes.php:ro']
    if plugin_cache:
        for slug in ('woocommerce', 'plugin-check'):
            mounts += ['-v', str(pathlib.Path(plugin_cache).resolve() / slug) +
                       ':/var/www/html/wp-content/plugins/' + slug + ':ro']
    site = start(suffix, '-p', '127.0.0.1::80',
                 '-e', 'WORDPRESS_DB_HOST=' + db, '-e', 'WORDPRESS_DB_NAME=' + database,
                 '-e', 'WORDPRESS_DB_USER=root', '-e', 'WORDPRESS_DB_PASSWORD=' + password,
                 '-e', 'WORDPRESS_TABLE_PREFIX=' + prefix, '-e', 'WORDPRESS_CONFIG_EXTRA=define("DISABLE_WP_CRON", true);',
                 '-v', str(source or ROOT / 'src/trunk') + ':' + PLUGIN,
                 '-v', str(ROOT / 'src/trunk/tests/vectors/bitcoin_addresses.json') + ':/qa-vectors.json:ro',
                 '-v', str(QA) + ':/qa:ro', *mounts, IMAGE)
    wait(lambda: ready('podman', 'exec', site, 'test', '-f', '/var/www/html/wp-config.php'))
    url = 'http://' + output('podman', 'port', site, '80/tcp')
    wp(site, 'core', 'install', '--url=' + url, '--title=Lock QA', '--admin_user=qa',
       '--admin_password=' + password, '--admin_email=qa@example.test', '--skip-email')
    for slug in ('woocommerce', 'plugin-check'):
        if not plugin_cache:
            wp(site, 'plugin', 'install', slug)
        wp(site, 'plugin', 'activate', slug)
    wp(site, 'user', 'create', 'buyer', 'buyer@example.test', '--role=customer', '--user_pass=' + password)
    return site, url


def worker(site, *args):
    return wp(site, 'eval-file', '/qa/worker.php', *map(str, args))


@contextlib.contextmanager
def held(site, kind):
    cmd = ['podman', 'exec', '-i', site, 'wp', '--allow-root', 'eval-file', '/qa/worker.php', 'hold', '1', kind]
    with subprocess.Popen(cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True) as proc:
        try:
            with selectors.DefaultSelector() as selector:
                selector.register(proc.stdout, selectors.EVENT_READ)
                assert selector.select(30), 'Fixture readiness timeout'
            signal = proc.stdout.readline().strip()
            assert signal.startswith('READY '), signal
            yield signal
            assert proc.poll() is None, 'Fixture stopped before observations completed'
        finally:
            proc.stdin.write('release\n')
            proc.stdin.close()
            if proc.wait(timeout=10):
                raise RuntimeError('Fixture did not release cleanly')


def candidate(a, db):
    b, url_b = store(db, 'b', 'store_b', 'wp_')
    c, url_c = store(db, 'c', 'store_a', 'other_')
    report = {}
    with held(a, 'install') as signal, ThreadPoolExecutor(2) as pool:
        for site in [b, c]:
            browser('activate', url_b if site == b else url_c)
            assert json.loads(worker(site, 'verify'))[0] == '2'
        report['parallel_installs'] = list(pool.map(lambda site: json.loads(worker(site, 'install')), [b, c]))
        assert all(row[0] == '2' and len(row[1]) == 4 for row in report['parallel_installs'])
        report['installer_holder'] = signal
    for index, site in enumerate([a, b, c]):
        assert worker(site, 'setup', index) == '1'
    with held(a, 'wallet') as signal:
        assert worker(a, 'reserve', 2) == '0'
        for site, url, layout in [(b, url_b, 'classic'), (c, url_c, 'blocks')]:
            assert worker(site, 'reserve', 1) == '0'
            product = worker(site, 'product')
            checkout = wp(site, 'option', 'get', 'woocommerce_checkout_page_id')
            if layout == 'classic':
                wp(site, 'post', 'update', checkout, '--post_content=[woocommerce_checkout]')
            os.environ['QA_CART'] = wp(site, 'option', 'get', 'woocommerce_cart_page_id')
            result = browser(layout, url, product, checkout)
            address, index, status = json.loads(worker(site, 'result', result['order']))
            assert address == result['address'] and index == 1 and status == 'pending'
            report[layout] = {'order': result['order'], 'index': index, 'status': status}
        report['wallet_holder'] = signal
    with ThreadPoolExecutor(2) as pool:
        indexes = sorted(pool.map(lambda _: int(worker(a, 'reserve', 1)), range(2)))
    assert indexes == [0, 1]
    report['same_wallet'] = indexes
    # A separate CLI-only network uses a portless local domain for WP multisite.
    for key in ('siteurl', 'home'):
        wp(a, 'option', 'update', key, 'http://pcm.example.test')
    wp(a, 'core', 'multisite-convert', '--title=QA network')
    blog_id = wp(a, 'site', 'create', '--slug=second', '--porcelain')
    report['switch_to_blog'] = json.loads(worker(a, 'multisite', blog_id))
    assert report['switch_to_blog'] == [0, 2, 3]
    (evidence / 'concurrency.json').write_text(json.dumps(report, indent=2) + '\n')


def browser(action, url, *args):
    env = {**os.environ, 'QA_PASSWORD': password, 'QA_EVIDENCE': str(evidence)}
    return json.loads(run('node', QA / 'browser.cjs', action, url, *args,
                          env=env, stdout=subprocess.PIPE, text=True).stdout)


def upgrade(db):
    base_zip = ROOT / 'releases' / (SLUG + '-0.6.0.zip')
    new_zip = ROOT / 'releases/advisory-locks/candidate.zip'
    with tempfile.TemporaryDirectory() as directory:
        root = pathlib.Path(directory)
        src = root / 'plugin'
        src.mkdir()

        def unpack(archive, dest):
            run('unzip', '-qo', archive, '-d', root / dest)
            run('rsync', '-a', '--delete', str(root / dest / SLUG) + '/', str(src) + '/')

        unpack(base_zip, 'old')
        site, url = store(db, 'package', 'store_b', 'upgrade_', src)
        wp(site, 'plugin', 'activate', SLUG)
        product = worker(site, 'product')

        def payment(op, method, arg):
            response = wp(site, 'eval-file', '/qa/payment.php', op, method, str(arg))
            return json.loads(response) if response else None

        methods = ('hd', 'fixed', 'btcpay', 'lnd_rest')
        original = {}
        first_wallet = {}
        def first_use(label):
            # Two ordinary first purchases sharing a previously unused HD configuration.
            # Report the outcome separately from the pre-created-wallet concurrency assertions.
            payment('configure', 'hd', 3 if label == 'base' else 4)
            with ThreadPoolExecutor(2) as pool:
                attempts = [pool.submit(payment, 'create', 'hd', product) for _ in range(2)]
            outcomes = []
            for attempt in attempts:
                try:
                    outcomes.append(attempt.result()['status'])
                except subprocess.CalledProcessError:
                    outcomes.append('failed')
            first_wallet[label] = outcomes

        for method in methods:
            payment('configure', method, 0)
            original[method] = payment('create', method, product)
        first_use('base')
        for archive, dest in [(new_zip, 'candidate'), (base_zip, 'rollback')]:
            options = ['woocommerce_paycrypto_me_settings', 'woocommerce_paycrypto_me_lightning_settings']
            settings = [wp(site, 'option', 'get', key, '--format=json') for key in options]
            run('podman', 'stop', site)
            unpack(archive, dest)
            run('podman', 'start', site)
            wait(lambda: ready('podman', 'exec', site, 'curl', '-fsS', 'http://localhost/wp-login.php'))
            assert settings == [wp(site, 'option', 'get', key, '--format=json') for key in options]
            for method, before in original.items():
                assert before == payment('snapshot', method, before['order']), (method, 'changed before retry')
                payment('configure', method, 0)
                after = payment('retry', method, before['order'])
                assert before == after, (method, 'historical payment changed')
            if dest == 'candidate':
                first_use('candidate')
                check = wp(site, 'plugin', 'check', SLUG, '--format=json')
                (evidence / 'plugin-check.log').write_text(check + '\n')
                assert check == 'Success: Checks complete. No errors found.' or json.loads(check) == [], 'Plugin Check reported findings'
                payment('configure', 'hd', 0)
                os.environ['QA_ORDER_URL'] = original['hd']['pay']
                retried = browser('order-pay', url)
                assert retried['address'] == original['hd']['identifier']
                for method in methods:
                    payment('configure', method, 0)
                    new = payment('create', method, product)
                    if method in ('btcpay', 'lnd_rest'):
                        os.environ['QA_ORDER_URL'] = new['pay']
                        invoice = browser('order-pay-' + method, url)
                        assert invoice['address'] == new['identifier'] and int(invoice['order']) == new['order']
                    if method == 'hd':
                        assert int(new['row']['derivation_index']) == 1
                        paid = payment('paid', method, new['order'])
                        assert paid['action'] == 'none'
                        os.environ['QA_ORDER_URL'] = paid['received']
                        browser('closed', url)
                    if method == 'fixed':
                        assert int(new['row']['wallet_xpubkeys_id']) == 0
        summary = {key: {'order': value['order'], 'status': value['status'],
                         'identifier': value['identifier']} for key, value in original.items()}
        summary['first_wallet_diagnostic'] = first_wallet
        summary['candidate_sha256'] = hashlib.sha256(new_zip.read_bytes()).hexdigest()
        (evidence / 'upgrade.json').write_text(json.dumps(summary, indent=2) + '\n')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--integration-only', action='store_true')
    parser.add_argument('--upgrade-only', action='store_true')
    args = parser.parse_args()
    if not __debug__:
        raise RuntimeError('Run Python with assertions enabled')
    evidence.mkdir(parents=True)
    print(evidence, flush=True)
    run('podman', 'network', 'create', run_id)
    try:
        db = start('db', '--tmpfs', '/var/lib/mysql:rw,size=2g', '-e',
                   'MYSQL_ROOT_PASSWORD=' + password, '-e', 'MYSQL_ROOT_HOST=%', 'mysql:8.0')
        mysql = ['podman', 'exec', '-e', 'MYSQL_PWD=' + password, db, 'mysql', '-h127.0.0.1', '-Nse']
        wait(lambda: ready(*mysql, 'SELECT 1'))
        run(*mysql, 'CREATE DATABASE store_a; CREATE DATABASE store_b')
        if args.upgrade_only:
            upgrade(db)
            return
        a, _ = store(db, 'a', 'store_a', 'wp_')
        wp(a, 'plugin', 'activate', SLUG)
        versions = {
            'git_base': output('git', 'rev-parse', 'HEAD'),
            'mysql': output(*mysql, 'SELECT VERSION()'), 'wordpress': wp(a, 'core', 'version'),
            'woocommerce': wp(a, 'plugin', 'get', 'woocommerce', '--field=version'),
            'plugin_check': wp(a, 'plugin', 'get', 'plugin-check', '--field=version'),
            'php': output('podman', 'exec', a, 'php', '-r', 'echo PHP_VERSION;'),
        }
        (evidence / 'versions.json').write_text(json.dumps(versions, indent=2) + '\n')
        suite(a, 'phpunit-integration.xml.dist')
        if not args.integration_only:
            suite(a, 'phpunit.xml.dist')
            with (evidence / 'smoke.log').open('w') as log:
                run('bash', 'scripts/smoke-minimal-host.sh', env={**os.environ, 'QA_PODMAN_WORDPRESS': a},
                    stdout=log, stderr=subprocess.STDOUT)
            with (evidence / 'docs.log').open('w') as log:
                run('bash', 'scripts/check-docs-drift.sh', stdout=log, stderr=subprocess.STDOUT)
            candidate(a, db)
            upgrade(db)
    finally:
        for name in reversed(containers):
            subprocess.run(['podman', 'rm', '-fv', name], stdout=subprocess.DEVNULL)
        run('podman', 'network', 'rm', run_id)


if __name__ == '__main__':
    main()
