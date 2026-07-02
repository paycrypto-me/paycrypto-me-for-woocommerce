<?php
use PayCryptoMe\WooCommerce\AssetManager;
use PHPUnit\Framework\TestCase;

class AssetManagerTest extends TestCase
{
    private $fixtures_dir;

    protected function setUp(): void
    {
        $this->fixtures_dir = dirname(__DIR__, 2) . '/assets/blocks/';
        if (!is_dir($this->fixtures_dir)) {
            mkdir($this->fixtures_dir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // clean created fixture files
        @unlink($this->fixtures_dir . 'testslug-blocks.asset.php');
        @unlink($this->fixtures_dir . 'testslug-blocks.js');
        @unlink($this->fixtures_dir . 'testslug-blocks.css');
    }

    public function test_get_asset_data_fallback()
    {
        $data = AssetManager::get_asset_data('nonexistent_slug');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('version', $data);
        $this->assertEquals('0.1.0', $data['version']);
    }

    public function test_register_block_assets_registers_handles()
    {
        // create asset file and dummy js/css
        $asset_content = "<?php return ['dependencies' => ['wp-element'], 'version' => 'deadbeef'];";
        file_put_contents($this->fixtures_dir . 'testslug-blocks.asset.php', $asset_content);
        file_put_contents($this->fixtures_dir . 'testslug-blocks.js', "console.log('ok');");
        file_put_contents($this->fixtures_dir . 'testslug-blocks.css', "/* css */");

        $handles = AssetManager::register_block_assets('testslug');

        $this->assertContains('testslug-blocks', $handles);
        $this->assertContains('testslug-blocks-style', $handles);

        // check globals registered
        $this->assertArrayHasKey('testslug-blocks', $GLOBALS['wp_registered_scripts']);
        $this->assertArrayHasKey('testslug-blocks-style', $GLOBALS['wp_registered_styles']);
    }
}
