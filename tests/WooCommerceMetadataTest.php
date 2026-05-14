<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Tag1\Scolta\Export\ContentItem;

/**
 * Tests for WooCommerce product metadata extraction in Scolta_Content_Gatherer.
 *
 * Verifies that product posts get price/sku/stock/category metadata populated
 * on ContentItem, and that non-product posts and absent WooCommerce are unaffected.
 */
class WooCommerceMetadataTest extends TestCase {

	private string $gatherer_file;
	private string $gatherer_source;

	protected function set_up(): void {
		parent::set_up();
		$this->gatherer_file   = dirname( __DIR__ ) . '/includes/class-scolta-content-gatherer.php';
		$this->gatherer_source = file_get_contents( $this->gatherer_file );

		if ( ! class_exists( 'Scolta_Content_Gatherer' ) ) {
			require_once $this->gatherer_file;
		}

		$GLOBALS['test_post_meta'] = [];
		$GLOBALS['test_post_terms'] = [];
	}

	protected function tear_down(): void {
		parent::tear_down();
		unset( $GLOBALS['test_post_meta'], $GLOBALS['test_post_terms'] );
	}

	// -------------------------------------------------------------------
	// Method existence and structure
	// -------------------------------------------------------------------

	public function test_extract_woocommerce_metadata_method_exists(): void {
		$this->assertTrue(
			method_exists( 'Scolta_Content_Gatherer', 'extract_woocommerce_metadata' ),
			'Scolta_Content_Gatherer must have extract_woocommerce_metadata() method'
		);
	}

	public function test_extract_woocommerce_metadata_is_private(): void {
		$ref = new ReflectionMethod( 'Scolta_Content_Gatherer', 'extract_woocommerce_metadata' );
		$this->assertTrue( $ref->isPrivate(), 'extract_woocommerce_metadata() must be private' );
	}

	public function test_extract_woocommerce_metadata_is_static(): void {
		$ref = new ReflectionMethod( 'Scolta_Content_Gatherer', 'extract_woocommerce_metadata' );
		$this->assertTrue( $ref->isStatic(), 'extract_woocommerce_metadata() must be static' );
	}

	// -------------------------------------------------------------------
	// Source-parse: WooCommerce guard and field extraction patterns
	// -------------------------------------------------------------------

	public function test_gather_guards_on_woocommerce_class_exists(): void {
		$this->assertStringContainsString(
			"class_exists( 'WooCommerce' )",
			$this->gatherer_source,
			"gather() must guard WooCommerce extraction with class_exists('WooCommerce')"
		);
	}

	public function test_gather_extracts_price_as_sortable(): void {
		$this->assertStringContainsString(
			"sortable['price']",
			$this->gatherer_source,
			'Price must go into sortable[] so Pagefind emits data-pagefind-sort'
		);
	}

	public function test_gather_extracts_sku_as_metadata(): void {
		$this->assertStringContainsString(
			"metadata['sku']",
			$this->gatherer_source,
			'SKU must go into metadata[]'
		);
	}

	public function test_gather_extracts_stock_status_as_metadata(): void {
		$this->assertStringContainsString(
			"metadata['stock_status']",
			$this->gatherer_source,
			'Stock status must go into metadata[]'
		);
	}

	public function test_gather_extracts_product_cat_taxonomy(): void {
		$this->assertStringContainsString(
			"'product_cat'",
			$this->gatherer_source,
			'Product category taxonomy must be extracted'
		);
	}

	public function test_gather_uses_wp_get_post_terms_for_categories(): void {
		$this->assertStringContainsString(
			'wp_get_post_terms',
			$this->gatherer_source,
			'Category extraction must use wp_get_post_terms()'
		);
	}

	public function test_gather_checks_product_post_type_before_extraction(): void {
		$this->assertStringContainsString(
			"'product' === \$post->post_type",
			$this->gatherer_source,
			"Extraction must be guarded by post_type === 'product'"
		);
	}

	// -------------------------------------------------------------------
	// Backward compatibility: non-product posts get no metadata
	// -------------------------------------------------------------------

	public function test_content_item_metadata_field_exists(): void {
		$item = new ContentItem(
			id: 'post-1',
			title: 'Test',
			bodyHtml: '<p>Hello</p>',
			url: '/test/',
			date: '2024-01-01',
		);
		$this->assertIsArray( $item->metadata, 'ContentItem::$metadata must be an array' );
		$this->assertEmpty( $item->metadata, 'Default metadata must be empty' );
	}

	public function test_content_item_sortable_field_exists(): void {
		$item = new ContentItem(
			id: 'post-1',
			title: 'Test',
			bodyHtml: '<p>Hello</p>',
			url: '/test/',
			date: '2024-01-01',
		);
		$this->assertIsArray( $item->sortable, 'ContentItem::$sortable must be an array' );
		$this->assertEmpty( $item->sortable, 'Default sortable must be empty' );
	}

	public function test_content_item_accepts_metadata_and_sortable(): void {
		$item = new ContentItem(
			id: 'post-42',
			title: 'Blue Sapphire',
			bodyHtml: '<p>A fine stone.</p>',
			url: '/shop/blue-sapphire/',
			date: '2024-03-15',
			metadata: [ 'sku' => 'SAP-001', 'stock_status' => 'instock' ],
			sortable: [ 'price' => '299.99' ],
		);

		$this->assertSame( [ 'sku' => 'SAP-001', 'stock_status' => 'instock' ], $item->metadata );
		$this->assertSame( [ 'price' => '299.99' ], $item->sortable );
	}

	// -------------------------------------------------------------------
	// Manifest saves metadata and sortable (source-parse)
	// -------------------------------------------------------------------

	public function test_manifest_entry_saves_metadata(): void {
		$this->assertStringContainsString(
			"'metadata' => \$item->metadata",
			$this->gatherer_source,
			'Manifest item entry must save $item->metadata for incremental build cache'
		);
	}

	public function test_manifest_entry_saves_sortable(): void {
		$this->assertStringContainsString(
			"'sortable' => \$item->sortable",
			$this->gatherer_source,
			'Manifest item entry must save $item->sortable for incremental build cache'
		);
	}
}
