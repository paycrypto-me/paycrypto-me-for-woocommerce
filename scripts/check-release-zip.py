#!/usr/bin/env python3
"""Inspect the distributable and optionally compare it with a ZIP or an SVN trunk."""

import argparse
import hashlib
import json
import re
from pathlib import Path, PurePosixPath
from zipfile import BadZipFile, ZipFile

SLUG = 'paycrypto-me-for-woocommerce'


def read_zip(path):
    files = {}
    with ZipFile(path) as archive:
        for member in archive.infolist():
            name = PurePosixPath(member.filename)
            if not name.parts or name.parts[0] != SLUG or '..' in name.parts:
                raise ValueError(f'Unexpected ZIP path: {member.filename}')
            if member.is_dir():
                continue
            relative = name.relative_to(SLUG).as_posix()
            if relative in files:
                raise ValueError(f'Duplicate ZIP member: {relative}')
            files[relative] = archive.read(member)
    return files


def verify(files, version):
    for gateway in ('paycrypto_me', 'paycrypto_me_lightning'):
        for extension in ('js', 'css', 'asset.php'):
            files[f'assets/blocks/{gateway}-blocks.{extension}']
    files['vendor/composer/autoload_classmap.php']
    for name in files:
        if {'tests', '.git', 'node_modules', 'phpunit'} & set(PurePosixPath(name).parts):
            raise ValueError(f'Development file in package: {name}')
        if name.startswith('.smoke-minimal-host-tmp') or PurePosixPath(name).name in (
            'auth.json', 'phpunit.xml.dist', 'phpunit-integration.xml.dist', 'package-lock.json',
        ):
            raise ValueError(f'Development file in package: {name}')
    header = files[f'{SLUG}.php'].decode()
    readme = files['readme.txt'].decode()
    escaped = re.escape(version)
    if (not re.search(rf'^\s*\*\s*Version:\s*{escaped}\s*$', header, re.M)
            or not re.search(rf"\bconst (?:string )?VERSION = '{escaped}';", header)
            or not re.search(rf'^Stable tag:\s*{escaped}\s*$', readme, re.M)):
        raise ValueError(f'Package metadata must declare {version}')
    for name in ('composer.json', 'package.json'):
        if json.loads(files[name]).get('version') != version:
            raise ValueError(f'{name} must declare {version}')


def compare(files, reference):
    if reference.is_dir():
        other = {
            path.relative_to(reference).as_posix(): path.read_bytes()
            for path in reference.rglob('*')
            if path.is_file() and '.svn' not in path.relative_to(reference).parts
        }
    else:
        other = read_zip(reference)
    differences = sorted(name for name in files.keys() | other.keys() if files.get(name) != other.get(name))
    if differences:
        raise ValueError(f'{len(differences)} file difference(s) against {reference}: {", ".join(differences[:10])}')
    print(f'{len(files)} files match {reference} byte for byte')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('version')
    parser.add_argument('zip', type=Path)
    parser.add_argument('--compare', type=Path)
    args = parser.parse_args()
    try:
        files = read_zip(args.zip)
        verify(files, args.version)
        if args.compare is not None:
            compare(files, args.compare)
        print(f'{len(files)} files, SHA-256 {hashlib.sha256(args.zip.read_bytes()).hexdigest()}')
    except (BadZipFile, OSError, ValueError, KeyError) as problem:
        parser.exit(1, f'Package verification failed: {problem}\n')


if __name__ == '__main__':
    main()
