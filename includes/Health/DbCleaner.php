<?php
/**
 * Veritabanı bakım motoru: adayları KÜÇÜK PARTİLER halinde, çekirdek API'lerle siler.
 *
 * Kurallar:
 * - Her aday silinmeden hemen önce yeniden doğrulanır (`DbStats::qualifies`): yayınlanmış yazı, canlı yorum,
 *   süresi dolmamış geçici veri hiçbir koşulda silinmez.
 * - Geniş `DELETE ... WHERE` yoktur; silme daima tek bir kimlik üzerinden çekirdek API ile yapılır
 *   (`wp_delete_post`, `wp_delete_post_revision`, `wp_delete_comment`, `delete_transient`, `delete_metadata_by_mid`).
 * - İdempotent: yarıda kalan tur tekrar koşulabilir; kalan adaylar yeniden hesaplanır.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Health;

defined( 'ABSPATH' ) || exit;

final class DbCleaner {

	/** Aynı anda tek bakım koşusu (Helpers\Lock anahtarı). */
	public const LOCK = 'adminkit_dbmaint_lock';

	/** Bir istekte en çok kaç tur döner (sonsuz döngü tavanı). */
	private const MAX_ROUNDS = 200;

	/**
	 * Kuru koşu: seçilen türler için sayı ve boyut tahmini (HİÇBİR yazma yok).
	 *
	 * @param string[]             $types Bakım türleri.
	 * @param array<string, mixed> $opts  Seçenekler (keep).
	 * @return array{types:array<string, array{count:int,bytes:int}>,total:array{count:int,bytes:int}}
	 */
	public static function preview( array $types, array $opts = array() ): array {
		$o    = DbStats::options( $opts );
		$rows = array();
		$tc   = 0;
		$tb   = 0;
		foreach ( $types as $type ) {
			if ( ! DbStats::is_type( (string) $type ) ) {
				continue;
			}
			$count            = DbStats::count( (string) $type, $o );
			$bytes            = DbStats::bytes( (string) $type, $o );
			$rows[ $type ]    = array( 'count' => $count, 'bytes' => $bytes );
			$tc              += $count;
			$tb              += $bytes;
		}
		return array(
			'types' => $rows,
			'total' => array( 'count' => $tc, 'bytes' => $tb ),
		);
	}

	/**
	 * Tek türü partiler halinde siler. `$deadline` (microtime) geçilince durur; çağıran işlemi yeniden çağırır.
	 *
	 * @param string               $type     Bakım türü.
	 * @param array<string, mixed> $opts     Seçenekler.
	 * @param float|null           $deadline Zaman bütçesi sınırı (microtime(true)); null = sınırsız (test).
	 * @return array{deleted:int,skipped:int,remaining:int,done:bool,stuck:bool}
	 */
	public static function run_type( string $type, array $opts = array(), ?float $deadline = null ): array {
		$result = array(
			'deleted'   => 0,
			'skipped'   => 0,
			'remaining' => 0,
			'done'      => true,
			'stuck'     => false,
		);
		if ( ! DbStats::is_type( $type ) ) {
			return $result;
		}
		$o      = DbStats::options( $opts );
		$rounds = 0;

		while ( $rounds < self::MAX_ROUNDS ) {
			if ( null !== $deadline && microtime( true ) >= $deadline ) {
				break;
			}
			$candidates = DbStats::batch( $type, DbStats::BATCH, $o );
			if ( ! $candidates ) {
				break;
			}
			$round_deleted = 0;
			foreach ( $candidates as $candidate ) {
				// Aday listesi ile silme arasında veri değişmiş olabilir: silmeden hemen önce yeniden doğrula.
				if ( ! DbStats::qualifies( $type, $candidate, $o ) ) {
					++$result['skipped'];
					continue;
				}
				if ( self::delete( $candidate ) ) {
					++$result['deleted'];
					++$round_deleted;
				} else {
					++$result['skipped'];
				}
			}
			++$rounds;
			if ( 0 === $round_deleted ) {
				// Hiçbiri silinemedi: aynı adaylar dönerse sonsuz döngü olur, dur ve çağırana bildir.
				$result['stuck'] = true;
				break;
			}
		}

		$result['remaining'] = DbStats::count( $type, $o );
		$result['done']      = 0 === $result['remaining'];
		self::flush_cache( $type );
		return $result;
	}

	/**
	 * Tek adayı çekirdek API ile siler.
	 *
	 * @param array<string, mixed> $candidate Aday (`kind`, `id`, gerekirse `name`/`table`).
	 */
	public static function delete( array $candidate ): bool {
		$kind = (string) ( $candidate['kind'] ?? '' );
		$id   = (int) ( $candidate['id'] ?? 0 );

		switch ( $kind ) {
			case 'transient':
				$name = (string) ( $candidate['name'] ?? '' );
				return '' !== $name && (bool) delete_transient( $name );
			case 'revision':
				return $id > 0 && wp_delete_post_revision( $id ) instanceof \WP_Post;
			case 'post':
				return $id > 0 && wp_delete_post( $id, true ) instanceof \WP_Post;
			case 'comment':
				return $id > 0 && (bool) wp_delete_comment( $id, true );
			case 'meta':
				$table = (string) ( $candidate['table'] ?? '' );
				return $id > 0 && in_array( $table, array( 'post', 'comment', 'term' ), true ) && (bool) delete_metadata_by_mid( $table, $id );
		}
		return false;
	}

	/** Tür bitince ilgili nesne önbelleği gruplarını boşalt (çekirdek API'ler zaten tek tek temizler). */
	private static function flush_cache( string $type ): void {
		if ( ! function_exists( 'wp_cache_flush_group' ) ) {
			return;
		}
		$groups = array(
			'expired_transients' => array( 'options' ),
			'old_revisions'      => array( 'posts', 'post_meta' ),
			'auto_drafts'        => array( 'posts', 'post_meta' ),
			'trash_posts'        => array( 'posts', 'post_meta' ),
			'spam_comments'      => array( 'comment' ),
			'orphan_meta'        => array( 'post_meta', 'comment_meta', 'term_meta' ),
		);
		foreach ( $groups[ $type ] ?? array() as $group ) {
			wp_cache_flush_group( $group );
		}
	}
}
