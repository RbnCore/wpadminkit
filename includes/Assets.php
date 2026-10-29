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

	/** Ortak arayüz kiti: stil + betik tutamacı (tüm RBN eklentileri bunu kullanır). */
	public const UI = 'rbn-admin-ui';

	private static bool $sprite_done = false;

	/** Kiti her yönetim ekranı için yalnız KAYDEDER; yükleyen ekran `wp_enqueue_*( Assets::UI )` çağırır. */
	public static function hooks(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'register_ui' ), 1 );
		add_action( 'admin_footer', array( self::class, 'print_sprite' ), 1 );
	}

	public static function register_ui(): void {
		wp_register_style( self::UI, ADMINKIT_URL . 'assets/ui/rbn-admin-ui.css', array(), self::ver( 'ui/rbn-admin-ui.css' ) );
		wp_register_script( self::UI, ADMINKIT_URL . 'assets/ui/rbn-admin-ui.js', array(), self::ver( 'ui/rbn-admin-ui.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	}

	/** SVG ikon sprite'ı: kit yüklendiyse sayfaya bir kez basılır (`<use href="#i-...">`). */
	public static function print_sprite(): void {
		if ( self::$sprite_done || ! wp_style_is( self::UI, 'enqueued' ) ) {
			return;
		}
		$file = ADMINKIT_DIR . 'assets/ui/rbn-icons.svg';
		if ( is_readable( $file ) ) {
			self::$sprite_done = true;
			echo file_get_contents( $file ); // phpcs:ignore WordPress.Security.EscapeOutput,WordPress.WP.AlternativeFunctions -- paket içi sabit SVG.
		}
	}

	/** Kit kökü için ton özniteliği: WP "Gece yarısı" şeması (ya da `adminkit_tone` süzgeci) koyu tondur. */
	public static function tone_attr(): string {
		$tone = (string) apply_filters( 'adminkit_tone', 'midnight' === (string) get_user_option( 'admin_color' ) ? 'koyu' : 'acik' );
		return 'koyu' === $tone ? ' data-tone="koyu"' : '';
	}

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

	/** Kit ikonu (SVG sprite): kimlik `rbn-icons.svg` içindeki `i-<id>` sembolüdür. */
	public static function icon( string $id, string $class = '' ): string {
		return '<svg class="rbn-ico' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '" aria-hidden="true" focusable="false"><use href="#i-' . esc_attr( $id ) . '"/></svg>';
	}

	/**
	 * Kit bildirimi (`.rbn-note`). `$type`: info|ok|warn|err. Başarı/bilgi `role=status`, uyarı/hata `role=alert`.
	 * `$html_action` çağıranın kaçışladığı eylem düğmesidir.
	 */
	public static function note( string $type, string $text, string $html_action = '' ): string {
		$icons = array( 'info' => 'info', 'ok' => 'check', 'warn' => 'alert', 'err' => 'x' );
		$type  = isset( $icons[ $type ] ) ? $type : 'info';
		$role  = in_array( $type, array( 'warn', 'err' ), true ) ? 'alert' : 'status';
		return '<div class="rbn-note' . ( 'info' === $type ? '' : ' rbn-note--' . $type ) . '" role="' . $role . '">' . self::icon( $icons[ $type ] ) . '<div class="rbn-note__t"><p>' . esc_html( $text ) . '</p>' . ( '' !== $html_action ? '<div class="rbn-note__act">' . $html_action . '</div>' : '' ) . '</div></div>';
	}
}
