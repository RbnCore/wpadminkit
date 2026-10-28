<?php
/**
 * Renk yardımcıları: hex doğrulama, WCAG kontrastı, hazır temalar, düzeltme ve yönetim paneli CSS'i.
 *
 * Saf işlevlerdir (WordPress'e bağımlı değil, ad çevirisi hariç). `assets/colortheme.js` aynı algoritmayı
 * canlı önizleme için uygular; iki tarafın çıktısı testle birebir karşılaştırılır.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit;

defined( 'ABSPATH' ) || exit;

final class Color {

	public const MIN_RATIO = 4.5;
	public const WHITE     = '#ffffff';
	public const INK       = '#1d2327';
	public const PAGE_BG   = '#f0f0f1';

	/** Hazır temalar. Kabuk `koyu`: yazılar beyaz; `acik`: yazılar koyu. Zemin her zaman açık tondur (içerik koyu yazılıdır). */
	public static function themes(): array {
		return array(
			'gece'       => array(
				'name'   => __( 'Night', 'adminkit' ),
				'top'    => '#11151a',
				'side'   => '#1b2229',
				'accent' => '#3858e9',
				'bg'     => '#eceef1',
				'shell'  => 'koyu',
			),
			'okyanus'    => array(
				'name'   => __( 'Ocean', 'adminkit' ),
				'top'    => '#0b3c5d',
				'side'   => '#0f4c75',
				'accent' => '#0a6ba8',
				'bg'     => '#edf3f7',
				'shell'  => 'koyu',
			),
			'orman'      => array(
				'name'   => __( 'Forest', 'adminkit' ),
				'top'    => '#1e3d2f',
				'side'   => '#244a39',
				'accent' => '#1b7a4a',
				'bg'     => '#eef3ef',
				'shell'  => 'koyu',
			),
			'gun_batimi' => array(
				'name'   => __( 'Sunset', 'adminkit' ),
				'top'    => '#ffd6bd',
				'side'   => '#fde5d6',
				'accent' => '#b23a17',
				'bg'     => '#f6efea',
				'shell'  => 'acik',
			),
			'grafit'     => array(
				'name'   => __( 'Graphite', 'adminkit' ),
				'top'    => '#25282b',
				'side'   => '#33383d',
				'accent' => '#5a52c8',
				'bg'     => '#f0f0f1',
				'shell'  => 'koyu',
			),
		);
	}

	/** Kullanıcıya gösterilen kimlikler: `wp` (WordPress düzeni), hazır temalar, `custom`. */
	public static function scheme_ids(): array {
		return array_merge( array( 'wp' ), array_keys( self::themes() ), array( 'custom' ) );
	}

	/** `#rgb`/`#rrggbb` → küçük harfli `#rrggbb`; geçersizse boş dize. */
	public static function hex( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim( $value ), $m ) ) {
			return '';
		}
		$h = strtolower( $m[1] );
		if ( 3 === strlen( $h ) ) {
			$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		return '#' . $h;
	}

	/** @return int[] */
	public static function rgb( string $hex ): array {
		return array( (int) hexdec( substr( $hex, 1, 2 ) ), (int) hexdec( substr( $hex, 3, 2 ) ), (int) hexdec( substr( $hex, 5, 2 ) ) );
	}

	public static function luminance( string $hex ): float {
		$lin = array();
		foreach ( self::rgb( $hex ) as $c ) {
			$c     = $c / 255;
			$lin[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
	}

	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/** $t oranında $b'ye doğru karıştırır. */
	public static function mix( string $a, string $b, float $t ): string {
		$x = self::rgb( $a );
		$y = self::rgb( $b );
		$o = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$o .= str_pad( dechex( (int) round( $x[ $i ] + ( $y[ $i ] - $x[ $i ] ) * $t ) ), 2, '0', STR_PAD_LEFT );
		}
		return $o;
	}

	/** $bg, $fg ile en az 4.5:1 değilse, $toward (siyah/beyaz) yönünde en yakın uyumlu tonu bulur. */
	public static function fix( string $bg, array $against, string $toward ): string {
		for ( $i = 0; $i <= 50; $i++ ) {
			$c  = self::mix( $bg, $toward, $i / 50 );
			$ok = true;
			foreach ( $against as $fg ) {
				$ok = $ok && self::contrast( $c, $fg ) >= self::MIN_RATIO;
			}
			if ( $ok ) {
				return $c;
			}
		}
		return $toward;
	}

	/** Üst çubuk / yan menü zemini: yazı rengiyle ≥4.5:1 değilse düzeltilir. */
	public static function chrome( string $bg, string $shell ): string {
		$text = 'koyu' === $shell ? self::WHITE : self::INK;
		return self::fix( $bg, array( $text ), 'koyu' === $shell ? '#000000' : '#ffffff' );
	}

	/** Vurgu rengi: beyaz zeminde bağlantı/düğme ve sayfa zemininde ≥4.5:1 olacak biçimde koyulaştırılır. */
	public static function accent( string $accent, string $page_bg ): string {
		return self::fix( $accent, array( self::WHITE, $page_bg ), '#000000' );
	}

	/**
	 * Seçimin (ham) kontrast tablosu.
	 *
	 * @param array<string, string> $s top, side, accent, bg, shell
	 * @return array<int, array<string, mixed>> key, ratio, ok, fixed
	 */
	public static function report( array $s ): array {
		$text = 'koyu' === $s['shell'] ? self::WHITE : self::INK;
		$rows = array(
			array( 'menu', self::contrast( $s['side'], $text ), self::chrome( $s['side'], $s['shell'] ), 'side' ),
			array( 'bar', self::contrast( $s['top'], $text ), self::chrome( $s['top'], $s['shell'] ), 'top' ),
			array( 'link', self::contrast( $s['accent'], self::WHITE ), self::accent( $s['accent'], $s['bg'] ), 'accent' ),
			array( 'page', self::contrast( $s['accent'], $s['bg'] ), self::accent( $s['accent'], $s['bg'] ), 'accent' ),
		);
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'key'   => $r[0],
				'field' => $r[3],
				'ratio' => round( $r[1], 2 ),
				'ok'    => $r[1] >= self::MIN_RATIO,
				'fixed' => $r[2],
			);
		}
		return $out;
	}

	/**
	 * Ham kayıttan doğrulanmış seçim: id (hazır tema/custom), top, side, accent, bg, shell. `wp` ya da geçersiz → null.
	 *
	 * @param mixed                 $raw      scheme, top, side, accent, shell içeren dizi.
	 * @param array<string, string> $fallback Özel renklerin yedeği (yönetici varsayılanı).
	 * @return array<string, string>|null
	 */
	public static function normalize( $raw, array $fallback = array() ): ?array {
		$raw = is_array( $raw ) ? $raw : array();
		$id  = isset( $raw['scheme'] ) && is_string( $raw['scheme'] ) ? $raw['scheme'] : 'wp';
		if ( ! in_array( $id, self::scheme_ids(), true ) || 'wp' === $id ) {
			return null;
		}
		$themes = self::themes();
		if ( isset( $themes[ $id ] ) ) {
			$t = $themes[ $id ];
			return array(
				'id'     => $id,
				'top'    => $t['top'],
				'side'   => $t['side'],
				'accent' => $t['accent'],
				'bg'     => $t['bg'],
				'shell'  => $t['shell'],
			);
		}
		$out = array(
			'id'    => 'custom',
			'bg'    => self::PAGE_BG,
			'shell' => ( $raw['shell'] ?? ( $fallback['shell'] ?? 'koyu' ) ) === 'acik' ? 'acik' : 'koyu',
		);
		foreach ( array( 'top' => '#1d2327', 'side' => '#23282d', 'accent' => '#2145e6' ) as $k => $def ) {
			$out[ $k ] = self::hex( $raw[ $k ] ?? '' ) ?: ( self::hex( $fallback[ $k ] ?? '' ) ?: $def );
		}
		return $out;
	}

	/** @return string "r, g, b" */
	private static function triplet( string $hex ): string {
		return implode( ', ', self::rgb( $hex ) );
	}

	/** Renk paleti: CSS şablonundaki %anahtar% yer tutucularının değerleri (JS aynı hesabı yapar). */
	public static function palette( array $s ): array {
		$koyu = 'koyu' === $s['shell'];
		$to   = $koyu ? '#000000' : '#ffffff';
		$top  = self::chrome( $s['top'], $s['shell'] );
		$side = self::chrome( $s['side'], $s['shell'] );
		$acc  = self::accent( $s['accent'], $s['bg'] );
		$d10  = self::mix( $acc, '#000000', 0.1 );
		$d20  = self::mix( $acc, '#000000', 0.2 );
		return array(
			'acc'     => $acc,
			'acc_rgb' => self::triplet( $acc ),
			'd10'     => $d10,
			'd10_rgb' => self::triplet( $d10 ),
			'd20'     => $d20,
			'd20_rgb' => self::triplet( $d20 ),
			'bg'      => $s['bg'],
			'side'    => $side,
			'top'     => $top,
			'sub'     => self::mix( $side, $to, 0.25 ),
			'bh'      => self::mix( $top, $to, 0.2 ),
			'bs'      => self::mix( $top, $to, 0.35 ),
			't'       => $koyu ? self::WHITE : self::INK,
			'w'       => self::WHITE,
		);
	}

	/**
	 * CSS şablonu (tek kaynak: PHP ve JS aynı şablonu doldurur). Seçiciler WP 7.1 yönetici renk düzenlerinden (colors.css) alınmıştır.
	 *
	 * @param bool $editor Blok düzenleyici tam ekranı: yalnız üst çubuk/menü kabuğu (düzenleyici içeriğine dokunulmaz).
	 */
	public static function template( bool $editor = false ): string {
		// WP vurgu değişkenini body.admin-color-* üzerinde tanımlar; :root yetmez, aynı gövdede daha özgül seçici gerekir.
		$c = ( $editor ? ':root' : ':root,body.wp-admin,body.wp-admin[class*=admin-color-]' ) . '{--adminkit-accent:%acc%;--adminkit-accent-contrast:%w%;--adminkit-accent-hover:%d10%;--adminkit-accent-active:%d20%;';
		if ( ! $editor ) {
			$c .= '--wp-admin-theme-color:%acc%;--wp-admin-theme-color--rgb:%acc_rgb%;--wp-admin-theme-color-darker-10:%d10%;--wp-admin-theme-color-darker-10--rgb:%d10_rgb%;--wp-admin-theme-color-darker-20:%d20%;--wp-admin-theme-color-darker-20--rgb:%d20_rgb%;';
		}
		$c .= '}';
		if ( ! $editor ) {
			$c .= 'body.wp-admin{background:%bg%}a{color:%acc%}a:hover,a:active,a:focus{color:%d10%}';
			$c .= '.nav-tab-active,.nav-tab-active:hover{background-color:%bg%;border-bottom-color:%bg%}';
		}
		$c .= '#adminmenuback,#adminmenuwrap,#adminmenu{background:%side%}';
		$c .= '#adminmenu a,#adminmenu div.wp-menu-image:before,#collapse-button{color:%t%}';
		$c .= '#adminmenu a:hover,#adminmenu li.menu-top:hover,#adminmenu li.opensub>a.menu-top,#adminmenu li>a.menu-top:focus,#collapse-button:hover,#collapse-button:focus{color:%w%;background-color:%acc%}';
		$c .= '#adminmenu li.menu-top:hover div.wp-menu-image:before,#adminmenu li.opensub>a.menu-top div.wp-menu-image:before,#adminmenu li.current div.wp-menu-image:before,#adminmenu li.wp-has-current-submenu div.wp-menu-image:before,#adminmenu li a:focus div.wp-menu-image:before{color:%w%}';
		$c .= '#adminmenu .wp-submenu,#adminmenu .wp-has-current-submenu .wp-submenu,#adminmenu .wp-has-current-submenu.opensub .wp-submenu,#adminmenu a.wp-has-current-submenu:focus+.wp-submenu{background:%sub%}';
		$c .= '#adminmenu li.wp-has-submenu.wp-not-current-submenu.opensub:hover:after,#adminmenu li.wp-has-submenu.wp-not-current-submenu:focus-within:after{border-right-color:%sub%}';
		$c .= '#adminmenu .wp-submenu a,#adminmenu .wp-has-current-submenu .wp-submenu a,#adminmenu .wp-has-current-submenu.opensub .wp-submenu a,#adminmenu .wp-submenu li.current a,#adminmenu .wp-submenu a:hover,#adminmenu .wp-submenu a:focus,#adminmenu .wp-submenu .wp-submenu-head{color:%t%}';
		$c .= '#adminmenu li.current a.menu-top,#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,#adminmenu li.wp-has-current-submenu .wp-submenu .wp-submenu-head,.folded #adminmenu li.current.menu-top{color:%w%;background:%acc%}';
		$c .= 'ul#adminmenu a.wp-has-current-submenu:after,ul#adminmenu>li.current>a.current:after{border-right-color:%bg%}';
		$c .= '#adminmenu .menu-counter,#adminmenu .awaiting-mod,#adminmenu .update-plugins{color:%w%;background:%acc%}';
		$c .= '#adminmenu li.current a .awaiting-mod,#adminmenu li a.wp-has-current-submenu .update-plugins,#adminmenu li:hover a .awaiting-mod,#adminmenu li.menu-top:hover>a .update-plugins{color:%t%;background:%sub%}';
		$c .= '#wpadminbar{color:%t%;background:%top%}';
		$c .= '#adminmenu .wp-submenu .yoast-brand-insights-content{color:#ffffff}'; // Yoast bu öğeye kendi koyu zeminini verir; açık kabukta metin koyu kalırsa okunmaz.
		$c .= '#wpadminbar .ab-item,#wpadminbar a.ab-item,#wpadminbar>#wp-toolbar span.ab-label,#wpadminbar>#wp-toolbar span.noticon,#wpadminbar .ab-icon,#wpadminbar .ab-icon:before,#wpadminbar .ab-item:before,#wpadminbar .ab-item:after,#wpadminbar .quicklinks li .blavatar,#wpadminbar #adminbarsearch:before,#wpadminbar .ab-submenu .ab-item,#wpadminbar .quicklinks .menupop ul li a,#wpadminbar .quicklinks .menupop.hover ul li a{color:%t%}';
		$c .= '#wpadminbar:not(.mobile) .ab-top-menu>li:hover>.ab-item,#wpadminbar:not(.mobile) .ab-top-menu>li>.ab-item:focus,#wpadminbar .ab-top-menu>li.menupop.hover>.ab-item{color:%t%;background:%bh%}';
		$c .= '#wpadminbar .menupop .ab-sub-wrapper{background:%bh%}';
		$c .= '#wpadminbar .quicklinks .menupop ul.ab-sub-secondary,#wpadminbar .quicklinks .menupop ul.ab-sub-secondary .ab-submenu{background:%bs%}';
		$c .= '#wpadminbar .quicklinks .menupop ul li a:hover,#wpadminbar .quicklinks .menupop ul li a:focus,#wpadminbar .quicklinks .menupop ul li a:hover strong,#wpadminbar .quicklinks .menupop ul li a:focus strong{color:%t%}';
		return $c;
	}

	/**
	 * Yönetim paneli CSS'i. Yalnız doğrulanmış hex içerir; uygulanan renkler her zaman ≥4.5:1'dir.
	 *
	 * @param array<string, string> $s normalize() çıktısı.
	 */
	public static function css( array $s, bool $editor = false ): string {
		$map = array();
		foreach ( self::palette( $s ) as $k => $v ) {
			$map[ '%' . $k . '%' ] = $v;
		}
		return strtr( self::template( $editor ), $map );
	}
}
