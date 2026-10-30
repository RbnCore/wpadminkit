<?php
/**
 * AdminKit Helpers: Central concurrency lock with TTL (time-to-live) and an owner token.
 *
 * Prevents race conditions during critical multi-step operations (such as address apply,
 * migration runs or batch operations) while ensuring stale locks from PHP fatal crashes
 * or timed-out processes do not cause permanent deadlocks.
 *
 * Every lock value is `<timestamp>:<token>`; the token is random per acquisition and only its own token lets a caller
 * release the lock. A stale lock is taken over with one compare-and-swap UPDATE, so two requests can never both
 * "reclaim" it, and a request that lost its lock (the stale takeover) cannot delete the new owner's lock.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Helpers;

defined( 'ABSPATH' ) || exit;

final class Lock {

	/** Default lock lifetime in seconds (5 minutes). */
	public const DEFAULT_TTL = 300;

	/** @var array<string, string> The value this request wrote, per lock option (owner token included). */
	private static array $owned = array();

	/**
	 * Acquires a concurrency lock with a time-to-live (TTL).
	 *
	 * If the lock option does not exist, it is created with the current timestamp and a fresh token.
	 * If the lock already exists:
	 *   - if the existing lock is older than $ttl seconds, it is considered stale (e.g. fatal crash)
	 *     and is taken over with one atomic compare-and-swap (only the exact old value is replaced).
	 *   - if the existing lock is still active (age < $ttl), acquiring fails and returns false.
	 *
	 * @param string $option Option name storing the lock value.
	 * @param int    $ttl    Lock lifetime in seconds (default 300s).
	 * @return bool True if acquired or taken over; false if currently held by an active operation.
	 */
	public static function acquire( string $option, int $ttl = self::DEFAULT_TTL ): bool {
		global $wpdb;
		$now   = time();
		$ttl   = max( 1, $ttl );
		$value = $now . ':' . bin2hex( random_bytes( 8 ) );

		$current = self::value( $option );
		if ( null !== $current ) {
			if ( ! self::stale( $current, $now, $ttl ) ) {
				return false;
			}
			return self::take_over( $option, $current, $value );
		}

		// One atomic create: INSERT IGNORE never overwrites a row that appeared meanwhile (`add_option()` would).
		if ( 1 === (int) Db::query( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", array( $option, $value ) ) ) {
			self::cache_set( $option, $value );
			self::$owned[ $option ] = $value;
			return true;
		}

		// The row appeared in the same instant: it belongs to another request; take it over only when it is stale.
		$current = self::value( $option );
		if ( null !== $current && self::stale( $current, $now, $ttl ) ) {
			return self::take_over( $option, $current, $value );
		}
		return false;
	}

	/**
	 * Releases the concurrency lock — only while this request still owns it.
	 *
	 * @param string $option Option name for the lock.
	 * @return bool True when this request's lock was removed; false when it is not the owner (nothing is deleted).
	 */
	public static function release( string $option ): bool {
		global $wpdb;
		if ( ! isset( self::$owned[ $option ] ) ) {
			return false;
		}
		$value = self::$owned[ $option ];
		unset( self::$owned[ $option ] );
		// One statement: only the exact value this request wrote is deleted, so a lock that a stale takeover gave to
		// another request stays with its new owner.
		$deleted = Db::query(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			array( $option, $value )
		);
		if ( 0 < (int) $deleted ) {
			wp_cache_delete( $option, 'options' );
			return true;
		}
		return false;
	}

	/**
	 * Returns the lock state: whether it is locked, its age in seconds, remaining TTL, and timestamp.
	 *
	 * @param string $option Option name for the lock.
	 * @param int    $ttl    Lock lifetime in seconds (default 300s).
	 * @return array{locked: bool, age: int, remaining: int, at: int}
	 */
	public static function state( string $option, int $ttl = self::DEFAULT_TTL ): array {
		$current = self::value( $option );
		if ( null === $current ) {
			return array(
				'locked'    => false,
				'age'       => 0,
				'remaining' => 0,
				'at'        => 0,
			);
		}

		$locked_at = (int) $current;
		$now       = time();
		$age       = max( 0, $now - $locked_at );
		$remaining = max( 0, $ttl - $age );
		$locked    = $remaining > 0;

		return array(
			'locked'    => $locked,
			'age'       => $age,
			'remaining' => $remaining,
			'at'        => $locked_at,
		);
	}

	/**
	 * Checks whether the lock is currently active.
	 *
	 * @param string $option Option name for the lock.
	 * @param int    $ttl    Lock lifetime in seconds (default 300s).
	 * @return bool
	 */
	public static function is_locked( string $option, int $ttl = self::DEFAULT_TTL ): bool {
		return self::state( $option, $ttl )['locked'];
	}

	/* ------------------------------------------------------------------ internals */

	/** The stored lock value, or null when there is no row (read straight from the table: the object cache may still hold a stale "missing"). */
	private static function value( string $option ): ?string {
		global $wpdb;
		$value = Db::get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", array( $option ) );
		return null === $value || '' === $value ? null : $value;
	}

	/** Is the stored value older than the TTL (or unreadable: a value that is not ours is never trusted)? */
	private static function stale( string $current, int $now, int $ttl ): bool {
		$locked_at = (int) $current;
		if ( $locked_at <= 0 ) {
			return true;
		}
		$age = $now - $locked_at;
		return $age >= $ttl || $age < 0;
	}

	/**
	 * Replaces one exact stale value with this request's value, atomically.
	 *
	 * @param string $option  Lock option.
	 * @param string $current The value read before the attempt.
	 * @param string $value   This request's value.
	 */
	private static function take_over( string $option, string $current, string $value ): bool {
		global $wpdb;
		$updated = Db::query(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			array( $value, $option, $current )
		);
		if ( 1 !== (int) $updated ) {
			return false; // Another request took the stale lock over first.
		}
		// The option API must see what the direct write did (the object cache would otherwise keep the old value).
		self::cache_set( $option, $value );
		self::$owned[ $option ] = $value;
		return true;
	}

	/**
	 * Mirrors a direct write into the option cache, including removing the name from WordPress' own "option does not
	 * exist" list (`notoptions`); otherwise `get_option()` keeps answering "missing" without ever touching the table.
	 */
	private static function cache_set( string $option, string $value ): void {
		wp_cache_set( $option, $value, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}
}
