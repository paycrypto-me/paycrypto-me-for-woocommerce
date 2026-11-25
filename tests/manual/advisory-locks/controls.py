"""Verify that the unit oracles reject independent identity and exclusion regressions."""
import os
from pathlib import Path
import subprocess
import tempfile

root = Path.cwd()
services = Path('includes/services')
names = services / 'class-advisory-lock-name.php'
wallet = services / 'pay-crypto-me-db-statements-service.php'
controls = [
    ('database', names, "'v1', $kind, $database,", "'v1', $kind,", 'test_database_changes_the_resource'),
    ('wallet', names, '$parts[] = (string) $wallet_id;', '', 'test_wallet_changes_the_resource'),
    ('acquisition', wallet,
     '$got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lock_name, $lock_timeout));',
     "$got = '1';", 'test_lock_failure_throws_exception'),
]
out = root / 'releases/advisory-locks/controls'
out.mkdir(parents=True, exist_ok=True)
with tempfile.TemporaryDirectory() as tmp:
    for label, path, old, new, test in controls:
        source = (root / 'src/trunk' / path).read_text()
        assert source.count(old) == 1
        modified = Path(tmp) / path.name
        modified.write_text(source.replace(old, new))
        result = subprocess.run(['podman', 'run', '--rm', '-v', str(root / 'src/trunk') + ':/plugin:ro',
                                 '-v', str(modified) + ':/plugin/' + str(path) + ':ro',
                                 '-w', '/plugin', '--entrypoint', 'php',
                                 os.getenv('QA_WP_IMAGE', 'localhost/paycrypto-plugin-release-tools:0.4.3'),
                                 'vendor/bin/phpunit', '--do-not-cache-result', '--filter', test],
                                text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        (out / (label + '.log')).write_text(result.stdout)
        if result.returncode != 1 or 'Failures: 1' not in result.stdout or test not in result.stdout:
            raise RuntimeError('Unexpected control outcome for ' + label)
        print(label, 'rejected by', test)
