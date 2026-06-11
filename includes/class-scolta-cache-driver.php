<?php
/**
 * WordPress transient cache adapter for AiEndpointHandler.
 *
 * @since 0.2.0
 *
 * @package Scolta
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\Cache\CacheDriverInterface;

/**
 * Backs the scolta-php cache interface with WordPress transients.
 */
class Scolta_Cache_Driver implements CacheDriverInterface {

	/**
	 * Fetch a cached value by key.
	 *
	 * @param string $key Cache key.
	 * @return mixed The cached value, or null on a miss.
	 */
	public function get( string $key ): mixed {
		$cached = get_transient( $key );
		return $cached !== false ? $cached : null;
	}

	/**
	 * Store a value as a transient with the given TTL.
	 *
	 * @param string $key        Cache key.
	 * @param mixed  $value      Value to cache.
	 * @param int    $ttlSeconds Time to live in seconds.
	 */
	public function set( string $key, mixed $value, int $ttlSeconds ): void {
		set_transient( $key, $value, $ttlSeconds );
	}
}
