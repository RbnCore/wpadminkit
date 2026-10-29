<?php
/**
 * Katalogdan tek tıkla kurulum ve etkinleştirme.
 *
 * - Paket WordPress'in kendi `Plugin_Upgrader::install` akışıyla kurulur; indirmeden önce SHA-256 ve Ed25519 imzası
 *   doğrulanır (`upgrader_pre_download`), açılan klasör de denetlenir (`upgrader_source_selection`). Bu kontrol kapatılamaz.
 * - İmzalanan ileti: `<ad>|<sürüm>|<sha256>`. Genel anahtar `includes/update-pubkey.php` (CookieKit'tekiyle aynı).
 * - Yalnız `https://github.com/RbnCore/<depo>/releases/download/...` adresleri; kancalar yalnız kurulum sürerken takılıdır.
 * - Kurulumdan sonra eklenti etkinleşmez; "Etkinleştir" ayrı bir tıklamadır.
 * - `DISALLOW_FILE_MODS` ya da merkezi ağ ayarı (GitHub) kapalıyken kurulum yapılmaz.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Kit;

use AdminKit\Core\Network;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public const MAX_ZIP    = 8388608; // 8 MB.
	public const NOTE_TTL   = 120;
	public const NOTE_KEY   = 'adminkit_kit_note_';
	public const ACT_INSTAL = 'adminkit_kit_install';
	public const ACT_ACTIVE = 'adminkit_kit_activate';

	/** Kurulum sürerken: beklenen paket adresi ve doğrulanan sürüm. */
	private static string $expect = '';
	private static ?string $verified = null;
	private static string $verified_id = '';

	public static function init(): void {
		add_action( 'admin_post_' . self::ACT_INSTAL, array( self::class, 'action_install' ) );
		add_action( 'admin_post_' . self::ACT_ACTIVE, array( self::class, 'action_activate' ) );
	}

	/** Katalog kurulumu sürüyor mu? Merkezi güncelleyici bu sırada paketi kendisi işlemez. */
	public static function installing(): bool {
		return '' !== self::$expect;
	}

	/** Bu sitede eklenti kurmaya WordPress izin veriyor mu (DISALLOW_FILE_MODS ve `file_mod_allowed` süzgeci). */
	public static function file_mods_allowed(): bool {
		return function_exists( 'wp_is_file_mod_allowed' ) ? wp_is_file_mod_allowed( 'adminkit_catalog_install' ) : ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	}

	// ───────────────────────── Doğrulama (saf) ─────────────────────────

	/** İmzanın bağlandığı ileti: paket adı sürüme, sürüm de özete bağlıdır. */
	public static function signed_message( string $id, string $version, string $sha256 ): string {
		return $id . '|' . $version . '|' . strtolower( $sha256 );
	}

	/** Ortak genel anahtar: 32 baytlık ham değer ya da boş. */
	private static function public_key(): string {
		$file = ADMINKIT_DIR . 'includes/update-pubkey.php';
		$b64  = is_readable( $file ) ? include $file : '';
		$raw  = is_string( $b64 ) ? base64_decode( $b64, true ) : false;
		return ( is_string( $raw ) && 32 === strlen( $raw ) ) ? $raw : '';
	}

	/**
	 * @param string $key_override Yalnız test için ham genel anahtar; boşsa gömülü anahtar.
	 * @return true|\WP_Error
	 */
	public static function verify_signature( string $message, string $sig_b64, string $key_override = '' ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return new \WP_Error( 'adminkit_no_sodium', __( 'Could not verify the package signature: this server has no signature support (sodium). Nothing was installed.', 'adminkit' ) );
		}
		$key = '' !== $key_override ? $key_override : self::public_key();
		$sig = base64_decode( trim( $sig_b64 ), true );
		if ( 32 !== strlen( $key ) ) {
			return new \WP_Error( 'adminkit_no_key', __( 'Could not verify the package signature: the plugin has no valid signature key. Nothing was installed.', 'adminkit' ) );
		}
		if ( ! is_string( $sig ) || 64 !== strlen( $sig ) || ! sodium_crypto_sign_verify_detached( $sig, $message, $key ) ) {
			return new \WP_Error( 'adminkit_bad_sig', __( 'Could not verify the package signature. Nothing was installed.', 'adminkit' ) );
		}
		return true;
	}

	/**
	 * İndirilen dosya: boyut, SHA-256 ve imza.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_package( string $id, string $file, string $version, string $sha_body, string $sig_b64, string $key_override = '' ) {
		$size = is_file( $file ) ? (int) filesize( $file ) : 0;
		if ( $size < 1 || $size > self::MAX_ZIP ) {
			return new \WP_Error( 'adminkit_bad_size', __( 'The package size is outside the expected range. Nothing was installed.', 'adminkit' ) );
		}
		if ( 1 !== preg_match( '/^\s*([0-9a-fA-F]{64})(?:\s|$)/', $sha_body, $m ) ) {
			return new \WP_Error( 'adminkit_bad_sha_file', __( 'Could not read the digest (SHA-256) file. Nothing was installed.', 'adminkit' ) );
		}
		$actual = (string) hash_file( 'sha256', $file );
		if ( ! hash_equals( strtolower( $m[1] ), $actual ) ) {
			return new \WP_Error( 'adminkit_sha_mismatch', __( 'The SHA-256 digest of the package does not match. Nothing was installed.', 'adminkit' ) );
		}
		return self::verify_signature( self::signed_message( $id, $version, $actual ), $sig_b64, $key_override );
	}

	/**
	 * Açılmış paketin kök klasörü ve ana dosyası.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_source( string $id, string $source, string $version ) {
		$dir  = rtrim( str_replace( '\\', '/', $source ), '/' );
		$main = $dir . '/' . $id . '.php';
		if ( basename( $dir ) !== $id || ! is_file( $main ) ) {
			/* translators: %s: plugin folder name */
			return new \WP_Error( 'adminkit_bad_layout', sprintf( __( 'The package root folder is not “%s” or the main file is missing. Nothing was installed.', 'adminkit' ), $id ) );
		}
		$head = get_file_data( $main, array( 'v' => 'Version', 'n' => 'Plugin Name' ) );
		if ( '' === $head['n'] || $head['v'] !== $version ) {
			return new \WP_Error( 'adminkit_bad_version', __( 'The version in the package does not match the released version. Nothing was installed.', 'adminkit' ) );
		}
		return true;
	}

	// ───────────────────────── Kurulum ─────────────────────────

	/**
	 * Katalog öğesini kurar (etkinleştirmez). Yetki denetimi çağıran işleyicidedir.
	 *
	 * @return true|\WP_Error
	 */
	public static function install( string $id ) {
		$item = Catalog::find( $id );
		if ( null === $item ) {
			return new \WP_Error( 'adminkit_unknown', __( 'This plugin is not in the catalog.', 'adminkit' ) );
		}
		if ( ! self::file_mods_allowed() ) {
			return new \WP_Error( 'adminkit_file_mods', __( 'Plugin installation is turned off on this site (DISALLOW_FILE_MODS). Nothing was installed.', 'adminkit' ) );
		}
		if ( ! Network::github_allowed( 'catalog' ) ) {
			return new \WP_Error( 'adminkit_offline', __( 'The plugin catalog GitHub lookup is turned off in the settings. Nothing was installed.', 'adminkit' ) );
		}
		if ( 'released' !== $item['status'] ) {
			return new \WP_Error( 'adminkit_soon', __( 'This plugin is not released yet.', 'adminkit' ) );
		}
		if ( Catalog::state( $item )['installed'] ) {
			return new \WP_Error( 'adminkit_installed', __( 'This plugin is already installed.', 'adminkit' ) );
		}
		$rec = Catalog::release( $item );
		if ( null === $rec || 'ok' !== $rec['status'] || ! is_array( $rec['release'] ) ) {
			return new \WP_Error( 'adminkit_no_release', __( 'Could not get the released version information. Nothing was installed.', 'adminkit' ) );
		}
		$rel = $rec['release'];

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$target  = trailingslashit( WP_PLUGIN_DIR ) . $id;
		$existed = file_exists( $target );
		$work    = trailingslashit( WP_CONTENT_DIR ) . 'upgrade/';
		$work_was = self::entries( $work );

		self::$expect      = $rel['package'];
		self::$verified    = null;
		self::$verified_id = '';
		add_filter( 'upgrader_pre_download', array( self::class, 'pre_download' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( self::class, 'source_selection' ), 10, 4 );
		$skin     = new QuietSkin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $rel['package'], array( 'clear_update_cache' => true ) );
		remove_filter( 'upgrader_pre_download', array( self::class, 'pre_download' ), 10 );
		remove_filter( 'upgrader_source_selection', array( self::class, 'source_selection' ), 10 );
		self::$expect      = '';
		self::$verified    = null;
		self::$verified_id = '';

		$error = null;
		if ( is_wp_error( $result ) ) {
			$error = $result;
		} elseif ( $skin->get_errors()->has_errors() ) {
			$error = $skin->get_errors();
		} elseif ( true !== $result ) {
			$error = new \WP_Error( 'adminkit_install_failed', __( 'The installation could not be completed.', 'adminkit' ) );
		}
		if ( null !== $error ) {
			if ( ! $existed ) {
				self::remove_dir( $target ); // Yarım kurulum bırakma.
			}
			foreach ( array_diff( self::entries( $work ), $work_was ) as $leftover ) {
				self::remove_dir( $work . $leftover ); // WordPress, kaynak reddinde çalışma klasörünü silmez.
			}
			wp_clean_plugins_cache( false );
			return $error;
		}
		wp_clean_plugins_cache( true );
		if ( ! Catalog::state( $item )['installed'] ) {
			if ( ! $existed ) {
				self::remove_dir( $target );
			}
			return new \WP_Error( 'adminkit_install_missing', __( 'The plugin file was not found after the installation. Nothing was installed.', 'adminkit' ) );
		}
		return true;
	}

	/** @return string[] Klasördeki girdi adları (yoksa boş). */
	private static function entries( string $dir ): array {
		$list = is_dir( $dir ) ? scandir( $dir ) : false;
		return is_array( $list ) ? array_values( array_diff( $list, array( '.', '..' ) ) ) : array();
	}

	private static function remove_dir( string $dir ): void {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		global $wp_filesystem;
		if ( is_object( $wp_filesystem ) ) {
			$wp_filesystem->delete( $dir, true );
		}
		if ( is_dir( $dir ) ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $f ) {
				$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions -- yarım kurulum temizliği.
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Paket indirilir, doğrulanır; geçerliyse geçici dosya yolu döner (WP onu açar).
	 *
	 * @param mixed  $reply   Önceki yanıt.
	 * @param string $package Paket adresi.
	 * @return mixed
	 */
	public static function pre_download( $reply, $package ) {
		if ( false !== $reply || '' === self::$expect || $package !== self::$expect ) {
			return $reply;
		}
		self::$verified = null;
		if ( 1 !== preg_match( '#^' . preg_quote( Catalog::PKG_BASE, '#' ) . '([a-z0-9\-]{2,42})/releases/download/(\d{1,4}\.\d{1,4}\.\d{1,4})/([a-z0-9\-]{2,40})-\2\.zip$#', $package, $m ) ) {
			return new \WP_Error( 'adminkit_bad_url', __( 'The package address is not in the expected format. Nothing was installed.', 'adminkit' ) );
		}
		$id      = $m[3];
		$version = $m[2];
		$item    = Catalog::find( $id );
		if ( null === $item || Catalog::repo_name( $item ) !== $m[1] ) {
			return new \WP_Error( 'adminkit_unknown', __( 'This plugin is not in the catalog.', 'adminkit' ) );
		}
		$sha = self::fetch_text( $package . '.sha256' );
		$sig = is_wp_error( $sha ) ? $sha : self::fetch_text( $package . '.sig' );
		if ( is_wp_error( $sig ) ) {
			return $sig;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $package, 300 );
		if ( is_wp_error( $tmp ) ) {
			return new \WP_Error( 'adminkit_download_fail', __( 'Could not download the package. Nothing was installed.', 'adminkit' ) );
		}
		$ok = self::check_package( $id, $tmp, $version, $sha, $sig );
		if ( is_wp_error( $ok ) ) {
			wp_delete_file( $tmp );
			return $ok;
		}
		self::$verified    = $version;
		self::$verified_id = $id;
		return $tmp;
	}

	/** Küçük metin varlığı (özet/imza) indirir. @return string|\WP_Error */
	private static function fetch_text( string $url ) {
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'limit_response_size' => 4096,
				'user-agent'          => 'AdminKit/' . ADMINKIT_VERSION,
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new \WP_Error( 'adminkit_fetch_fail', __( 'Could not download the digest or signature file. Nothing was installed.', 'adminkit' ) );
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/**
	 * Açılmış paket: kök klasör ve ana dosya kontrolü. Yalnız kurulum sürerken takılıdır.
	 *
	 * @param mixed               $source        Açılmış klasör.
	 * @param string              $remote_source Açma konumu.
	 * @param mixed               $upgrader      Yükseltici.
	 * @param array<string,mixed> $hook_extra    Ek bilgi.
	 * @return mixed
	 */
	public static function source_selection( $source, $remote_source = '', $upgrader = null, $hook_extra = array() ) {
		if ( is_wp_error( $source ) || '' === self::$expect ) {
			return $source;
		}
		$version = self::$verified;
		$id      = self::$verified_id;
		if ( null === $version || '' === $id ) {
			return new \WP_Error( 'adminkit_unverified', __( 'The package did not pass verification. Nothing was installed.', 'adminkit' ) );
		}
		$ok = self::check_source( $id, (string) $source, $version );
		return is_wp_error( $ok ) ? $ok : $source;
	}

	// ───────────────────────── İşleyiciler ─────────────────────────

	/** Başarı kodu → ileti. */
	public static function note_text( string $code ): string {
		$map = array(
			'kuruldu' => __( 'The plugin was installed. Select “Activate” to use it.', 'adminkit' ),
			'etkin'   => __( 'The plugin was activated.', 'adminkit' ),
		);
		return $map[ $code ] ?? '';
	}

	private static function cap_fail(): void {
		wp_die( esc_html__( 'You are not allowed to do this.', 'adminkit' ), '', array( 'response' => 403 ) );
	}

	private static function posted_id(): string {
		$id = isset( $_POST['id'] ) && is_string( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- çağıran nonce'u doğrulamıştır.
		return $id;
	}

	public static function action_install(): void {
		if ( ! current_user_can( 'install_plugins' ) ) {
			self::cap_fail();
		}
		$id = self::posted_id();
		check_admin_referer( self::ACT_INSTAL . '_' . $id );
		$res = self::install( $id );
		if ( is_wp_error( $res ) ) {
			self::done( 'hata', $res->get_error_message() );
		}
		self::done( 'kuruldu' );
	}

	public static function action_activate(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			self::cap_fail();
		}
		$id = self::posted_id();
		check_admin_referer( self::ACT_ACTIVE . '_' . $id );
		$item = Catalog::find( $id );
		if ( null === $item || ! Catalog::state( $item )['installed'] ) {
			self::done( 'hata', __( 'The plugin to activate was not found.', 'adminkit' ) );
		}
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$res = activate_plugin( $item['plugin_file'] );
		if ( is_wp_error( $res ) ) {
			self::done( 'hata', $res->get_error_message() );
		}
		self::done( 'etkin' );
	}

	/** Sonucu 2 dakikalık kullanıcı notuna yazar ve katalog sekmesine döner. */
	private static function done( string $code, string $message = '' ): void {
		set_transient( self::NOTE_KEY . get_current_user_id(), array( 'code' => $code, 'message' => $message ), self::NOTE_TTL );
		wp_safe_redirect( Overview::url( 'katalog' ) );
		exit;
	}

	/**
	 * Bekleyen notu okur ve siler.
	 *
	 * @return array{code:string,message:string}|null
	 */
	public static function take_note(): ?array {
		$key  = self::NOTE_KEY . get_current_user_id();
		$note = get_transient( $key );
		if ( false === $note ) {
			return null;
		}
		delete_transient( $key );
		return is_array( $note ) && isset( $note['code'] ) ? array( 'code' => (string) $note['code'], 'message' => (string) ( $note['message'] ?? '' ) ) : null;
	}
}
