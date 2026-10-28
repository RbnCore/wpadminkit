<?php
/**
 * RBN Kit ortak üst menüsü: yan menüde Başlangıç'ın hemen altında tek "RBN Kit" menüsü; her RBN eklentisi kendi alt öğesini ekler.
 *
 * ORTAK DOSYA: aynı dosya birden çok RBN eklentisinde birebir bulunur; sınıf korumalıdır, ilk yüklenen kazanır.
 * Bağımlılığı yoktur; WordPress dışında hiçbir şey çağırmaz. Kopyalarken DEĞİŞTİRMEYİN.
 *
 * Kullanım (eklentinin yüklenme anında, `admin_menu` öncesi):
 *   RbnKitMenu::add( array( 'slug' => 'adminkit', 'title' => 'AdminKit', 'menu_title' => 'AdminKit', 'cap' => 'manage_options', 'callback' => $callable, 'order' => 90 ) );
 *   $hook = RbnKitMenu::hook( 'adminkit' ); // ekran kimliği (asset kapıları için): rbn-kit_page_adminkit
 * Süzgeçler: `rbn_kit_items` (kayıtlı öğeler dizisi), `rbn_kit_overview_callback` (Genel bakış sayfasının çizicisi).
 *
 * @package RbnKit
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'RbnKitMenu' ) ) {

	final class RbnKitMenu {

		public const SLUG = 'rbn-kit';

		/** @var array<string, array<string, mixed>> slug => öğe */
		private static array $items = array();

		/** @var array<string, string> slug => ekran kimliği (`$hook_suffix`) */
		private static array $hooks = array();

		private static bool $hooked = false;

		/**
		 * Alt öğe ekler. Eksik/geçersiz öğe yok sayılır; aynı slug ikinci kez eklenirse ilki kalır.
		 *
		 * @param array<string, mixed> $item slug, title, menu_title, cap, callback, order (10..99).
		 */
		public static function add( array $item ): void {
			$slug = isset( $item['slug'] ) && is_string( $item['slug'] ) ? $item['slug'] : '';
			if ( '' === $slug || self::SLUG === $slug || ! preg_match( '/^[a-z0-9_\-]+$/', $slug ) || isset( self::$items[ $slug ] ) ) {
				return;
			}
			if ( empty( $item['callback'] ) || ! is_callable( $item['callback'] ) ) {
				return;
			}
			$title = isset( $item['title'] ) ? trim( (string) $item['title'] ) : '';
			if ( '' === $title ) {
				return;
			}
			$menu_title = isset( $item['menu_title'] ) && '' !== trim( (string) $item['menu_title'] ) ? (string) $item['menu_title'] : $title;
			$cap        = isset( $item['cap'] ) && '' !== (string) $item['cap'] ? (string) $item['cap'] : 'manage_options';
			$order      = isset( $item['order'] ) ? max( 10, min( 99, (int) $item['order'] ) ) : 50;

			self::$items[ $slug ] = array(
				'slug'       => $slug,
				'title'      => $title,
				'menu_title' => $menu_title,
				'cap'        => $cap,
				'callback'   => $item['callback'],
				'order'      => $order,
				'seq'        => count( self::$items ),
				'version'    => isset( $item['version'] ) ? (string) $item['version'] : '',
			);
			if ( ! self::$hooked ) {
				self::$hooked = true;
				add_action( 'admin_menu', array( self::class, 'register' ), 9 );
			}
		}

		/** Alt öğenin ekran kimliği (`rbn-kit_page_<slug>`; Genel bakış: `toplevel_page_rbn-kit`). Kayıtlı değilse boş dize. */
		public static function hook( string $slug ): string {
			return self::$hooks[ $slug ] ?? '';
		}

		/**
		 * Kayıtlı öğeler, sıraya göre (eşit sırada ekleme sırası).
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public static function items(): array {
			$list = array_values( self::$items );
			usort(
				$list,
				static function ( array $a, array $b ): int {
					return array( $a['order'], $a['seq'] ) <=> array( $b['order'], $b['seq'] );
				}
			);
			$list = apply_filters( 'rbn_kit_items', $list );
			return is_array( $list ) ? $list : array();
		}

		/** `admin_menu` (öncelik 9): üst menü bir kez, ardından Genel bakış ve eklenti alt öğeleri. */
		public static function register(): void {
			global $admin_page_hooks, $menu;
			if ( ! self::$items || isset( $admin_page_hooks[ self::SLUG ] ) ) {
				return;
			}
			$overview = apply_filters( 'rbn_kit_overview_callback', array( self::class, 'render_minimal' ) );
			if ( ! is_callable( $overview ) ) {
				$overview = array( self::class, 'render_minimal' );
			}
			$position = isset( $menu[3] ) ? '3.1' : 3;
			self::$hooks[ self::SLUG ] = (string) add_menu_page( 'RBN Kit', 'RBN Kit', 'manage_options', self::SLUG, $overview, 'dashicons-screenoptions', $position );
			// WordPress'in otomatik tekrar eden ilk alt öğesini ezmek için üst slug ile aynı slug'lı "Genel bakış".
			add_submenu_page( self::SLUG, 'Genel bakış', 'Genel bakış', 'manage_options', self::SLUG, $overview );
			foreach ( self::items() as $item ) {
				$hook = add_submenu_page( self::SLUG, (string) $item['title'], (string) $item['menu_title'], (string) $item['cap'], (string) $item['slug'], $item['callback'] );
				if ( $hook ) {
					self::$hooks[ (string) $item['slug'] ] = (string) $hook;
				}
			}
		}

		/** En küçük Genel bakış: yalnız kayıtlı RBN eklentilerinin bağlantı listesi. */
		public static function render_minimal(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html( 'Bu sayfayı görme yetkiniz yok.' ), '', array( 'response' => 403 ) );
			}
			echo '<div class="wrap"><h1>RBN Kit</h1><p>Kurulu RBN eklentileri:</p><ul class="rbn-kit-list">';
			foreach ( self::items() as $item ) {
				if ( ! current_user_can( (string) $item['cap'] ) ) {
					continue;
				}
				$ver = '' !== (string) $item['version'] ? ' <span class="description">v' . esc_html( (string) $item['version'] ) . '</span>' : '';
				echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=' . rawurlencode( (string) $item['slug'] ) ) ) . '">' . esc_html( (string) $item['title'] ) . '</a>' . $ver . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- parçalar kaçışlı.
			}
			echo '</ul></div>';
		}
	}
}
