<?php
/**
 * Plugin Name:       AdminKit — Yönetim Paneli Araçları
 * Plugin URI:        https://github.com/RbnCore
 * Description:       WordPress yönetim paneline modül modül özellik ekler: yukarı çık düğmesi, yapışkan kaydet çubuğu, bildirim düzenleyici, renk teması ve örnek özelleştirme. Her modül açılıp kapatılır. Ön yüze çıktı vermez, dış servis çağırmaz.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            RBN Bilişim
 * Author URI:        https://github.com/RbnCore
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       adminkit
 * Domain Path:       /languages
 *
 * @package AdminKit
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'ADMINKIT_VERSION', '0.2.0' );
define( 'ADMINKIT_FILE', __FILE__ );
define( 'ADMINKIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADMINKIT_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( 0 !== strpos( $class_name, 'AdminKit\\' ) ) {
			return;
		}
		$path = ADMINKIT_DIR . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( 'AdminKit\\' ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// Etkinleştirme kancası yalnız etkinleştirmede çalışır (ön yüze çıktı vermez); WP-CLI için her bağlamda kayıtlıdır.
register_activation_hook( __FILE__, array( \AdminKit\Plugin::class, 'activate' ) );

// Yalnız yönetim paneli: ön yüzde hiçbir kanca, varlık ya da çıktı yoktur.
if ( is_admin() ) {
	add_action( 'plugins_loaded', array( \AdminKit\Plugin::class, 'boot' ) );
}
