<?php
/**
 * Çekirdek: dil dosyası, modül önyükleme, ayar sayfası.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		add_action( 'init', array( self::class, 'init' ), 1 );
		add_filter( 'plugin_action_links_' . plugin_basename( ADMINKIT_FILE ), array( SettingsPage::class, 'action_links' ) );
		Kit\Overview::hooks();
		add_action( 'admin_init', array( Settings::class, 'register' ) );
	}

	public static function init(): void {
		load_plugin_textdomain( 'adminkit', false, dirname( plugin_basename( ADMINKIT_FILE ) ) . '/languages' );
		SettingsPage::hooks(); // Menü başlıkları çevrilir; çeviri yüklendikten sonra kaydedilir.
		foreach ( Registry::all() as $module ) {
			$settings = Settings::for_module( $module );
			if ( $settings['enabled'] ) {
				$module->boot( $settings );
			}
		}
	}

	public static function activate(): void {
		// Ayar satırı autoload 'no' ile açılır: yalnız yönetim panelinde okunur.
		add_option( Settings::OPTION, array(), '', false );
		add_option( Kit\Catalog::OPT_LIVE, '1', '', false );
	}
}
