<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\BitcoinAddressService;
use BitWasp\Buffertools\Buffer;
use BitWasp\Bitcoin\Network\NetworkFactory;

class BitcoinAddressServiceTest extends TestCase
{
    /**
     * @dataProvider invalid_non_hardened_index_provider
     */
    public function test_generate_address_rejects_indices_outside_non_hardened_range(int $index): void
    {
        $hdFactory = $this->getMockBuilder(\BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory::class)
            ->onlyMethods(['fromExtended'])
            ->disableOriginalConstructor()
            ->getMock();
        $hdFactory->expects($this->never())->method('fromExtended');

        $service = new BitcoinAddressService($hdFactory);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('between 0 and 2147483647 for non-hardened BIP-32 derivation');

        $service->generate_address_from_xPub('xpub_fake', $index, NetworkFactory::bitcoin());
    }

    public function invalid_non_hardened_index_provider(): array
    {
        return [
            'negative index' => [-1],
            'first hardened index' => [2147483648],
            'large integer' => [PHP_INT_MAX],
        ];
    }

    public function test_prefix_map_contains_expected_keys()
    {
        $svc = new BitcoinAddressService();
        $map = $svc->get_prefix_map();

        $this->assertArrayHasKey('xpub', $map);
        $this->assertArrayHasKey('ypub', $map);
        $this->assertArrayHasKey('zpub', $map);
        $this->assertEquals('p2wpkh', $map['zpub']['type']);
    }

    public function test_generate_address_rejects_an_unsupported_extended_public_key_prefix_before_derivation(): void
    {
        $hdFactory = $this->getMockBuilder(\BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory::class)
            ->onlyMethods(['fromExtended'])
            ->disableOriginalConstructor()
            ->getMock();
        $hdFactory->expects($this->never())->method('fromExtended');

        $service = new BitcoinAddressService($hdFactory);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported extended public key prefix: qpub');

        $service->generate_address_from_xPub('qpub_fake', 0, NetworkFactory::bitcoin());
    }

    public function test_generate_address_rejects_an_unknown_forced_type_before_derivation(): void
    {
        $hdFactory = $this->getMockBuilder(\BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory::class)
            ->onlyMethods(['fromExtended'])
            ->disableOriginalConstructor()
            ->getMock();
        $hdFactory->expects($this->never())->method('fromExtended');

        $service = new BitcoinAddressService($hdFactory);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported Bitcoin address type: unknown');

        $service->generate_address_from_xPub('xpub_fake', 0, NetworkFactory::bitcoin(), 'unknown');
    }

    public function test_generate_p2pkh_and_p2sh_use_address_creator()
    {
        // Create a stub public key hash Buffer (20 bytes)
        $pubHash = Buffer::hex('0123456789abcdef0123456789abcdef01234567');

        // Mock HD objects: fromExtended() -> HierarchicalKey (hdKey)
        // hdKey->derivePath() -> derived HierarchicalKey which returns a PublicKey
        $hdFactory = $this->getMockBuilder(\BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory::class)
            ->onlyMethods(['fromExtended'])
            ->disableOriginalConstructor()
            ->getMock();

        $hdKey = $this->getMockBuilder(\BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey::class)
            ->disableOriginalConstructor()
            ->getMock();

        $derived = $this->getMockBuilder(\BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey::class)
            ->disableOriginalConstructor()
            ->getMock();

        $publicKeyMock = $this->getMockBuilder(\BitWasp\Bitcoin\Crypto\EcAdapter\Key\PublicKeyInterface::class)
            ->onlyMethods(['getPubKeyHash'])
            ->getMockForAbstractClass();
        $publicKeyMock->method('getPubKeyHash')->willReturn($pubHash);

        $derived->method('getPublicKey')->willReturn($publicKeyMock);
        $hdKey->method('getDepth')->willReturn(3);
        $hdKey->method('derivePath')->willReturn($derived);
        $hdFactory->method('fromExtended')->willReturn($hdKey);

        // Mock AddressCreator to return Address objects with getAddress()
        $addressCreator1 = $this->getMockBuilder(\BitWasp\Bitcoin\Address\AddressCreator::class)
            ->onlyMethods(['fromOutputScript'])
            ->disableOriginalConstructor()
            ->getMock();

        $dummyBuffer = \BitWasp\Buffertools\Buffer::hex('00');
        $scriptStub = new class implements \BitWasp\Bitcoin\Script\ScriptInterface {
            public function getScriptHash(): \BitWasp\Buffertools\BufferInterface { return \BitWasp\Buffertools\Buffer::hex('00'); }
            public function getWitnessScriptHash(): \BitWasp\Buffertools\BufferInterface { return \BitWasp\Buffertools\Buffer::hex('00'); }
            public function getScriptParser(): \BitWasp\Bitcoin\Script\Parser\Parser { throw new \RuntimeException('not used'); }
            public function getOpcodes(): \BitWasp\Bitcoin\Script\Opcodes { throw new \RuntimeException('not used'); }
            public function isPushOnly(array & $ops = null): bool { return false; }
            public function isWitness(& $witness): bool { return false; }
            public function isP2SH(& $scriptHash): bool { return false; }
            public function countSigOps(bool $accurate = true): int { return 0; }
            public function countP2shSigOps(\BitWasp\Bitcoin\Script\ScriptInterface $scriptSig): int { return 0; }
            public function countWitnessSigOps(\BitWasp\Bitcoin\Script\ScriptInterface $scriptSig, \BitWasp\Bitcoin\Script\ScriptWitnessInterface $witness, int $flags): int { return 0; }
            public function equals(\BitWasp\Bitcoin\Script\ScriptInterface $script): bool { return false; }
            public function __toString(): string { return ''; }
            public function getBuffer(): \BitWasp\Buffertools\BufferInterface { return \BitWasp\Buffertools\Buffer::hex('00'); }
            public function getHex(): string { return '00'; }
            public function getBinary(): string { return ''; }
            public function getInt() { return 0; }
        };

        $addressObj = new class($dummyBuffer, $scriptStub) extends \BitWasp\Bitcoin\Address\Address {
            private $script;
            public function __construct($hash, $script) { parent::__construct($hash); $this->script = $script; }
            public function getAddress(?\BitWasp\Bitcoin\Network\NetworkInterface $network = null): string { return '1MockP2PKHAddress'; }
            public function getScriptPubKey(): \BitWasp\Bitcoin\Script\ScriptInterface { return $this->script; }
        };
        $addressCreator1->method('fromOutputScript')->willReturn($addressObj);

        // Create a partial mock of the service to stub out Base58 conversion
        $svc = $this->getMockBuilder(\PayCryptoMe\WooCommerce\BitcoinAddressService::class)
            ->onlyMethods(['convert_extended_pubkey_prefix'])
            ->setConstructorArgs([$hdFactory, $addressCreator1])
            ->getMock();
        $svc->method('convert_extended_pubkey_prefix')->willReturn('converted_xpub');

        $network = NetworkFactory::bitcoin();

        $addr = $svc->generate_address_from_xPub('xpub_fake', 5, $network, 'p2pkh');
        $this->assertEquals('1MockP2PKHAddress', $addr);

        // For p2sh, return different address
        $addressObj2 = new class($dummyBuffer, $scriptStub) extends \BitWasp\Bitcoin\Address\Address {
            private $script;
            public function __construct($hash, $script) { parent::__construct($hash); $this->script = $script; }
            public function getAddress(?\BitWasp\Bitcoin\Network\NetworkInterface $network = null): string { return '3MockP2SHAddress'; }
            public function getScriptPubKey(): \BitWasp\Bitcoin\Script\ScriptInterface { return $this->script; }
        };
        $addressCreator2 = $this->getMockBuilder(\BitWasp\Bitcoin\Address\AddressCreator::class)
            ->onlyMethods(['fromOutputScript'])
            ->disableOriginalConstructor()
            ->getMock();
        $addressCreator2->method('fromOutputScript')->willReturn($addressObj2);

        $svc2 = $this->getMockBuilder(\PayCryptoMe\WooCommerce\BitcoinAddressService::class)
            ->onlyMethods(['convert_extended_pubkey_prefix'])
            ->setConstructorArgs([$hdFactory, $addressCreator2])
            ->getMock();
        $svc2->method('convert_extended_pubkey_prefix')->willReturn('converted_ypub');

        $addr2 = $svc2->generate_address_from_xPub('ypub_fake', 7, $network, 'p2sh-p2wpkh');
        $this->assertEquals('3MockP2SHAddress', $addr2);
    }

    public function test_generate_p2wpkh_returns_bech32_prefix()
    {
        // similar setup but no AddressCreator needed because p2wpkh builds SegwitAddress
        $pubHash = Buffer::hex('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        $hdFactory = $this->getMockBuilder(\BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory::class)
            ->onlyMethods(['fromExtended'])
            ->disableOriginalConstructor()
            ->getMock();

        $hdKey = $this->getMockBuilder(\BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey::class)
            ->disableOriginalConstructor()
            ->getMock();

        $derived = $this->getMockBuilder(\BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey::class)
            ->disableOriginalConstructor()
            ->getMock();

        $publicKeyMock = $this->getMockBuilder(\BitWasp\Bitcoin\Crypto\EcAdapter\Key\PublicKeyInterface::class)
            ->onlyMethods(['getPubKeyHash'])
            ->getMockForAbstractClass();
        $publicKeyMock->method('getPubKeyHash')->willReturn($pubHash);

        $derived->method('getPublicKey')->willReturn($publicKeyMock);
        $hdKey->method('getDepth')->willReturn(3);
        $hdKey->method('derivePath')->willReturn($derived);
        $hdFactory->method('fromExtended')->willReturn($hdKey);

        $svc = $this->getMockBuilder(\PayCryptoMe\WooCommerce\BitcoinAddressService::class)
            ->onlyMethods(['convert_extended_pubkey_prefix'])
            ->setConstructorArgs([$hdFactory, null])
            ->getMock();
        $svc->method('convert_extended_pubkey_prefix')->willReturn('converted_zpub');
        $network = NetworkFactory::bitcoin();

        $addr = $svc->generate_address_from_xPub('zpub_fake', 3, $network, null);
        $this->assertStringStartsWith('bc1', $addr);
    }

    /**
     * @dataProvider incompatible_extended_pubkey_depth_provider
     */
    public function test_generate_address_rejects_keys_that_are_not_account_level(int $depth): void
    {
        $hdFactory = $this->getMockBuilder(\BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory::class)
            ->onlyMethods(['fromExtended'])
            ->disableOriginalConstructor()
            ->getMock();
        $hdKey = $this->getMockBuilder(\BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey::class)
            ->disableOriginalConstructor()
            ->getMock();
        $hdKey->method('getDepth')->willReturn($depth);
        $hdKey->expects($this->never())->method('derivePath');
        $hdFactory->method('fromExtended')->willReturn($hdKey);

        $service = $this->getMockBuilder(BitcoinAddressService::class)
            ->onlyMethods(['convert_extended_pubkey_prefix'])
            ->setConstructorArgs([$hdFactory])
            ->getMock();
        $service->method('convert_extended_pubkey_prefix')->willReturn('converted_xpub');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an account-level key at BIP32 depth 3');

        $service->generate_address_from_xPub('xpub_fake', 0, NetworkFactory::bitcoin());
    }

    public function incompatible_extended_pubkey_depth_provider(): array
    {
        return [
            'root/master public key' => [0],
            'purpose-level key' => [1],
            'coin-level key' => [2],
            'external-chain key' => [4],
        ];
    }
}
