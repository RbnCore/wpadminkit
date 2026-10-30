<?php
/**
 * Central rate limit: ONE counter implementation for every RBN plugin.
 *
 * A hit returns true when the key already reached its limit inside the window. The counter lives in the options table
 * as a transient-shaped row (`_transient_rbnkit_rate_<digest>` + its timeout row), so WordPress' own
 * `delete_expired_transients` cron removes a window that nobody touches again; nothing personal is stored — the
 * caller passes an already digested key (see `key()`).
 *
 * The value carries both numbers of the window (`hits|window-start`) and a hit is ONE atomic statement: creating the
 * window, starting a new one after it ended and adding a hit can never interleave, so two requests at the same moment
 * cannot lose a hit (WPRJ-685 D-3 — the old read-then-write counter could).
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Security;

use AdminKit\Helpers\Db;

defined( 'ABSPATH' ) || exit;

final class RateLimit {

	/** Option-name prefix of one counter row (the `_transient_` shape lets WordPress clean an expired window). */
	public const PREFIX = 'rbnkit_rate_';

	/** Default window in seconds. */
	public const DEFAULT_WINDOW = 60;

	/**
	 * Counts one hit. Returns true when this hit is OVER the limit.
	 *
	 * The counter never grows past the limit: once it is full, further hits inside the same window leave it alone, so
	 * `hits()` keeps saying "limit" until the window ends.
	 *
	 * @param string $key    Counter key (already digested: no personal data).
	 * @param int    $limit  Allowed hits inside the window.
	 * @param int    $window Window length in seconds.
	 */
	public static function too_many( string $key, int $limit, int $window = self::DEFAULT_WINDOW ): bool {
		global $wpdb;
		$digest = self::key( $key );
		$limit  = max( 1, $limit );
		$window = max( 1, $window );
		$now    = time();

		// ONE statement: insert the first hit, reset an ended window, or add one hit below the limit. `VALUES(...)` is
		// the row that would have been inserted (`1|<now>`); an unchanged row means "the counter is full".
		$changed = Db::query(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')"
			. " ON DUPLICATE KEY UPDATE option_value = IF("
			. "CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) <= %d, VALUES(option_value),"
			. "IF(CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d,"
			. "CONCAT(CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) + 1, '|', SUBSTRING_INDEX(option_value, '|', -1)),"
			. 'option_value))',
			array( self::option( $digest ), '1|' . $now, $now - $window, $limit )
		);
		$value = (string) Db::get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", array( self::option( $digest ) ) );
		$hits  = self::hits_of( $value, $now, $window );

		// The timeout row is only the cleaner's marker: it always names the end of the CURRENT window (`start + window`),
		// so an expired window is removed by WordPress' transient cron. It is not part of the counting decision.
		$start = self::start_of( $value, $now, $window );
		if ( $start > 0 ) {
			Db::query(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				array( self::timeout_option( $digest ), (string) ( $start + $window ) )
			);
		}

		if ( false === $changed ) {
			return $hits >= $limit; // The statement failed: decide from what is stored.
		}
		return 0 === (int) $changed;
	}

	/** How many hits the key has inside its window (0 when there is no window). */
	public static function hits( string $key, int $window = self::DEFAULT_WINDOW ): int {
		global $wpdb;
		$value = (string) Db::get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", array( self::option( self::key( $key ) ) ) );
		return self::hits_of( $value, time(), max( 1, $window ) );
	}

	/** Forgets a counter (a successful login clears its failures, a test starts from zero). */
	public static function forget( string $key ): void {
		global $wpdb;
		$digest = self::key( $key );
		Db::query(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s OR option_name = %s",
			array( self::option( $digest ), self::timeout_option( $digest ) )
		);
	}

	/** The stored key: short, stable and without personal data. */
	public static function key( string $key ): string {
		return substr( md5( $key ), 0, 20 );
	}

	/** The counter row of one digested key. */
	private static function option( string $digest ): string {
		return '_transient_' . self::PREFIX . $digest;
	}

	/** The timeout row of one digested key (WordPress removes the pair when the window ended). */
	private static function timeout_option( string $digest ): string {
		return '_transient_timeout_' . self::PREFIX . $digest;
	}

	/** Hits of a stored `hits|start` value; 0 when the value is missing or its window ended. */
	private static function hits_of( string $value, int $now, int $window ): int {
		$start = self::start_of( $value, $now, $window );
		if ( $start <= 0 ) {
			return 0;
		}
		return (int) strtok( $value, '|' );
	}

	/** Window start of a stored `hits|start` value; 0 when the value is missing or the window ended. */
	private static function start_of( string $value, int $now, int $window ): int {
		$start = (int) ( explode( '|', $value, 2 )[1] ?? 0 );
		if ( $start <= 0 || $now - $start >= $window ) {
			return 0;
		}
		return $start;
	}
}
