<?php
/**
 * Persistent object cache (Redis, Memcached...) through the wp_cache_* API. Chosen only when an external object cache is installed.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Driver;

defined( 'ABSPATH' ) || exit;

final class WpCacheDriver implements CacheDriver {

	public const GROUP = 'rbnkit';

	public function get( string $key ): ?array {
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );
		return $found && is_array( $value ) && array_key_exists( 'v', $value ) ? $value : null;
	}

	public function set( string $key, array $wrapped, int $ttl ): bool {
		return wp_cache_set( $key, $wrapped, self::GROUP, $ttl );
	}

	public function add( string $key, int $ttl ): bool {
		return wp_cache_add( $key, array( 'v' => 1 ), self::GROUP, $ttl ); // Atomic on Redis/Memcached.
	}

	public function delete( string $key ): bool {
		return wp_cache_delete( $key, self::GROUP );
	}

	public function purge( string $prefix ): void {
		unset( $prefix );
		// Object cache back ends cannot list keys; the group version bump already makes old entries unreachable, and TTL evicts them.
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
		}
	}
}
