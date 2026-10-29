<?php
/**
 * Modül 2 — Yapışkan kaydet çubuğu: uzun ayar sayfalarında mevcut gönder düğmesini tetikler.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Modules;

use AdminKit\Assets;
use AdminKit\Module;

defined( 'ABSPATH' ) || exit;

final class StickySave implements Module {

	public function id(): string {
		return 'sticky_save';
	}

	public function title(): string {
		return __( 'Sticky save bar', 'adminkit' );
	}

	public function description(): string {
		return __( 'Keeps a save button at the bottom of long settings pages and warns about unsaved changes.', 'adminkit' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'scope',
				'type'    => 'select',
				'label'   => __( 'Where to show it', 'adminkit' ),
				'default' => 'all',
				'options' => array(
					'options' => __( 'Settings screens only (options.php forms)', 'adminkit' ),
					'all'     => __( 'Every admin form with a main save button', 'adminkit' ),
				),
			),
			array(
				'key'     => 'min_height',
				'type'    => 'number',
				'label'   => __( 'Only on pages taller than', 'adminkit' ),
				'default' => 900,
				'min'     => 0,
				'max'     => 5000,
				'step'    => 50,
				'unit'    => 'px',
				'help'    => __( 'Short pages already show the save button; the bar appears when the page is at least this tall.', 'adminkit' ),
			),
		);
	}

	public function boot( array $settings ): void {
		add_action(
			'admin_enqueue_scripts',
			static function () use ( $settings ): void {
				$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
					return;
				}
				wp_enqueue_style( Assets::UI );
				Assets::load(
					'stickysave',
					array(
						'scope'     => (string) $settings['scope'],
						'minHeight' => (int) $settings['min_height'],
						'tone'      => false === strpos( Assets::tone_attr(), 'koyu' ) ? '' : 'koyu',
						'i18n'      => array(
							'save'  => __( 'Save changes', 'adminkit' ),
							'dirty' => __( 'You have unsaved changes', 'adminkit' ),
							'clean' => __( 'All changes saved', 'adminkit' ),
							'bar'   => __( 'Save bar', 'adminkit' ),
						),
					)
				);
			}
		);
	}
}
