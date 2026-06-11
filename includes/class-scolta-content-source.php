<?php
/**
 * WordPress content source for Scolta indexing.
 *
 * Implements ContentSourceInterface from scolta-php for cross-platform
 * consistency. WordPress-internal code uses snake_case methods (WP convention);
 * the interface requires camelCase. Both are available — the camelCase methods
 * delegate to their snake_case counterparts.
 *
 * Uses WP_Query for enumeration, apply_filters('the_content') for rendering,
 * and generator functions (yield) to keep memory flat.
 *
 * @package Scolta
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\Content\ContentSourceInterface;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Exposes published WordPress posts to the scolta-php indexing pipeline.
 */
class Scolta_Content_Source implements ContentSourceInterface {

	/**
	 * Active Scolta configuration.
	 *
	 * @var ScoltaConfig
	 */
	private ScoltaConfig $config;

	/**
	 * Constructor.
	 *
	 * @param ScoltaConfig $config Active Scolta configuration.
	 */
	public function __construct( ScoltaConfig $config ) {
		$this->config = $config;
	}

	// -----------------------------------------------------------------
	// ContentSourceInterface (camelCase) — delegates to snake_case.
	// -----------------------------------------------------------------

	/**
	 * Yield all published content (interface delegate).
	 *
	 * @param array $options Source options; supports 'post_types'.
	 *
	 * @inheritDoc
	 */
	public function getPublishedContent( array $options = array() ): iterable {
		$post_types = $options['post_types'] ?? array( 'post', 'page' );
		return $this->get_published_content( $post_types );
	}

	/**
	 * Yield changed content from the tracker (interface delegate).
	 *
	 * @inheritDoc
	 */
	public function getChangedContent(): iterable {
		return $this->get_changed_content();
	}

	/**
	 * Get deleted/unpublished content IDs (interface delegate).
	 *
	 * @inheritDoc
	 */
	public function getDeletedIds(): array {
		return $this->get_deleted_ids();
	}

	/**
	 * Clear the change tracker (interface delegate).
	 *
	 * @inheritDoc
	 */
	public function clearTracker(): void {
		Scolta_Tracker::clear();
	}

	/**
	 * Get total published content count (interface delegate).
	 *
	 * @param array $options Source options; supports 'post_types'.
	 *
	 * @inheritDoc
	 */
	public function getTotalCount( array $options = array() ): int {
		$post_types = $options['post_types'] ?? array( 'post', 'page' );
		return $this->get_total_count( $post_types );
	}

	/**
	 * Get the count of pending tracker records (interface delegate).
	 *
	 * @inheritDoc
	 */
	public function getPendingCount(): int {
		return Scolta_Tracker::get_pending_count();
	}

	// -----------------------------------------------------------------
	// WordPress-native methods (snake_case).
	// -----------------------------------------------------------------

	/**
	 * Yield all published content as ContentItem objects.
	 *
	 * @param string[] $post_types Post types to include.
	 * @return \Generator<ContentItem>
	 */
	public function get_published_content( array $post_types = array( 'post', 'page' ) ): \Generator { // phpcs:ignore Generic.Files.LineLength.MaxExceeded
		$paged    = 1;
		$per_page = 50;

		do {
			$query = new WP_Query(
				array(
					'post_type'        => $post_types,
					'post_status'      => 'publish',
					'posts_per_page'   => $per_page,
					'paged'            => $paged,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => false,
				)
			);

			foreach ( $query->posts as $post ) {
				// The shared mapper applies the scolta_content_item filter
				// and returns null for excluded/empty items.
				$item = $this->post_to_content_item( $post );
				if ( $item !== null ) {
					yield $item;
				}
			}

			++$paged;
		} while ( $paged <= $query->max_num_pages );

		wp_reset_postdata();
	}

	/**
	 * Yield changed content items from the tracker.
	 *
	 * @return \Generator<ContentItem>
	 */
	public function get_changed_content(): \Generator {
		$pending = Scolta_Tracker::get_pending( 'index' );

		foreach ( $pending as $record ) {
			$post = get_post( (int) $record->content_id );
			if ( ! $post || $post->post_status !== 'publish' ) {
				continue;
			}

			$item = $this->post_to_content_item( $post );
			if ( $item !== null ) {
				yield $item;
			}
		}
	}

	/**
	 * Get content IDs that have been deleted or unpublished.
	 *
	 * @return string[] Post IDs to remove from the index.
	 */
	public function get_deleted_ids(): array {
		$pending = Scolta_Tracker::get_pending( 'delete' );
		return array_map( fn( $r ) => (string) $r->content_id, $pending );
	}

	/**
	 * Convert a WP_Post into a platform-agnostic ContentItem.
	 *
	 * Delegates to the shared mapper so both pipelines produce identical
	 * items — see Scolta_Content_Gatherer::to_content_item() for the
	 * canonical id/date/title semantics.
	 *
	 * @param \WP_Post $post The post to convert.
	 */
	private function post_to_content_item( \WP_Post $post ): ?ContentItem {
		return Scolta_Content_Gatherer::to_content_item( $post, $this->config->siteName );
	}

	/**
	 * Get total published post count for configured types.
	 *
	 * @param string[] $post_types Post types to count.
	 */
	public function get_total_count( array $post_types = array( 'post', 'page' ) ): int {
		$count = 0;
		foreach ( $post_types as $type ) {
			$counts = wp_count_posts( $type );
			$count += (int) ( $counts->publish ?? 0 );
		}
		return $count;
	}
}
