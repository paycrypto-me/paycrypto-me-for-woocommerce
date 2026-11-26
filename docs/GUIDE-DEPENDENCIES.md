# [GUIDE] Composer dependencies

The crypto dependencies are the official upstream packages, resolved from Packagist — no forks and
no VCS `repositories` block. `bitwasp/bitcoin` `^1.1` is the only crypto package in `require`; it
pulls in `bitwasp/buffertools`, `bitwasp/bech32` and `paragonie/ecc` transitively — the hardened
fork that ships `ConstantTimeMath`, though the adapter actually loaded on the derivation path is
`GmpMath` (measured). That is consistent with E4 in the doc below: constant-time math protects
operations on a *secret* scalar, and this plugin only derives public keys from an xPub. Do not cite
`ConstantTimeMath` as if it were on our hot path. A fresh `composer install` needs **no private repo and no token**,
and no `minimum-stability: dev`.

It is not GitHub-free, though: Packagist serves the metadata, but the dist zips still come from
`codeload.github.com` (true of most Packagist packages). Anonymous downloads are rate-limited — a
clean install can fail with `HTTP/2 429 … Source fallback is disabled`, measured. Two ways out: a
repo-root `auth.json` (`release.sh` forwards it via `COMPOSER_AUTH`, which is why that plumbing is
still there), or `--prefer-source`, which clones instead of downloading zips.

`config.platform.php` is pinned to **`8.1`** — the plugin's real PHP floor, the same value as
`Requires PHP:` in the header — and `composer.json` carries
`"replace": {"lastguest/murmurhash": "2.0.0"}`. The two go together and neither works alone:
`bitwasp/bitcoin v1.1.0` fixes `murmurhash` to the **exact** version `v2.0.0`, which declares
`php: ^7`, so an honest PHP 8 resolution refuses to install it; `replace` drops it from the tree so
the pin no longer has to lie about the platform. Raising the version from our own `require` is
impossible — the upstream constraint is an exact version, so anything else conflicts.

The pin used to be `7.4`, and that was **not** a narrow exemption for one package: it resolved the
whole tree as if on 7.4, so the plugin shipped `paragonie/sodium_compat` a major behind (v1 instead
of v2) and `paragonie/random_compat`, a PHP 5 polyfill measured at **0 files loaded**. Both are gone;
the crypto chain (`bitwasp/*`, `paragonie/ecc`) is byte-identical across the change. Historical measurements: [docs/archive/DONE-LEAN-VENDOR-TREE.md](archive/DONE-LEAN-VENDOR-TREE.md) (archived, may be absent — see the archive policy in [the QA guide](GUIDE-QA.md#documentation-maintenance)).

What `replace` costs, explicitly: `murmurhash` goes from *installed and never executed* to *not
installed*, so `BitWasp\Bitcoin\Crypto\Hash::murmur3()` — reachable only from
`Bloom/BloomFilter.php:250` — would now fatal instead of no-op. The mitigation is
`VendorReplaceGuardTest`, which greps every hand-written PHP file that ships — `includes/`,
`exceptions/`, `templates/`, the entrypoint and `uninstall.php` — for `lastguest\Murmur`, `murmur3`
and `BloomFilter`, and fails in development rather than in a store. Granularity is the
**method**, not the class: `Hash::sha256()` and the other seven statics are unaffected and safe to
use, so never widen that guard to ban `Crypto\Hash` wholesale.

**`./scripts/check-platform-pin.sh`** audits the pin via `composer why-not --locked php <floor>` (the
floor read from the plugin header, so bumping it moves the check; `--locked` audits the lock, so it
also works with no `vendor/` installed), and distinguishes two regimes:
**pin >= floor** is a *declaration* — it hides nothing and only makes resolution reproducible, so
**any** package blocking the floor fails the script; **pin < floor** is a *suppression* and gets
audited against `ALLOWED_OFFENDERS`, which is now **empty** and should stay that way. Widening that
allowlist, or lowering the pin to make the script pass, is never the fix. It runs automatically in
`release.sh`'s *Platform pin audit* phase. Background: [docs/archive/DONE-CRYPTO-DEPENDENCIES.md](archive/DONE-CRYPTO-DEPENDENCIES.md) → E7/E7.1/E7.2 (archived, may be absent — see the archive policy in [the QA guide](GUIDE-QA.md#documentation-maintenance)).

Remove the `replace` and its guard only after the locked upstream version supports the required PHP floor with the real transitive package. Confirm the changed lock and run the platform audit and address vectors before removing either.

> **The two `lucas-rosa95/*` forks were retired** — see
> [docs/archive/DONE-CRYPTO-DEPENDENCIES.md](archive/DONE-CRYPTO-DEPENDENCIES.md) (archived, may be
> absent). The `bitcoin` fork carried no source
> fix of its own, was one method *behind* upstream (its absence a fatal on class load), and upstream
> `v1.1.0` passes the full suite and all 60 address vectors unchanged. The side-channel advisories
> that used to sit in `config.audit.ignore` were for `mdanter/ecc`. Run `composer audit --locked` for the current dependency set.
