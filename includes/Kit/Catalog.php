<?php
/**
 * Eklenti kataloğu: statik liste + kurulu/etkin durumu + GitHub "son yayın" sorgusu (önbellekli, ayardan kapatılabilir).
 *
 * Gizlilik: GitHub'a yalnız katalog sayfası açıldığında, yalnız `released` öğeler için, belirteçsiz düz GET gider
 * (site adresi/kullanıcı bilgisi yok). Anahtar kapalıysa HİÇ ağ çağrısı yapılmaz.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Kit;

defined( 'ABSPATH' ) || exit;

final class Catalog {

	/** Ayar: "Eklenti kataloğunu GitHub'dan canlı sorgula" ('1' = açık, varsayılan; '0' = kapalı). */
	public const OPT_LIVE = 'adminkit_catalog_live';
	public const CACHE    = 'adminkit_kit_rel_';
	public const MAX_BODY = 262144;
	public const PKG_BASE = 'https://github.com/RbnCore/';
	private const TTL_OK  = 43200; // 12 saat.
	private const TTL_ERR = 3600;  // 1 saat.

	/** @var array<int, array<string, string>>|null */
	private static ?array $items = null;

	public static function live(): bool {
		return '0' !== (string) get_option( self::OPT_LIVE, '1' );
	}

	/**
	 * Geçerli katalog öğeleri. Bozuk satır (eksik alan, kimlikle uyuşmayan depo/dosya) atlanır.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function items(): array {
		if ( null !== self::$items ) {
			return self::$items;
		}
		$raw  = is_readable( ADMINKIT_DIR . 'includes/catalog.php' ) ? include ADMINKIT_DIR . 'includes/catalog.php' : array();
		$list = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			$item = self::clean( $row );
			if ( null !== $item ) {
				$list[] = $item;
			}
		}
		self::$items = $list;
		return $list;
	}

	/** Testler için: katalog önbelleğini boşaltır. */
	public static function reset(): void {
		self::$items = null;
	}

	/**
	 * @param mixed $row Ham satır.
	 * @return array<string, string>|null
	 */
	public static function clean( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$out = array();
		foreach ( array( 'id', 'name', 'tagline', 'dashicon', 'plugin_file', 'repo', 'status', 'settings_slug' ) as $key ) {
			if ( ! isset( $row[ $key ] ) || ! is_string( $row[ $key ] ) || '' === trim( $row[ $key ] ) ) {
				return null;
			}
			$out[ $key ] = trim( $row[ $key ] );
		}
		$id = $out['id'];
		if ( 1 !== preg_match( '/^[a-z0-9\-]{2,40}$/', $id ) || 'RbnCore/' . $id !== $out['repo'] || $id . '/' . $id . '.php' !== $out['plugin_file'] ) {
			return null;
		}
		if ( ! in_array( $out['status'], array( 'released', 'soon' ), true ) || 1 !== preg_match( '/^[a-z0-9\-]{1,40}$/', $out['dashicon'] ) || 1 !== preg_match( '/^[a-z0-9_\-]+$/', $out['settings_slug'] ) ) {
			return null;
		}
		return $out;
	}

	/** @return array<string, string>|null */
	public static function find( string $id ): ?array {
		foreach ( self::items() as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Kurulu eklentiler: plugin_file => başlıklar.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugins();
	}

	/**
	 * Öğenin yerel durumu.
	 *
	 * @param array<string, string> $item Katalog öğesi.
	 * @return array{installed:bool,active:bool,version:string}
	 */
	public static function state( array $item ): array {
		$all       = self::installed_plugins();
		$installed = isset( $all[ $item['plugin_file'] ] );
		return array(
			'installed' => $installed,
			'active'    => $installed && ( is_plugin_active( $item['plugin_file'] ) || is_plugin_active_for_network( $item['plugin_file'] ) ),
			'version'   => $installed ? (string) ( $all[ $item['plugin_file'] ]['Version'] ?? '' ) : '',
		);
	}

	/** `0.1.2` ya da `v0.1.2` → `0.1.2`; başka biçim null. */
	public static function normalize_version( string $tag ): ?string {
		return 1 === preg_match( '/^v?(\d{1,4}\.\d{1,4}\.\d{1,4})$/', trim( $tag ), $m ) ? $m[1] : null;
	}

	/**
	 * GitHub "latest release" yanıtını doğrular. Varlık adları `<id>-<sürüm>.zip` (+ `.sha256`, `.sig`) ve adresleri
	 * tam `https://github.com/RbnCore/<id>/releases/download/<sürüm>/...` olmalı; eksik/bozuksa null.
	 *
	 * @param array<string, string> $item Katalog öğesi.
	 * @param mixed                 $data json_decode çıktısı.
	 * @return array<string, string>|null
	 */
	public static function parse_release( array $item, $data ): ?array {
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$version = self::normalize_version( (string) ( $data['tag_name'] ?? '' ) );
		if ( null === $version || ! isset( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
			return null;
		}
		$id     = $item['id'];
		$prefix = self::PKG_BASE . $id . '/releases/download/' . rawurlencode( $version ) . '/';
		$want   = array(
			'package' => $id . '-' . $version . '.zip',
			'sha'     => $id . '-' . $version . '.zip.sha256',
			'sig'     => $id . '-' . $version . '.zip.sig',
		);
		$found  = array();
		foreach ( $data['assets'] as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['name'], $asset['browser_download_url'] ) ) {
				continue;
			}
			$key = array_search( (string) $asset['name'], $want, true );
			if ( false !== $key && (string) $asset['browser_download_url'] === $prefix . $want[ $key ] ) {
				$found[ $key ] = $prefix . $want[ $key ];
			}
		}
		if ( 3 !== count( $found ) ) {
			return null;
		}
		return array(
			'version' => $version,
			'package' => $found['package'],
			'sha'     => $found['sha'],
			'sig'     => $found['sig'],
		);
	}

	/**
	 * Öğenin son yayını. Kapalıyken ya da `soon` öğede ağ çağrısı YAPILMAZ (null).
	 *
	 * @param array<string, string> $item  Katalog öğesi.
	 * @param bool                  $force Önbelleği yok say.
	 * @return array{status:string,release:?array<string,string>,time:int}|null status: ok | none | error
	 */
	public static function release( array $item, bool $force = false ): ?array {
		if ( 'released' !== $item['status'] || ! self::live() ) {
			return null;
		}
		$key = self::CACHE . $item['id'];
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['status'] ) ) {
				return $cached;
			}
		}
		$res    = wp_remote_get(
			'https://api.github.com/repos/' . $item['repo'] . '/releases/latest',
			array(
				'timeout'             => 5,
				'redirection'         => 2,
				'limit_response_size' => self::MAX_BODY,
				'user-agent'          => 'AdminKit/' . ADMINKIT_VERSION,
				'headers'             => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		$status = 'error';
		$rel    = null;
		$ttl    = self::TTL_ERR;
		if ( ! is_wp_error( $res ) ) {
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( 404 === $code ) {
				$status = 'none';
				$ttl    = self::TTL_OK;
			} elseif ( 200 === $code ) {
				$rel = self::parse_release( $item, json_decode( (string) wp_remote_retrieve_body( $res ), true ) );
				if ( null !== $rel ) {
					$status = 'ok';
					$ttl    = self::TTL_OK;
				}
			}
		}
		$record = array(
			'status'  => $status,
			'release' => $rel,
			'time'    => time(),
		);
		set_transient( $key, $record, $ttl );
		return $record;
	}

	/**
	 * Ayar sayfasındaki anahtarın temizleyicisi: yalnız '1' açık; gönderilmeyen (işaretsiz) kapalıdır.
	 *
	 * @param mixed $value Ham değer.
	 */
	public static function sanitize_live( $value ): string {
		return '1' === (string) $value ? '1' : '0';
	}
}
