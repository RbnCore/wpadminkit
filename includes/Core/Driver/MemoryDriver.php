<?php
/**
 * Request-local storage for non-persistent groups.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Driver;

defined( 'ABSPATH' ) || exit;

final class MemoryDriver implements CacheDriver {

	/** @var array<string, array<string, mixed>> */
	private array $data = array();

	public function get( string $key ): ?array {
		return $this->data[ $key ] ?? null;
	}

	public function set( string $key, array $wrapped, int $ttl ): bool {
		unset( $ttl ); // The request is the lifetime.
		$this->data[ $key ] = $wrapped;
		return true;
	}

	public function add( string $key, int $ttl ): bool {
		unset( $ttl );
		if ( isset( $this->data[ $key ] ) ) {
			return false;
		}
		$this->data[ $key ] = array( 'v' => 1 );
		return true;
	}

	public function delete( string $key ): bool {
		unset( $this->data[ $key ] );
		return true;
	}

	public function purge( string $prefix ): void {
		foreach ( array_keys( $this->data ) as $key ) {
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				unset( $this->data[ $key ] );
			}
		}
	}
}
