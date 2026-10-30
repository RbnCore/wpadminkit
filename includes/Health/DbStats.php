<?php
/**
 * Ortak veritabanı sayım/aday modeli: Sistem sağlığı ekranı ve Veritabanı bakımı modülü AYNI kaynağı okur.
 *
 * Tek SQL kaynağı ilkesi: sayı, boyut tahmini, aday listesi ve "silme anında hâlâ uygun mu" denetimi bu sınıftadır.
 * Bakım modülünün kuru koşu sayısı ile gerçekten sildiği sayı böylece aynı yüklemden gelir (kayma yok).
 *
 * Kapsam yalnız WordPress ÇEKİRDEK verisidir: süresi dolmuş geçici veri, eski revizyonlar, otomatik taslaklar,
 * çöp yazı/sayfa, spam/çöp yorum, yetim meta. Üçüncü taraf eklenti tabloları/seçenekleri kapsam dışıdır.
 *
 * Tablo adları `%i`, değerler `%s`/`%d` ile hazırlanır; kullanıcı girdisi SQL'e girmez.
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

	/** Bakım türleri (kararlı kimlikler). */
	public const TYPES = array( 'expired_transients', 'old_revisions', 'auto_drafts', 'trash_posts', 'spam_comments', 'orphan_meta' );

	public const DEFAULT_KEEP      = 5;
	public const KEEP_MIN          = 1;
	public const KEEP_MAX          = 100;
	public const TRASH_DAYS        = 30;
	public const SPAM_DAYS         = 30;
	public const AUTO_DRAFT_DAYS   = 7;

	/** Bir turda en çok işlenecek aday sayısı. */
	public const BATCH = 500;

	/* ------------------------------------------------------------------ System Health methods */

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
	 * Complete database health summary for System Health reporting.
	 *
	 * @return array{
	 *     size: array{total_size: int, data_size: int, index_size: int, table_count: int},
	 *     tables: array<int, array{name: string, num_rows: int, data_size: int, index_size: int, total_size: int}>,
	 *     autoload: array{total_size: int, count: int, largest: array<int, array{name: string, size: int}>},
	 *     counts: array<string, array{count: int, bytes: int}>
	 * }
	 */
	public static function summary(): array {
		return array(
			'size'     => self::db_size(),
			'tables'   => self::largest_tables( 10 ),
			'autoload' => self::autoload_stats( 10 ),
			'counts'   => self::counts(),
		);
	}

	/**
	 * Convenience method for System Health maintenance candidate counts.
	 *
	 * @return array<string, int>
	 */
	public static function maintenance_counts(): array {
		$counts = self::counts();
		return array(
			'expired_transients' => $counts['expired_transients']['count'] ?? 0,
			'revisions'          => $counts['old_revisions']['count'] ?? 0,
			'old_revisions'      => $counts['old_revisions']['count'] ?? 0,
			'auto_drafts'        => $counts['auto_drafts']['count'] ?? 0,
			'trash_posts'        => $counts['trash_posts']['count'] ?? 0,
			'trash_comments'     => $counts['spam_comments']['count'] ?? 0,
			'spam_comments'      => $counts['spam_comments']['count'] ?? 0,
			'orphan_meta'        => $counts['orphan_meta']['count'] ?? 0,
			'orphan_postmeta'    => self::orphan_postmeta_count(),
			'orphan_termmeta'    => self::orphan_termmeta_count(),
			'orphan_commentmeta' => self::orphan_commentmeta_count(),
		);
	}

	public static function expired_transients_count(): int {
		return self::count( 'expired_transients' );
	}

	public static function revisions_count(): int {
		return self::count( 'old_revisions' );
	}

	public static function auto_drafts_count(): int {
		return self::count( 'auto_drafts' );
	}

	public static function trash_posts_count(): int {
		return self::count( 'trash_posts' );
	}

	public static function trash_comments_count(): int {
		return self::count( 'spam_comments' );
	}

	public static function orphan_postmeta_count(): int {
		$map = self::meta_map( 'post' );
		return null !== $map ? (int) Db::get_var( self::orphan_count_sql( $map ), self::orphan_args( $map ) ) : 0;
	}

	public static function orphan_termmeta_count(): int {
		$map = self::meta_map( 'term' );
		return null !== $map ? (int) Db::get_var( self::orphan_count_sql( $map ), self::orphan_args( $map ) ) : 0;
	}

	public static function orphan_commentmeta_count(): int {
		$map = self::meta_map( 'comment' );
		return null !== $map ? (int) Db::get_var( self::orphan_count_sql( $map ), self::orphan_args( $map ) ) : 0;
	}

	/* ------------------------------------------------------------------ Maintenance & Candidate batching (WPRJ-709) */

	/** @return string[] */
	public static function types(): array {
		return self::TYPES;
	}

	public static function is_type( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * Doğrulanmış seçenekler (kırpılır; bilinmeyen anahtar yok sayılır).
	 *
	 * @param array<string, mixed> $opts keep (tutulacak revizyon sayısı).
	 * @return array{keep:int}
	 */
	public static function options( array $opts = array() ): array {
		$keep = isset( $opts['keep'] ) ? (int) $opts['keep'] : self::DEFAULT_KEEP;
		return array( 'keep' => max( self::KEEP_MIN, min( self::KEEP_MAX, $keep ) ) );
	}

	/**
	 * Tür başına kayıt sayısı ve kabaca silinecek bayt (tahmin).
	 *
	 * @param array<string, mixed> $opts Seçenekler.
	 * @return array<string, array{count:int,bytes:int}>
	 */
	public static function counts( array $opts = array() ): array {
		$out = array();
		foreach ( self::TYPES as $type ) {
			$out[ $type ] = array(
				'count' => self::count( $type, $opts ),
				'bytes' => self::bytes( $type, $opts ),
			);
		}
		return $out;
	}

	/** Tek türdeki uygun kayıt sayısı (`COUNT(*)`; kesin). */
	public static function count( string $type, array $opts = array() ): int {
		$o = self::options( $opts );
		global $wpdb;

		switch ( $type ) {
			case 'expired_transients':
				return (int) Db::get_var(
					'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s AND option_value < %d',
					array( $wpdb->options, self::transient_like(), time() )
				);
			case 'old_revisions':
				list( $sql, $args ) = self::revision_sql( 'SELECT COUNT(*)', $o );
				return (int) Db::get_var( $sql, $args );
			case 'auto_drafts':
				return (int) Db::get_var(
					'SELECT COUNT(*) FROM %i WHERE post_status = %s AND DATE_SUB( NOW(), INTERVAL %d DAY ) > post_date',
					array( $wpdb->posts, 'auto-draft', self::AUTO_DRAFT_DAYS )
				);
			case 'trash_posts':
				return (int) Db::get_var(
					'SELECT COUNT(*) FROM %i WHERE post_status = %s AND post_type IN ( \'post\', \'page\' ) AND post_modified_gmt <> %s AND post_modified_gmt < %s',
					array( $wpdb->posts, 'trash', '0000-00-00 00:00:00', self::cutoff( self::TRASH_DAYS ) )
				);
			case 'spam_comments':
				return (int) Db::get_var(
					'SELECT COUNT(*) FROM %i WHERE comment_approved IN ( \'spam\', \'trash\' ) AND comment_date_gmt <> %s AND comment_date_gmt < %s',
					array( $wpdb->comments, '0000-00-00 00:00:00', self::cutoff( self::SPAM_DAYS ) )
				);
			case 'orphan_meta':
				$total = 0;
				foreach ( self::meta_maps() as $map ) {
					$total += (int) Db::get_var( self::orphan_count_sql( $map ), self::orphan_args( $map ) );
				}
				return $total;
		}
		return 0;
	}

	/** Tek türde kabaca silinecek bayt (tahmin; arayüzde "~"). */
	public static function bytes( string $type, array $opts = array() ): int {
		$o = self::options( $opts );
		global $wpdb;

		switch ( $type ) {
			case 'expired_transients':
				return (int) Db::get_var(
					"SELECT COALESCE( SUM( LENGTH( v.option_value ) ), 0 )
					FROM %i t
					INNER JOIN %i v ON v.option_name = CONCAT( '_transient_', SUBSTRING( t.option_name, 19 ) )
					WHERE t.option_name LIKE %s AND t.option_value < %d",
					array( $wpdb->options, $wpdb->options, self::transient_like(), time() )
				);
			case 'old_revisions':
				list( $sql, $args ) = self::revision_sql( 'SELECT COALESCE( SUM( LENGTH( r.post_content ) ), 0 )', $o );
				return (int) Db::get_var( $sql, $args );
			case 'auto_drafts':
				return (int) Db::get_var(
					'SELECT COALESCE( SUM( LENGTH( post_content ) + LENGTH( post_title ) + LENGTH( post_excerpt ) ), 0 ) FROM %i WHERE post_status = %s AND DATE_SUB( NOW(), INTERVAL %d DAY ) > post_date',
					array( $wpdb->posts, 'auto-draft', self::AUTO_DRAFT_DAYS )
				);
			case 'trash_posts':
				return (int) Db::get_var(
					'SELECT COALESCE( SUM( LENGTH( post_content ) + LENGTH( post_title ) + LENGTH( post_excerpt ) ), 0 ) FROM %i WHERE post_status = %s AND post_type IN ( \'post\', \'page\' ) AND post_modified_gmt <> %s AND post_modified_gmt < %s',
					array( $wpdb->posts, 'trash', '0000-00-00 00:00:00', self::cutoff( self::TRASH_DAYS ) )
				);
			case 'spam_comments':
				return (int) Db::get_var(
					'SELECT COALESCE( SUM( LENGTH( comment_content ) + LENGTH( comment_author ) ), 0 ) FROM %i WHERE comment_approved IN ( \'spam\', \'trash\' ) AND comment_date_gmt <> %s AND comment_date_gmt < %s',
					array( $wpdb->comments, '0000-00-00 00:00:00', self::cutoff( self::SPAM_DAYS ) )
				);
			case 'orphan_meta':
				$total = 0;
				foreach ( self::meta_maps() as $map ) {
					$total += (int) Db::get_var( self::orphan_bytes_sql( $map ), self::orphan_args( $map ) );
				}
				return $total;
		}
		return 0;
	}

	/**
	 * Bir turda silinecek adaylar (en fazla $limit).
	 *
	 * Aday biçimi: kind = transient|revision|post|comment|meta; ayrıca id (int), name (transient) ya da
	 * table (meta: post|comment|term).
	 *
	 * @param array<string, mixed> $opts Seçenekler.
	 * @return array<int, array<string, mixed>>
	 */
	public static function batch( string $type, int $limit, array $opts = array() ): array {
		$o     = self::options( $opts );
		$limit = max( 1, min( self::BATCH, $limit ) );
		global $wpdb;

		switch ( $type ) {
			case 'expired_transients':
				$names = Db::get_col(
					'SELECT option_name FROM %i WHERE option_name LIKE %s AND option_value < %d ORDER BY option_value ASC, option_name ASC LIMIT %d',
					array( $wpdb->options, self::transient_like(), time(), $limit )
				);
				$out = array();
				foreach ( $names as $name ) {
					$out[] = array( 'kind' => 'transient', 'id' => 0, 'name' => substr( (string) $name, 19 ) );
				}
				return $out;
			case 'old_revisions':
				list( $sql, $args ) = self::revision_sql( 'SELECT r.ID', $o );
				$sql               .= ' ORDER BY r.post_parent ASC, r.post_date ASC, r.ID ASC LIMIT %d';
				$args[]             = $limit;
				return self::ids( Db::get_col( $sql, $args ), 'revision' );
			case 'auto_drafts':
				return self::ids(
					Db::get_col(
						'SELECT ID FROM %i WHERE post_status = %s AND DATE_SUB( NOW(), INTERVAL %d DAY ) > post_date ORDER BY post_date ASC, ID ASC LIMIT %d',
						array( $wpdb->posts, 'auto-draft', self::AUTO_DRAFT_DAYS, $limit )
					),
					'post'
				);
			case 'trash_posts':
				return self::ids(
					Db::get_col(
						'SELECT ID FROM %i WHERE post_status = %s AND post_type IN ( \'post\', \'page\' ) AND post_modified_gmt <> %s AND post_modified_gmt < %s ORDER BY post_modified_gmt ASC, ID ASC LIMIT %d',
						array( $wpdb->posts, 'trash', '0000-00-00 00:00:00', self::cutoff( self::TRASH_DAYS ), $limit )
					),
					'post'
				);
			case 'spam_comments':
				return self::ids(
					Db::get_col(
						'SELECT comment_ID FROM %i WHERE comment_approved IN ( \'spam\', \'trash\' ) AND comment_date_gmt <> %s AND comment_date_gmt < %s ORDER BY comment_date_gmt ASC, comment_ID ASC LIMIT %d',
						array( $wpdb->comments, '0000-00-00 00:00:00', self::cutoff( self::SPAM_DAYS ), $limit )
					),
					'comment'
				);
			case 'orphan_meta':
				$out = array();
				foreach ( self::meta_maps() as $map ) {
					$left = $limit - count( $out );
					if ( $left <= 0 ) {
						break;
					}
					$ids = Db::get_col( self::orphan_ids_sql( $map ), array_merge( self::orphan_args( $map ), array( $left ) ) );
					foreach ( $ids as $id ) {
						$out[] = array( 'kind' => 'meta', 'table' => $map['table'], 'id' => (int) $id );
					}
				}
				return $out;
		}
		return array();
	}

	/**
	 * Silme anında aday hâlâ uygun mu? (Aday listesi ile silme arasında veri değişmiş olabilir.)
	 *
	 * @param array<string, mixed> $candidate Aday.
	 * @param array<string, mixed> $opts      Seçenekler.
	 */
	public static function qualifies( string $type, array $candidate, array $opts = array() ): bool {
		$o = self::options( $opts );
		global $wpdb;

		switch ( $type ) {
			case 'expired_transients':
				$name = (string) ( $candidate['name'] ?? '' );
				if ( '' === $name ) {
					return false;
				}
				$value = Db::get_var(
					'SELECT option_value FROM %i WHERE option_name = %s',
					array( $wpdb->options, self::transient_prefix() . $name )
				);
				return null !== $value && '' !== $value && (int) $value < time();
			case 'old_revisions':
				$id = (int) ( $candidate['id'] ?? 0 );
				if ( $id <= 0 ) {
					return false;
				}
				list( $sql, $args ) = self::revision_sql( 'SELECT COUNT(*)', $o, ' AND r.ID = %d' );
				array_splice( $args, 2, 0, array( $id ) ); // ID koşulu, alt sorgudaki iki %i ve keep'ten ÖNCE.
				return (int) Db::get_var( $sql, $args ) > 0;
			case 'auto_drafts':
			case 'trash_posts':
				$id = (int) ( $candidate['id'] ?? 0 );
				if ( $id <= 0 ) {
					return false;
				}
				if ( 'auto_drafts' === $type ) {
					$sql  = 'SELECT COUNT(*) FROM %i WHERE ID = %d AND post_status = %s AND DATE_SUB( NOW(), INTERVAL %d DAY ) > post_date';
					$args = array( $wpdb->posts, $id, 'auto-draft', self::AUTO_DRAFT_DAYS );
				} else {
					$sql  = 'SELECT COUNT(*) FROM %i WHERE ID = %d AND post_status = %s AND post_type IN ( \'post\', \'page\' ) AND post_modified_gmt <> %s AND post_modified_gmt < %s';
					$args = array( $wpdb->posts, $id, 'trash', '0000-00-00 00:00:00', self::cutoff( self::TRASH_DAYS ) );
				}
				return (int) Db::get_var( $sql, $args ) > 0;
			case 'spam_comments':
				$id = (int) ( $candidate['id'] ?? 0 );
				if ( $id <= 0 ) {
					return false;
				}
				return (int) Db::get_var(
					'SELECT COUNT(*) FROM %i WHERE comment_ID = %d AND comment_approved IN ( \'spam\', \'trash\' ) AND comment_date_gmt <> %s AND comment_date_gmt < %s',
					array( $wpdb->comments, $id, '0000-00-00 00:00:00', self::cutoff( self::SPAM_DAYS ) )
				) > 0;
			case 'orphan_meta':
				$map = self::meta_map( (string) ( $candidate['table'] ?? '' ) );
				$id  = (int) ( $candidate['id'] ?? 0 );
				if ( null === $map || $id <= 0 ) {
					return false;
				}
				return (int) Db::get_var( self::orphan_qualify_sql( $map ), array_merge( array( $id ), self::orphan_args( $map ) ) ) > 0;
		}
		return false;
	}

	/* ------------------------------------------------------------------ internals */

	/** Geçici veri öneki (19 karakter). */
	private static function transient_prefix(): string {
		return '_transient_timeout_';
	}

	/** `LIKE` deseni: alt çizgiler `$wpdb->esc_like()` ile kaçırılır. */
	private static function transient_like(): string {
		global $wpdb;
		return $wpdb->esc_like( self::transient_prefix() ) . '%';
	}

	/** "N gün önce" sınırı (GMT, `Y-m-d H:i:s`). */
	private static function cutoff( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}

	/**
	 * Eski revizyon SQL'i: her `post_parent` için en yeni `keep` revizyonun DIŞINDA kalanlar.
	 *
	 * @param string               $select SELECT ifadesi (`SELECT COUNT(*)`, `SELECT r.ID` …).
	 * @param array<string, mixed> $o      Seçenekler.
	 * @param string               $extra  Ek WHERE parçası (ör. ` AND r.ID = %d`).
	 * @return array{0:string,1:array<int, mixed>}
	 */
	private static function revision_sql( string $select, array $o, string $extra = '' ): array {
		global $wpdb;
		$sql = $select . " FROM %i r
			WHERE r.post_type = 'revision'" . $extra . '
			AND (
				SELECT COUNT(*) FROM %i n
				WHERE n.post_type = \'revision\'
					AND n.post_parent = r.post_parent
					AND ( n.post_date > r.post_date OR ( n.post_date = r.post_date AND n.ID > r.ID ) )
			) >= %d';
		return array( $sql, array( $wpdb->posts, $wpdb->posts, $o['keep'] ) );
	}

	/**
	 * @param array<int, mixed> $ids Kolon değerleri.
	 * @return array<int, array{kind:string,id:int}>
	 */
	private static function ids( array $ids, string $kind ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = array( 'kind' => $kind, 'id' => (int) $id );
		}
		return $out;
	}

	/**
	 * Meta tabloları: silme API'si türü (`post`/`comment`/`term`), tablo ve ebeveyn eşlemesi.
	 *
	 * @return array<string, array{table:string,parent:string,column:string,parent_column:string,meta_type:string}>
	 */
	private static function meta_maps(): array {
		return array(
			'post'    => array( 'table' => 'postmeta', 'parent' => 'posts', 'column' => 'post_id', 'parent_column' => 'ID', 'meta_type' => 'post' ),
			'comment' => array( 'table' => 'commentmeta', 'parent' => 'comments', 'column' => 'comment_id', 'parent_column' => 'comment_ID', 'meta_type' => 'comment' ),
			'term'    => array( 'table' => 'termmeta', 'parent' => 'terms', 'column' => 'term_id', 'parent_column' => 'term_id', 'meta_type' => 'term' ),
		);
	}

	/** @return array{table:string,parent:string,column:string,parent_column:string,meta_type:string}|null */
	private static function meta_map( string $key ): ?array {
		$maps = self::meta_maps();
		return $maps[ $key ] ?? null;
	}

	/** @return array<int, mixed> Meta tablosunun tablo argümanları (`$wpdb->prefix` dahil). */
	private static function orphan_args( array $map ): array {
		global $wpdb;
		return array( $wpdb->prefix . $map['table'], $wpdb->prefix . $map['parent'] );
	}

	private static function orphan_count_sql( array $map ): string {
		return 'SELECT COUNT(*) FROM %i m LEFT JOIN %i p ON p.' . $map['parent_column'] . ' = m.' . $map['column'] . ' WHERE p.' . $map['parent_column'] . ' IS NULL';
	}

	private static function orphan_bytes_sql( array $map ): string {
		return 'SELECT COALESCE( SUM( LENGTH( m.meta_value ) + LENGTH( m.meta_key ) ), 0 ) FROM %i m LEFT JOIN %i p ON p.' . $map['parent_column'] . ' = m.' . $map['column'] . ' WHERE p.' . $map['parent_column'] . ' IS NULL';
	}

	private static function orphan_ids_sql( array $map ): string {
		return 'SELECT m.meta_id FROM %i m LEFT JOIN %i p ON p.' . $map['parent_column'] . ' = m.' . $map['column'] . ' WHERE p.' . $map['parent_column'] . ' IS NULL ORDER BY m.meta_id ASC LIMIT %d';
	}

	private static function orphan_qualify_sql( array $map ): string {
		return 'SELECT COUNT(*) FROM %i m LEFT JOIN %i p ON p.' . $map['parent_column'] . ' = m.' . $map['column'] . ' WHERE m.meta_id = %d AND p.' . $map['parent_column'] . ' IS NULL';
	}
}
