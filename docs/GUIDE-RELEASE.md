# [GUIDE] Releasing PayCrypto.Me for WooCommerce

The distribution slug is `paycrypto-me-for-woocommerce`. Run commands from the repository root. The examples use 0.6.1 as the baseline and 0.6.2 as the target. Substitute both versions for the release at hand. Run blocks with assignments in Bash. The Podman SVN commands also work in fish.

## Last verified publication

**0.6.1, November 25, 2026.** SVN trunk **r3824316**, tag **r3824318** (21:30:12 UTC). At verification, the official API reported 0.6.1 and all 812 files in the public download matched the approved package byte for byte. Git tag `v0.6.1` points to `d5869ce`.

Approved local ZIP SHA-256: `a363476ac4434b8747c5e7a557b71500e20ae062b17df268b47b9458d5b9a991`.

## Installation and derivation lock transition after 0.6.0

When upgrading from 0.6.0 or earlier to table-scoped locks, pause incoming requests, cron, queues and WP-CLI on every server for the affected store. Wait for active processes to finish. WordPress maintenance mode allows existing requests to continue. Replace the package, reload workers and OPcache as the host requires, then check the loaded version, `paycrypto_me_db_version` and the tables returned by `DbInstaller::tables()`. Reopen the store. Follow the same sequence for rollback.

Old and new workers can otherwise acquire different lock names for the same resource. The verified transition preserves schema version 2, settings, records and indexes. Independent stores can continue serving traffic.

## 1. Confirm source, version and release notes

Check the merged PR, remote reference, local changes, Git and SVN tags and published version. Identify the reviewed source and every release change that will enter the bump commit. Choose a patch for a compatible fix, minor for a new capability and major for a breaking contract. Explain any required merchant action in the `Upgrade Notice`.

Update `src/trunk/CHANGELOG.md` and the `Changelog` section of `src/trunk/readme.txt` with the same version. Preserve history. Regenerate catalogs for changed strings. Follow the [directory branding guide](GUIDE-DIRECTORY-BRANDING.md) for artwork. Keep approved assets in `src/assets/` for separate delivery to SVN.

## 2. Build the local ZIP

With Docker Compose, run `./scripts/release.sh` using the ephemeral `release` service. On Podman, the wrapper below uses host Node and npm and runs PHP and Composer in the QA image. Both paths need Git, Bash, rsync, zip and unzip. Set `RELEASE_PODMAN_IMAGE` to use an equivalent local image with PHP, Composer, GMP and GD.

```bash
bash scripts/release-podman.sh -v 0.6.2 -s paycrypto-me-for-woocommerce --dry-run
bash scripts/release-podman.sh -v 0.6.2 -s paycrypto-me-for-woocommerce
```

The script builds Blocks, runs PHPUnit and the platform pin, documentation and i18n audits, updates versions, validates or refreshes Composer lock metadata and packages production dependencies. Compare manifests and lockfiles with the baseline. For a simple bump, expect root version changes and an updated Composer hash if needed. Review changes in resolved dependencies separately. npm also audits the build chain. Assess development advisories against the files actually distributed.

Use `--no-build` to reuse assets built from the exact source already verified in this flow. Use `--no-zip` to rehearse a bump or `--no-docker` for a local runtime. Normally commit and tag after validating the package. The optional `--git` creates a local commit and tag for review before pushing.

## 3. Validate and freeze the artifact

```bash
python3 scripts/check-release-zip.py 0.6.2 releases/paycrypto-me-for-woocommerce-0.6.2.zip
(cd releases && sha256sum paycrypto-me-for-woocommerce-0.6.2.zip > paycrypto-me-for-woocommerce-0.6.2.zip.sha256)
```

Run real MySQL and smoke tests using the [QA matrix](GUIDE-QA.md#verification-matrix). The full Podman run below needs checkout development dependencies, Playwright/Chromium and both ZIP files. Obtain the baseline from the official download if necessary. Record both digests.

```bash
bash tests/manual/advisory-locks/run.sh \
  --baseline-zip releases/paycrypto-me-for-woocommerce-0.6.1.zip \
  --candidate-zip releases/paycrypto-me-for-woocommerce-0.6.2.zip
```

The full command exercises integration, smoke, classic checkout and Blocks on the source tree, then clean installation, upgrade and rollback with the exact packages. If the source suites have already passed separately, add `--upgrade-only` for the package checks. Require zero Plugin Check errors and warnings on the installed ZIP. Use the disposable lab store. A development bind mount can serve different files.

Collect evidence in the release log. If a distributed file changes, rebuild, recompute the digest and repeat the affected checks. Publish the approved bytes.

## 4. Prepare and review SVN

SVN preparation takes files from the approved ZIP and mirrors directory assets from `src/assets/`. `--svn-prepare` writes the local working copy. `--svn-publish` prepares it and writes trunk and tag to SVN. Both require `--no-build --no-tests --no-zip`.

The local Podman image `localhost/paycrypto-plugin-svn-tools:0.4.3` includes SVN, rsync and unzip. Use the same dedicated `SVN_WORKSPACE` under `releases/` for preparation and publication. The script cleans that working copy before synchronizing it. Keep unrelated edits in a separate checkout.

```sh
podman run --rm -v "$PWD:/repo" -w /repo \
  -e SVN_WORKSPACE=/repo/releases/svn-0.6.2 \
  --entrypoint bash localhost/paycrypto-plugin-svn-tools:0.4.3 \
  scripts/release.sh -v 0.6.2 -s paycrypto-me-for-woocommerce \
  --no-build --no-tests --no-zip --no-docker --svn-prepare

python3 scripts/check-release-zip.py 0.6.2 releases/paycrypto-me-for-woocommerce-0.6.2.zip \
  --compare releases/svn-0.6.2/svn/trunk
```

Review all additions, modifications and deletions. Compare asset changes with the approved scope. Verify destinations and confirm the new tag is absent before a new publication. To test changes to the SVN runner, use `svnadmin create` and `SVN_URL=file:///...` in a separate workspace. Create trunk, assets and tags in the test repository, run prepare and publish, then compare the exported tag with the ZIP.

## 5. Push to GitHub and publish

Review and commit the bump locally. Create an annotated tag on the commit that produced the ZIP. Confirm the commit and tag identify the validated code. Follow the authorized push flow for `main`. When a direct push is authorized, push the commit and tag together and verify the remote references.

```bash
git tag -a v0.6.2 -m "Release v0.6.2"
git push --atomic origin refs/heads/main:refs/heads/main refs/tags/v0.6.2:refs/tags/v0.6.2
git ls-remote origin refs/heads/main 'refs/tags/v0.6.2^{}'
```

Give the operator the publish command, approved ZIP and reviewed staging location. Authenticate in the interactive terminal with a committer account and its WordPress.org SVN-specific password. Set `SVN_USER` to override the default username. Keep passwords out of command arguments, history, logs and comments. [WordPress.org SVN handbook](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)

```sh
bash -c 'cd releases && sha256sum -c paycrypto-me-for-woocommerce-0.6.2.zip.sha256' &&
podman run --rm -it -v "$PWD:/repo" -w /repo \
  -e SVN_WORKSPACE=/repo/releases/svn-0.6.2 \
  --entrypoint bash localhost/paycrypto-plugin-svn-tools:0.4.3 \
  scripts/release.sh -v 0.6.2 -s paycrypto-me-for-woocommerce \
  --no-build --no-tests --no-zip --no-docker --svn-publish
```

With host SVN, invoke `scripts/release.sh` through Bash with the same flags and an absolute `SVN_WORKSPACE`. Prepare and publish verify the checksum when the companion `.sha256` file exists.

### Recover a partial publication

The script commits trunk and copies its revision to the tag. After an error or lost response, inspect remote trunk and tag history, check whether the tag exists and compare files with the ZIP. If trunk matches and the tag is missing, verify the server state and rerun publish to complete the copy. If the tag exists and matches, proceed to public verification. Resolve divergent tags or a trunk changed by another release with the maintainer. The script rejects overwriting or nesting existing tags.

## 6. Verify and record publication

Verify SVN revisions, API version and the directory download. If release confirmation is enabled, the operator confirms in the WordPress.org dashboard. Public endpoints may lag the tag. Record an old API version or unavailable download as pending and recheck. [Release confirmation handbook](https://developer.wordpress.org/plugins/wordpress-org/release-confirmation-emails/)

```bash
curl -fsSL 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=paycrypto-me-for-woocommerce' |
  python3 -c 'import json, sys; print(json.load(sys.stdin)["version"])'
curl -fsSL https://downloads.wordpress.org/plugin/paycrypto-me-for-woocommerce.0.6.2.zip \
  -o releases/public-0.6.2.zip
python3 scripts/check-release-zip.py 0.6.2 releases/paycrypto-me-for-woocommerce-0.6.2.zip \
  --compare releases/public-0.6.2.zip
```

WordPress.org rebuilds the ZIP. Compare the member list and individual file bytes because compression metadata can change the archive digest. For artwork changes, also compare the SVN `assets/` tree. Update the last verified publication above and the published version in `AGENTS.md`. Record version, revisions, time, file count, local digest, Git reference and relevant limitations. Close verification when both the API and public content match.
