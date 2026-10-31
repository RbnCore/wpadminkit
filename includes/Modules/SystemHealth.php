<?php
/**
 * Modül 8 — Sistem sağlığı: sunucu, PHP, WordPress, veritabanı ve WP-Cron durumunu SALT OKUMA olarak özetler.
 *
 * Kural (patron, 30–31.10.2026): yalnız bu sitenin kendisi okunur; dış istek YOK, siteler arası kanal YOK,
 * cron yöneticisi YOK (olay listeleme/çalıştırma/silme yok). Hiçbir şey silinmez: temizlik Veritabanı bakımı
 * modülünün işidir ve sayılar tek kaynaktan (`AdminKit\Health\DbStats`) okunur.
 *
 * Sonuç `Core\Cache` içinde `adminkit.health` grubunda 5 dakika tutulur; "Yenile" nonce'lu bir admin-post
 * bağlantısıdır ve yalnız grubu temizler. "Destek raporunu kopyala" site adresi, kullanıcı adı, e-posta,
 * parola, anahtar veya tuz içermez.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Modules;

use AdminKit\Assets;
use AdminKit\Core\Cache;
use AdminKit\Core\Security\Guard;
use AdminKit\Health\DbStats;
use AdminKit\Helpers\Toast;
use AdminKit\Module;
use AdminKit\Settings;
use AdminKit\SettingsPage;

defined( 'ABSPATH' ) || exit;

final class SystemHealth implements Module {

	public const NONCE          = 'adminkit_health';
	public const ACTION_REFRESH = 'adminkit_health_refresh';
	public const GROUP          = 'adminkit.health';
	public const CACHE_KEY      = 'snapshot';
	public const TTL            = 300;

	/* Eşikler (saf durum fonksiyonları bunları kullanır; testler sahte değerlerle sınar). */
	public const PHP_OK          = '8.2';
	public const PHP_WARN        = '8.0';
	public const WP_OK           = '6.4';
	public const WP_WARN         = '6.0';
	public const MYSQL_OK        = '8.0';
	public const MYSQL_WARN      = '5.7';
	public const MARIADB_OK      = '10.5';
	public const MARIADB_WARN    = '10.2';
	public const MEMORY_OK       = 268435456; // 256 MB
	public const MEMORY_WARN     = 134217728; // 128 MB
	public const WP_MEMORY_OK    = 134217728; // 128 MB
	public const WP_MEMORY_WARN  = 41943040;  // 40 MB (WordPress varsayılanı: altına düşerse sorun)
	public const EXEC_OK         = 30;
	public const EXEC_WARN       = 15;
	public const INPUT_OK        = 1000;
	public const INPUT_WARN      = 500;
	public const DISK_OK         = 2147483648; // 2 GB
	public const DISK_WARN       = 524288000;  // 500 MB

	public function id(): string {
		return 'system_health';
	}

	public function title(): string {
		return __( 'System health', 'adminkit' );
	}

	public function description(): string {
		return __( 'Reads the server, PHP, WordPress, database and WP-Cron status of this site only. Nothing is changed, run or deleted, and no external request is sent.', 'adminkit' );
	}

	public function default_enabled(): bool {
		return false;
	}

	public function switch_label(): string {
		return __( 'Use system health', 'adminkit' );
	}

	/** Sağlık raporu büyük bir arayüz olduğu için kendi sekmesini alır. */
	public function tab_label(): string {
		return __( 'System health', 'adminkit' );
	}

	public function off_hint(): string {
		return __( 'Turn it on and press Save to see the health report.', 'adminkit' );
	}

	public function settings_schema(): array {
		return array(
			array(
				'key'    => 'ui',
				'type'   => 'custom',
				'label'  => __( 'Health report', 'adminkit' ),
				'render' => array( $this, 'render_panel' ),
				// Anahtar uygulanınca panel yenilemeden değişir; betik modül kapalıyken yüklenmediği için canlı yüklenir (WPRJ-738).
				'live'   => array(
					'scripts' => array( 'adminkit-systemhealth' ),
					'paths'   => array( 'modules.' . $this->id() . '.enabled' ),
				),
			),
		);
	}

	public function boot( array $settings ): void {
		unset( $settings );
		add_action( 'admin_post_' . self::ACTION_REFRESH, array( self::class, 'refresh' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/** Yalnız Ayarlar → AdminKit ekranında, modül AÇIKKEN yüklenir (kopyalama betiği; özel CSS yok). */
	public static function enqueue( string $hook ): void {
		if ( '' === SettingsPage::screen() || SettingsPage::screen() !== $hook ) {
			return;
		}
		Assets::load(
			'systemhealth',
			array(
				'i18n' => array(
					'copied'   => __( 'The support report was copied to the clipboard.', 'adminkit' ),
					'copyFail' => __( 'Could not copy. Select the report text and copy it yourself.', 'adminkit' ),
				),
			),
			false
		);
	}

	/* ------------------------------------------------------------------ uç */

	/** "Yenile": nonce doğrulanır, önbellek grubu temizlenir, sekmeye dönülür. Veri toplama burada yapılmaz. */
	public static function refresh(): void {
		Guard::admin_action( self::NONCE, 'manage_options', '_wpnonce', 'die' );
		if ( ! self::enabled() ) {
			Toast::flash( 'info', __( 'The module is off; nothing was refreshed.', 'adminkit' ) );
			self::back();
		}
		Cache::flush_group( self::GROUP );
		Toast::flash( 'success', __( 'Health data refreshed.', 'adminkit' ) );
		self::back();
	}

	private static function back(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce refresh() başında doğrulandı; değer beyaz listeden.
		$sub = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : '';
		$url = self::tab_url();
		wp_safe_redirect( in_array( $sub, array( 'overview', 'server', 'db', 'cron', 'report' ), true ) ? add_query_arg( 'sub', $sub, $url ) : $url );
		exit;
	}

	public static function tab_url(): string {
		return add_query_arg( 'tab', ( new self() )->id(), SettingsPage::url() );
	}

	public static function enabled(): bool {
		$settings = Settings::for_module( new self() );
		return ! empty( $settings['enabled'] );
	}

	/* ------------------------------------------------------------------ veri */

	/** Önbellekli anlık görüntü (5 dakika). Hesaplama tek geçişte `collect()` içinde yapılır. */
	public static function snapshot(): array {
		$row = Cache::remember( self::GROUP, self::CACHE_KEY, self::TTL, array( self::class, 'collect' ) );
		return is_array( $row ) ? $row : array();
	}

	/**
	 * Tüm sağlık verisi: sürümler, limitler, eklentiler, veritabanı özeti ve cron durumu (salt okuma).
	 *
	 * @return array<string, mixed>
	 */
	public static function collect(): array {
		global $wpdb, $wp_version;

		$php = PHP_VERSION;
		$wp  = isset( $wp_version ) && is_string( $wp_version ) && '' !== $wp_version ? $wp_version : get_bloginfo( 'version' );

		$db_server = '';
		if ( is_object( $wpdb ) ) {
			if ( method_exists( $wpdb, 'db_server_info' ) ) {
				$db_server = (string) $wpdb->db_server_info();
			} else {
				$db_server = (string) $wpdb->db_version();
			}
		}
		if ( '' === $db_server ) {
			$db_server = (string) \AdminKit\Helpers\Db::get_var( 'SELECT VERSION()', array() );
		}

		$memory   = self::to_bytes( (string) ini_get( 'memory_limit' ) );
		$wp_mem   = self::to_bytes( defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '40M' );
		$wp_max   = self::to_bytes( defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : ( defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '256M' ) );
		$upload   = self::to_bytes( (string) ini_get( 'upload_max_filesize' ) );
		$post     = self::to_bytes( (string) ini_get( 'post_max_size' ) );
		$exec     = (int) ini_get( 'max_execution_time' );
		$vars     = (int) ini_get( 'max_input_vars' );

		$cron = self::cron_status_raw();

		$missing = self::missing_extensions();

		$uploads = wp_get_upload_dir();
		$writable = empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && wp_is_writable( (string) $uploads['basedir'] );

		$disk = null;
		if ( function_exists( 'disk_free_space' ) ) {
			$free = @disk_free_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- olmayan/ölçülemeyen diskte uyarı basmaz.
			if ( false !== $free ) {
				$disk = (int) $free;
			}
		}

		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : '';

		return array(
			'generated'  => time(),
			'server'     => array(
				'php'              => $php,
				'wordpress'        => $wp,
				'database'         => $db_server,
				'web'              => $server,
				'memory'           => $memory,
				'wp_memory'        => $wp_mem,
				'wp_max_memory'    => $wp_max,
				'upload'           => $upload,
				'post'             => $post,
				'execution'        => $exec,
				'input_vars'       => $vars,
				'opcache'          => self::opcache_on(),
				'object_cache'     => (bool) wp_using_ext_object_cache(),
				'missing'          => $missing,
				'https'            => is_ssl(),
				'output_buffering' => (string) ini_get( 'output_buffering' ),
				'timezone_php'     => date_default_timezone_get(),
				'timezone_wp'      => function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : (string) get_option( 'timezone_string' ),
				'disk_free'        => $disk,
				'uploads_writable' => $writable,
			),
			'cron'       => $cron,
			'db'         => DbStats::summary(),
			'plugins'    => self::active_plugins(),
			'theme'      => self::active_theme(),
		);
	}

	/** @return array<int, string> */
	private static function active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( array_unique( array_map( 'strval', $active ) ) as $file ) {
			if ( isset( $all[ $file ] ) && is_array( $all[ $file ] ) ) {
				$name    = isset( $all[ $file ]['Name'] ) ? (string) $all[ $file ]['Name'] : $file;
				$version = isset( $all[ $file ]['Version'] ) ? (string) $all[ $file ]['Version'] : '';
				$out[]   = '' !== $version ? $name . ' ' . $version : $name;
			}
		}
		return $out;
	}

	/** @return array{name:string,version:string} */
	private static function active_theme(): array {
		$theme = wp_get_theme();
		return array(
			'name'    => $theme instanceof \WP_Theme ? (string) $theme->get( 'Name' ) : '',
			'version' => $theme instanceof \WP_Theme ? (string) $theme->get( 'Version' ) : '',
		);
	}

	/** @return string[] Eksik zorunlu eklentiler (gd/imagick ikisinden biri yeter). */
	public static function missing_extensions(): array {
		$checks = array(
			'zip'      => extension_loaded( 'zip' ),
			'mbstring' => extension_loaded( 'mbstring' ),
			'curl'     => extension_loaded( 'curl' ),
			'openssl'  => extension_loaded( 'openssl' ),
			'sodium'   => extension_loaded( 'sodium' ),
			'intl'     => extension_loaded( 'intl' ),
		);
		$missing = array();
		foreach ( $checks as $name => $on ) {
			if ( ! $on ) {
				$missing[] = $name;
			}
		}
		if ( ! extension_loaded( 'gd' ) && ! extension_loaded( 'imagick' ) ) {
			$missing[] = 'gd/imagick';
		}
		return $missing;
	}

	private static function opcache_on(): bool {
		if ( ! function_exists( 'opcache_get_status' ) ) {
			return false;
		}
		$status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- kısıtlı sunucuda false döner.
		return is_array( $status ) && ! empty( $status['opcache_enabled'] );
	}

	/**
	 * WP-Cron durumu — YALNIZ OKUMA. Olay listesi dışarı verilmez; yalnız sayılar ve son çalışma zamanı.
	 *
	 * @return array{disabled:bool,last_run:float|null,events:int,late:int,oldest_late:int|null}
	 */
	public static function cron_status_raw(): array {
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$last     = get_transient( 'doing_cron' );
		$last     = false === $last || '' === $last ? null : (float) $last;

		$now    = time();
		$events = 0;
		$late   = 0;
		$oldest = null;
		$cron   = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		foreach ( (array) $cron as $timestamp => $hooks ) {
			$count = 0;
			foreach ( (array) $hooks as $entries ) {
				$count += is_array( $entries ) ? count( $entries ) : 0;
			}
			$events += $count;
			$timestamp = (int) $timestamp;
			if ( $timestamp < $now && $count > 0 ) {
				$late += $count;
				$delay = $now - $timestamp;
				if ( null === $oldest || $delay > $oldest ) {
					$oldest = $delay;
				}
			}
		}

		return array(
			'disabled'    => (bool) $disabled,
			'last_run'    => $last,
			'events'      => $events,
			'late'        => $late,
			'oldest_late' => $oldest,
		);
	}

	/* ------------------------------------------------------------------ saf durum fonksiyonları */

	public static function php_status( string $version ): string {
		if ( version_compare( $version, self::PHP_OK, '>=' ) ) {
			return 'ok';
		}
		return version_compare( $version, self::PHP_WARN, '>=' ) ? 'warn' : 'err';
	}

	public static function wp_status( string $version ): string {
		if ( version_compare( $version, self::WP_OK, '>=' ) ) {
			return 'ok';
		}
		return version_compare( $version, self::WP_WARN, '>=' ) ? 'warn' : 'err';
	}

	public static function db_status( string $server ): string {
		$mariadb = false !== stripos( $server, 'mariadb' );
		$version = '';
		if ( 1 === preg_match( '/(\d+\.\d+(?:\.\d+)?)/', $server, $m ) ) {
			$version = $m[1];
		}
		if ( '' === $version ) {
			return 'warn';
		}
		if ( $mariadb ) {
			if ( version_compare( $version, self::MARIADB_OK, '>=' ) ) {
				return 'ok';
			}
			return version_compare( $version, self::MARIADB_WARN, '>=' ) ? 'warn' : 'err';
		}
		if ( version_compare( $version, self::MYSQL_OK, '>=' ) ) {
			return 'ok';
		}
		return version_compare( $version, self::MYSQL_WARN, '>=' ) ? 'warn' : 'err';
	}

	public static function memory_status( int $bytes ): string {
		if ( $bytes < 0 || $bytes >= self::MEMORY_OK ) {
			return 'ok';
		}
		return $bytes >= self::MEMORY_WARN ? 'warn' : 'err';
	}

	public static function wp_memory_status( int $bytes ): string {
		if ( $bytes < 0 || $bytes >= self::WP_MEMORY_OK ) {
			return 'ok';
		}
		return $bytes >= self::WP_MEMORY_WARN ? 'warn' : 'err';
	}

	public static function max_memory_status( int $bytes ): string {
		return self::memory_status( $bytes );
	}

	public static function execution_status( int $seconds ): string {
		if ( $seconds <= 0 || $seconds >= self::EXEC_OK ) {
			return 'ok';
		}
		return $seconds >= self::EXEC_WARN ? 'warn' : 'err';
	}

	public static function input_vars_status( int $vars ): string {
		if ( $vars >= self::INPUT_OK ) {
			return 'ok';
		}
		return $vars >= self::INPUT_WARN ? 'warn' : 'err';
	}

	public static function upload_status( int $post_max, int $upload_max ): string {
		if ( $upload_max <= 0 || $post_max <= 0 ) {
			return 'err';
		}
		return $post_max >= $upload_max ? 'ok' : 'warn';
	}

	public static function autoload_status( int $bytes ): string {
		if ( $bytes < DbStats::AUTOLOAD_WARN_BYTES ) {
			return 'ok';
		}
		return $bytes <= DbStats::AUTOLOAD_DANGER_BYTES ? 'warn' : 'err';
	}

	public static function cron_status( bool $disabled, ?float $last_run, int $late, ?int $oldest_late ): string {
		if ( $disabled || null === $last_run ) {
			return 'warn';
		}
		if ( $late <= 0 ) {
			return 'ok';
		}
		return null !== $oldest_late && $oldest_late >= DAY_IN_SECONDS ? 'err' : 'warn';
	}

	public static function https_status( bool $ssl ): string {
		return $ssl ? 'ok' : 'err';
	}

	/** @param string[] $missing Eksik eklentiler. */
	public static function extensions_status( array $missing ): string {
		$n = count( $missing );
		if ( 0 === $n ) {
			return 'ok';
		}
		return $n <= 2 ? 'warn' : 'err';
	}

	public static function opcache_status( bool $on ): string {
		return $on ? 'ok' : 'warn';
	}

	public static function object_cache_status( bool $on ): string {
		return $on ? 'ok' : 'warn';
	}

	public static function disk_status( ?int $free ): string {
		if ( null === $free ) {
			return 'warn';
		}
		if ( $free < self::DISK_WARN ) {
			return 'err';
		}
		return $free < self::DISK_OK ? 'warn' : 'ok';
	}

	public static function uploads_status( bool $writable ): string {
		return $writable ? 'ok' : 'err';
	}

	public static function timezone_status( string $php, string $wp ): string {
		return strtolower( trim( $php ) ) === strtolower( trim( $wp ) ) ? 'ok' : 'warn';
	}

	/* ------------------------------------------------------------------ satırlar */

	/**
	 * Sunucu/PHP satırları. Her satır: key, label, value, status, means, todo.
	 *
	 * @param array<string, mixed> $snapshot Anlık görüntü.
	 * @return array<int, array<string, string>>
	 */
	public static function server_rows( array $snapshot ): array {
		$s    = (array) ( $snapshot['server'] ?? array() );
		$rows = array();

		$rows[] = self::row(
			'php',
			__( 'PHP version', 'adminkit' ),
			(string) ( $s['php'] ?? '' ),
			self::php_status( (string) ( $s['php'] ?? '0' ) ),
			__( 'The PHP version that runs WordPress and its plugins.', 'adminkit' ),
			__( 'Ask the host to switch to PHP 8.2 or newer.', 'adminkit' )
		);
		$rows[] = self::row(
			'wordpress',
			__( 'WordPress version', 'adminkit' ),
			(string) ( $s['wordpress'] ?? '' ),
			self::wp_status( (string) ( $s['wordpress'] ?? '0' ) ),
			__( 'The WordPress core version this site runs.', 'adminkit' ),
			__( 'Update WordPress from Dashboard → Updates.', 'adminkit' )
		);
		$rows[] = self::row(
			'database',
			__( 'Database version', 'adminkit' ),
			(string) ( $s['database'] ?? '' ),
			self::db_status( (string) ( $s['database'] ?? '' ) ),
			__( 'The MySQL or MariaDB server that stores the site.', 'adminkit' ),
			__( 'Ask the host to update the database server.', 'adminkit' )
		);
		$rows[] = self::row(
			'memory',
			__( 'PHP memory limit', 'adminkit' ),
			self::memory_text( (int) ( $s['memory'] ?? 0 ) ),
			self::memory_status( (int) ( $s['memory'] ?? 0 ) ),
			__( 'The most memory one request may use.', 'adminkit' ),
			__( 'Raise memory_limit to 256M or more.', 'adminkit' )
		);
		$rows[] = self::row(
			'wp_memory',
			__( 'WordPress memory limit', 'adminkit' ),
			self::memory_text( (int) ( $s['wp_memory'] ?? 0 ) ),
			self::wp_memory_status( (int) ( $s['wp_memory'] ?? 0 ) ),
			__( 'The memory WordPress allows itself for normal requests.', 'adminkit' ),
			__( 'Raise WP_MEMORY_LIMIT to 128M or more in wp-config.php.', 'adminkit' )
		);
		$rows[] = self::row(
			'wp_max_memory',
			__( 'Admin memory limit', 'adminkit' ),
			self::memory_text( (int) ( $s['wp_max_memory'] ?? 0 ) ),
			self::max_memory_status( (int) ( $s['wp_max_memory'] ?? 0 ) ),
			__( 'The memory WordPress may use in the admin area.', 'adminkit' ),
			__( 'Raise WP_MAX_MEMORY_LIMIT to 256M or more.', 'adminkit' )
		);
		$rows[] = self::row(
			'upload',
			__( 'Upload size', 'adminkit' ),
			self::bytes_text( (int) ( $s['upload'] ?? 0 ) ) . ' / ' . self::bytes_text( (int) ( $s['post'] ?? 0 ) ),
			self::upload_status( (int) ( $s['post'] ?? 0 ), (int) ( $s['upload'] ?? 0 ) ),
			__( 'The largest single upload and the largest form post PHP accepts.', 'adminkit' ),
			__( 'Raise upload_max_filesize and post_max_size; post_max_size must be at least as large.', 'adminkit' )
		);
		$rows[] = self::row(
			'execution',
			__( 'Maximum execution time', 'adminkit' ),
			self::seconds_text( (int) ( $s['execution'] ?? 0 ) ),
			self::execution_status( (int) ( $s['execution'] ?? 0 ) ),
			__( 'How long one request may run before PHP stops it.', 'adminkit' ),
			__( 'Raise max_execution_time to 30 seconds or more.', 'adminkit' )
		);
		$rows[] = self::row(
			'input_vars',
			__( 'Maximum form fields', 'adminkit' ),
			number_format_i18n( (int) ( $s['input_vars'] ?? 0 ) ),
			self::input_vars_status( (int) ( $s['input_vars'] ?? 0 ) ),
			__( 'How many fields one request may carry; large settings forms can hit this.', 'adminkit' ),
			__( 'Raise max_input_vars to 1000 or more.', 'adminkit' )
		);
		$rows[] = self::row(
			'opcache',
			'OPcache',
			! empty( $s['opcache'] ) ? __( 'On', 'adminkit' ) : __( 'Off', 'adminkit' ),
			self::opcache_status( ! empty( $s['opcache'] ) ),
			__( 'PHP keeps compiled files in memory, so pages load faster.', 'adminkit' ),
			__( 'Ask the host to enable OPcache.', 'adminkit' )
		);
		$rows[] = self::row(
			'object_cache',
			__( 'Persistent object cache', 'adminkit' ),
			! empty( $s['object_cache'] ) ? __( 'On', 'adminkit' ) : __( 'Off', 'adminkit' ),
			self::object_cache_status( ! empty( $s['object_cache'] ) ),
			__( 'A cache server (Redis or Memcached) stores repeated database results.', 'adminkit' ),
			__( 'Not required; it helps busy sites. Ask the host if the site is slow.', 'adminkit' )
		);
		$missing = array_map( 'strval', (array) ( $s['missing'] ?? array() ) );
		$rows[]  = self::row(
			'extensions',
			__( 'Required PHP extensions', 'adminkit' ),
			$missing ? implode( ', ', $missing ) : __( 'All present', 'adminkit' ),
			self::extensions_status( $missing ),
			__( 'Extensions that plugins commonly need: zip, image, text, network and encryption.', 'adminkit' ),
			__( 'Ask the host to enable the missing extensions.', 'adminkit' )
		);
		$rows[] = self::row(
			'https',
			__( 'HTTPS', 'adminkit' ),
			! empty( $s['https'] ) ? __( 'Yes', 'adminkit' ) : __( 'No', 'adminkit' ),
			self::https_status( ! empty( $s['https'] ) ),
			__( 'The admin area is served over an encrypted connection.', 'adminkit' ),
			__( 'Turn on SSL at the host and set the site address to https in Settings → General.', 'adminkit' )
		);
		$rows[] = self::row(
			'output_buffering',
			__( 'Output buffering', 'adminkit' ),
			self::output_buffering_text( (string) ( $s['output_buffering'] ?? '' ) ),
			'ok',
			__( 'PHP collects output before sending it, which avoids header errors.', 'adminkit' ),
			''
		);
		$rows[] = self::row(
			'timezone',
			__( 'Timezone', 'adminkit' ),
			(string) ( $s['timezone_php'] ?? '' ) . ' / ' . (string) ( $s['timezone_wp'] ?? '' ),
			self::timezone_status( (string) ( $s['timezone_php'] ?? '' ), (string) ( $s['timezone_wp'] ?? '' ) ),
			__( 'PHP and WordPress agree on the time zone used for dates.', 'adminkit' ),
			__( 'Set the site time zone in Settings → General.', 'adminkit' )
		);
		$rows[] = self::row(
			'disk',
			__( 'Free disk space', 'adminkit' ),
			null === ( $s['disk_free'] ?? null ) ? __( 'Not measurable', 'adminkit' ) : self::bytes_text( (int) $s['disk_free'] ),
			self::disk_status( null === ( $s['disk_free'] ?? null ) ? null : (int) $s['disk_free'] ),
			__( 'How much room is left on the disk that holds this site.', 'adminkit' ),
			__( 'Free space or ask the host for more; a full disk stops uploads and database writes.', 'adminkit' )
		);
		$rows[] = self::row(
			'uploads',
			__( 'Uploads folder', 'adminkit' ),
			! empty( $s['uploads_writable'] ) ? __( 'Writable', 'adminkit' ) : __( 'Not writable', 'adminkit' ),
			self::uploads_status( ! empty( $s['uploads_writable'] ) ),
			__( 'WordPress can write files to wp-content/uploads.', 'adminkit' ),
			__( 'Fix the folder permissions on the host.', 'adminkit' )
		);
		$rows[] = self::row(
			'web',
			__( 'Web server', 'adminkit' ),
			'' !== (string) ( $s['web'] ?? '' ) ? (string) $s['web'] : __( 'Unknown', 'adminkit' ),
			'ok',
			__( 'The web server software reported by PHP.', 'adminkit' ),
			''
		);

		return $rows;
	}

	/**
	 * WP-Cron satırları (salt okuma).
	 *
	 * @param array<string, mixed> $snapshot Anlık görüntü.
	 * @return array<int, array<string, string>>
	 */
	public static function cron_rows( array $snapshot ): array {
		$c     = (array) ( $snapshot['cron'] ?? array() );
		$last  = isset( $c['last_run'] ) && null !== $c['last_run'] ? (float) $c['last_run'] : null;
		$late  = (int) ( $c['late'] ?? 0 );
		$delay = isset( $c['oldest_late'] ) && null !== $c['oldest_late'] ? (int) $c['oldest_late'] : null;
		$rows  = array();

		$rows[] = self::row(
			'cron_disabled',
			__( 'Disabled in wp-config', 'adminkit' ),
			! empty( $c['disabled'] ) ? __( 'Yes', 'adminkit' ) : __( 'No', 'adminkit' ),
			! empty( $c['disabled'] ) ? 'warn' : 'ok',
			__( 'When it is on, WordPress does not start cron jobs on page visits; a real server cron must call wp-cron.php.', 'adminkit' ),
			__( 'If this is on, make sure the server cron calls wp-cron.php regularly.', 'adminkit' )
		);
		$rows[] = self::row(
			'cron_last',
			__( 'Last run', 'adminkit' ),
			null === $last ? __( 'No run yet', 'adminkit' ) : sprintf( /* translators: %s: time since the last run */ __( '%s ago', 'adminkit' ), human_time_diff( (int) $last ) ),
			self::cron_status( ! empty( $c['disabled'] ), $last, $late, $delay ),
			__( 'The last time WP-Cron started a run on this site.', 'adminkit' ),
			__( 'No run yet means cron has not started; visit the site or check the server cron.', 'adminkit' )
		);
		$rows[] = self::row(
			'cron_events',
			__( 'Scheduled events', 'adminkit' ),
			number_format_i18n( (int) ( $c['events'] ?? 0 ) ),
			'ok',
			__( 'How many timed events are waiting in the cron list.', 'adminkit' ),
			''
		);
		$rows[] = self::row(
			'cron_late',
			__( 'Late events', 'adminkit' ),
			number_format_i18n( $late ) . ( null !== $delay && $late > 0 ? ' · ' . sprintf( /* translators: %s: how long the oldest late event has waited */ __( 'oldest %s late', 'adminkit' ), human_time_diff( time() - $delay ) ) : '' ),
			null !== $delay && $late > 0 ? self::cron_status( ! empty( $c['disabled'] ), $last, $late, $delay ) : 'ok',
			__( 'Events whose time has passed but that have not run yet.', 'adminkit' ),
			__( 'Late events mean WP-Cron is not running; check traffic or the server cron.', 'adminkit' )
		);

		return $rows;
	}

	/** @return array<string, string> */
	private static function row( string $key, string $label, string $value, string $status, string $means, string $todo ): array {
		return array(
			'key'    => $key,
			'label'  => $label,
			'value'  => $value,
			'status' => self::normalize_status( $status ),
			'means'  => $means,
			'todo'   => $todo,
		);
	}

	private static function normalize_status( string $status ): string {
		return in_array( $status, array( 'ok', 'warn', 'err' ), true ) ? $status : 'warn';
	}

	/* ------------------------------------------------------------------ ekran */

	/**
	 * Sağlık ekranı (modül kartının içinde). Modül KAPALIYKEN tek satır yönlendirme basar; hiçbir sorgu yoktur.
	 *
	 * @param string               $base     Ayar adı öneki (kullanılmıyor).
	 * @param array<string, mixed> $settings Modül ayarları.
	 */
	public function render_panel( string $base, array $settings ): void {
		unset( $base );
		if ( empty( $settings['enabled'] ) ) {
			echo Assets::note( 'info', $this->off_hint() ); // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::note() kaçışlar.
			return;
		}

		$snapshot = self::snapshot();
		if ( ! $snapshot ) {
			echo Assets::note( 'warn', __( 'The health data could not be read. Press Refresh and try again.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}

		$server = self::server_rows( $snapshot );
		$cron   = self::cron_rows( $snapshot );
		$db     = (array) ( $snapshot['db'] ?? array() );
		$since  = human_time_diff( (int) ( $snapshot['generated'] ?? time() ) );

		$autoload_status = self::autoload_status( (int) ( $db['autoload']['total_size'] ?? 0 ) );
		$attention       = array(
			'server' => self::attention_count( $server ),
			'db'     => 'ok' === self::normalize_status( $autoload_status ) ? 0 : 1,
			'cron'   => self::attention_count( $cron ),
		);
		$attention['overview'] = $attention['server'] + $attention['db'] + $attention['cron'];
		$tabs                  = array(
			'overview' => __( 'Overview', 'adminkit' ),
			'server'   => __( 'Server and PHP', 'adminkit' ),
			'db'       => __( 'Database', 'adminkit' ),
			'cron'     => __( 'WP-Cron', 'adminkit' ),
			'report'   => __( 'Support report', 'adminkit' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- salt okuma: yalnız beyaz listedeki sekme anahtarı.
		$sub = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : '';
		$sub = isset( $tabs[ $sub ] ) ? $sub : 'overview';
		?>
		<div class="ak-health" data-health>
			<ul class="rbn-tabs rbn-tabs--sub ak-health__tabs" role="tablist" aria-label="<?php esc_attr_e( 'System health sections', 'adminkit' ); ?>" data-rbn-tabs>
				<?php foreach ( $tabs as $key => $label ) : ?>
					<li role="presentation">
						<button type="button" role="tab" id="ak-health-tab-<?php echo esc_attr( $key ); ?>" class="rbn-tabs__tab" data-key="<?php echo esc_attr( $key ); ?>" aria-controls="ak-health-panel-<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $sub === $key ? 'true' : 'false'; ?>" tabindex="<?php echo $sub === $key ? '0' : '-1'; ?>">
							<?php echo esc_html( $label ); ?>
							<?php if ( ! empty( $attention[ $key ] ) ) : ?>
								<span class="rbn-count" aria-hidden="true"><?php echo esc_html( (string) $attention[ $key ] ); ?></span>
								<span class="rbn-sr"><?php echo esc_html( sprintf( /* translators: %d: number of checks that need attention */ _n( '%d check needs attention', '%d checks need attention', $attention[ $key ], 'adminkit' ), $attention[ $key ] ) ); ?></span>
							<?php endif; ?>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>

			<section class="ak-health__panel" id="ak-health-panel-overview" role="tabpanel" aria-labelledby="ak-health-tab-overview" tabindex="0">
			<?php echo Assets::note( 'info', __( 'Everything here is read-only and belongs to this site only: no external request is sent and nothing is deleted.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::note() kaçışlar. ?>
			<p class="rbn-help">
				<?php
				/* translators: %s: how long ago the data was read */
				printf( esc_html__( 'Read %s ago. Press Refresh to read it again.', 'adminkit' ), esc_html( $since ) );
				?>
			</p>
			<p>
				<?php
				if ( $attention['overview'] > 0 ) {
					echo '<span class="rbn-chip rbn-chip--warn">' . Assets::icon( 'alert' ) . esc_html( sprintf( /* translators: %d: number of checks that need attention */ _n( '%d check needs attention', '%d checks need attention', $attention['overview'], 'adminkit' ), $attention['overview'] ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::icon() kaçışlar.
				} else {
					echo '<span class="rbn-chip rbn-chip--ok">' . Assets::icon( 'check' ) . esc_html__( 'All checks are good', 'adminkit' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::icon() kaçışlar.
				}
				?>
			</p>

			<ul class="rbn-stats">
				<li class="rbn-stat">
					<p class="rbn-stat__k"><?php esc_html_e( 'PHP', 'adminkit' ); ?></p>
					<p class="rbn-stat__v"><?php echo esc_html( (string) ( $snapshot['server']['php'] ?? '' ) ); ?></p>
					<p class="rbn-stat__s"><?php echo self::chip( self::php_status( (string) ( $snapshot['server']['php'] ?? '0' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- chip() kaçışlar. ?></p>
				</li>
				<li class="rbn-stat">
					<p class="rbn-stat__k"><?php esc_html_e( 'Database size', 'adminkit' ); ?></p>
					<p class="rbn-stat__v"><?php echo esc_html( self::bytes_text( (int) ( $db['size']['total_size'] ?? 0 ) ) ); ?></p>
					<p class="rbn-stat__s"><?php echo esc_html( sprintf( /* translators: %d: table count */ __( '%d tables', 'adminkit' ), (int) ( $db['size']['table_count'] ?? 0 ) ) ); ?></p>
				</li>
				<li class="rbn-stat">
					<p class="rbn-stat__k"><?php esc_html_e( 'Autoload', 'adminkit' ); ?></p>
					<p class="rbn-stat__v"><?php echo esc_html( self::bytes_text( (int) ( $db['autoload']['total_size'] ?? 0 ) ) ); ?></p>
					<p class="rbn-stat__s"><?php echo self::chip( $autoload_status ); // phpcs:ignore WordPress.Security.EscapeOutput -- chip() kaçışlar. ?></p>
				</li>
				<li class="rbn-stat">
					<p class="rbn-stat__k">WP-Cron</p>
					<p class="rbn-stat__v"><?php echo self::chip( self::cron_status( ! empty( $snapshot['cron']['disabled'] ), isset( $snapshot['cron']['last_run'] ) && null !== $snapshot['cron']['last_run'] ? (float) $snapshot['cron']['last_run'] : null, (int) ( $snapshot['cron']['late'] ?? 0 ), isset( $snapshot['cron']['oldest_late'] ) && null !== $snapshot['cron']['oldest_late'] ? (int) $snapshot['cron']['oldest_late'] : null ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<p class="rbn-stat__s"><?php echo esc_html( sprintf( /* translators: %d: scheduled event count */ __( '%d events', 'adminkit' ), (int) ( $snapshot['cron']['events'] ?? 0 ) ) ); ?></p>
				</li>
			</ul>

			<div class="rbn-form-actions"><?php echo self::refresh_button( 'overview' ); // phpcs:ignore WordPress.Security.EscapeOutput -- refresh_button() kaçışlar. ?></div>
			</section>

			<section class="ak-health__panel" id="ak-health-panel-server" role="tabpanel" aria-labelledby="ak-health-tab-server" tabindex="0">
			<h3><?php esc_html_e( 'Server and PHP', 'adminkit' ); ?></h3>
			<?php self::rows_table( __( 'Server and PHP checks', 'adminkit' ), $server ); ?>
			</section>

			<section class="ak-health__panel" id="ak-health-panel-db" role="tabpanel" aria-labelledby="ak-health-tab-db" tabindex="0">
			<h3><?php esc_html_e( 'Database', 'adminkit' ); ?></h3>
			<p class="rbn-help"><?php esc_html_e( 'The database is read only here. Nothing is deleted on this screen; cleanup lives in the Database maintenance module.', 'adminkit' ); ?></p>
			<?php
			if ( self::db_maintenance_enabled() ) {
				echo '<p><a class="rbn-btn" href="' . esc_url( add_query_arg( 'tab', ( new DbMaintenance() )->id(), SettingsPage::url() ) ) . '">' . Assets::icon( 'broom' ) . esc_html__( 'Go to Database maintenance', 'adminkit' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::icon() kaçışlar.
			}
			self::db_section( $db );
			?>
			</section>

			<section class="ak-health__panel" id="ak-health-panel-cron" role="tabpanel" aria-labelledby="ak-health-tab-cron" tabindex="0">
			<h3><?php esc_html_e( 'WP-Cron status', 'adminkit' ); ?></h3>
			<p class="rbn-help"><?php esc_html_e( 'This screen never runs, lists or deletes cron events; it only reads the status.', 'adminkit' ); ?></p>
			<?php self::rows_table( __( 'WP-Cron checks', 'adminkit' ), $cron ); ?>
			</section>

			<section class="ak-health__panel" id="ak-health-panel-report" role="tabpanel" aria-labelledby="ak-health-tab-report" tabindex="0">
			<h3><?php esc_html_e( 'Support report', 'adminkit' ); ?></h3>
			<p class="rbn-help"><?php esc_html_e( 'The report contains versions, limits and plugin names only: no site address, user name, e-mail address, password, key or salt.', 'adminkit' ); ?></p>
			<label class="rbn-label" for="adminkit-health-report"><?php esc_html_e( 'Plain-text summary', 'adminkit' ); ?></label>
			<textarea class="rbn-textarea rbn-input--mono" id="adminkit-health-report" rows="14" readonly><?php echo esc_textarea( self::support_report( $snapshot ) ); ?></textarea>
			<div class="rbn-form-actions">
				<button type="button" class="rbn-btn rbn-btn--pri" data-health-copy><?php echo Assets::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput -- sabit SVG. ?><?php esc_html_e( 'Copy support report', 'adminkit' ); ?></button>
				<?php echo self::refresh_button( 'report' ); // phpcs:ignore WordPress.Security.EscapeOutput -- refresh_button() kaçışlar. ?>
			</div>
			<p class="rbn-help" data-health-status role="status" aria-live="polite"></p>
			</section>
		</div>
		<?php
	}

	/** Dikkat gerektiren (ok olmayan) denetim sayısı. @param array<int, array<string, string>> $rows Satırlar. */
	private static function attention_count( array $rows ): int {
		$n = 0;
		foreach ( $rows as $row ) {
			if ( 'ok' !== self::normalize_status( (string) ( $row['status'] ?? '' ) ) ) {
				++$n;
			}
		}
		return $n;
	}

	private static function db_maintenance_enabled(): bool {
		$settings = Settings::for_module( new DbMaintenance() );
		return ! empty( $settings['enabled'] );
	}

	/** Yenile bağlantısı; işlem bitince kullanıcı aynı iç sekmeye döner. */
	private static function refresh_button( string $sub ): string {
		return '<a class="rbn-btn" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_REFRESH . '&sub=' . $sub ), self::NONCE ) ) . '">' . Assets::icon( 'refresh' ) . esc_html__( 'Refresh', 'adminkit' ) . '</a>';
	}

	/** @param array<int, array<string, string>> $rows Satırlar. */
	private static function rows_table( string $caption, array $rows ): void {
		?>
		<div class="rbn-tw">
			<table class="rbn-table rbn-table--stack">
				<caption class="rbn-sr"><?php echo esc_html( $caption ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Check', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Value', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What it means', 'adminkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<th scope="row" data-label="<?php esc_attr_e( 'Check', 'adminkit' ); ?>"><?php echo esc_html( $row['label'] ); ?></th>
							<td data-label="<?php esc_attr_e( 'Value', 'adminkit' ); ?>"><?php echo esc_html( $row['value'] ); ?></td>
							<td data-label="<?php esc_attr_e( 'Status', 'adminkit' ); ?>"><?php echo self::chip( $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- chip() kaçışlar. ?></td>
							<td data-label="<?php esc_attr_e( 'What it means', 'adminkit' ); ?>">
								<div>
								<?php echo esc_html( $row['means'] ); ?>
								<span class="rbn-help">
									<?php
									if ( 'ok' === $row['status'] || '' === $row['todo'] ) {
										esc_html_e( 'Nothing to do.', 'adminkit' );
									} else {
										echo esc_html( __( 'What to do:', 'adminkit' ) . ' ' . $row['todo'] );
									}
									?>
								</span>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** @param array<string, mixed> $db DbStats::summary() çıktısı. */
	private static function db_section( array $db ): void {
		$counts = (array) ( $db['counts'] ?? array() );
		?>
		<h4><?php esc_html_e( 'Records waiting for cleanup (read only)', 'adminkit' ); ?></h4>
		<div class="rbn-tw">
			<table class="rbn-table rbn-table--stack">
				<caption class="rbn-sr"><?php esc_html_e( 'Records waiting for cleanup', 'adminkit' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Type', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Records', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size', 'adminkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( DbStats::types() as $type ) : ?>
						<?php $row = $counts[ $type ] ?? array( 'count' => 0, 'bytes' => 0 ); ?>
						<tr>
							<th scope="row" data-label="<?php esc_attr_e( 'Type', 'adminkit' ); ?>"><?php echo esc_html( DbMaintenance::type_label( $type ) ); ?></th>
							<td class="rbn-num" data-label="<?php esc_attr_e( 'Records', 'adminkit' ); ?>"><?php echo esc_html( number_format_i18n( (int) $row['count'] ) ); ?></td>
							<td class="rbn-num" data-label="<?php esc_attr_e( 'Size', 'adminkit' ); ?>"><?php echo esc_html( self::bytes_text( (int) $row['bytes'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<h4><?php esc_html_e( 'Largest tables', 'adminkit' ); ?></h4>
		<div class="rbn-tw">
			<table class="rbn-table rbn-table--stack">
				<caption class="rbn-sr"><?php esc_html_e( 'Largest tables', 'adminkit' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Table', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Rows', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Data', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Index', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Total', 'adminkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) ( $db['tables'] ?? array() ) as $table ) : ?>
						<tr>
							<th scope="row" data-label="<?php esc_attr_e( 'Table', 'adminkit' ); ?>"><?php echo esc_html( (string) ( $table['name'] ?? '' ) ); ?></th>
							<td class="rbn-num" data-label="<?php esc_attr_e( 'Rows', 'adminkit' ); ?>"><?php echo esc_html( number_format_i18n( (int) ( $table['num_rows'] ?? 0 ) ) ); ?></td>
							<td class="rbn-num" data-label="<?php esc_attr_e( 'Data', 'adminkit' ); ?>"><?php echo esc_html( self::bytes_text( (int) ( $table['data_size'] ?? 0 ) ) ); ?></td>
							<td class="rbn-num" data-label="<?php esc_attr_e( 'Index', 'adminkit' ); ?>"><?php echo esc_html( self::bytes_text( (int) ( $table['index_size'] ?? 0 ) ) ); ?></td>
							<td class="rbn-num" data-label="<?php esc_attr_e( 'Total', 'adminkit' ); ?>"><?php echo esc_html( self::bytes_text( (int) ( $table['total_size'] ?? 0 ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<h4><?php esc_html_e( 'Largest autoload options', 'adminkit' ); ?></h4>
		<p class="rbn-help"><?php esc_html_e( 'Autoload options are loaded on every request, so large ones slow the whole site. The warning threshold is 800 KB; above 1.5 MB every page load suffers.', 'adminkit' ); ?></p>
		<div class="rbn-tw">
			<table class="rbn-table rbn-table--stack">
				<caption class="rbn-sr"><?php esc_html_e( 'Largest autoload options', 'adminkit' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Option', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size', 'adminkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) ( $db['autoload']['largest'] ?? array() ) as $option ) : ?>
						<tr>
							<th scope="row" data-label="<?php esc_attr_e( 'Option', 'adminkit' ); ?>"><?php echo esc_html( (string) ( $option['name'] ?? '' ) ); ?></th>
							<td class="rbn-num" data-label="<?php esc_attr_e( 'Size', 'adminkit' ); ?>"><?php echo esc_html( self::bytes_text( (int) ( $option['size'] ?? 0 ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Durum çipi: renk + ikon + metin (WCAG: yalnız renk yok). */
	public static function chip( string $status ): string {
		$status = self::normalize_status( $status );
		$icons  = array(
			'ok'   => 'check',
			'warn' => 'alert',
			'err'  => 'x',
		);
		return '<span class="rbn-chip rbn-chip--' . esc_attr( $status ) . '">' . Assets::icon( $icons[ $status ] ) . esc_html( self::status_label( $status ) ) . '</span>';
	}

	public static function status_label( string $status ): string {
		switch ( self::normalize_status( $status ) ) {
			case 'ok':
				return __( 'Good', 'adminkit' );
			case 'err':
				return __( 'Problem', 'adminkit' );
		}
		return __( 'Needs attention', 'adminkit' );
	}

	/* ------------------------------------------------------------------ destek raporu */

	/**
	 * Düz metin destek raporu: site adresi, kullanıcı adı, e-posta, parola, anahtar ve tuz İÇERMEZ.
	 *
	 * @param array<string, mixed> $snapshot Anlık görüntü.
	 */
	public static function support_report( array $snapshot ): string {
		$s   = (array) ( $snapshot['server'] ?? array() );
		$c   = (array) ( $snapshot['cron'] ?? array() );
		$db  = (array) ( $snapshot['db'] ?? array() );
		$out = array();

		$out[] = 'RBN AdminKit — ' . __( 'Support report', 'adminkit' );
		$out[] = __( 'Generated', 'adminkit' ) . ': ' . wp_date( 'Y-m-d H:i', (int) ( $snapshot['generated'] ?? time() ) );
		$out[] = 'WordPress: ' . (string) ( $s['wordpress'] ?? '' ) . ' · PHP: ' . (string) ( $s['php'] ?? '' ) . ' · ' . __( 'Database', 'adminkit' ) . ': ' . (string) ( $s['database'] ?? '' );
		$out[] = __( 'Web server', 'adminkit' ) . ': ' . ( '' !== (string) ( $s['web'] ?? '' ) ? (string) $s['web'] : __( 'Unknown', 'adminkit' ) );
		$out[] = __( 'Memory', 'adminkit' ) . ': PHP ' . self::memory_text( (int) ( $s['memory'] ?? 0 ) ) . ' · WordPress ' . self::memory_text( (int) ( $s['wp_memory'] ?? 0 ) ) . ' · ' . __( 'Admin', 'adminkit' ) . ' ' . self::memory_text( (int) ( $s['wp_max_memory'] ?? 0 ) );
		$out[] = __( 'Upload', 'adminkit' ) . ': ' . self::bytes_text( (int) ( $s['upload'] ?? 0 ) ) . ' / ' . self::bytes_text( (int) ( $s['post'] ?? 0 ) ) . ' · ' . __( 'Maximum execution time', 'adminkit' ) . ': ' . self::seconds_text( (int) ( $s['execution'] ?? 0 ) ) . ' · ' . __( 'Maximum form fields', 'adminkit' ) . ': ' . number_format_i18n( (int) ( $s['input_vars'] ?? 0 ) );
		$out[] = 'HTTPS: ' . ( ! empty( $s['https'] ) ? __( 'Yes', 'adminkit' ) : __( 'No', 'adminkit' ) ) . ' · OPcache: ' . ( ! empty( $s['opcache'] ) ? __( 'On', 'adminkit' ) : __( 'Off', 'adminkit' ) ) . ' · ' . __( 'Persistent object cache', 'adminkit' ) . ': ' . ( ! empty( $s['object_cache'] ) ? __( 'On', 'adminkit' ) : __( 'Off', 'adminkit' ) );
		$missing = array_map( 'strval', (array) ( $s['missing'] ?? array() ) );
		$out[]   = __( 'Required PHP extensions', 'adminkit' ) . ': ' . ( $missing ? implode( ', ', $missing ) : __( 'All present', 'adminkit' ) );
		$out[]   = __( 'Database size', 'adminkit' ) . ': ' . self::bytes_text( (int) ( $db['size']['total_size'] ?? 0 ) ) . ' · ' . sprintf( /* translators: %d: table count */ __( '%d tables', 'adminkit' ), (int) ( $db['size']['table_count'] ?? 0 ) ) . ' · Autoload: ' . self::bytes_text( (int) ( $db['autoload']['total_size'] ?? 0 ) ) . ' (' . sprintf( /* translators: %d: option count */ __( '%d options', 'adminkit' ), (int) ( $db['autoload']['count'] ?? 0 ) ) . ') [' . self::status_label( self::autoload_status( (int) ( $db['autoload']['total_size'] ?? 0 ) ) ) . ']';

		$cleanup = array();
		$counts  = (array) ( $db['counts'] ?? array() );
		foreach ( DbStats::types() as $type ) {
			$cleanup[] = DbMaintenance::type_label( $type ) . ': ' . number_format_i18n( (int) ( $counts[ $type ]['count'] ?? 0 ) );
		}
		$out[] = __( 'Records waiting for cleanup', 'adminkit' ) . ': ' . implode( ' · ', $cleanup );

		$last = isset( $c['last_run'] ) && null !== $c['last_run'] ? (float) $c['last_run'] : null;
		$out[] = 'WP-Cron: ' . ( ! empty( $c['disabled'] ) ? __( 'disabled in wp-config', 'adminkit' ) : __( 'enabled', 'adminkit' ) )
			. ' · ' . __( 'Last run', 'adminkit' ) . ': ' . ( null === $last ? __( 'No run yet', 'adminkit' ) : sprintf( /* translators: %s: time since the last run */ __( '%s ago', 'adminkit' ), human_time_diff( (int) $last ) ) )
			. ' · ' . sprintf( /* translators: %d: scheduled event count */ __( '%d events', 'adminkit' ), (int) ( $c['events'] ?? 0 ) )
			. ' · ' . __( 'Late events', 'adminkit' ) . ': ' . number_format_i18n( (int) ( $c['late'] ?? 0 ) );

		$plugins = (array) ( $snapshot['plugins'] ?? array() );
		$plugins = array_map( array( self::class, 'scrub' ), array_map( 'strval', $plugins ) );
		$out[]   = __( 'Active plugins', 'adminkit' ) . ': ' . ( $plugins ? implode( '; ', $plugins ) : __( 'None', 'adminkit' ) );
		$theme   = (array) ( $snapshot['theme'] ?? array() );
		$out[]   = __( 'Active theme', 'adminkit' ) . ': ' . self::scrub( trim( (string) ( $theme['name'] ?? '' ) . ' ' . (string) ( $theme['version'] ?? '' ) ) );

		$warnings = array();
		foreach ( array_merge( self::server_rows( $snapshot ), self::cron_rows( $snapshot ) ) as $row ) {
			if ( 'ok' !== $row['status'] ) {
				$warnings[] = $row['label'] . ': ' . $row['value'] . ' [' . self::status_label( $row['status'] ) . ']';
			}
		}
		$out[] = __( 'Warnings', 'adminkit' ) . ' (' . count( $warnings ) . '): ' . ( $warnings ? implode( ' · ', $warnings ) : __( 'None', 'adminkit' ) );

		return implode( "\n", $out );
	}

	/**
	 * Eklenti/tema adı gibi dış kaynaklı metinlerden e-posta ve adres kalıntılarını gizler: rapor kopyalanıp
	 * paylaşılabilir, alan adı ve kişisel veri taşımaz.
	 */
	public static function scrub( string $text ): string {
		$text = (string) preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[hidden]', $text );
		$text = (string) preg_replace( '#\bhttps?://\S+#i', '[hidden]', $text );
		// Çıplak alan adı (sürüm numarası değil): son parça bilinen bir üst alan adı olmalı.
		$text = (string) preg_replace( '/\b(?:[A-Za-z0-9\-]+\.)+(?:com|net|org|edu|gov|io|dev|app|co|info|biz|xyz|shop|site|online|test|local|tr|uk|de)\b/i', '[hidden]', $text );
		return trim( $text );
	}

	/* ------------------------------------------------------------------ biçimlendirme */

	public static function to_bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( '-1' === $value ) {
			return -1;
		}
		$unit = strtolower( substr( $value, -1 ) );
		$num  = (float) $value;
		switch ( $unit ) {
			case 'g':
				$num *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$num *= 1024 * 1024;
				break;
			case 'k':
				$num *= 1024;
				break;
		}
		return (int) $num;
	}

	private static function memory_text( int $bytes ): string {
		return $bytes < 0 ? __( 'Unlimited', 'adminkit' ) : self::bytes_text( $bytes );
	}

	private static function bytes_text( int $bytes ): string {
		return size_format( max( 0, $bytes ), 1 );
	}

	private static function seconds_text( int $seconds ): string {
		return $seconds <= 0 ? __( 'Unlimited', 'adminkit' ) : sprintf( /* translators: %d: seconds */ __( '%d seconds', 'adminkit' ), $seconds );
	}

	private static function output_buffering_text( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || '0' === $value ) {
			return __( 'Off', 'adminkit' );
		}
		return __( 'On', 'adminkit' );
	}
}
