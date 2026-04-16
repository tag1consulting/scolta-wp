<?php
/**
 * WordPress transient cache adapter for AiEndpointHandler.
 *
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\Cache\CacheDriverInterface;

class Scolta_Cache_Driver implements CacheDriverInterface {

	public function get( string $key ): mixed {
		$cached = get_transient( $key );
		return $cached !== false ? $cached : null;
	}

	public function set( string $key, mixed $value, int $ttlSeconds ): void {
		set_transient( $key, $value, $ttlSeconds );
	}
}
