<?php
/**
 * Modül 1 — Yukarı çık düğmesi (tüm yönetim sayfaları).
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Modules;

use AdminKit\Assets;
use AdminKit\Module;

defined( 'ABSPATH' ) || exit;

final class ScrollTop implements Module {

	public function id(): string {
		return 'scroll_top';
	}

	public function title(): string {
		return __( 'Back to top', 'adminkit' );
	}

	public function description(): string {
		return __( 'Shows a button on every admin page that scrolls back to the top.', 'adminkit' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'position',
				'type'    => 'select',
				'label'   => __( 'Position', 'adminkit' ),
				'default' => 'right',
				'options' => array(
					'right' => __( 'Bottom right', 'adminkit' ),
					'left'  => __( 'Bottom left', 'adminkit' ),
				),
			),
			array(
				'key'     => 'threshold',
				'type'    => 'number',
				'label'   => __( 'Show after scrolling', 'adminkit' ),
				'default' => 400,
				'min'     => 100,
				'max'     => 5000,
				'step'    => 50,
				'unit'    => 'px',
			),
			array(
				'key'     => 'margin',
				'type'    => 'number',
				'label'   => __( 'Distance from the edge', 'adminkit' ),
				'default' => 24,
				'min'     => 8,
				'max'     => 120,
				'unit'    => 'px',
			),
			array(
				'key'     => 'block_editor',
				'type'    => 'checkbox',
				'label'   => __( 'Also show in the block editor', 'adminkit' ),
				'default' => false,
				'help'    => __( 'The block editor scrolls on its own, so the button is hidden there by default.', 'adminkit' ),
			),
		);
	}

	public function boot( array $settings ): void {
		add_action(
			'admin_enqueue_scripts',
			static function () use ( $settings ): void {
				$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				if ( empty( $settings['block_editor'] ) && $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
					return;
				}
				Assets::load(
					'scrolltop',
					array(
						'position'  => (string) $settings['position'],
						'threshold' => (int) $settings['threshold'],
						'margin'    => (int) $settings['margin'],
						'label'     => __( 'Back to top', 'adminkit' ),
					)
				);
			}
		);
	}
}
