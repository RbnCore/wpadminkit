<?php
/**
 * Central cache: named groups registered by plugins, versioned keys, stampede lock, pluggable driver.
 *
 * A thin layer over the WordPress object cache / transients. Clearing a group raises its version number
 * (old entries become unreachable and expire by TTL); no rows are scanned or deleted.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core;

use AdminKit\Core\Driver\CacheDriver;
use AdminKit\Core\Driver\MemoryDriver;
use AdminKit\Core\Driver\TransientDriver;
use AdminKit\Core\Driver\WpCacheDriver;

defined( 'ABSPATH' ) || exit;

final class Cache {

	public const OPT_VERSIONS = 'rbnkit_core_group_versions';
	public const OPT_STATS    = 'rbnkit_core_cache_stats';

	private const NAME_RE   = '/^[a-z0-9_]{1,20}$/';
	private const MAX_TTL   = 2592000; // 30 days: a never-expiring transient would be autoloaded.
	private const LOCK_TTL  = 30;
	private const WAIT_STEP = 100000;  // 100 ms.
	private const WAIT_MAX  = 5;       // 5 steps = 500 ms at most.

	/** @var array<string, array{plugin:string,name:string,id:string,ttl:int,persistent:bool}> */
	private static array $groups = array();

	/** @var array<string, CacheDriver> */
	private static array $drivers = array();

	/** @var array<string, int>|null */
	private static ?array $versions = null;

	/** @var array<string, int> Writes counted in this request, saved once at shutdown. */
	private static array $pending = array();

	/**
	 * Registers a cache group. The first registration of an id wins.
	 *
	 * @param string $plugin     Plugin slug part (`[a-z0-9_]`, 20 characters at most).
	 * @param string $name       Group name (same rule).
	 * @param int    $ttl        Default lifetime in seconds (1 to 30 days).
	 * @param bool   $persistent False keeps the group in memory for the request only.
	 * @return string Group id `plugin.name`, empty string when the arguments are invalid.
	 */
	public static function register_group( string $plugin, string $name, int $ttl, bool $persistent = true ): string {
		if ( 1 !== preg_match( self::NAME_RE, $plugin ) || 1 !== preg_match( self::NAME_RE, $name ) ) {
			_doing_it_wrong( __METHOD__, 'Plugin and group names must match [a-z0-9_]{1,20}.', '0.1.0' );
			return '';
		}
		$id = $plugin . '.' . $name;
		if ( ! isset( self::$groups[ $id ] ) ) {
			self::$groups[ $id ] = array(
				'plugin'     => $plugin,
				'name'       => $name,
				'id'         => $id,
				'ttl'        => self::clamp_ttl( $ttl ),
				'persistent' => $persistent,
			);
		}
		return $id;
	}

	/** @return array<string, array{plugin:string,name:string,id:string,ttl:int,persistent:bool}> */
	public static function groups(): array {
		return self::$groups;
	}

	/**
	 * Reads a value. `$found` tells a stored `false`/`null` apart from a miss.
	 *
	 * @return mixed
	 */
	public static function get( string $group, string $key, ?bool &$found = null ) {
		$found = false;
		$def   = self::def( $group );
		if ( null === $def ) {
			return null;
		}
		$wrapped = self::driver( $def )->get( self::key( $def, $key ) );
		if ( null === $wrapped ) {
			return null;
		}
		$found = true;
		return $wrapped['v'];
	}

	/**
	 * @param mixed    $value Any serializable value.
	 * @param int|null $ttl   Seconds; null = the group's default.
	 */
	public static function set( string $group, string $key, $value, ?int $ttl = null ): bool {
		$def = self::def( $group );
		if ( null === $def ) {
			return false;
		}
		$ok = self::driver( $def )->set( self::key( $def, $key ), array( 'v' => $value ), null === $ttl ? $def['ttl'] : self::clamp_ttl( $ttl ) );
		if ( $ok ) {
			self::$pending[ $def['id'] ] = ( self::$pending[ $def['id'] ] ?? 0 ) + 1;
		}
		return $ok;
	}

	public static function delete( string $group, string $key ): bool {
		$def = self::def( $group );
		return null !== $def && self::driver( $def )->delete( self::key( $def, $key ) );
	}

	/**
	 * Returns the cached value or computes it once. While one request computes, others wait up to 500 ms for the
	 * value, then compute on their own (nothing waits forever). The lock is always released.
	 *
	 * @param callable():mixed $callback Computes the value on a miss.
	 * @return mixed
	 */
	public static function remember( string $group, string $key, ?int $ttl, callable $callback ) {
		$value = self::get( $group, $key, $found );
		if ( $found ) {
			return $value;
		}
		$def = self::def( $group );
		if ( null === $def ) {
			return $callback();
		}
		$driver = self::driver( $def );
		$lock   = self::key( $def, $key ) . '.lock';
		$held   = $driver->add( $lock, self::LOCK_TTL );
		for ( $i = 0; ! $held && $i < self::WAIT_MAX; $i++ ) {
			usleep( self::WAIT_STEP );
			$value = self::get( $group, $key, $found );
			if ( $found ) {
				return $value;
			}
			$held = $driver->add( $lock, self::LOCK_TTL );
		}
		try {
			$value = $callback();
			self::set( $group, $key, $value, $ttl );
			return $value;
		} finally {
			if ( $held ) {
				$driver->delete( $lock );
			}
		}
	}

	/** Clears a group by raising its version. */
	public static function flush_group( string $group ): bool {
		$def = self::def( $group );
		if ( null === $def ) {
			return false;
		}
		$versions            = self::versions();
		$versions[ $def['id'] ] = ( $versions[ $def['id'] ] ?? 1 ) + 1;
		self::$versions      = $versions;
		update_option( self::OPT_VERSIONS, $versions, true );
		$stats                = self::stats_raw();
		$stats[ $def['id'] ]  = array(
			'sets'       => 0,
			'flushed_at' => time(),
		);
		unset( self::$pending[ $def['id'] ] );
		update_option( self::OPT_STATS, $stats, false );
		/**
		 * Fires after a group was cleared.
		 *
		 * @param string $plugin Plugin part of the group id.
		 * @param string $name   Group name.
		 */
		do_action( 'rbnkit_core_cache_flushed', $def['plugin'], $def['name'] );
		return true;
	}

	/** Clears every registered group. Returns how many were cleared. */
	public static function flush_all(): int {
		$n = 0;
		foreach ( array_keys( self::$groups ) as $id ) {
			$n += self::flush_group( $id ) ? 1 : 0;
		}
		return $n;
	}

	/**
	 * Removes everything one plugin cached: raises the version of each of its groups (known from the version row,
	 * so groups of deactivated plugins count too) and asks the driver to delete the entries by key prefix.
	 */
	public static function purge_plugin( string $plugin ): void {
		if ( 1 !== preg_match( self::NAME_RE, $plugin ) ) {
			return;
		}
		$versions = self::versions();
		foreach ( array_keys( $versions ) as $id ) {
			if ( 0 === strpos( (string) $id, $plugin . '.' ) ) {
				$versions[ $id ]++;
			}
		}
		self::$versions = $versions;
		update_option( self::OPT_VERSIONS, $versions, true );
		$stats = self::stats_raw();
		foreach ( array_keys( $stats ) as $id ) {
			if ( 0 === strpos( (string) $id, $plugin . '.' ) ) {
				unset( $stats[ $id ] );
			}
		}
		update_option( self::OPT_STATS, $stats, false );
		$prefix = 'rbnkit_' . $plugin . '.';
		foreach ( array( new TransientDriver(), new WpCacheDriver() ) as $driver ) {
			$driver->purge( $prefix );
		}
		foreach ( self::$drivers as $driver ) {
			$driver->purge( $prefix );
		}
	}

	/**
	 * Cheap per-group counters for the admin page.
	 *
	 * @return array<int, array<string, mixed>> id, plugin, name, ttl, persistent, driver, version, sets, flushed_at.
	 */
	public static function stats(): array {
		$versions = self::versions();
		$stored   = self::stats_raw();
		$out      = array();
		foreach ( self::$groups as $id => $def ) {
			$out[] = array(
				'id'         => $id,
				'plugin'     => $def['plugin'],
				'name'       => $def['name'],
				'ttl'        => $def['ttl'],
				'persistent' => $def['persistent'],
				'driver'     => ( new \ReflectionClass( self::driver( $def ) ) )->getShortName(),
				'version'    => $versions[ $id ] ?? 1,
				'sets'       => (int) ( $stored[ $id ]['sets'] ?? 0 ) + (int) ( self::$pending[ $id ] ?? 0 ),
				'flushed_at' => (int) ( $stored[ $id ]['flushed_at'] ?? 0 ),
			);
		}
		return $out;
	}

	/** `shutdown`: saves the write counters with one option update, only when something was written. */
	public static function save_stats(): void {
		if ( ! self::$pending ) {
			return;
		}
		$stats = self::stats_raw();
		foreach ( self::$pending as $id => $n ) {
			$stats[ $id ] = array(
				'sets'       => (int) ( $stats[ $id ]['sets'] ?? 0 ) + $n,
				'flushed_at' => (int) ( $stats[ $id ]['flushed_at'] ?? 0 ),
			);
		}
		self::$pending = array();
		update_option( self::OPT_STATS, $stats, false );
	}

	/** Test helper: forgets registrations, drivers and in-request state (stored options are not touched). */
	public static function reset(): void {
		self::$groups   = array();
		self::$drivers  = array();
		self::$versions = null;
		self::$pending  = array();
	}

	/* ------------------------------------------------------------------ internals */

	/** @return array{plugin:string,name:string,id:string,ttl:int,persistent:bool}|null */
	private static function def( string $group ): ?array {
		if ( isset( self::$groups[ $group ] ) ) {
			return self::$groups[ $group ];
		}
		_doing_it_wrong( __METHOD__, 'Cache group [' . substr( (string) preg_replace( '/[^a-z0-9_.\-]/i', '', $group ), 0, 60 ) . '] is not registered.', '0.1.0' );
		return null;
	}

	private static function clamp_ttl( int $ttl ): int {
		return max( 1, min( self::MAX_TTL, $ttl ) );
	}

	/** Key shape `rbnkit_<plugin>.<name>.<version>.<md5>`; "." never occurs in plugin/name, so a plugin prefix cannot match another plugin. */
	private static function key( array $def, string $key ): string {
		$versions = self::versions();
		return 'rbnkit_' . $def['plugin'] . '.' . $def['name'] . '.' . ( $versions[ $def['id'] ] ?? 1 ) . '.' . md5( $key );
	}

	private static function driver( array $def ): CacheDriver {
		if ( isset( self::$drivers[ $def['id'] ] ) ) {
			return self::$drivers[ $def['id'] ];
		}
		if ( ! $def['persistent'] ) {
			$driver = new MemoryDriver();
		} else {
			$driver = wp_using_ext_object_cache() ? new WpCacheDriver() : new TransientDriver();
		}
		/**
		 * Swaps the storage of a cache group.
		 *
		 * @param CacheDriver $driver Default driver.
		 * @param array       $def    Group definition (plugin, name, id, ttl, persistent).
		 */
		$filtered = apply_filters( 'rbnkit_core_cache_driver', $driver, $def );
		if ( ! $filtered instanceof CacheDriver ) {
			$filtered = $driver;
		}
		self::$drivers[ $def['id'] ] = $filtered;
		return $filtered;
	}

	/** @return array<string, int> */
	private static function versions(): array {
		if ( null === self::$versions ) {
			$stored         = get_option( self::OPT_VERSIONS, array() );
			self::$versions = array_map( 'intval', is_array( $stored ) ? $stored : array() );
		}
		return self::$versions;
	}

	/** @return array<string, array<string, int>> */
	private static function stats_raw(): array {
		$stored = get_option( self::OPT_STATS, array() );
		return is_array( $stored ) ? $stored : array();
	}
}
