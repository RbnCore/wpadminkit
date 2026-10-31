<?php
/**
 * Modül 7 — Veritabanı bakımı: süresi dolmuş geçici veri, eski revizyon, otomatik taslak, çöp, spam yorum ve
 * yetim meta temizliği. YIKICI: önizleme → açık onay → küçük partilerle silme; geri alma YOK.
 *
 * Sayılar ve silme yüklemleri tek kaynaktan gelir (`AdminKit\Health\DbStats`); silme motoru
 * `AdminKit\Health\DbCleaner`. Bu sınıf yalnız modül sözleşmesi, ekran ve HTTP uçlarını taşır.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Modules;

use AdminKit\Assets;
use AdminKit\Health\DbCleaner;
use AdminKit\Health\DbStats;
use AdminKit\Module;
use AdminKit\Settings;
use AdminKit\SettingsPage;

use AdminKit\Core\Log;
use AdminKit\Core\Security\Guard;
use AdminKit\Helpers\Header;
use AdminKit\Helpers\Lock;
use AdminKit\Helpers\Toast;

defined( 'ABSPATH' ) || exit;

final class DbMaintenance implements Module {

	public const NONCE          = 'adminkit_dbmaint';
	public const ACTION_PREVIEW = 'adminkit_dbmaint_preview';
	public const ACTION_RUN     = 'adminkit_dbmaint_run';
	public const CHANNEL        = 'adminkit.dbmaint';

	public function id(): string {
		return 'db_maintenance';
	}

	public function title(): string {
		return __( 'Database maintenance', 'adminkit' );
	}

	public function description(): string {
		return __( 'Deletes old WordPress core leftovers: expired temporary data, old revisions, automatic drafts, trashed posts, spam comments and orphan meta rows. Preview first; deleting cannot be undone.', 'adminkit' );
	}

	public function default_enabled(): bool {
		return false;
	}

	/** Kart anahtarının görünen adı (tek kaynak: modül aç/kapa anahtarı). */
	public function switch_label(): string {
		return __( 'Use database maintenance', 'adminkit' );
	}

	/** Ayarları büyük olduğu için kendi sekmesini alır. */
	public function tab_label(): string {
		return __( 'Database maintenance', 'adminkit' );
	}

	public function off_hint(): string {
		return __( 'Turn it on and press Save to open the cleanup tools.', 'adminkit' );
	}

	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'keep_revisions',
				'type'    => 'number',
				'label'   => __( 'Revisions to keep per post', 'adminkit' ),
				'default' => DbStats::DEFAULT_KEEP,
				'min'     => DbStats::KEEP_MIN,
				'max'     => DbStats::KEEP_MAX,
				'unit'    => __( 'per post', 'adminkit' ),
				'help'    => __( 'Only older revisions beyond this number are deleted. The published post itself is never touched.', 'adminkit' ),
			),
			array(
				'key'    => 'ui',
				'type'   => 'custom',
				'label'  => __( 'Cleanup tools', 'adminkit' ),
				'render' => array( $this, 'render_panel' ),
				// Anahtar uygulanınca panel yenilemeden değişir; betik modül kapalıyken yüklenmediği için canlı yüklenir (WPRJ-738).
				'live'   => array(
					'scripts' => array( 'adminkit-dbmaint' ),
					'paths'   => array( 'modules.' . $this->id() . '.enabled' ),
				),
			),
		);
	}

	public function boot( array $settings ): void {
		Log::register_channel( 'adminkit', 'dbmaint', 90, 200 );
		add_action( 'wp_ajax_' . self::ACTION_PREVIEW, array( self::class, 'ajax_preview' ) );
		add_action( 'wp_ajax_' . self::ACTION_RUN, array( self::class, 'ajax_run' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/** Yalnız Ayarlar → AdminKit ekranında, modül AÇIKKEN yüklenir. */
	public static function enqueue( string $hook ): void {
		if ( '' === SettingsPage::screen() || SettingsPage::screen() !== $hook ) {
			return;
		}
		Assets::load(
			'dbmaint',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'confirm' => self::confirm_word(),
				'types'   => DbStats::types(),
				'i18n'    => array(
					'none'          => __( 'Select at least one cleanup type.', 'adminkit' ),
					'previewing'    => __( 'Counting…', 'adminkit' ),
					'preview'       => __( '%1$d row(s) will be deleted (~%2$s).', 'adminkit' ),
					'previewEmpty'  => __( 'Nothing matches the selected types. Nothing will be deleted.', 'adminkit' ),
					'confirmTitle'  => __( 'Delete database rows?', 'adminkit' ),
					'confirmText'   => __( 'This cannot be undone. Take a database backup first.', 'adminkit' ),
					/* translators: %s: confirmation word */
					'typeToConfirm' => __( 'Type %s to confirm', 'adminkit' ),
					'confirmOk'     => __( 'Delete', 'adminkit' ),
					'working'       => __( 'Deleting…', 'adminkit' ),
					/* translators: 1: deleted rows, 2: estimated total rows, 3: type label */
					'progress'      => __( '%1$d of ~%2$d deleted (%3$s)', 'adminkit' ),
					/* translators: %d: deleted rows */
					'done'          => __( 'Finished: %d row(s) deleted.', 'adminkit' ),
					'busy'          => __( 'Another maintenance run is in progress. Try again in a moment.', 'adminkit' ),
					'failed'        => __( 'The request failed. Nothing else was deleted.', 'adminkit' ),
					'stuck'         => __( 'Some rows could not be deleted; they may have changed meanwhile.', 'adminkit' ),
				),
			),
			false
		);
	}

	/* ------------------------------------------------------------------ ekran */

	/**
	 * Temizlik araçları (modül kartının içinde). Form GÖNDERMEZ: düğmeler AJAX ile çalışır; JS yoksa hiçbir şey silinmez.
	 *
	 * @param string               $base     Ayar adı öneki (kullanılmıyor; arayüz alanları kendi kimliklerini taşır).
	 * @param array<string, mixed> $settings Modül ayarları.
	 */
	public function render_panel( string $base, array $settings ): void {
		if ( empty( $settings['enabled'] ) ) {
			echo Assets::note( 'info', __( 'Turn the module on and press Save to use the cleanup tools.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::note() kaçışlar.
			return;
		}
		$counts = DbStats::counts( array( 'keep' => (int) ( $settings['keep_revisions'] ?? DbStats::DEFAULT_KEEP ) ) );
		?>
		<div class="ak-db" data-dbmaint>
			<?php echo Assets::note( 'warn', __( 'There is no undo. Deleted rows are gone for good. Take a full database backup before you run this; this tool does not create or download backups.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::note() kaçışlar. ?>
			<p class="rbn-help"><?php esc_html_e( 'The numbers below are live and read-only. Nothing is deleted until you preview the selection and confirm.', 'adminkit' ); ?></p>
			<div class="rbn-tw">
				<table class="rbn-table rbn-table--stack ak-db__t">
					<caption class="rbn-sr"><?php esc_html_e( 'Cleanup types', 'adminkit' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Type', 'adminkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Records', 'adminkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Size', 'adminkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( DbStats::types() as $type ) : ?>
							<?php $row = $counts[ $type ] ?? array( 'count' => 0, 'bytes' => 0 ); ?>
							<tr data-dbmaint-row="<?php echo esc_attr( $type ); ?>">
								<th scope="row" data-label="<?php esc_attr_e( 'Type', 'adminkit' ); ?>">
									<label class="rbn-check" for="ak-db-<?php echo esc_attr( $type ); ?>">
										<input type="checkbox" id="ak-db-<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $type ); ?>" data-dbmaint-type />
										<span><?php echo esc_html( self::type_label( $type ) ); ?></span>
									</label>
									<p class="rbn-help" id="ak-db-<?php echo esc_attr( $type ); ?>-h"><?php echo esc_html( self::type_help( $type, (int) ( $settings['keep_revisions'] ?? DbStats::DEFAULT_KEEP ) ) ); ?></p>
								</th>
								<td class="rbn-num" data-label="<?php esc_attr_e( 'Records', 'adminkit' ); ?>" data-dbmaint-count><?php echo esc_html( number_format_i18n( (int) $row['count'] ) ); ?></td>
								<td class="rbn-num" data-label="<?php esc_attr_e( 'Size', 'adminkit' ); ?>" data-dbmaint-bytes><?php echo esc_html( size_format( (int) $row['bytes'], 0 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="rbn-form-actions">
				<button type="button" class="rbn-btn" data-dbmaint-preview><?php esc_html_e( 'Preview selected', 'adminkit' ); ?></button>
				<button type="button" class="rbn-btn rbn-btn--danger" data-dbmaint-run><?php esc_html_e( 'Delete selected…', 'adminkit' ); ?></button>
			</div>
			<p class="rbn-help" data-dbmaint-status role="status" aria-live="polite"></p>
			<p class="rbn-help" data-dbmaint-progress role="status" aria-live="polite" hidden></p>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ uçlar */

	/** Kuru koşu: seçilen türlerin sayısı ve boyutu (salt okuma). */
	public static function ajax_preview(): void {
		Guard::admin_action( self::NONCE, 'manage_options', 'nonce', 'json' );
		$types = self::requested_types();
		if ( ! $types ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one cleanup type.', 'adminkit' ) ), 400 );
		}
		wp_send_json_success( DbCleaner::preview( $types, self::opts() ) );
	}

	/**
	 * Tek türü partiler halinde siler. Onay sözcüğü, yetki ve nonce zorunlu; aynı anda tek koşu (Lock).
	 * Kilit TTL'i 120 sn; her istek işini bitirince kilidi bırakır (işlem idempotent olduğu için doğruluk kilide bağlı değildir).
	 */
	public static function ajax_run(): void {
		Guard::admin_action( self::NONCE, 'manage_options', 'nonce', 'json' );

		$type = isset( $_POST['type'] ) ? sanitize_key( (string) wp_unslash( $_POST['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Guard yukarıda doğruladı.
		if ( ! DbStats::is_type( $type ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown cleanup type.', 'adminkit' ) ), 400 );
		}

		$word  = self::confirm_word();
		$given = isset( $_POST['confirm'] ) ? trim( sanitize_text_field( (string) wp_unslash( $_POST['confirm'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $given || self::fold( $given ) !== self::fold( $word ) ) {
			wp_send_json_error( array( 'message' => __( 'Type the confirmation word to delete. Nothing was deleted.', 'adminkit' ) ), 403 );
		}

		if ( ! Lock::acquire( DbCleaner::LOCK, 120 ) ) {
			wp_send_json_error( array( 'message' => __( 'Another maintenance run is in progress. Try again in a moment.', 'adminkit' ) ), 409 );
		}
		try {
			$result = DbCleaner::run_type( $type, self::opts(), self::deadline() );
		} finally {
			Lock::release( DbCleaner::LOCK );
		}

		if ( $result['deleted'] > 0 || $result['done'] ) {
			Log::log(
				self::CHANNEL,
				'info',
				'cleanup',
				sprintf( 'Cleanup %1$s: %2$d deleted, %3$d skipped.', $type, $result['deleted'], $result['skipped'] ),
				array(
					'type'    => $type,
					'deleted' => $result['deleted'],
					'skipped' => $result['skipped'],
					'user'    => get_current_user_id(),
				)
			);
		}
		if ( $result['done'] ) {
			/* translators: 1: deleted rows, 2: cleanup type */
			Toast::flash( 'success', sprintf( __( '%1$d row(s) deleted (%2$s).', 'adminkit' ), (int) $result['deleted'], self::type_label( $type ) ) );
		}

		wp_send_json_success( array_merge( $result, array( 'type' => $type ) ) );
	}

	/* ------------------------------------------------------------------ yardımcılar */

	/** @return array<string, mixed> */
	private static function opts(): array {
		$settings = Settings::for_module( new self() );
		return array( 'keep' => (int) ( $settings['keep_revisions'] ?? DbStats::DEFAULT_KEEP ) );
	}

	/** @return string[] */
	private static function requested_types(): array {
		$raw = isset( $_POST['types'] ) ? (array) wp_unslash( $_POST['types'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification -- Guard doğruladı; değerler beyaz listeye karşı süzülür.
		$out = array();
		foreach ( $raw as $type ) {
			$type = sanitize_key( (string) $type );
			if ( DbStats::is_type( $type ) ) {
				$out[ $type ] = $type;
			}
		}
		return array_values( $out );
	}

	/** Onay sözcüğü (Türkçe arayüzde "SİL"; İngilizce kaynakta "DELETE"). */
	public static function confirm_word(): string {
		return (string) __( 'DELETE', 'adminkit' );
	}

	/** Büyük/küçük harf duyarsız karşılaştırma; Türkçe i/İ eşlemesi korunur. */
	private static function fold( string $value ): string {
		$value = str_replace( array( 'i', 'ı' ), array( 'İ', 'I' ), $value );
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
	}

	/** İstek başına zaman bütçesi: `max_execution_time` varsa %60'ı, yoksa 20 sn (en az 5 sn). */
	private static function deadline(): float {
		$max    = (int) ini_get( 'max_execution_time' );
		$budget = $max > 0 ? max( 5.0, $max * 0.6 ) : 20.0;
		return microtime( true ) + $budget;
	}

	public static function type_label( string $type ): string {
		$labels = array(
			'expired_transients' => __( 'Expired temporary data (transients)', 'adminkit' ),
			'old_revisions'      => __( 'Old revisions', 'adminkit' ),
			'auto_drafts'        => __( 'Automatic drafts', 'adminkit' ),
			'trash_posts'        => __( 'Trashed posts and pages', 'adminkit' ),
			'spam_comments'      => __( 'Spam and trashed comments', 'adminkit' ),
			'orphan_meta'        => __( 'Orphan meta rows', 'adminkit' ),
		);
		return $labels[ $type ] ?? $type;
	}

	public static function type_help( string $type, int $keep ): string {
		$keep = max( DbStats::KEEP_MIN, min( DbStats::KEEP_MAX, $keep ) );
		switch ( $type ) {
			case 'expired_transients':
				return __( 'Only temporary entries whose expiry time has passed. Live temporary data is kept.', 'adminkit' );
			case 'old_revisions':
				/* translators: %d: revisions kept per post */
				return sprintf( __( 'Keeps the newest %d revisions of each post. The published post itself is never touched.', 'adminkit' ), $keep );
			case 'auto_drafts':
				return __( 'Automatic drafts older than 7 days (the same rule WordPress uses on its daily cleanup).', 'adminkit' );
			case 'trash_posts':
				return __( 'Posts and pages that have been in the trash for more than 30 days.', 'adminkit' );
			case 'spam_comments':
				return __( 'Spam and trashed comments older than 30 days.', 'adminkit' );
			case 'orphan_meta':
				return __( 'Meta rows whose post, comment or term no longer exists.', 'adminkit' );
		}
		return '';
	}
}
