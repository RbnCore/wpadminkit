<?php
/**
 * RBN Kit ortak üst menüsü: yan menüde Başlangıç'ın hemen altında tek "RBN Kit" menüsü; her RBN eklentisi kendi alt öğesini ekler.
 *
 * ORTAK DOSYA: aynı dosya birden çok RBN eklentisinde birebir bulunur; sınıf korumalıdır, ilk yüklenen kazanır.
 * Bağımlılığı yoktur; WordPress dışında hiçbir şey çağırmaz. Kopyalarken DEĞİŞTİRMEYİN.
 * Kopyaları eşitlemek için: `php araclar/menu-senkron.php` (AdminKit deposu; `--kontrol` yalnız denetler, `--yaz` eşitler).
 * Çeviri: ortak metin alanı `rbn-kit` (languages/rbn-kit.pot, rbn-kit-tr_TR.po/.mo); bu dosyayla BİRLİKTE kopyalanır.
 *
 * MENÜ SIRASI SABİTTİR: Overview → AdminKit → CookieKit → ContentHealth → WPSeoKit. Sıra yalnız aşağıdaki ORDER tablosundadır
 * ve yalnız geliştirici koddan değiştirir; kurulum/yükleme sırası, `add()` çağrısı, başka eklentilerin `admin_menu`
 * öncelikleri ve kullanıcı sırayı değiştiremez (ayar, süzgeç, kanca yoktur). Yeni RBN eklentisi eklenince bu tabloya satır
 * eklenir ve kopyalar eşitlenir; tabloda olmayan slug'lar bu öğelerden SONRA, slug'a göre alfabetik gelir.
 *
 * Kullanım (eklentinin yüklenme anında, `admin_menu` öncesi):
 *   RbnKitMenu::add( array( 'slug' => 'adminkit', 'title' => 'AdminKit', 'menu_title' => 'AdminKit', 'cap' => 'manage_options', 'callback' => $callable ) );
 *   $hook = RbnKitMenu::hook( 'adminkit' ); // ekran kimliği (asset kapıları için): rbn-kit_page_adminkit
 * Süzgeçler: `rbn_kit_items` (yalnız Genel bakış listesi içindir, menü sırasını etkilemez), `rbn_kit_overview_callback` (Genel bakış çizicisi).
 *
 * @package RbnKit
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'RbnKitMenu' ) ) {

	final class RbnKitMenu {

		public const SLUG = 'rbn-kit';

		/** Kanonik menü sırası (slug => sıra). SABİT: tek yer burasıdır; yeni RBN eklentisi için satır ekleyin. */
		private const ORDER = array(
			'rbn-kit'       => 0,
			'adminkit'      => 10,
			'cookiekit'     => 20,
			'contenthealth' => 30,
			'wpseokit'      => 40,
		);

		/** @var array<string, array<string, mixed>> slug => öğe */
		private static array $items = array();

		/** @var array<string, string> slug => ekran kimliği (`$hook_suffix`) */
		private static array $hooks = array();

		private static bool $hooked = false;

		/**
		 * Alt öğe ekler. Eksik/geçersiz öğe yok sayılır; aynı slug ikinci kez eklenirse ilki kalır.
		 *
		 * @param array<string, mixed> $item slug, title, menu_title, cap, callback. Sıra bildirilemez (`order` yok sayılır).
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
			if ( isset( $item['order'] ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				_doing_it_wrong( __METHOD__, 'The RBN Kit menu order is fixed; `order` is ignored (the order lives in the RbnKitMenu::ORDER table).', '0.1.0' );
			}

			self::$items[ $slug ] = array(
				'slug'       => $slug,
				'title'      => $title,
				'menu_title' => $menu_title,
				'cap'        => $cap,
				'callback'   => $item['callback'],
				'version'    => isset( $item['version'] ) ? (string) $item['version'] : '',
			);
			if ( ! self::$hooked ) {
				self::$hooked = true;
				add_action( 'admin_menu', array( self::class, 'register' ), 9 );
				add_action( 'admin_menu', array( self::class, 'enforce_order' ), PHP_INT_MAX );
			}
		}

		/** Alt öğenin ekran kimliği (`rbn-kit_page_<slug>`; Genel bakış: `toplevel_page_rbn-kit`). Kayıtlı değilse boş dize. */
		public static function hook( string $slug ): string {
			return self::$hooks[ $slug ] ?? '';
		}

		/**
		 * Kayıtlı öğeler, kanonik sıraya göre (tabloda olmayanlar sonda, slug'a göre).
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public static function items(): array {
			$list = array_values( self::$items );
			usort(
				$list,
				static function ( array $a, array $b ): int {
					return array( self::rank( (string) $a['slug'] ), (string) $a['slug'] ) <=> array( self::rank( (string) $b['slug'] ), (string) $b['slug'] );
				}
			);
			$list = apply_filters( 'rbn_kit_items', $list );
			return is_array( $list ) ? $list : array();
		}

		/** Slug'ın kanonik sırası; tabloda yoksa tüm bilinenlerden sonra. */
		private static function rank( string $slug ): int {
			return self::ORDER[ $slug ] ?? PHP_INT_MAX;
		}

		/** `admin_menu` (en son öncelik): RBN Kit alt menüsünü kanonik sıraya yeniden kurar; başka eklentinin yaptığı sıralama geçersizdir. */
		public static function enforce_order(): void {
			global $submenu;
			if ( ! isset( $submenu[ self::SLUG ] ) || ! is_array( $submenu[ self::SLUG ] ) ) {
				return;
			}
			$rows = array();
			foreach ( $submenu[ self::SLUG ] as $row ) {
				if ( ! is_array( $row ) || ! isset( $row[2] ) || ! is_string( $row[2] ) || isset( $rows[ $row[2] ] ) ) {
					continue;
				}
				$rows[ $row[2] ] = $row;
			}
			uksort(
				$rows,
				static function ( string $a, string $b ): int {
					return array( self::rank( $a ), $a ) <=> array( self::rank( $b ), $b );
				}
			);
			$submenu[ self::SLUG ] = array_values( $rows );
		}

		/**
		 * Loads the shared `rbn-kit` translations of the copy that won the class race (languages/rbn-kit-<locale>.mo next to it).
		 * Safe to call more than once; call it only after `init` (menu and screen rendering), never while the plugin file loads.
		 */
		public static function load_i18n(): void {
			static $loaded = array();
			$locale = determine_locale();
			if ( isset( $loaded[ $locale ] ) ) {
				return;
			}
			$loaded[ $locale ] = true;
			load_textdomain( 'rbn-kit', dirname( __DIR__ ) . '/languages/rbn-kit-' . $locale . '.mo' );
		}

		/** `admin_menu` (öncelik 9): üst menü bir kez, ardından Genel bakış ve eklenti alt öğeleri. */
		public static function register(): void {
			global $admin_page_hooks, $menu;
			if ( ! self::$items || isset( $admin_page_hooks[ self::SLUG ] ) ) {
				return;
			}
			self::load_i18n();
			$overview = apply_filters( 'rbn_kit_overview_callback', array( self::class, 'render_minimal' ) );
			if ( ! is_callable( $overview ) ) {
				$overview = array( self::class, 'render_minimal' );
			}
			$position = isset( $menu[3] ) ? '3.1' : 3;
			self::$hooks[ self::SLUG ] = (string) add_menu_page( 'RBN Kit', 'RBN Kit', 'manage_options', self::SLUG, $overview, 'dashicons-screenoptions', $position );
			// WordPress'in otomatik tekrar eden ilk alt öğesini ezmek için üst slug ile aynı slug'lı "Genel bakış".
			add_submenu_page( self::SLUG, __( 'Overview', 'rbn-kit' ), __( 'Overview', 'rbn-kit' ), 'manage_options', self::SLUG, $overview );
			foreach ( self::items() as $item ) {
				$hook = add_submenu_page( self::SLUG, (string) $item['title'], (string) $item['menu_title'], (string) $item['cap'], (string) $item['slug'], $item['callback'] );
				if ( $hook ) {
					self::$hooks[ (string) $item['slug'] ] = (string) $hook;
				}
			}
		}

		/** En küçük Genel bakış: yalnız kayıtlı RBN eklentilerinin bağlantı listesi. */
		public static function render_minimal(): void {
			self::load_i18n();
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'rbn-kit' ), '', array( 'response' => 403 ) );
			}
			echo '<div class="wrap"><h1>RBN Kit</h1><p>' . esc_html__( 'Installed RBN plugins:', 'rbn-kit' ) . '</p><ul class="rbn-kit-list">';
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
