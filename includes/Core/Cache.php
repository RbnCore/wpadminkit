<?php
/**
 * Central cache: named groups registered by plugins, versioned keys, stampede lock, pluggable driver.
 *
 * A thin layer over the WordPress object cache / transients. Clearing a group raises its version number
 * (old entries become unreachable and expire by TTL); no rows are scanned or deleted.
 *
 * Every group keeps its version counter in its OWN option row (`rbnkit_core_gv_<plugin>.<name>`). One row per
 * group means a flush can never drop another group's counter, and the counter is raised with a single atomic
 * UPDATE, so two processes can never lower it. The legacy option `rbnkit_core_group_versions` is kept as a
 * read-only mirror (for readers of the old shape) and as the seed of the first run after the upgrade.
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

	/** Legacy option (all groups in one row). Kept as a mirror and as the seed of the first run after the upgrade. */
	public const OPT_VERSIONS = 'rbnkit_core_group_versions';
	public const OPT_STATS    = 'rbnkit_core_cache_stats';

	/** One option row per group: `rbnkit_core_gv_<plugin>.<name>`. */
	public const OPT_GROUP_PREFIX = 'rbnkit_core_gv_';

	private const NAME_RE   = '/^[a-z0-9_]{1,20}$/';
	private const MAX_TTL   = 2592000; // 30 days: a never-expiring transient would be autoloaded.
	private const LOCK_TTL  = 30;
	private const WAIT_STEP = 100000;  // 100 ms.
	private const WAIT_MAX  = 5;       // 5 steps = 500 ms at most.

	/** @var array<string, array{plugin:string,name:string,id:string,ttl:int,persistent:bool}> */
	private static array $groups = array();

	/** @var array<string, CacheDriver> */
	private static array $drivers = array();

	/** @var array<string, int> Counter of every group known in this request. */
	private static array $versions = array();

	/** @var array<string, int> Writes counted in this request, saved once at shutdown. */
	private static array $pending = array();

	/** True when a counter row was created in this request: the legacy mirror has to catch up. */
	private static bool $mirror_dirty = false;

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
			// The group owns a counter row from now on: a group that was never cleared can no longer fall back to
			// version 1 and make an old entry reachable again.
			self::version( $id );
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
		// The group owns a counter row before it stores anything: a group that is written to but never cleared must
		// still have a counter of its own (otherwise it keeps version 1 forever and old entries stay reachable).
		self::version( $def['id'] );
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
		$option = self::group_option( $def['id'] );
		self::version( $def['id'] ); // The row exists (above every stored entry) before it is raised.
		self::bump_row( $option );
		self::$versions[ $def['id'] ] = self::row_value( $option );
		self::sync_option_cache( $option, self::$versions[ $def['id'] ] );
		self::sync_mirror();
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
	 * Removes everything one plugin cached: raises the version of each of its groups (known from the counter rows,
	 * so groups of deactivated plugins count too) and asks the driver to delete the entries by key prefix.
	 */
	public static function purge_plugin( string $plugin ): void {
		if ( 1 !== preg_match( self::NAME_RE, $plugin ) ) {
			return;
		}
		// Groups of the plugin: the counter rows and the groups the legacy mirror still knows (a group of a
		// deactivated plugin has no row yet, but the mirror knows its id).
		$ids = array();
		foreach ( self::plugin_group_options( $plugin ) as $option ) {
			$ids[] = substr( $option, strlen( self::OPT_GROUP_PREFIX ) );
		}
		$mirror = get_option( self::OPT_VERSIONS, array() );
		if ( is_array( $mirror ) ) {
			foreach ( array_keys( $mirror ) as $id ) {
				if ( 0 === strpos( (string) $id, $plugin . '.' ) ) {
					$ids[] = (string) $id;
				}
			}
		}
		foreach ( array_unique( $ids ) as $id ) {
			$option = self::group_option( $id );
			self::version( $id ); // The row exists before it is raised.
			self::bump_row( $option );
			self::$versions[ $id ] = self::row_value( $option );
			self::sync_option_cache( $option, self::$versions[ $id ] );
		}
		self::sync_mirror();
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
		if ( self::$mirror_dirty ) {
			self::sync_mirror();
		}
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
		self::$groups       = array();
		self::$drivers      = array();
		self::$versions     = array();
		self::$pending      = array();
		self::$mirror_dirty = false;
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

	/** The option row of one group. */
	public static function group_option( string $id ): string {
		return self::OPT_GROUP_PREFIX . $id;
	}

	/**
	 * The counter of one group. The row is created on first use, above every value the group ever had, so an old
	 * entry can never become reachable again (a counter that fell back to 1 would revive it).
	 */
	private static function version( string $id ): int {
		if ( isset( self::$versions[ $id ] ) ) {
			return self::$versions[ $id ];
		}
		$option = self::group_option( $id );
		$stored = get_option( $option, false );
		$value  = is_numeric( $stored ) ? (int) $stored : 0;
		$legacy = self::legacy_version( $id );
		if ( $value < 1 ) {
			// The row is missing. It starts above every version the group has ever reached (the legacy mirror and
			// the versions of the stored entries), so no old entry can become reachable again.
			$high = max( $legacy, self::max_stored_version( $id ) );
			self::create_row( $option, $high > 0 ? $high + 1 : 1 );
			$value = self::row_value( $option );
			self::$mirror_dirty = true;
		} elseif ( $value < $legacy ) {
			// The row was written back to a lower value (a stale writer or a hand-made edit). The mirror holds the
			// high-water mark, so the row is raised again: a counter that falls makes an old entry reachable.
			self::create_row( $option, $legacy + 1 );
			$value = self::row_value( $option );
			self::$mirror_dirty = true;
		}
		self::sync_option_cache( $option, $value );
		return self::$versions[ $id ] = $value;
	}

	/** @return array<string, int> */
	private static function versions(): array {
		foreach ( array_keys( self::$groups ) as $id ) {
			self::version( $id );
		}
		return self::$versions;
	}

	/** The counter the legacy mirror knows for one group (0 when it does not know it). */
	private static function legacy_version( string $id ): int {
		$stored = get_option( self::OPT_VERSIONS, array() );
		if ( is_array( $stored ) && isset( $stored[ $id ] ) && is_numeric( $stored[ $id ] ) ) {
			return (int) $stored[ $id ];
		}
		return 0;
	}

	/** The highest version among the stored entries of one group (0 when there is none). */
	private static function max_stored_version( string $id ): int {
		global $wpdb;
		if ( ! preg_match( '/^([a-z0-9_]{1,20})\.([a-z0-9_]{1,20})$/', $id, $m ) ) {
			return 0;
		}
		// Stored key shape: `_transient_rbnkit_<plugin>.<name>.<version>.<md5>` (the md5 has no dot).
		$like = $wpdb->esc_like( '_transient_rbnkit_' . $m[1] . '.' . $m[2] . '.' ) . '%';
		$max  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(option_name, '.', 3), '.', -1) AS UNSIGNED)) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);
		return (int) $max;
	}

	/**
	 * Creates the counter row of a group, or raises it to `$value` when the row exists. A counter that another
	 * process raised meanwhile is never lowered (the write is a GREATEST, not a plain replace).
	 */
	private static function create_row( string $option, int $value ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'on') ON DUPLICATE KEY UPDATE option_value = GREATEST(CAST(option_value AS UNSIGNED), CAST(VALUES(option_value) AS UNSIGNED)), option_id = option_id",
				$option,
				(string) $value
			)
		);
		$raised = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = GREATEST(CAST(option_value AS UNSIGNED), %d) WHERE option_name = %s",
				$value,
				$option
			)
		);
		if ( false === $raised ) {
			$stored = get_option( $option, false );
			if ( ! is_numeric( $stored ) || (int) $stored < $value ) {
				update_option( $option, (string) $value, true );
			}
		}
	}

	/** Raises one counter by one. One atomic UPDATE: two processes can never lower it and never skip a step. */
	private static function bump_row( string $option ): void {
		global $wpdb;
		$ok = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
				$option
			)
		);
		if ( false === $ok ) {
			$stored = get_option( $option, false );
			update_option( $option, (string) ( is_numeric( $stored ) ? (int) $stored + 1 : 1 ), true );
		}
	}

	/** The value of one counter row (0 when the row is gone). */
	private static function row_value( string $option ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT CAST(option_value AS UNSIGNED) FROM {$wpdb->options} WHERE option_name = %s", $option )
		);
	}

	/** The counter rows of one plugin (groups of deactivated plugins included). @return string[] */
	private static function plugin_group_options( string $plugin ): array {
		global $wpdb;
		$like = $wpdb->esc_like( self::OPT_GROUP_PREFIX . $plugin . '.' ) . '%';
		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);
		return array_values( array_filter( (array) $rows, static fn( $n ): bool => is_string( $n ) && str_starts_with( $n, self::OPT_GROUP_PREFIX . $plugin . '.' ) ) );
	}

	/** Keeps the object cache in step with a counter row that was written with direct SQL. */
	private static function sync_option_cache( string $option, int $value ): void {
		$alloptions = wp_load_alloptions( true );
		if ( is_array( $alloptions ) ) {
			if ( isset( $alloptions[ $option ] ) ) {
				$alloptions[ $option ] = (string) $value;
				wp_cache_set( 'alloptions', $alloptions, 'options' );
			} else {
				wp_cache_set( $option, (string) $value, 'options' );
			}
		}
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * Keeps the legacy option in step with the counter rows: every value is raised, never lowered, and a value that
	 * is missing from the mirror is written again. The mirror is not used for the cache keys any more.
	 */
	private static function sync_mirror(): void {
		self::$mirror_dirty = false;
		$mirror             = get_option( self::OPT_VERSIONS, array() );
		$mirror             = is_array( $mirror ) ? $mirror : array();
		$changed            = false;
		// Every counter row wins over the mirror: a mirror value that is lower is raised, never lowered.
		foreach ( self::all_group_options() as $id => $option ) {
			$stored = get_option( $option, false );
			if ( ! is_numeric( $stored ) ) {
				continue;
			}
			$value = (int) $stored;
			if ( ! isset( $mirror[ $id ] ) || (int) $mirror[ $id ] < $value ) {
				$mirror[ $id ] = $value;
				$changed       = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPT_VERSIONS, $mirror, true );
		}
	}

	/** Every counter row of the site: id => option name. @return array<string, string> */
	private static function all_group_options(): array {
		global $wpdb;
		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::OPT_GROUP_PREFIX ) . '%' )
		);
		$out  = array();
		foreach ( (array) $rows as $name ) {
			if ( is_string( $name ) && str_starts_with( $name, self::OPT_GROUP_PREFIX ) ) {
				$out[ substr( $name, strlen( self::OPT_GROUP_PREFIX ) ) ] = $name;
			}
		}
		return $out;
	}

	/** Key shape `rbnkit_<plugin>.<name>.<version>.<md5>`; "." never occurs in plugin/name, so a plugin prefix cannot match another plugin. */
	private static function key( array $def, string $key ): string {
		return 'rbnkit_' . $def['plugin'] . '.' . $def['name'] . '.' . self::version( $def['id'] ) . '.' . md5( $key );
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

	/** @return array<string, array<string, int>> */
	private static function stats_raw(): array {
		$stored = get_option( self::OPT_STATS, array() );
		return is_array( $stored ) ? $stored : array();
	}
}
