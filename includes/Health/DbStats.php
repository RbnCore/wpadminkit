<?php
/**
 * Shared database health and statistics helper: read-only metrics for System Health and DB Maintenance.
 *
 * Used by AdminKit\Modules\SystemHealth (WPRJ-708) and AdminKit\Modules\DbMaintenance (WPRJ-709).
 * All queries are strictly read-only and routed through AdminKit\Helpers\Db.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Health;

use AdminKit\Helpers\Db;

defined( 'ABSPATH' ) || exit;

final class DbStats {

	/** Autoload size warning threshold in bytes (WordPress recommended threshold: 800 KB). */
	public const AUTOLOAD_WARN_BYTES = 819200;

	/** Autoload size danger threshold in bytes (1.5 MB). */
	public const AUTOLOAD_DANGER_BYTES = 1572864;

	/**
	 * Total database size, data size, index size and table count.
	 *
	 * @return array{total_size: int, data_size: int, index_size: int, table_count: int}
	 */
	public static function db_size(): array {
		$sql = 'SELECT
			COALESCE(SUM(data_length + index_length), 0) AS total_size,
			COALESCE(SUM(data_length), 0) AS data_size,
			COALESCE(SUM(index_length), 0) AS index_size,
			COUNT(*) AS table_count
			FROM information_schema.TABLES
			WHERE table_schema = DATABASE()';

		$row = Db::get_row( $sql, array(), ARRAY_A );

		return array(
			'total_size'  => isset( $row['total_size'] ) ? (int) $row['total_size'] : 0,
			'data_size'   => isset( $row['data_size'] ) ? (int) $row['data_size'] : 0,
			'index_size'  => isset( $row['index_size'] ) ? (int) $row['index_size'] : 0,
			'table_count' => isset( $row['table_count'] ) ? (int) $row['table_count'] : 0,
		);
	}

	/**
	 * Largest tables ordered by total size.
	 *
	 * @param int $limit Maximum number of tables to return.
	 * @return array<int, array{name: string, num_rows: int, data_size: int, index_size: int, total_size: int}>
	 */
	public static function largest_tables( int $limit = 10 ): array {
		$limit = max( 1, min( 100, $limit ) );
		$sql   = 'SELECT
			table_name AS name,
			COALESCE(table_rows, 0) AS num_rows,
			COALESCE(data_length, 0) AS data_size,
			COALESCE(index_length, 0) AS index_size,
			COALESCE(data_length + index_length, 0) AS total_size
			FROM information_schema.TABLES
			WHERE table_schema = DATABASE()
			ORDER BY total_size DESC
			LIMIT %d';

		$rows = Db::get_results( $sql, array( $limit ), ARRAY_A );
		$out  = array();

		foreach ( $rows as $r ) {
			$out[] = array(
				'name'       => isset( $r['name'] ) ? (string) $r['name'] : '',
				'num_rows'   => isset( $r['num_rows'] ) ? (int) $r['num_rows'] : 0,
				'data_size'  => isset( $r['data_size'] ) ? (int) $r['data_size'] : 0,
				'index_size' => isset( $r['index_size'] ) ? (int) $r['index_size'] : 0,
				'total_size' => isset( $r['total_size'] ) ? (int) $r['total_size'] : 0,
			);
		}

		return $out;
	}

	/**
	 * Autoload options statistics (total size, total count, largest autoload options).
	 *
	 * @param int $limit Maximum number of largest options to return.
	 * @return array{total_size: int, count: int, largest: array<int, array{name: string, size: int}>}
	 */
	public static function autoload_stats( int $limit = 10 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );

		$size_sql = 'SELECT COALESCE(SUM(LENGTH(option_value)), 0) AS total_size, COUNT(*) AS total_count
			FROM ' . $wpdb->options . '
			WHERE autoload IN (%s, %s, %s, %s)';

		$summary = Db::get_row( $size_sql, array( 'yes', 'on', 'auto', 'auto-on' ), ARRAY_A );

		$largest_sql = 'SELECT option_name AS name, LENGTH(option_value) AS size
			FROM ' . $wpdb->options . '
			WHERE autoload IN (%s, %s, %s, %s)
			ORDER BY size DESC
			LIMIT %d';

		$largest_rows = Db::get_results( $largest_sql, array( 'yes', 'on', 'auto', 'auto-on', $limit ), ARRAY_A );
		$largest      = array();

		foreach ( $largest_rows as $r ) {
			$largest[] = array(
				'name' => isset( $r['name'] ) ? (string) $r['name'] : '',
				'size' => isset( $r['size'] ) ? (int) $r['size'] : 0,
			);
		}

		return array(
			'total_size' => isset( $summary['total_size'] ) ? (int) $summary['total_size'] : 0,
			'count'      => isset( $summary['total_count'] ) ? (int) $summary['total_count'] : 0,
			'largest'    => $largest,
		);
	}

	/**
	 * Expired transients count in options table.
	 */
	public static function expired_transients_count(): int {
		global $wpdb;
		$sql  = 'SELECT COUNT(*) FROM ' . $wpdb->options . '
			WHERE (option_name LIKE %s OR option_name LIKE %s)
			AND CAST(option_value AS UNSIGNED) < %d
			AND CAST(option_value AS UNSIGNED) > 0';
		$args = array( '_transient_timeout_%', '_site_transient_timeout_%', time() );

		return (int) Db::get_var( $sql, $args );
	}

	/**
	 * Post revisions count.
	 */
	public static function revisions_count(): int {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' WHERE post_type = %s';

		return (int) Db::get_var( $sql, array( 'revision' ) );
	}

	/**
	 * Auto-draft posts count.
	 */
	public static function auto_drafts_count(): int {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' WHERE post_status = %s';

		return (int) Db::get_var( $sql, array( 'auto-draft' ) );
	}

	/**
	 * Trashed posts count.
	 */
	public static function trash_posts_count(): int {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' WHERE post_status = %s';

		return (int) Db::get_var( $sql, array( 'trash' ) );
	}

	/**
	 * Trashed or spam comments count.
	 */
	public static function trash_comments_count(): int {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . $wpdb->comments . ' WHERE comment_approved IN (%s, %s)';

		return (int) Db::get_var( $sql, array( 'trash', 'spam' ) );
	}

	/**
	 * Orphan postmeta rows count (postmeta rows whose post_id does not exist in wp_posts).
	 */
	public static function orphan_postmeta_count(): int {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . $wpdb->postmeta . ' pm
			LEFT JOIN ' . $wpdb->posts . ' p ON p.ID = pm.post_id
			WHERE p.ID IS NULL';

		return (int) Db::get_var( $sql, array() );
	}

	/**
	 * Orphan termmeta rows count (termmeta rows whose term_id does not exist in wp_terms).
	 */
	public static function orphan_termmeta_count(): int {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . $wpdb->termmeta . ' tm
			LEFT JOIN ' . $wpdb->terms . ' t ON t.term_id = tm.term_id
			WHERE t.term_id IS NULL';

		return (int) Db::get_var( $sql, array() );
	}

	/**
	 * Orphan commentmeta rows count (commentmeta rows whose comment_id does not exist in wp_comments).
	 */
	public static function orphan_commentmeta_count(): int {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . $wpdb->commentmeta . ' cm
			LEFT JOIN ' . $wpdb->comments . ' c ON c.comment_ID = cm.comment_id
			WHERE c.comment_ID IS NULL';

		return (int) Db::get_var( $sql, array() );
	}

	/**
	 * All maintenance candidate counts in a single associative array.
	 *
	 * @return array<string, int>
	 */
	public static function maintenance_counts(): array {
		return array(
			'expired_transients' => self::expired_transients_count(),
			'revisions'          => self::revisions_count(),
			'auto_drafts'        => self::auto_drafts_count(),
			'trash_posts'        => self::trash_posts_count(),
			'trash_comments'     => self::trash_comments_count(),
			'orphan_postmeta'    => self::orphan_postmeta_count(),
			'orphan_termmeta'    => self::orphan_termmeta_count(),
			'orphan_commentmeta' => self::orphan_commentmeta_count(),
		);
	}

	/**
	 * Complete database health summary for System Health reporting.
	 *
	 * @return array{
	 *     size: array{total_size: int, data_size: int, index_size: int, table_count: int},
	 *     tables: array<int, array{name: string, num_rows: int, data_size: int, index_size: int, total_size: int}>,
	 *     autoload: array{total_size: int, count: int, largest: array<int, array{name: string, size: int}>},
	 *     counts: array<string, int>
	 * }
	 */
	public static function summary(): array {
		return array(
			'size'     => self::db_size(),
			'tables'   => self::largest_tables( 10 ),
			'autoload' => self::autoload_stats( 10 ),
			'counts'   => self::maintenance_counts(),
		);
	}
}
