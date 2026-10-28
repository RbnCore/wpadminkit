<?php
/**
 * Central log: channels registered by plugins, one table, personal data scrubbed centrally, pruned daily.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core;

use AdminKit\Core\Driver\LogDriver;
use AdminKit\Core\Driver\TableLogDriver;

defined( 'ABSPATH' ) || exit;

final class Log {

	public const PRUNE_HOOK = 'rbnkit_core_prune';

	private const NAME_RE     = '/^[a-z0-9_]{1,20}$/';
	private const LEVELS      = array( 'debug', 'info', 'warning', 'error' );
	private const MAX_MESSAGE = 500;
	private const MAX_CONTEXT = 4000;

	/** @var array<string, array{plugin:string,name:string,id:string,retention_days:int,max_rows:int,levels:string[]}> */
	private static array $channels = array();

	private static ?LogDriver $driver = null;

	/**
	 * Registers a log channel. The first registration of an id wins.
	 *
	 * @param string   $plugin         Plugin slug part (`[a-z0-9_]`, 20 characters at most).
	 * @param string   $name           Channel name (same rule).
	 * @param int      $retention_days Rows older than this are pruned (1 to 365).
	 * @param int      $max_rows       Newest rows kept per channel (100 to 100000).
	 * @param string[] $levels         Accepted levels: debug, info, warning, error.
	 * @return string Channel id `plugin.name`, empty string when the arguments are invalid.
	 */
	public static function register_channel( string $plugin, string $name, int $retention_days = 90, int $max_rows = 500, array $levels = array( 'info', 'warning', 'error' ) ): string {
		if ( 1 !== preg_match( self::NAME_RE, $plugin ) || 1 !== preg_match( self::NAME_RE, $name ) ) {
			_doing_it_wrong( __METHOD__, 'Plugin and channel names must match [a-z0-9_]{1,20}.', '0.1.0' );
			return '';
		}
		$id = $plugin . '.' . $name;
		if ( ! isset( self::$channels[ $id ] ) ) {
			$levels = array_values( array_intersect( self::LEVELS, array_map( 'strval', $levels ) ) );
			self::$channels[ $id ] = array(
				'plugin'         => $plugin,
				'name'           => $name,
				'id'             => $id,
				'retention_days' => max( 1, min( 365, $retention_days ) ),
				'max_rows'       => max( 100, min( 100000, $max_rows ) ),
				'levels'         => $levels ? $levels : array( 'info', 'warning', 'error' ),
			);
		}
		return $id;
	}

	/** @return array<string, array{plugin:string,name:string,id:string,retention_days:int,max_rows:int,levels:string[]}> */
	public static function channels(): array {
		return self::$channels;
	}

	/**
	 * Writes one row. Returns false when the channel is unknown, the level is not accepted by the channel, or the write failed.
	 *
	 * @param array<mixed> $context Extra data; scrubbed by Privacy before storage.
	 */
	public static function log( string $channel, string $level, string $event, string $message = '', array $context = array() ): bool {
		if ( ! isset( self::$channels[ $channel ] ) ) {
			_doing_it_wrong( __METHOD__, esc_html( 'Log channel "' . $channel . '" is not registered.' ), '0.1.0' );
			return false;
		}
		$event = substr( (string) preg_replace( '/[^a-z0-9_.:\-]/', '', strtolower( $event ) ), 0, 60 );
		if ( '' === $event || ! in_array( $level, self::$channels[ $channel ]['levels'], true ) ) {
			return false;
		}
		/**
		 * Last chance to shape the context. It runs BEFORE the personal-data filter, so it cannot let anything past it.
		 *
		 * @param array<mixed> $context Raw context.
		 * @param string       $channel Channel id.
		 * @param string       $level   Level.
		 * @param string       $event   Event code.
		 */
		$context = apply_filters( 'rbnkit_core_log_context', $context, $channel, $level, $event );
		$context = is_array( $context ) ? Privacy::context( $context ) : array();
		$json    = $context ? (string) wp_json_encode( $context ) : '';
		if ( strlen( $json ) > self::MAX_CONTEXT ) {
			$json = (string) wp_json_encode( array( '_truncated' => true ) );
		}
		$row = array(
			'channel'    => $channel,
			'level'      => $level,
			'event'      => $event,
			'message'    => mb_substr( Privacy::text( $message ), 0, self::MAX_MESSAGE ),
			'context'    => $json,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$id  = self::driver()->insert( $row );
		if ( $id > 0 ) {
			/**
			 * Fires after a row was stored.
			 *
			 * @param array<string, mixed> $row Stored (scrubbed) row with its id.
			 */
			do_action( 'rbnkit_core_log_written', $row + array( 'id' => $id ) );
		}
		return $id > 0;
	}

	/**
	 * Reads one channel, newest first.
	 *
	 * @param array<string, mixed> $filters level, type (event code), from, to (Y-m-d), page, per_page (1 to 50).
	 * @return array{total:int,items:array<int,array<string,mixed>>}
	 */
	public static function query( string $channel, array $filters = array() ): array {
		if ( ! isset( self::$channels[ $channel ] ) ) {
			return array(
				'total' => 0,
				'items' => array(),
			);
		}
		$clean = array(
			'level'    => isset( $filters['level'] ) && in_array( $filters['level'], self::LEVELS, true ) ? (string) $filters['level'] : '',
			'event'    => isset( $filters['type'] ) ? (string) $filters['type'] : ( isset( $filters['event'] ) ? (string) $filters['event'] : '' ),
			'from'     => self::date( $filters['from'] ?? '' ),
			'to'       => self::date( $filters['to'] ?? '' ),
			'page'     => max( 1, (int) ( $filters['page'] ?? 1 ) ),
			'per_page' => max( 1, min( 50, (int) ( $filters['per_page'] ?? 8 ) ) ),
		);
		return self::driver()->query( $channel, $clean );
	}

	public static function count( string $channel ): int {
		return isset( self::$channels[ $channel ] ) ? self::driver()->count( $channel ) : 0;
	}

	/** Prunes one channel by age, then by row limit. Returns the deleted count. */
	public static function prune_channel( string $channel, ?int $now = null ): int {
		if ( ! isset( self::$channels[ $channel ] ) ) {
			return 0;
		}
		$def     = self::$channels[ $channel ];
		$cutoff  = gmdate( 'Y-m-d H:i:s', ( $now ?? time() ) - $def['retention_days'] * DAY_IN_SECONDS );
		$deleted = self::driver()->prune( $channel, $cutoff, $def['max_rows'] );
		if ( $deleted > 0 ) {
			/**
			 * Fires after a channel was pruned.
			 *
			 * @param string $channel Channel id.
			 * @param int    $deleted Deleted rows.
			 */
			do_action( 'rbnkit_core_pruned', $channel, $deleted );
		}
		return $deleted;
	}

	/** Cron callback: prunes every registered channel. */
	public static function prune_all(): void {
		foreach ( array_keys( self::$channels ) as $channel ) {
			self::prune_channel( $channel );
		}
	}

	/** Deletes every row of a plugin (uninstall path). Returns the deleted count. */
	public static function purge_plugin( string $plugin ): int {
		return 1 === preg_match( self::NAME_RE, $plugin ) ? self::driver()->purge( $plugin . '.' ) : 0;
	}

	public static function driver(): LogDriver {
		if ( null === self::$driver ) {
			/**
			 * Swaps the log storage.
			 *
			 * @param LogDriver $driver Default table driver.
			 */
			$driver       = apply_filters( 'rbnkit_core_log_driver', new TableLogDriver() );
			self::$driver = $driver instanceof LogDriver ? $driver : new TableLogDriver();
		}
		return self::$driver;
	}

	/** Test helper: forgets registrations and the driver (stored rows are not touched). */
	public static function reset(): void {
		self::$channels = array();
		self::$driver   = null;
	}

	private static function date( $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}
}
