<?php
/**
 * Log table. Additive and idempotent: dbDelta may run any number of times; the stored version avoids needless runs.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const VERSION    = '1';
	public const OPT_DB_VER = 'rbnkit_core_db_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rbnkit_log';
	}

	/** Cheap per-request check (autoloaded option): installs or upgrades only when the version differs. */
	public static function maybe_upgrade(): void {
		if ( self::VERSION !== (string) get_option( self::OPT_DB_VER, '' ) ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  channel varchar(60) NOT NULL,
  level varchar(10) NOT NULL,
  event varchar(60) NOT NULL,
  message varchar(500) NOT NULL DEFAULT '',
  context text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY channel_created (channel,created_at)
) {$charset};"
		);
		if ( self::table_exists() ) {
			update_option( self::OPT_DB_VER, self::VERSION, true );
		}
	}

	public static function table_exists(): bool {
		global $wpdb;
		return self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
