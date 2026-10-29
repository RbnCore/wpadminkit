<?php
/**
 * AdminKit Helpers: Merkezi Toast bildirim yardımcısı ve flash mesaj yöneticisi.
 *
 * Post-Redirect-Get (PRG) akışlarında işlem sonucu bildirimlerini kullanıcı bazlı
 * transient'te saklar ve sonraki sayfa yüklemesinde RbnUi.toast ile tek seferlik gösterir.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Helpers;

use AdminKit\Assets;

defined( 'ABSPATH' ) || exit;

final class Toast {

	/** Transient anahtarı öneki. */
	private const TRANSIENT_PREFIX = 'rbn_toast_';

	/** Transient geçerlilik süresi (saniye). */
	private const TTL = 120;

	/**
	 * Kancaları kaydeder.
	 */
	public static function hooks(): void {
		add_action( 'admin_footer', array( self::class, 'render' ), 20 );
	}

	/**
	 * Bir sonraki sayfa yüklemesinde gösterilmek üzere tek seferlik bir toast mesajı kuyruğa ekler.
	 *
	 * @param string               $type    Bildirim türü: success|info|warning|error.
	 * @param string               $message Bildirim metni.
	 * @param array<string, mixed> $options Ek seçenekler: title, duration, id, action => ['label' => ..., 'url' => ...].
	 */
	public static function flash( string $type, string $message, array $options = array() ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$valid_types = array( 'success', 'info', 'warning', 'error' );
		// Yaygın takma adları dönüştür (ok -> success, warn -> warning, err -> error).
		if ( 'ok' === $type ) {
			$type = 'success';
		} elseif ( 'warn' === $type ) {
			$type = 'warning';
		} elseif ( 'err' === $type ) {
			$type = 'error';
		}

		if ( ! in_array( $type, $valid_types, true ) ) {
			$type = 'info';
		}

		$toast = array(
			'type'    => $type,
			'message' => wp_strip_all_tags( $message ),
		);

		if ( ! empty( $options['title'] ) ) {
			$toast['title'] = wp_strip_all_tags( (string) $options['title'] );
		}

		if ( isset( $options['duration'] ) && is_numeric( $options['duration'] ) ) {
			$toast['duration'] = (int) $options['duration'];
		}

		if ( ! empty( $options['id'] ) ) {
			$toast['id'] = sanitize_key( (string) $options['id'] );
		}

		if ( ! empty( $options['action'] ) && is_array( $options['action'] ) && ! empty( $options['action']['label'] ) ) {
			$toast['action'] = array(
				'label' => sanitize_text_field( (string) $options['action']['label'] ),
				'url'   => ! empty( $options['action']['url'] ) ? esc_url_raw( (string) $options['action']['url'] ) : '',
			);
		}

		$key     = self::TRANSIENT_PREFIX . $user_id;
		$flashes = get_transient( $key );
		if ( ! is_array( $flashes ) ) {
			$flashes = array();
		}

		$flashes[] = $toast;
		set_transient( $key, $flashes, self::TTL );
	}

	/**
	 * Geçerli kullanıcıya ait flash mesajlarını okur ve siler (tek seferlik tüketim).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_flashes(): array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}

		$key     = self::TRANSIENT_PREFIX . $user_id;
		$flashes = get_transient( $key );
		if ( ! is_array( $flashes ) || empty( $flashes ) ) {
			return array();
		}

		delete_transient( $key );
		return $flashes;
	}

	/**
	 * Yönetim paneli altlığında birikmiş flash bildirimlerini RbnUi.toast ile basar.
	 */
	public static function render(): void {
		if ( ! is_admin() || ! is_user_logged_in() ) {
			return;
		}

		// Yalnızca AdminKit / RBN UI kiti eklenmiş sayfalarda çalışır.
		if ( ! wp_style_is( Assets::UI, 'enqueued' ) && ! wp_script_is( Assets::UI, 'enqueued' ) ) {
			return;
		}

		$flashes = self::get_flashes();
		if ( empty( $flashes ) ) {
			return;
		}

		?>
		<script>
		(function () {
			var flashes = <?php echo wp_json_encode( $flashes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
			if (!Array.isArray(flashes) || !flashes.length) { return; }
			function fire() {
				if (window.RbnUi && typeof window.RbnUi.toast === 'function') {
					flashes.forEach(function (f) {
						window.RbnUi.toast(f);
					});
				}
			}
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', fire);
			} else {
				fire();
			}
		}());
		</script>
		<?php
	}
}
