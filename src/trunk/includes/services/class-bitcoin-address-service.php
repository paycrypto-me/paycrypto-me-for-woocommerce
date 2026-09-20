<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       BitcoinAddressService
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Address\SegwitAddress;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Base58;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Script\WitnessProgram;
use BitWasp\Buffertools\Buffer;

\defined('ABSPATH') || exit;

class BitcoinAddressService
{
    /**
     * The largest BIP-32 child number that remains non-hardened.
     *
     * Payment addresses are derived from an extended public key, so hardened
     * child derivation is not available and must never be requested.
     */
    private const MAX_NON_HARDENED_INDEX = 0x7fffffff;

    /**
     * The configured public key is the BIP44-style account node:
     * m / purpose' / coin_type' / account'.
     *
     * The service owns the remaining relative public derivation, `0/index`.
     * BIP32 serialization does not retain the complete path, but its depth lets
     * us reject root, purpose, coin and external-chain nodes rather than deriving
     * a syntactically valid address from the wrong branch.
     */
    private const ACCOUNT_EXTENDED_PUBKEY_DEPTH = 3;

    /** Address policies the gateway deliberately knows how to construct. */
    private const SUPPORTED_ADDRESS_TYPES = [
        'p2pkh',
        'p2sh-p2wpkh',
        'p2wpkh',
    ];

    private array $prefixMap = [
        // mainnet
        'xpub' => ['hex' => '0488b21e', 'type' => 'p2pkh', 'testnet' => false],
        'ypub' => ['hex' => '049d7cb2', 'type' => 'p2sh-p2wpkh', 'testnet' => false],
        'zpub' => ['hex' => '04b24746', 'type' => 'p2wpkh', 'testnet' => false],
        // testnet
        'tpub' => ['hex' => '043587cf', 'type' => 'p2pkh', 'testnet' => true],
        'upub' => ['hex' => '044a5262', 'type' => 'p2sh-p2wpkh', 'testnet' => true],
        'vpub' => ['hex' => '045f1cf6', 'type' => 'p2wpkh', 'testnet' => true],
    ];

    private ?HierarchicalKeyFactory $hdFactory;
    private ?AddressCreator $addressCreator;

    public function __construct(?HierarchicalKeyFactory $hdFactory = null, ?AddressCreator $addressCreator = null)
    {
        // Deliberately NOT instantiated here: both factories need the GMP extension (via the
        // underlying EC adapter), and this service is constructed eagerly in the gateway's
        // constructor, which WooCommerce runs on every request. Defer construction until an
        // actual on-chain operation needs them, so hosts without GMP don't fatal on every page load.
        $this->hdFactory = $hdFactory;
        $this->addressCreator = $addressCreator;
    }

    private function get_hd_factory(): HierarchicalKeyFactory
    {
        return $this->hdFactory ??= new HierarchicalKeyFactory();
    }

    private function get_address_creator(): AddressCreator
    {
        return $this->addressCreator ??= new AddressCreator();
    }

    /**
     * Runs $fn with E_DEPRECATED masked, always restoring the previous level.
     *
     * ONLY for the consciously-accepted bitwasp deprecations that fire on the serialization path —
     * "Use of parent in callables" from buffertools/CachingTypeFactory, plus the tentative return
     * types in bitcoin's Script\Opcodes/Parser. Those print mid-request under WP_DEBUG display and
     * broke the admin settings-save redirect ("headers already sent"). This masks E_DEPRECATED
     * ALONE — never any other level — and does NOT catch anything: a missing-extension \Error still
     * propagates, preserving the \Exception-only validation contract (see the note above
     * validate_bitcoin_address() and EnvironmentRequirements). error_reporting() governs which
     * diagnostics are emitted, not thrown Throwables, so it cannot hide the GMP \Error nor the
     * eventual PHP 9 fatal (which arrives as a thrown Error). Not a general tool — do not use it to
     * silence our own deprecations.
     */
    private static function suppress_vendor_deprecations(callable $fn)
    {
        // error_reporting() here only NARROWS the reported level (masks E_DEPRECATED) and restores
        // it in finally — it reduces diagnostic disclosure, the opposite of the sniff's full-path-
        // disclosure concern, and narrowing it is the whole purpose of this helper. Both sniffs that
        // flag the call are listed: WPCS's path-disclosure one and Plugin Check's own
        // DirectErrorReportingCall, which fires independently and would otherwise leave three
        // warnings on shipped code.
        // phpcs:disable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall
        $previous = error_reporting();
        error_reporting($previous & ~E_DEPRECATED);

        try {
            return $fn();
        } finally {
            error_reporting($previous);
        }
        // phpcs:enable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall
    }

    /**
     * Generate an address from an extended public key (xpub/ypub/zpub...)
     *
     * This method is intentionally thin: it validates inputs, derives the
     * child public key and then delegates to small, testable generator helpers
     * which produce the final address string.
     *
     * @param string $xPub Extended public key
     * @param int $index Non-hardened BIP-32 address index (0–2147483647)
     * @param NetworkInterface $network Network object
     * @param string|null $forceType Optional force address type (p2pkh|p2sh-p2wpkh|p2wpkh)
     * @return string
     */
    public function generate_address_from_xPub(string $xPub, int $index, NetworkInterface $network, ?string $forceType = null, ?callable $logger = null): string
    {
        if ($index < 0 || $index > self::MAX_NON_HARDENED_INDEX) {
            throw new \InvalidArgumentException(
                'Derivation index must be between 0 and 2147483647 for non-hardened BIP-32 derivation.'
            );
        }

        // The derivation/generation body runs inside suppress_vendor_deprecations(): fromExtended,
        // derivePath and the p2pkh/p2sh/p2wpkh generators all exercise the bitwasp serialization
        // path that emits accepted E_DEPRECATED notices. Wrapping here keeps them out of the
        // response on the checkout/order-pay derivation the same way the validators do on save.
        return self::suppress_vendor_deprecations(function () use ($xPub, $index, $network, $forceType): string {
            $currentPrefix = $this->get_prefix_from_xpub($xPub);
            $type = $this->resolve_address_type($currentPrefix, $forceType);

            $converted = $this->convert_extended_pubkey_prefix($xPub, $network);
            $hdKey = $this->get_hd_factory()->fromExtended($converted, $network);

            $this->assert_account_level_extended_pubkey($hdKey->getDepth());

            // Do NOT attempt to derive hardened paths (those with a trailing ').
            // Hardened derivation requires the private key; account-level public
            // keys can derive only this non-hardened external-chain/address suffix.
            $childKey = $hdKey->derivePath("0/{$index}");
            $publicKey = $childKey->getPublicKey();

            $publicKeyHash = $publicKey->getPubKeyHash();

            switch ($type) {
                case 'p2pkh':
                    return $this->generate_p2pkh_from_pubhash($publicKeyHash, $network);

                case 'p2sh-p2wpkh':
                    return $this->generate_p2sh_p2wpkh_from_pubhash($publicKeyHash, $network);

                case 'p2wpkh':
                    return $this->generate_p2wpkh_from_pubhash($publicKeyHash, $network);

                default:
                    // Keep this guard even though resolve_address_type() has already validated
                    // the value: generating a valid address for an unintended policy is worse
                    // than failing the payment attempt.
                    throw new \InvalidArgumentException(
                        \sprintf('Unsupported Bitcoin address type: %s.', $type)
                    );
            }
        });
    }

    /**
     * Reject every level except the account-level node before a relative
     * `0/index` derivation. Depth alone cannot prove purpose, coin type or
     * account number; those remain the documented merchant configuration
     * contract. It can prove that an external-chain key (depth 4), or a node
     * above the account level, must not be used with this relative path.
     */
    private function assert_account_level_extended_pubkey(int $depth): void
    {
        if ($depth !== self::ACCOUNT_EXTENDED_PUBKEY_DEPTH) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'Bitcoin extended public key must be an account-level key at BIP32 depth %d; received depth %d. Configure m/purpose\'/coin_type\'/account\', then this gateway derives 0/index.',
                    self::ACCOUNT_EXTENDED_PUBKEY_DEPTH,
                    $depth
                )
            );
        }
    }

    /**
     * Resolve the output policy before parsing or deriving the extended key.
     *
     * A BIP-32 key alone does not state which Bitcoin output it should produce.
     * Unknown prefixes and caller-supplied overrides therefore fail closed instead
     * of being interpreted as a different, but still syntactically valid, address.
     */
    private function resolve_address_type(string $prefix, ?string $forceType): string
    {
        if ($forceType !== null) {
            if (!in_array($forceType, self::SUPPORTED_ADDRESS_TYPES, true)) {
                throw new \InvalidArgumentException(
                    \sprintf(
                        'Unsupported Bitcoin address type: %s. Supported address types: %s.',
                        $forceType,
                        implode(', ', self::SUPPORTED_ADDRESS_TYPES)
                    )
                );
            }

            return $forceType;
        }

        return $this->get_prefix_meta($prefix)['type'];
    }

    public function get_prefix_from_xpub(string $xPub): string
    {
        return substr($xPub, 0, 4);
    }

    public function get_prefix_map(): array
    {
        return $this->prefixMap;
    }

    /**
     * Whether an extended-pubkey-shaped identifier's prefix belongs to the given network.
     *
     * `convert_extended_pubkey_prefix()` rewrites version bytes to the target network before
     * validating, so a testnet key always passes validation against mainnet (and vice-versa)
     * unless this is checked first. Returns true for identifiers whose prefix isn't a known
     * extended-pubkey prefix (e.g. a static address) — this guard has nothing to say about them.
     */
    public function prefix_matches_network(string $identifier, string $network_type): bool
    {
        $prefix = $this->get_prefix_from_xpub($identifier);

        if (!isset($this->prefixMap[$prefix])) {
            return true;
        }

        return $this->prefixMap[$prefix]['testnet'] === ($network_type === 'testnet');
    }

    private function generate_p2pkh_from_pubhash($publicKeyHash, NetworkInterface $network): string
    {
        $scriptPubKey = ScriptFactory::scriptPubKey()->payToPubKeyHash($publicKeyHash);
        $addr = $this->get_address_creator()->fromOutputScript($scriptPubKey, $network);
        return $addr->getAddress($network);
    }

    private function generate_p2wpkh_from_pubhash($publicKeyHash, NetworkInterface $network): string
    {
        $witnessProgram = WitnessProgram::v0($publicKeyHash);
        $address = new SegwitAddress($witnessProgram);
        return $address->getAddress($network);
    }

    private function generate_p2sh_p2wpkh_from_pubhash($publicKeyHash, NetworkInterface $network): string
    {
        $redeemScript = ScriptFactory::scriptPubKey()->witnessKeyHash($publicKeyHash);
        $redeemScriptHash = $redeemScript->getScriptHash();
        $p2shScript = ScriptFactory::scriptPubKey()->payToScriptHash($redeemScriptHash);
        $addr = $this->get_address_creator()->fromOutputScript($p2shScript, $network);
        return $addr->getAddress($network);
    }

    private function get_prefix_meta(string $prefix): array
    {
        if (!isset($this->prefixMap[$prefix])) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'Unsupported extended public key prefix: %s. Supported prefixes: %s.',
                    $prefix,
                    implode(', ', array_keys($this->prefixMap))
                )
            );
        }

        return $this->prefixMap[$prefix];
    }

    public function convert_extended_pubkey_prefix(string $xPub, ?NetworkInterface $network = null): string
    {
        $currentPrefix = substr($xPub, 0, 4);

        $meta = $this->get_prefix_meta($currentPrefix);

        $newHex = $network !== null ? $network->getHDPubByte() : $meta['hex'];

        // Base58::decodeCheck/encodeCheck + Buffer reach the buffertools serialization path that
        // emits the accepted E_DEPRECATED notices; keep them out of the response.
        return self::suppress_vendor_deprecations(function () use ($xPub, $newHex): string {
            $buffer = Base58::decodeCheck($xPub);

            $hexData = $buffer->getHex();
            $newHexData = $newHex . substr($hexData, 8);
            $newBuffer = Buffer::hex($newHexData);

            return Base58::encodeCheck($newBuffer);
        });
    }

    /**
     * Whether validating this identifier needs the big-integer math the GMP extension provides.
     *
     * Only bech32 (segwit) identifiers avoid it: `bitwasp/bech32` is pure PHP, while extended
     * public keys and base58 addresses both reach `Base58::decode()`, which calls `gmp_init()`.
     * This is what lets a host without GMP still take on-chain payments to a fixed bc1/tb1
     * address, even though xPub derivation is impossible there.
     */
    public function requires_gmp_math(string $identifier, NetworkInterface $network): bool
    {
        return !$this->is_segwit_candidate($identifier, $network);
    }

    private function is_segwit_candidate(string $identifier, NetworkInterface $network): bool
    {
        return strpos(strtolower($identifier), strtolower($network->getSegwitBech32Prefix()) . '1') === 0;
    }

    /**
     * bech32-only address validation, mirroring AddressCreator::readSegwitAddress().
     *
     * Needed as a separate path because `AddressCreator::fromString()` tries base58 *first* and
     * its `catch (\Exception)` does not stop the `\Error` that `Base58::decode()` raises on a host
     * without GMP — so a perfectly valid bc1 address used to fail there before bech32 was ever
     * tried, even though bech32 itself needs no big-integer math at all.
     */
    public function validate_segwit_address(string $address, NetworkInterface $network, ?callable $logger = null): bool
    {
        try {
            // Wrapped (not the catch) so bitwasp parse \Exceptions still fall to false below and an
            // \Error still propagates; only the accepted E_DEPRECATED notices are masked.
            self::suppress_vendor_deprecations(function () use ($network, $address): void {
                [$version, $program] = \BitWasp\Bech32\decodeSegwit($network->getSegwitBech32Prefix(), $address);

                // WitnessProgram::v0() enforces the 20/32-byte program length, exactly as
                // AddressCreator does — the accepted/rejected set stays identical.
                $version === 0
                    ? WitnessProgram::v0(new Buffer($program))
                    : new WitnessProgram($version, new Buffer($program));
            });

            return true;
        } catch (\Exception $e) {
            if ($logger !== null) {
                $logger(
                    \sprintf('Segwit address validation failed: %s', esc_html( wp_strip_all_tags( $e->getMessage() ) )),
                    'debug'
                );
            }

            return false;
        }
    }

    /**
     * Catches \Exception, NOT \Throwable: every "this string isn't a valid address" failure
     * arrives as an Exception (Base58ChecksumFailure, ParserOutOfRange, InvalidArgumentException),
     * while a missing extension arrives as an \Error. Swallowing the latter into `false` is what
     * made a host without GMP report a valid key as invalid — let it propagate so the caller can
     * name the real cause. See EnvironmentRequirements.
     */
    public function validate_bitcoin_address(string $address, NetworkInterface $network, ?callable $logger = null): bool
    {
        // Routed before AddressCreator on purpose — see validate_segwit_address().
        if ($this->is_segwit_candidate($address, $network)) {
            return $this->validate_segwit_address($address, $network, $logger);
        }

        try {
            // Uses the lazy accessor like every other method here; this one used to build its own
            // AddressCreator and silently ignore the injected one. Wrapped so the base58 path's
            // accepted E_DEPRECATED notices stay out of the response; the \Exception-not-\Throwable
            // contract is untouched (the wrap does not catch).
            self::suppress_vendor_deprecations(fn () => $this->get_address_creator()->fromString($address, $network));

            return true;
        } catch (\Exception $e) {
            if ($logger !== null) {
                $logger(
                    \sprintf('Bitcoin address validation failed: %s', esc_html( wp_strip_all_tags( $e->getMessage() ) )),
                    'debug'
                );
            }
            return false;
        }
    }

    /** Same \Exception-not-\Throwable contract as validate_bitcoin_address(). */
    public function validate_extended_pubkey(string $xPub, NetworkInterface $network, ?callable $logger = null): bool
    {

        try {
            // Wrapped so the Base58 + HD-key parse (the settings-save deprecation trigger) keeps its
            // accepted E_DEPRECATED notices out of the response. The wrap does not catch, so a parse
            // \Exception still falls to false below and a missing-extension \Error still propagates.
            self::suppress_vendor_deprecations(function () use ($xPub, $network): void {
                $replaceHex = $this->convert_extended_pubkey_prefix($xPub, $network);

                // The lazy accessor, not a fresh factory: this method used to build its own and
                // silently ignore the injected one, exactly like validate_bitcoin_address() did with
                // AddressCreator. Construction still happens inside the try, so a host without GMP
                // still raises the \Error the contract above depends on.
                $hdKey = $this->get_hd_factory()->fromExtended($replaceHex, $network);
                $this->assert_account_level_extended_pubkey($hdKey->getDepth());
            });

            return true;
        } catch (\Exception $e) {
            if ($logger !== null) {
                $logger(
                    \sprintf('Extended pubkey validation failed: %s', esc_html( wp_strip_all_tags( $e->getMessage() ) )),
                    'debug'
                );
            }
            return false;
        }
    }

    public function build_bitcoin_payment_uri(string $address, ?float $amount = null, ?string $label = null, ?string $message = null): string
    {
        $uri = "bitcoin:{$address}";

        $params = [];

        if ($amount !== null) {
            $params['amount'] = number_format($amount, 8, '.', '');
        }

        if ($label !== null) {
            $params['label'] = $label;
        }

        if ($message !== null) {
            $params['message'] = $message;
        }

        if (!empty($params)) {
            $uri .= '?' . http_build_query($params);
        }

        return $uri;
    }
}
