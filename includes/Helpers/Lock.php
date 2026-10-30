<?php
/**
 * AdminKit Helpers: Central concurrency lock with TTL (time-to-live).
 *
 * Prevents race conditions during critical multi-step operations (such as address apply,
 * migration runs or batch operations) while ensuring stale locks from PHP fatal crashes
 * or timed-out processes do not cause permanent deadlocks.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Helpers;

defined( 'ABSPATH' ) || exit;

final class Lock {

	/** Default lock lifetime in seconds (5 minutes). */
	public const DEFAULT_TTL = 300;

	/**
	 * Acquires a concurrency lock with a time-to-live (TTL).
	 *
	 * If the lock option does not exist, it is created with the current timestamp.
	 * If the lock already exists:
	 *   - if the existing lock is older than $ttl seconds, it is considered stale (e.g. fatal crash)
	 *     and is safely reclaimed by updating the option with the current timestamp.
	 *   - if the existing lock is still active (age < $ttl), acquiring fails and returns false.
	 *
	 * @param string $option Option name storing the lock timestamp.
	 * @param int    $ttl    Lock lifetime in seconds (default 300s).
	 * @return bool True if acquired or reclaimed; false if currently held by an active operation.
	 */
	public static function acquire( string $option, int $ttl = self::DEFAULT_TTL ): bool {
		$now = time();
		$ttl = max( 1, $ttl );

		$current = get_option( $option, null );
		if ( null !== $current && false !== $current && '' !== $current ) {
			$locked_at = (int) $current;
			$age       = $now - $locked_at;
			if ( $age >= 0 && $age < $ttl ) {
				return false;
			}
			// Stale lock: reclaim it.
			update_option( $option, $now, false );
			return true;
		}

		if ( add_option( $option, $now, '', 'no' ) ) {
			return true;
		}

		// In case add_option returned false due to a concurrent write, check again.
		$current = get_option( $option, null );
		if ( null !== $current && false !== $current && '' !== $current ) {
			$locked_at = (int) $current;
			$age       = $now - $locked_at;
			if ( $age >= 0 && $age < $ttl ) {
				return false;
			}
			update_option( $option, $now, false );
			return true;
		}

		return false;
	}

	/**
	 * Releases the concurrency lock.
	 *
	 * @param string $option Option name for the lock.
	 * @return bool True on success, false on failure.
	 */
	public static function release( string $option ): bool {
		return delete_option( $option );
	}

	/**
	 * Returns the lock state: whether it is locked, its age in seconds, remaining TTL, and timestamp.
	 *
	 * @param string $option Option name for the lock.
	 * @param int    $ttl    Lock lifetime in seconds (default 300s).
	 * @return array{locked: bool, age: int, remaining: int, at: int}
	 */
	public static function state( string $option, int $ttl = self::DEFAULT_TTL ): array {
		$current = get_option( $option, null );
		if ( null === $current || false === $current || '' === $current ) {
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
}
