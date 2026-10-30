<?php
/**
 * AdminKit Helpers: the ONE database gateway of every RBN plugin.
 *
 * Two jobs, one file:
 *
 * - schema helpers (`install`, `maybe_upgrade`, `drop_tables`) — idempotent, additive;
 * - query helpers (`query`, `get_var`, `get_row`, `get_results`, `get_col`, `insert`, `update`, `delete`, `in`).
 *
 * A plugin never writes `$wpdb->…` itself: every call goes through these thin wrappers, which prepare the SQL with
 * the given arguments first. Placeholders are WordPress' own (`%s`, `%d`, `%i`, `%f`); a value is never pasted into
 * an SQL string.
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

	/* ------------------------------------------------------------------ queries */

	/**
	 * Runs a write statement (INSERT / UPDATE / DELETE / DDL).
	 *
	 * @param string              $sql  SQL with placeholders.
	 * @param array<int, mixed>   $args Values for the placeholders.
	 * @return int|bool Rows affected, or false when the query failed.
	 */
	public static function query( string $sql, array $args = array() ) {
		global $wpdb;
		return $wpdb->query( self::prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * One value of the first row.
	 *
	 * @param string            $sql    SQL with placeholders.
	 * @param array<int, mixed> $args   Values for the placeholders.
	 * @param int               $column Column offset.
	 * @return string|null
	 */
	public static function get_var( string $sql, array $args = array(), int $column = 0 ): ?string {
		global $wpdb;
		$value = $wpdb->get_var( self::prepare( $sql, $args ), $column ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null === $value ? null : (string) $value;
	}

	/**
	 * One row.
	 *
	 * @param string            $sql    SQL with placeholders.
	 * @param array<int, mixed> $args   Values for the placeholders.
	 * @param string            $output OBJECT | ARRAY_A | ARRAY_N.
	 * @return array<string,mixed>|object|null
	 */
	public static function get_row( string $sql, array $args = array(), string $output = OBJECT ) {
		global $wpdb;
		return $wpdb->get_row( self::prepare( $sql, $args ), $output ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Every row of a result set.
	 *
	 * @param string            $sql    SQL with placeholders.
	 * @param array<int, mixed> $args   Values for the placeholders.
	 * @param string            $output OBJECT | ARRAY_A | ARRAY_N.
	 * @return array<int, mixed>
	 */
	public static function get_results( string $sql, array $args = array(), string $output = OBJECT ): array {
		global $wpdb;
		$rows = $wpdb->get_results( self::prepare( $sql, $args ), $output ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One column of a result set.
	 *
	 * @param string            $sql    SQL with placeholders.
	 * @param array<int, mixed> $args   Values for the placeholders.
	 * @param int               $column Column offset.
	 * @return array<int, mixed>
	 */
	public static function get_col( string $sql, array $args = array(), int $column = 0 ): array {
		global $wpdb;
		$col = $wpdb->get_col( self::prepare( $sql, $args ), $column ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $col ) ? $col : array();
	}

	/**
	 * Inserts one row.
	 *
	 * @param string               $table  Full table name (`self::table()` / `Db::table()`).
	 * @param array<string, mixed> $data   Column => value.
	 * @param array<int, string>   $format Placeholder per column (empty = let WordPress decide).
	 * @return int|bool Insert id, or false on failure.
	 */
	public static function insert( string $table, array $data, array $format = array() ) {
		global $wpdb;
		return $wpdb->insert( $table, $data, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** The insert id of the last `insert()`. */
	public static function insert_id(): int {
		global $wpdb;
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates rows.
	 *
	 * @param string               $table        Full table name.
	 * @param array<string, mixed> $data         Column => new value.
	 * @param array<string, mixed> $where        Column => value of the WHERE clause.
	 * @param array<int, string>   $format       Placeholder per data column.
	 * @param array<int, string>   $where_format Placeholder per WHERE column.
	 * @return int|bool Rows affected, or false on failure.
	 */
	public static function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ) {
		global $wpdb;
		return $wpdb->update( $table, $data, $where, $format, $where_format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes rows.
	 *
	 * @param string               $table        Full table name.
	 * @param array<string, mixed> $where        Column => value of the WHERE clause.
	 * @param array<int, string>   $where_format Placeholder per WHERE column.
	 * @return int|bool Rows affected, or false on failure.
	 */
	public static function delete( string $table, array $where, array $where_format = array() ) {
		global $wpdb;
		return $wpdb->delete( $table, $where, $where_format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Placeholder list for `IN ( … )`: `Db::in( $ids )` gives `%d, %d, %d`.
	 *
	 * The values are passed to the query as arguments in the SAME order; an empty list becomes NULL, which matches
	 * no row (`IN (NULL)`), so the query stays valid.
	 *
	 * @param array<int, mixed> $values Values that will be passed as query arguments.
	 * @param string            $format Placeholder per value.
	 */
	public static function in( array $values, string $format = '%d' ): string {
		if ( array() === $values ) {
			return 'NULL';
		}
		return implode( ', ', array_fill( 0, count( $values ), $format ) );
	}

	/** Prepares the SQL when there are arguments; an empty argument list is returned unchanged. */
	private static function prepare( string $sql, array $args ): string {
		global $wpdb;
		if ( array() === $args ) {
			return $sql;
		}
		self::check_shape( $sql, $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the query is prepared right here.
		return (string) $wpdb->prepare( $sql, $args );
	}

	/**
	 * Catches the two shapes that make `$wpdb->prepare()` fail SILENTLY (WPRJ-691): an argument list that is a
	 * single nested array (`array( array_merge( … ) )` — one argument, N placeholders) and a placeholder/argument
	 * count mismatch. WordPress logs a notice and returns an empty string for the first; without this check the
	 * caller just gets zero rows and nobody notices.
	 *
	 * The report carries COUNTS and the caller location only — never the values, never the SQL text (both may
	 * contain personal data).
	 */
	private static function check_shape( string $sql, array $args ): void {
		$nested = false;
		foreach ( $args as $value ) {
			if ( is_array( $value ) ) {
				$nested = true;
				break;
			}
		}
		$placeholders = self::placeholder_count( $sql );
		if ( ! $nested && $placeholders === count( $args ) ) {
			return;
		}
		self::shape_error( $nested, $placeholders, count( $args ) );
	}

	/**
	 * Number of `%s` / `%d` / `%f` / `%F` / `%i` placeholders in the query, by WordPress' own rules
	 * (`class-wpdb.php::prepare()`): `%%` and any percent that does not start a placeholder (e.g. the `%` of a
	 * LIKE pattern inside quotes) do not count.
	 */
	private static function placeholder_count( string $sql ): int {
		if ( false === strpos( $sql, '%' ) ) {
			return 0;
		}
		$allowed_format = '(?:[1-9][0-9]*[$])?[-+0-9]*(?: |0|\'.)?[-+0-9]*(?:\.[0-9]+)?';
		$query          = str_replace( array( "'%s'", '"%s"' ), '%s', $sql );
		$query          = (string) preg_replace( "/%(?:%|$|(?!($allowed_format)?[sdfFi]))/", '%%\\1', $query );
		$split          = preg_split( "/(^|[^%]|(?:%%)+)(%(?:$allowed_format)?[sdfFi])/", $query, -1, PREG_SPLIT_DELIM_CAPTURE );
		return (int) ( ( count( (array) $split ) - 1 ) / 3 );
	}

	/** Reports a broken prepare() shape: `_doing_it_wrong()` (visible with WP_DEBUG) plus one line in `adminkit.db`. */
	private static function shape_error( bool $nested, int $placeholders, int $arguments ): void {
		static $reported = array();
		$caller = '';
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- locating the caller for the report.
			if ( isset( $frame['file'] ) && __FILE__ !== $frame['file'] ) {
				$caller = basename( (string) $frame['file'] ) . ':' . (int) ( $frame['line'] ?? 0 );
				break;
			}
		}
		$message = sprintf(
			'Db prepare() %s: %d placeholder(s) but %d argument(s).',
			$nested ? 'got a nested argument array' : 'argument count mismatch',
			$placeholders,
			$arguments
		);
		$key = $message . ' ' . $caller;
		if ( isset( $reported[ $key ] ) ) {
			return;
		}
		$reported[ $key ] = true;

		_doing_it_wrong( __METHOD__, esc_html( $message . ' ' . $caller ), ADMINKIT_VERSION );

		if ( class_exists( '\AdminKit\Core\Log' ) && isset( \AdminKit\Core\Log::channels()['adminkit.db'] ) ) {
			\AdminKit\Core\Log::log( 'adminkit.db', 'error', 'prepare_shape', $message, array( 'caller' => $caller ) );
		}
	}
}
