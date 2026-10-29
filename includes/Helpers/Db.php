<?php
/**
 * AdminKit Helpers: Idempotent database schema installer and upgrade scaffold.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Helpers;

defined( 'ABSPATH' ) || exit;

final class Db {

	/**
	 * Returns full WordPress prefixed table name for a plugin and table slug.
	 *
	 * @param string $prefix Plugin prefix (e.g. 'contenthealth' or 'seokit').
	 * @param string $name   Table slug (e.g. 'runs' or 'redirects').
	 */
	public static function table( string $prefix, string $name ): string {
		global $wpdb;
		return $wpdb->prefix . $prefix . '_' . $name;
	}

	/**
	 * Executes DDL statements via dbDelta and optionally records the schema version.
	 *
	 * @param array<int, string> $ddl_statements Array of CREATE TABLE statements.
	 * @param string             $version_option  Optional option name storing schema version.
	 * @param string|int|null    $version         Optional schema version value.
	 * @param bool               $autoload        Whether the version option should autoload.
	 */
	public static function install( array $ddl_statements, string $version_option = '', $version = null, bool $autoload = true ): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		foreach ( $ddl_statements as $ddl ) {
			if ( strpos( $ddl, '%s' ) !== false ) {
				$ddl = sprintf( $ddl, $charset );
			}
			dbDelta( $ddl );
		}

		if ( '' !== $version_option && null !== $version ) {
			update_option( $version_option, $version, $autoload );
		}
	}

	/**
	 * Checks the stored schema version against target version and calls installer if older/missing.
	 *
	 * @param string          $version_option Stored schema version option name.
	 * @param string|int      $target_version Target schema version.
	 * @param callable        $installer      Callable to execute when upgrade is needed.
	 */
	public static function maybe_upgrade( string $version_option, $target_version, callable $installer ): void {
		$current = get_option( $version_option );
		if ( (string) $current !== (string) $target_version ) {
			call_user_func( $installer );
		}
	}

	/**
	 * Drops given tables idempotently.
	 *
	 * @param array<int, string> $table_names Array of full table names.
	 */
	public static function drop_tables( array $table_names ): void {
		global $wpdb;
		foreach ( $table_names as $tbl ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- fixed table names.
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $tbl ) . '`' );
		}
	}
}
