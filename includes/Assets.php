<?php
/**
 * Yalnız yönetim panelinde, yalnız ilgili modül açıkken yüklenen varlıklar (bağımlılıksız, defer).
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit;

defined( 'ABSPATH' ) || exit;

final class Assets {

	/** Varlık sürüm parametresi: dosya içeriğinin özeti (aynı sürüm numarasıyla değişen dosya tarayıcı önbelleğinde eskimez). */
	public static function ver( string $file ): string {
		$path = ADMINKIT_DIR . 'assets/' . $file;
		$hash = is_readable( $path ) ? md5_file( $path ) : false;
		return $hash ? substr( $hash, 0, 10 ) : ADMINKIT_VERSION;
	}

	/**
	 * @param string               $slug   Dosya adı gövdesi (assets/<slug>.css|js).
	 * @param array<string, mixed> $config JS'e verilecek ayarlar.
	 */
	public static function load( string $slug, array $config = array(), bool $css = true ): void {
		$handle = 'adminkit-' . $slug;
		if ( $css ) {
			wp_enqueue_style( $handle, ADMINKIT_URL . 'assets/' . $slug . '.css', array(), self::ver( $slug . '.css' ) );
		}
		wp_enqueue_script(
			$handle,
			ADMINKIT_URL . 'assets/' . $slug . '.js',
			array(),
			self::ver( $slug . '.js' ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_add_inline_script(
			$handle,
			'window.AdminKit=window.AdminKit||{};window.AdminKit[' . wp_json_encode( $slug ) . ']=' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
