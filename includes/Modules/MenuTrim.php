<?php
/**
 * Modül 4 — Örnek özelleştirme: seçilen üst menü öğelerini yan menüden GİZLER (yalnız görünüm; erişim/yetki kapanmaz).
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Modules;

use AdminKit\Module;

defined( 'ABSPATH' ) || exit;

final class MenuTrim implements Module {

	/** Kilitlenmeyi önlemek için asla gizlenmeyen öğeler (RBN Kit, Ayarlar ve Eklentiler). */
	private const PROTECTED = array( 'rbn-kit', 'options-general.php', 'plugins.php' );

	public function id(): string {
		return 'menu_trim';
	}

	public function title(): string {
		return __( 'Hide side menu items', 'adminkit' );
	}

	public function description(): string {
		return __( 'Example module: hides the menu items you pick. They are only hidden from view; access stays.', 'adminkit' );
	}

	public function default_enabled(): bool {
		return false;
	}

	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'hidden',
				'type'    => 'checklist',
				'label'   => __( 'Menu items to hide', 'adminkit' ),
				'default' => array(),
				'options' => array( self::class, 'menu_choices' ),
				'help'    => __( 'RBN Kit, Settings and Plugins can not be hidden, so you can always find this page.', 'adminkit' ),
			),
		);
	}

	/**
	 * Seçenekler: menü öğesi kimliği (li id) => ad.
	 *
	 * @return array<string, string>
	 */
	public static function menu_choices(): array {
		global $menu;
		$out = array();
		foreach ( is_array( $menu ) ? $menu : array() as $item ) {
			if ( ! is_array( $item ) || empty( $item[0] ) || empty( $item[5] ) || in_array( $item[2], self::PROTECTED, true ) ) {
				continue;
			}
			$name = trim( wp_strip_all_tags( (string) preg_replace( '/<span[^>]*>.*?<\/span>/is', '', (string) $item[0] ) ) );
			if ( '' !== $name ) {
				$out[ (string) $item[5] ] = $name;
			}
		}
		return $out;
	}

	public function boot( array $settings ): void {
		$hidden = array_filter( (array) $settings['hidden'], 'is_string' );
		if ( ! $hidden ) {
			return;
		}
		add_action(
			'admin_head',
			static function () use ( $hidden ): void {
				$sel = array();
				foreach ( $hidden as $id ) {
					$sel[] = '#adminmenu li#' . preg_replace( '/[^A-Za-z0-9_\-]/', '', $id );
				}
				echo '<style id="adminkit-menu-trim">' . esc_html( implode( ',', $sel ) ) . '{display:none!important}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		);
	}
}
