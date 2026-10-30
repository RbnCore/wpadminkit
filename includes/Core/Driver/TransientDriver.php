<?php
/**
 * Database-backed storage through transients; the default when no external object cache exists.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Driver;

defined( 'ABSPATH' ) || exit;

final class TransientDriver implements CacheDriver {

	public function get( string $key ): ?array {
		$value = get_transient( $key );
		return is_array( $value ) && array_key_exists( 'v', $value ) ? $value : null;
	}

	public function set( string $key, array $wrapped, int $ttl ): bool {
		return set_transient( $key, $wrapped, $ttl );
	}

	public function add( string $key, int $ttl ): bool {
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		// Not atomic (same as the WPSeoKit score lock): two requests inside the same few milliseconds may both win; the effect is a duplicate computation, never a wrong value.
		return set_transient( $key, array( 'v' => 1 ), $ttl );
	}

	public function delete( string $key ): bool {
		return delete_transient( $key );
	}

	public function purge( string $prefix ): void {
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
		$time = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
		Db::query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", array( $like, $time ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
