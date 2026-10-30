<?php
/**
 * Central rate limit: ONE counter implementation for every RBN plugin.
 *
 * A hit returns true when the key already reached its limit inside the window. The counter lives in the AdminKit
 * Core cache (`adminkit.rate` group), so a persistent object cache removes the database work and a plain site keeps
 * the counters in transients until the window ends. Nothing personal is stored: the caller passes an already
 * digested key (see `key()`).
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Security;

use AdminKit\Core\Cache;

defined( 'ABSPATH' ) || exit;

final class RateLimit {

	/** The cache group every counter belongs to. */
	public const GROUP = 'adminkit.rate';

	/** Default window in seconds. */
	public const DEFAULT_WINDOW = 60;

	/**
	 * Counts one hit. Returns true when this hit is OVER the limit.
	 *
	 * @param string $key    Counter key (already digested: no personal data).
	 * @param int    $limit  Allowed hits inside the window.
	 * @param int    $window Window length in seconds.
	 */
	public static function too_many( string $key, int $limit, int $window = self::DEFAULT_WINDOW ): bool {
		$key    = self::key( $key );
		$limit  = max( 1, $limit );
		$window = max( 1, $window );
		$now    = time();
		$state  = Cache::get( self::GROUP, $key );
		if ( ! is_array( $state ) || $now - (int) $state[1] >= $window ) {
			Cache::set( self::GROUP, $key, array( 1, $now ), $window );
			return false;
		}
		if ( (int) $state[0] >= $limit ) {
			// The window is kept alive so a caller that keeps knocking stays limited until it ends.
			Cache::set( self::GROUP, $key, array( (int) $state[0], (int) $state[1] ), max( 1, $window - ( $now - (int) $state[1] ) ) );
			return true;
		}
		Cache::set( self::GROUP, $key, array( (int) $state[0] + 1, (int) $state[1] ), max( 1, $window - ( $now - (int) $state[1] ) ) );
		return false;
	}

	/** How many hits the key has inside its window (0 when there is no window). */
	public static function hits( string $key, int $window = self::DEFAULT_WINDOW ): int {
		$state = Cache::get( self::GROUP, self::key( $key ) );
		if ( ! is_array( $state ) || time() - (int) $state[1] >= max( 1, $window ) ) {
			return 0;
		}
		return (int) $state[0];
	}

	/** Forgets a counter (a successful login clears its failures, a test starts from zero). */
	public static function forget( string $key ): void {
		Cache::delete( self::GROUP, self::key( $key ) );
	}

	/** The stored key: short, stable and without personal data. */
	public static function key( string $key ): string {
		return substr( md5( $key ), 0, 20 );
	}
}
