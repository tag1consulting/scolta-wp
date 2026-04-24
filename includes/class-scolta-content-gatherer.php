<?php
/**
 * Content gatherer for the PHP indexer pipeline.
 *
 * Queries WordPress for published posts of configured types and
 * yields them as ContentItem objects one at a time. The generator-
 * based approach keeps peak RSS bounded regardless of corpus size —
 * each batch of 100 posts is freed before the next is loaded.
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\Export\ContentItem;

class Scolta_Content_Gatherer {

	/**
	 * Count published content items without loading their body content.
	 *
	 * Uses WP_Query with fields='ids' so WordPress returns only post IDs,
	 * not full post objects. For 44k posts this transfers ~350 KB of integers
	 * instead of hundreds of megabytes of post_content.
	 *
	 * @return int Total count of published posts across configured post types.
	 */
	public static function gather_count(): int {
		$settings   = get_option( 'scolta_settings', array() );
		$post_types = $settings['post_types'] ?? array( 'post', 'page' );
		$query      = new \WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);
		return count( $query->posts );
	}

	/**
	 * Gather published content as a generator that yields ContentItem objects.
	 *
	 * Paginates WP_Query in batches of 100 posts. After each batch,
	 * wp_cache_flush_group( 'posts' ) releases WordPress's internal post cache
	 * so that only one batch worth of post_content is resident in RAM at a time.
	 *
	 * Callers must NOT convert the generator to an array — that would defeat
	 * the purpose and restore the pre-0.3.2 eager-load behaviour. Pass the
	 * generator directly to IndexBuildOrchestrator::build() or
	 * ContentExporter::filterItems().
	 *
	 * @return \Generator<ContentItem> Yields one ContentItem per post.
	 */
	public static function gather(): \Generator {
		$settings   = get_option( 'scolta_settings', array() );
		$post_types = $settings['post_types'] ?? array( 'post', 'page' );
		$site_name  = $settings['site_name'] ?? get_bloginfo( 'name' );
		$batch      = 100;
		$offset     = 0;

		while ( true ) {
			$query = new \WP_Query(
				array(
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => $batch,
					'offset'         => $offset,
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			if ( empty( $query->posts ) ) {
				break;
			}

			foreach ( $query->posts as $post ) {
				$item = new ContentItem(
					id: 'post-' . $post->ID,
					title: $post->post_title,
					bodyHtml: apply_filters( 'the_content', $post->post_content ),
					url: get_permalink( $post ),
					date: get_the_date( 'Y-m-d', $post ),
					siteName: $site_name,
				);

				/**
				 * Filter the ContentItem before it is added to the search index.
				 *
				 * Allows plugins to modify the title, body HTML, URL, date, or
				 * site name for a post before indexing. Use this to inject content
				 * from ACF fields, WooCommerce attributes, or any custom data source
				 * that is not rendered through `the_content` filter.
				 *
				 * @param ContentItem  $item  The content item about to be indexed.
				 * @param \WP_Post     $post  The WordPress post object.
				 */
				yield apply_filters( 'scolta_content_item', $item, $post );
			}

			$offset += count( $query->posts );
			wp_cache_flush_group( 'posts' );
			unset( $query );
		}
	}
}
