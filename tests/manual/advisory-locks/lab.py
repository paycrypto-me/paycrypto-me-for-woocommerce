"""Disposable WordPress stores on a single MySQL server. Requires Podman."""
import argparse
import contextlib
import hashlib
import json
import os
import pathlib
import re
import secrets
import selectors
import subprocess
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor
from zipfile import BadZipFile, ZipFile

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


def suite(site, config, label=''):
    logfile = evidence / (label + config + '.log')
    with logfile.open('w') as log:
        run('podman', 'exec', '-w', PLUGIN, site, 'php', 'vendor/bin/phpunit', '-c', config,
            stdout=log, stderr=subprocess.STDOUT)
    summary = logfile.read_text().strip().rpartition('\n')[2]
    if not re.search(r'\b(?:Assertions: [1-9]\d*\b|[1-9]\d* assertions?\))', summary):
        raise RuntimeError(f'PHPUnit did not report any assertions. See {logfile}')
    print(summary, flush=True)


def store(db, suffix, database, prefix, source=None, *, with_nodes=False):
    plugin_cache = os.getenv('QA_PLUGIN_CACHE')
    mounts = []
    if with_nodes:
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


def package_version(archive):
    with ZipFile(archive) as package:
        readme = package.read(f'{SLUG}/readme.txt').decode()
    for line in readme.splitlines():
        if line.startswith('Stable tag:'):
            return line.split(':', 1)[1].strip()
    raise ValueError(f'Stable tag missing in {archive}')


def verify_package(site, log_name):
    result = wp(site, 'plugin', 'check', SLUG, '--format=json')
    (evidence / log_name).write_text(result + '\n')
    assert result == 'Success: Checks complete. No errors found.' or json.loads(result) == [], \
        'Plugin Check reported findings'


def record_versions(site, mysql):
    versions = {
        'git_base': output('git', 'rev-parse', 'HEAD'),
        'mysql': output(*mysql, 'SELECT VERSION()'),
        'wordpress': wp(site, 'core', 'version'),
        'woocommerce': wp(site, 'plugin', 'get', 'woocommerce', '--field=version'),
        'plugin_check': wp(site, 'plugin', 'get', 'plugin-check', '--field=version'),
        'php': output('podman', 'exec', site, 'php', '-r', 'echo PHP_VERSION;'),
    }
    (evidence / 'versions.json').write_text(json.dumps(versions, indent=2) + '\n')


def fresh_package(db, archive, version):
    with tempfile.TemporaryDirectory() as directory:
        run('unzip', '-qo', archive, '-d', directory)
        site, _ = store(db, 'fresh', 'store_a', 'fresh_', pathlib.Path(directory) / SLUG)
        wp(site, 'plugin', 'activate', SLUG)
        actual_version = wp(site, 'plugin', 'get', SLUG, '--field=version')
        assert actual_version == version, (version, actual_version)
        schema_version, tables = json.loads(worker(site, 'verify'))
        expected = wp(site, 'eval', 'echo \\PayCryptoMe\\WooCommerce\\DbInstaller::DB_VERSION;')
        assert str(schema_version) == expected, (expected, schema_version)
        assert tables and len(tables) == len(set(tables)), tables
        verify_package(site, 'fresh-plugin-check.log')
        (evidence / 'fresh-install.json').write_text(json.dumps({
            'version': actual_version, 'schema_version': schema_version, 'tables': tables,
            'candidate_sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
        }, indent=2) + '\n')
        run('podman', 'stop', site)


def upgrade(db, base_zip, new_zip, base_version, *, mysql=None):
    with tempfile.TemporaryDirectory() as directory:
        root = pathlib.Path(directory)
        src = root / 'plugin'
        src.mkdir()

        def unpack(archive, dest):
            run('unzip', '-qo', archive, '-d', root / dest)
            run('rsync', '-a', '--delete', str(root / dest / SLUG) + '/', str(src) + '/')

        unpack(base_zip, 'old')
        site, url = store(db, 'package', 'store_b', 'upgrade_', src, with_nodes=True)
        if mysql is not None:
            record_versions(site, mysql)
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
            payment('configure', 'hd', 9 if label == 'base' else 10)
            def purchase(_):
                proc = subprocess.run(['podman', 'exec', site, 'wp', '--allow-root', 'eval-file',
                                       '/qa/payment.php', 'create', 'hd', product], capture_output=True, text=True)
                if proc.returncode == 0:
                    return json.loads(proc.stdout)['status']
                if 'Duplicate entry' in proc.stderr and 'unique_xpub_network' in proc.stderr:
                    return 'duplicate_wallet'
                raise RuntimeError('Unexpected failure during first purchase: ' + proc.stderr)

            with ThreadPoolExecutor(2) as pool:
                outcomes = list(pool.map(purchase, range(2)))
            assert 'pending' in outcomes, 'Both first purchases failed on ' + label
            first_wallet[label] = outcomes

        for method in methods:
            payment('configure', method, 0)
            original[method] = payment('create', method, product)
        first_use('base')
        for archive, dest in [(new_zip, 'candidate'), (base_zip, 'rollback')]:
            options = ['woocommerce_paycrypto_me_settings', 'woocommerce_paycrypto_me_lightning_settings']
            meta = {m: payment('meta', m, before['order']) for m, before in original.items()}
            settings = [wp(site, 'option', 'get', key, '--format=json') for key in options]
            run('podman', 'stop', site)
            unpack(archive, dest)
            run('podman', 'start', site)
            wait(lambda: ready('podman', 'exec', site, 'curl', '-fsS', 'http://localhost/wp-login.php'))
            assert settings == [wp(site, 'option', 'get', key, '--format=json') for key in options]
            for method, before in original.items():
                assert meta[method] == payment('meta', method, before['order'])
                assert before == payment('snapshot', method, before['order']), (method, 'changed before retry')
                payment('configure', method, 0)
                after = payment('retry', method, before['order'])
                assert before == after, (method, 'historical payment changed')
            if dest == 'candidate':
                if base_version == '0.6.0':
                    for kind in ('install', 'wallet'):
                        with held(site, kind):
                            assert worker(site, 'legacy-check', 1, kind) == '1'
                first_use('candidate')
                verify_package(site, 'plugin-check.log')
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
        summary['baseline_sha256'] = hashlib.sha256(base_zip.read_bytes()).hexdigest()
        summary['candidate_sha256'] = hashlib.sha256(new_zip.read_bytes()).hexdigest()
        (evidence / 'upgrade.json').write_text(json.dumps(summary, indent=2) + '\n')


def first_wallet(site, mysql):
    """Run only in a fresh disposable source store with the wp_ prefix."""
    def payment(operation, argument):
        value = wp(site, 'eval-file', '/qa/payment.php', operation, 'hd', str(argument))
        return json.loads(value) if value else None

    def sql(statement):
        return output(*mysql, 'USE store_a; ' + statement)

    wallets = 'wp_paycrypto_me_bitcoin_wallet_xpubkeys'
    indexes = 'wp_paycrypto_me_bitcoin_derivation_indexes'
    transactions = 'wp_paycrypto_me_bitcoin_transactions_data'

    def snapshot():
        return {
            'wallets': sql(f'SELECT id,xpub,network FROM {wallets} ORDER BY id').splitlines(),
            'indexes': sql(f'SELECT wallet_xpubkeys_id,derivation_index FROM {indexes} ORDER BY 1,2').splitlines(),
            'payments': sql(f'SELECT order_id,id,wallet_xpubkeys_id,derivation_index_id,payment_address '
                            f'FROM {transactions} ORDER BY order_id').splitlines(),
        }

    hashes = {}
    for cls in ['BitcoinPaymentProcessor', 'PayCryptoMeDBStatementsService']:
        filename = wp(site, 'eval', f'echo (new ReflectionClass("PayCryptoMe\\\\WooCommerce\\\\{cls}"))->getFileName();')
        relative = pathlib.PurePosixPath(filename).relative_to(PLUGIN)
        digest = output('podman', 'exec', site, 'sha256sum', filename).split()[0]
        assert digest == hashlib.sha256((ROOT / 'src/trunk' / relative).read_bytes()).hexdigest()
        hashes[str(relative)] = digest

    vector = json.loads((ROOT / 'src/trunk/tests/vectors/bitcoin_addresses.json').read_text())[0]
    expected = {item['index']: item['address'] for item in vector['addresses']}
    assert wp(site, 'eval', 'echo (int)(extension_loaded("gmp") && extension_loaded("mysqli"));') == '1'
    unique = sql(f"SHOW INDEX FROM {wallets} WHERE Key_name='unique_xpub_network'")
    assert [(r.split('\t')[1], r.split('\t')[3:5]) for r in unique.splitlines()] == [
        ('0', ['1', 'xpub']), ('0', ['2', 'network'])]
    assert worker(site, 'setup', 1) == '1'  # A different wallet occupies the first ID.
    payment('configure', 0)
    product = worker(site, 'product')
    orders = [payment('prepare', product) for _ in range(2)]
    before = snapshot()
    assert len(set(orders)) == 2 and len(before['wallets']) == 1
    assert before['wallets'][0].split('\t')[1] != vector['xpub']
    assert before['indexes'] == before['payments'] == []
    report = {'source_sha256': hashes, 'ready': [], 'attempts': [], 'before': before}

    def save():
        (evidence / 'first-wallet.json').write_text(json.dumps(report, indent=2) + '\n')

    processes = []
    try:
        for order in orders:
            processes.append(subprocess.Popen(
                ['podman', 'exec', '-i', site, 'wp', '--allow-root', 'eval-file',
                 '/qa/payment.php', 'race', 'hd', str(order)],
                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True))
        with selectors.DefaultSelector() as selector:
            for proc, order in zip(processes, orders):
                selector.register(proc.stdout, selectors.EVENT_READ, order)
            deadline = time.monotonic() + 30
            while selector.get_map():
                events = selector.select(max(0, deadline - time.monotonic()))
                assert events, 'Timed out waiting for both wallet INSERTs'
                for key, _ in events:
                    line = key.fileobj.readline().strip().split()
                    assert line[:2] == ['READY', str(key.data)] and len(line) == 3, line
                    report['ready'].append({'order': key.data, 'connection': int(line[2])})
                    selector.unregister(key.fileobj)
        assert len({event['connection'] for event in report['ready']}) == 2 and snapshot() == before
        release = processes[::-1] if os.getenv('QA_FIRST_WALLET_REVERSE') == '1' else processes
        report['release_order'] = [orders[processes.index(proc)] for proc in release]
        for proc in release:
            proc.stdin.write('release\n')
            proc.stdin.flush()
        for order, proc in zip(orders, processes):
            stdout, stderr = proc.communicate(timeout=30)
            assert proc.returncode == 0, stderr
            report['attempts'].append({'order': order, **json.loads(stdout),
                                       'duplicate_wallet': 'Duplicate entry' in stderr and 'unique_xpub_network' in stderr})
    finally:
        for proc in processes:
            if proc.poll() is None:
                proc.kill()
            proc.wait()
        report['after'] = snapshot()
        save()

    attempts = report['attempts']
    assert all(a['result'] == 'success' and not a['duplicate_wallet'] for a in attempts), attempts
    after = report['after']
    assert len(after['wallets']) == 2 and after['wallets'][0] == before['wallets'][0]
    wallet, xpub, network = after['wallets'][1].split('\t')
    assert (xpub, network) == (vector['xpub'], vector['network']) and int(wallet) > 1
    assert after['indexes'] == [f'{wallet}\t0', f'{wallet}\t1']
    assert len(after['payments']) == 2
    identities = {}
    for row in after['payments']:
        order, _, row_wallet, index, address = row.split('\t')
        assert row_wallet == wallet and address == expected[int(index)]
        identity = payment('identity', order)
        assert identity == {'method': 'paycrypto_me', 'status': 'pending', 'address': address, 'index': index}
        identities[int(order)] = identity
    assert set(identities) == set(orders) and {v['index'] for v in identities.values()} == {'0', '1'}
    for order in orders:
        assert payment('attempt', order)['result'] == 'success'
        assert payment('identity', order) == identities[order]
    assert snapshot() == after
    report['retried'] = identities

    payment('configure', 2)  # Unused third wallet.
    wp(site, 'option', 'patch', 'insert', 'woocommerce_paycrypto_me_settings', 'debug_log', 'yes')
    order = payment('prepare', product)
    sql(f"CREATE TRIGGER qa_wallet_failure BEFORE INSERT ON {wallets} FOR EACH ROW "
        "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='qa_first_wallet_failure'")
    try:
        attempt = subprocess.run(['podman', 'exec', site, 'wp', '--allow-root', 'eval-file',
                                  '/qa/payment.php', 'attempt', 'hd', str(order)], capture_output=True, text=True, check=True)
        failure = json.loads(attempt.stdout)
        assert failure['result'] == 'failure' and failure['message'] and 'qa_first_wallet_failure' in attempt.stderr
        assert f'Failed to persist wallet xPub for order #{order}' in failure['diagnostic'], failure
        identity = payment('identity', order)
        assert identity['address'] == identity['index'] == '' and snapshot() == after
        report['write_failure'] = {'order': order, **failure}
    finally:
        sql('DROP TRIGGER qa_wallet_failure')
    assert payment('attempt', order)['result'] == 'success'
    report['recovered'] = payment('identity', order)
    save()


def main():
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--first-wallet-only', action='store_true')
    mode.add_argument('--integration-only', action='store_true')
    mode.add_argument('--upgrade-only', action='store_true')
    mode.add_argument('--parallel-suites', action='store_true')
    parser.add_argument('--baseline-zip', type=pathlib.Path)
    parser.add_argument('--candidate-zip', type=pathlib.Path,
                        help='Validate this existing release ZIP without rebuilding it')
    args = parser.parse_args()
    if not __debug__:
        raise RuntimeError('Run Python with assertions enabled')
    if args.first_wallet_only or args.integration_only or args.parallel_suites:
        if args.candidate_zip or args.baseline_zip:
            parser.error('ZIP inputs require a package run')
    else:
        baseline = (args.baseline_zip or ROOT / 'releases' / f'{SLUG}-0.6.0.zip').resolve()
        package = (args.candidate_zip or ROOT / 'releases/advisory-locks/candidate.zip').resolve()
        try:
            base_version = package_version(baseline)
            version = package_version(package)
        except (OSError, BadZipFile, KeyError, ValueError) as problem:
            parser.error(f'Unusable package input: {problem}')
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
            upgrade(db, baseline, package, base_version, mysql=mysql)
            if args.candidate_zip:
                fresh_package(db, package, version)
            return
        a, _ = store(db, 'a', 'store_a', 'wp_')
        wp(a, 'plugin', 'activate', SLUG)
        record_versions(a, mysql)
        if args.first_wallet_only or args.integration_only:
            first_wallet(a, mysql)
            if args.first_wallet_only:
                return
        if args.parallel_suites:
            c, _ = store(db, 'c', 'store_a', 'other_')
            wp(c, 'plugin', 'activate', SLUG)
            with ThreadPoolExecutor(2) as pool:
                tasks = [pool.submit(suite, site, 'phpunit-integration.xml.dist', label)
                         for site, label in [(a, 'A-'), (c, 'C-')]]
                for task in tasks:
                    task.result()
            return
        suite(a, 'phpunit-integration.xml.dist')
        if not args.integration_only:
            suite(a, 'phpunit.xml.dist')
            with (evidence / 'smoke.log').open('w') as log:
                run('bash', 'scripts/smoke-minimal-host.sh', env={**os.environ, 'QA_PODMAN_WORDPRESS': a},
                    stdout=log, stderr=subprocess.STDOUT)
            with (evidence / 'docs.log').open('w') as log:
                run('bash', 'scripts/check-docs-drift.sh', stdout=log, stderr=subprocess.STDOUT)
            candidate(a, db)
            upgrade(db, baseline, package, base_version)
            if args.candidate_zip:
                fresh_package(db, package, version)
    finally:
        for name in reversed(containers):
            subprocess.run(['podman', 'rm', '-fv', name], stdout=subprocess.DEVNULL)
        run('podman', 'network', 'rm', run_id)


if __name__ == '__main__':
    main()
