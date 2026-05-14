<?php
/**
 * Content gatherer for the PHP indexer pipeline.
 *
 * Queries WordPress for published posts of configured types and
 * yields them as ContentItem objects one at a time. The generator-
 * based approach keeps peak RSS bounded regardless of corpus size —
 * each batch of 100 posts is freed before the next is loaded.
 *
 * When a TimestampManifest is passed to gather(), posts whose
 * post_modified_gmt timestamp matches the manifest are yielded as
 * CachedContentReference objects without loading their full content.
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\CachedContentReference;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Index\TimestampManifest;

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
	 * Return post_modified_gmt UNIX timestamps for a batch of post IDs.
	 *
	 * Direct $wpdb query — avoids loading full WP_Post objects just to
	 * compare modification times. Returns 0 for any ID not found.
	 *
	 * @param int[] $ids Post IDs to look up.
	 * @return array<int, int> Map of post ID → UNIX timestamp (0 on miss).
	 *
	 * @since 0.3.12
	 */
	public static function get_post_timestamps( array $ids ): array {
		if ( empty( $ids ) ) {
			return array();
		}

		global $wpdb;

		$ids_int    = array_map( 'intval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids_int ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
		$sql  = "SELECT ID, UNIX_TIMESTAMP(post_modified_gmt) FROM {$wpdb->posts}"
			. " WHERE ID IN ({$placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk timestamp lookup for incremental indexing; core API has no batch equivalent.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$ids_int ), ARRAY_N );

		$result = array();
		foreach ( $rows as $row ) {
			$result[ (int) $row[0] ] = (int) $row[1];
		}
		return $result;
	}

	/**
	 * Gather published content as a generator that yields ContentItem objects.
	 *
	 * Paginates WP_Query in batches of 100 posts. After each batch,
	 * wp_cache_flush_group( 'posts' ) releases WordPress's internal post cache
	 * so that only one batch worth of post_content is resident in RAM at a time.
	 *
	 * When $manifest is provided and $force is false, posts whose
	 * post_modified_gmt timestamp matches the manifest are yielded as
	 * CachedContentReference objects — no WP_Query content load is needed for
	 * those posts. Posts that changed are fully loaded and yielded as
	 * ContentItem objects; the manifest is updated with the new timestamp and
	 * content hash.
	 *
	 * Callers must NOT convert the generator to an array — that would defeat
	 * the purpose and restore the pre-0.3.2 eager-load behaviour. Pass the
	 * generator directly to IndexBuildOrchestrator::build() or
	 * ContentExporter::filterItems().
	 *
	 * @param \Tag1\Scolta\Index\TimestampManifest|null $manifest
	 *   When provided, unchanged posts are yielded as CachedContentReferences.
	 * @param bool $force
	 *   When true, ignore the manifest and fully load every post.
	 *
	 * @return \Generator<ContentItem|CachedContentReference>
	 *
	 * @since 0.3.2
	 */
	public static function gather(
		?TimestampManifest $manifest = null,
		bool $force = false
	): \Generator {
		$settings   = get_option( 'scolta_settings', array() );
		$post_types = $settings['post_types'] ?? array( 'post', 'page' );
		$site_name  = $settings['site_name'] ?? get_bloginfo( 'name' );
		$batch      = 100;
		$offset     = 0;

		while ( true ) {
			// Fetch IDs only for timestamp check.
			$id_query = new \WP_Query(
				array(
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => $batch,
					'offset'         => $offset,
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
				)
			);

			if ( empty( $id_query->posts ) ) {
				break;
			}

			$batch_ids = array_map( 'intval', $id_query->posts );

			// Timestamp check: split into cached vs. needs-load.
			$timestamps  = array();
			$ids_to_load = $batch_ids;

			if ( $manifest !== null && ! $force ) {
				$timestamps  = self::get_post_timestamps( $batch_ids );
				$ids_to_load = array();

				foreach ( $batch_ids as $post_id ) {
					$entity_key = (string) $post_id;
					$entry      = $manifest->get( $entity_key );

					$stored_ts = (int) ( $timestamps[ $post_id ] ?? 0 );
					if ( $entry !== null && $stored_ts === $entry['ts'] ) {
						foreach ( $entry['items'] as $item_data ) {
							yield new CachedContentReference(
								entityKey:   $entity_key,
								contentHash: $item_data['hash'],
								id:          $item_data['id'],
								url:         $item_data['url'],
								date:        $item_data['date'],
								siteName:    $item_data['siteName'],
								language:    $item_data['language'],
								filters:     $item_data['filters'] ?? array(),
							);
						}
					} else {
						$ids_to_load[] = $post_id;
					}
				}
			}

			if ( ! empty( $ids_to_load ) ) {
				$query = new \WP_Query(
					array(
						'post_type'      => $post_types,
						'post_status'    => 'publish',
						'post__in'       => $ids_to_load,
						'posts_per_page' => count( $ids_to_load ),
						'no_found_rows'  => true,
						'orderby'        => 'post__in',
					)
				);

				foreach ( $query->posts as $post ) {
					$woo_meta = ( 'product' === $post->post_type )
						? self::extract_woocommerce_metadata( $post )
						: array(
							'metadata' => array(),
							'sortable' => array(),
						);

					$item = new ContentItem(
						id: 'post-' . $post->ID,
						title: $post->post_title,
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core the_content filter, not a custom hook.
						bodyHtml: apply_filters( 'the_content', $post->post_content ),
						url: get_permalink( $post ),
						date: get_the_date( 'Y-m-d', $post ),
						siteName: $site_name,
						metadata: $woo_meta['metadata'],
						sortable: $woo_meta['sortable'],
					);

					/**
					 * Filter the ContentItem before it is added to the search index.
					 *
					 * @param ContentItem  $item  The content item about to be indexed.
					 * @param \WP_Post     $post  The WordPress post object.
					 */
					$item = apply_filters( 'scolta_content_item', $item, $post );
					if ( $item !== null ) {
						if ( $manifest !== null && ! $force ) {
							$ts    = (int) ( $timestamps[ $post->ID ] ?? 0 );
							$hash  = PhpIndexer::contentHash( $item );
							$manifest->put(
								(string) $post->ID,
								$ts,
								array(
									array(
										'hash'     => $hash,
										'id'       => $item->id,
										'url'      => $item->url,
										'date'     => $item->date,
										'siteName' => $item->siteName,
										'language' => $item->language,
										'filters'  => $item->filters,
										'metadata' => $item->metadata,
										'sortable' => $item->sortable,
									),
								)
							);
						}
						yield $item;
					}
				}

				unset( $query );
			}

			$offset += count( $batch_ids );

			// Release WordPress's internal object caches accumulated during this
			// batch. Without flushing, these caches grow monotonically across the
			// entire build, inflating RSS regardless of batch size.
			if ( function_exists( 'wp_cache_flush_group' ) ) {
				wp_cache_flush_group( 'posts' );
				wp_cache_flush_group( 'post_meta' );
				wp_cache_flush_group( 'terms' );
				wp_cache_flush_group( 'term_relationships' );
			} else {
				// Nuclear fallback for WP < 6.1 without an object cache plugin.
				wp_cache_flush();
			}

			// Break circular reference chains from WP_Post objects and filter
			// callbacks that PHP's refcount GC cannot collect.
			gc_collect_cycles();
		}
	}

	/**
	 * Extract WooCommerce product metadata from post meta and taxonomy.
	 *
	 * Returns two arrays keyed for ContentItem's `metadata` and `sortable`
	 * parameters. Price is placed in `sortable` so Pagefind emits both
	 * data-pagefind-meta and data-pagefind-sort for ordering. All other
	 * product fields are placed in `metadata` for display only.
	 *
	 * Returns empty arrays when WooCommerce is not active or fields are absent.
	 *
	 * @param \WP_Post $post The WooCommerce product post.
	 * @return array{metadata: array<string, string>, sortable: array<string, string>}
	 *
	 * @since 1.0.0
	 */
	private static function extract_woocommerce_metadata( \WP_Post $post ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'metadata' => array(),
				'sortable' => array(),
			);
		}

		$metadata = array();
		$sortable = array();

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- post meta lookup for specific known keys on a single post.
		$price = get_post_meta( $post->ID, '_price', true );
		if ( '' !== $price && false !== $price ) {
			$sortable['price'] = (string) $price;
		}

		$regular_price = get_post_meta( $post->ID, '_regular_price', true );
		if ( '' !== $regular_price && false !== $regular_price ) {
			$metadata['regular_price'] = (string) $regular_price;
		}

		$sale_price = get_post_meta( $post->ID, '_sale_price', true );
		if ( '' !== $sale_price && false !== $sale_price ) {
			$metadata['sale_price'] = (string) $sale_price;
		}

		$sku = get_post_meta( $post->ID, '_sku', true );
		if ( '' !== $sku && false !== $sku ) {
			$metadata['sku'] = (string) $sku;
		}

		$stock_status = get_post_meta( $post->ID, '_stock_status', true );
		if ( '' !== $stock_status && false !== $stock_status ) {
			$metadata['stock_status'] = (string) $stock_status;
		}
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		$terms = wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$metadata['product_cat'] = implode( ', ', $terms );
		}

		return array(
			'metadata' => $metadata,
			'sortable' => $sortable,
		);
	}
}
