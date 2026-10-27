<?php
/**
 * Modül 3 — Bildirim düzenleyici: bilgi bildirimlerini tek kutuda toplar; hata bildirimleri ASLA gizlenmez.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Modules;

use AdminKit\Assets;
use AdminKit\Module;

defined( 'ABSPATH' ) || exit;

final class NoticeTidy implements Module {

	private const META = 'adminkit_notices_open';

	public function id(): string {
		return 'notice_tidy';
	}

	public function title(): string {
		return __( 'Notice organizer', 'adminkit' );
	}

	public function description(): string {
		return __( 'Collects admin notices into one box. Errors always stay visible where they are.', 'adminkit' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'collapse',
				'type'    => 'checkbox',
				'label'   => __( 'Keep the box closed until opened', 'adminkit' ),
				'default' => true,
				'help'    => __( 'Your own open/closed choice is remembered per user and overrides this.', 'adminkit' ),
			),
			array(
				'key'     => 'errors_visible',
				'type'    => 'checkbox',
				'label'   => __( 'Always show error notices', 'adminkit' ),
				'default' => true,
				'locked'  => true,
				'help'    => __( 'Locked on: errors are never hidden or removed.', 'adminkit' ),
			),
		);
	}

	public function boot( array $settings ): void {
		add_action(
			'admin_enqueue_scripts',
			static function () use ( $settings ): void {
				$pref = get_user_meta( get_current_user_id(), self::META, true );
				Assets::load(
					'notices',
					array(
						'open'  => '' === $pref ? ! $settings['collapse'] : '1' === (string) $pref,
						'ajax'  => admin_url( 'admin-ajax.php' ),
						'nonce' => wp_create_nonce( 'adminkit_notices' ),
						'i18n'  => array(
							'title'  => __( 'Notices', 'adminkit' ),
							'errors' => __( 'errors stay visible below', 'adminkit' ),
							'toggle' => __( 'Show or hide the collected notices', 'adminkit' ),
						),
					)
				);
			}
		);
		add_action( 'wp_ajax_adminkit_notices', array( self::class, 'ajax_state' ) );
	}

	/** Kutunun açık/kapalı tercihini kullanıcı metasına yazar; hiçbir bildirim silinmez. */
	public static function ajax_state(): void {
		check_ajax_referer( 'adminkit_notices', 'nonce' );
		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( null, 403 );
		}
		$open = isset( $_POST['open'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['open'] ) ) ? '1' : '0';
		update_user_meta( get_current_user_id(), self::META, $open );
		wp_send_json_success( array( 'open' => $open ) );
	}
}
