<?php
/**
 * Central cache: named groups registered by plugins, versioned keys, stampede lock, pluggable driver.
 *
 * A thin layer over the WordPress object cache / transients. Clearing a group raises its version number and then
 * DELETES the entries the raise made unreachable (their rows would otherwise pile up in `wp_options` until the TTL
 * runs out — on a shared host a frequently cleared group could bloat the table). The delete is batched and limited to
 * the flushed group's own key prefix (WPRJ-719).
 *
 * Every group keeps its version counter in its OWN option row (`rbnkit_core_gv_<plugin>.<name>`). One row per
 * group means a flush can never drop another group's counter, and the counter is raised with a single atomic
 * UPDATE, so two processes can never lower it. The counter is the ONE source: a row that is missing is created
 * above every version the group's stored entries have reached, so an old entry can never become reachable again.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core;

use AdminKit\Helpers\Db;

use AdminKit\Core\Driver\CacheDriver;
use AdminKit\Core\Driver\MemoryDriver;
use AdminKit\Core\Driver\TransientDriver;
use AdminKit\Core\Driver\WpCacheDriver;

defined( 'ABSPATH' ) || exit;

final class Cache {

	/** Statistics option (write counters per group). */
	public const OPT_STATS = 'rbnkit_core_cache_stats';

	/** One option row per group: `rbnkit_core_gv_<plugin>.<name>`. */
	public const OPT_GROUP_PREFIX = 'rbnkit_core_gv_';

	private const NAME_RE   = '/^[a-z0-9_]{1,20}$/';
	private const MAX_TTL   = 2592000; // 30 days: a never-expiring transient would be autoloaded.
	private const LOCK_TTL  = 30;
	private const WAIT_STEP = 100000;  // 100 ms.
	private const WAIT_MAX  = 5;       // 5 steps = 500 ms at most.

	/** Rows removed per DELETE when a flush sweeps the old versions of a group (WPRJ-719). */
	private const SWEEP_BATCH = 1000;

	/** Most batches one flush deletes (50 x 1000 rows); a bigger leftover is finished by the next flush. */
	private const SWEEP_ROUNDS = 50;

	/** @var array<string, array{plugin:string,name:string,id:string,ttl:int,persistent:bool}> */
	private static array $groups = array();

	/** @var array<string, CacheDriver> */
	private static array $drivers = array();

	/** @var array<string, int> Counter of every group known in this request. */
	private static array $versions = array();

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

	/** Clears a group by raising its version and removes the entries the raise made unreachable. */
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
		// The raise alone only makes the old entries unreachable; without this sweep their rows stay in `wp_options`
		// until the TTL runs out (WPRJ-719). Delete first, then report: a crash in between leaves dead rows that the
		// next flush of the same group removes, never a reachable entry.
		self::sweep_old( $def, self::$versions[ $def['id'] ] );
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
		// Groups of the plugin: the counter rows (a group of a deactivated plugin has a row too, because the row is
		// created above every stored entry when the group is first used).
		$ids = array();
		foreach ( self::plugin_group_options( $plugin ) as $option ) {
			$ids[] = substr( $option, strlen( self::OPT_GROUP_PREFIX ) );
		}
		foreach ( array_unique( $ids ) as $id ) {
			$option = self::group_option( $id );
			self::version( $id ); // The row exists before it is raised.
			self::bump_row( $option );
			self::$versions[ $id ] = self::row_value( $option );
			self::sync_option_cache( $option, self::$versions[ $id ] );
		}
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
		self::$versions = array();
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
		// The high-water mark comes from the group's own stored entries: an entry that was written with version N
		// means the counter has reached N, and a counter below N would make that entry reachable again.
		$high = self::max_stored_version( $id );
		if ( $value < 1 ) {
			// The row is missing. It starts above every version the group has ever reached, so no old entry can
			// become reachable again.
			self::create_row( $option, $high > 0 ? $high + 1 : 1 );
			$value = self::row_value( $option );
		} elseif ( $value < $high ) {
			// The row was written back to a lower value (a stale writer or a hand-made edit). The counter is raised
			// again: a counter that falls makes an old entry reachable.
			self::create_row( $option, $high + 1 );
			$value = self::row_value( $option );
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

	/** The highest version among the stored entries of one group (0 when there is none). */
	private static function max_stored_version( string $id ): int {
		global $wpdb;
		if ( ! preg_match( '/^([a-z0-9_]{1,20})\.([a-z0-9_]{1,20})$/', $id, $m ) ) {
			return 0;
		}
		// Stored key shape: `_transient_rbnkit_<plugin>.<name>.<version>.<md5>` (the md5 has no dot).
		$like = $wpdb->esc_like( '_transient_rbnkit_' . $m[1] . '.' . $m[2] . '.' ) . '%';
		$max  = Db::get_var( "SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(option_name, '.', 3), '.', -1) AS UNSIGNED)) FROM {$wpdb->options} WHERE option_name LIKE %s", array( $like ) );
		return (int) $max;
	}

	/**
	 * Deletes the rows of ONE group that a version raise made unreachable: every stored entry of the group whose
	 * version is below the current one (value, timeout and stampede-lock rows alike). Rows of other groups, of other
	 * plugins and the group's own counter row (`rbnkit_core_gv_…`) do not carry the key prefix and are never touched.
	 *
	 * Batched, so a group that already carries thousands of dead rows cannot hit the request time limit; whatever is
	 * left after the last batch is removed by the next flush of the same group.
	 *
	 * @param array{plugin:string,name:string,persistent:bool} $def     Group definition.
	 * @param int                                              $current The version the group was just raised to.
	 * @return int Rows deleted.
	 */
	private static function sweep_old( array $def, int $current ): int {
		global $wpdb;
		if ( empty( $def['persistent'] ) ) {
			return 0; // A request-local group never writes a row.
		}
		// Stored key shape: `_transient_rbnkit_<plugin>.<name>.<version>.<md5>` — plus its `_transient_timeout_`
		// twin and the `.lock` suffix of the stampede lock. Plugin and name carry no dot, so the third dot-separated
		// part is the version.
		$prefix  = 'rbnkit_' . $def['plugin'] . '.' . $def['name'] . '.';
		$sql     = "DELETE FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND CAST( SUBSTRING_INDEX( SUBSTRING_INDEX( option_name, '.', 3 ), '.', -1 ) AS UNSIGNED ) < %d LIMIT %d";
		$args    = array(
			$wpdb->esc_like( '_transient_' . $prefix ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%',
			$current,
			self::SWEEP_BATCH,
		);
		$deleted = 0;
		for ( $round = 0; $round < self::SWEEP_ROUNDS; $round++ ) {
			$rows = Db::query( $sql, $args );
			if ( false === $rows || (int) $rows < 1 ) {
				break;
			}
			$deleted += (int) $rows;
			if ( (int) $rows < self::SWEEP_BATCH ) {
				break;
			}
		}
		return $deleted;
	}

	/**
	 * Creates the counter row of a group, or raises it to `$value` when the row exists. A counter that another
	 * process raised meanwhile is never lowered (the write is a GREATEST, not a plain replace).
	 */
	private static function create_row( string $option, int $value ): void {
		global $wpdb;
		Db::query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'on') ON DUPLICATE KEY UPDATE option_value = GREATEST(CAST(option_value AS UNSIGNED), CAST(VALUES(option_value) AS UNSIGNED)), option_id = option_id", array( $option, (string) $value ) );
		$raised = Db::query( "UPDATE {$wpdb->options} SET option_value = GREATEST(CAST(option_value AS UNSIGNED), %d) WHERE option_name = %s", array( $value, $option ) );
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
		$ok = Db::query( "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s", array( $option ) );
		if ( false === $ok ) {
			$stored = get_option( $option, false );
			update_option( $option, (string) ( is_numeric( $stored ) ? (int) $stored + 1 : 1 ), true );
		}
	}

	/** The value of one counter row (0 when the row is gone). */
	private static function row_value( string $option ): int {
		global $wpdb;
		return (int) Db::get_var( "SELECT CAST(option_value AS UNSIGNED) FROM {$wpdb->options} WHERE option_name = %s", array( $option ) );
	}

	/** The counter rows of one plugin (groups of deactivated plugins included). @return string[] */
	private static function plugin_group_options( string $plugin ): array {
		global $wpdb;
		$like = $wpdb->esc_like( self::OPT_GROUP_PREFIX . $plugin . '.' ) . '%';
		$rows = Db::get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", array( $like ) );
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
