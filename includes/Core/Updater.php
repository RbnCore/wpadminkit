<?php
/**
 * Central updater (core API level 3): the ONE update module of every RBN plugin.
 *
 * A plugin registers itself with `Updater::register( slug, main file, version )`; the repository, name and description come
 * from the catalog row of the same slug (one source; read lazily, after `init`). The hooks below are added once, for all registered plugins:
 *
 * - The network call happens only inside WordPress' own update check (`update_plugins_github.com`); never on the front end.
 * - No token, account or site data is sent: an unauthenticated GET with the User-Agent `AdminKit/<version>`.
 * - A package is installed only after its SHA-256 digest and Ed25519 signature are verified (`Installer::check_package`,
 *   the same verification as the catalog install, with the one public key in `includes/update-pubkey.php`). This cannot be turned off.
 * - The central network setting (`Network::github_allowed( 'updates:<slug>' )`) switches every plugin off at once; then no request is sent.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core;

use AdminKit\Kit\Catalog;
use AdminKit\Kit\Download;
use AdminKit\Kit\Installer;

defined( 'ABSPATH' ) || exit;

final class Updater {

	public const GROUP       = 'adminkit.updates';
	public const CHANNEL     = 'adminkit.updates';
	public const OPT_CHECKED = 'adminkit_updates_checked';
	public const ACTION      = 'adminkit_update_check';
	public const NOTE_PARAM  = 'rbnkit_update';
	private const TTL_OK     = 43200; // 12 hours (GitHub: 60 unauthenticated requests per hour).
	private const TTL_ERR    = 3600;
	private const NOTES_LIMIT = 2000;

	/** @var array<string, array{file:string,version:string}> Registered plugins by slug. */
	private static array $plugins = array();

	/** Slug of the package that passed verification in this request; source selection compares against it. */
	private static ?string $verified = null;

	private static bool $hooked = false;

	/** Adds the hooks once. Called from `Core::boot()` in admin, cron and WP-CLI requests only; never on the front end. */
	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_filter( 'update_plugins_github.com', array( self::class, 'offer' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( self::class, 'pre_download' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( self::class, 'source_selection' ), 10, 4 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'action_check' ) );
	}

	/**
	 * Registers a plugin for central updates. Call it on `rbnkit_core_ready` (or directly when that already fired).
	 *
	 * The FIRST registration of a slug wins: a second plugin that claims the same slug is refused (the first owner
	 * stays), so an update can never be applied to another plugin's file.
	 *
	 * @param string $slug    Plugin folder = catalog id.
	 * @param string $file    Main plugin file (`__FILE__` of the main file).
	 * @param string $version Installed version (the plugin's version constant).
	 */
	public static function register( string $slug, string $file, string $version ): bool {
		// The catalog is NOT read here: its rows carry translated text and registration happens before `init`.
		if ( 1 !== preg_match( '/^[a-z0-9\-]{2,40}$/', $slug ) ) {
			return false;
		}
		if ( isset( self::$plugins[ $slug ] ) ) {
			Log::log(
				self::CHANNEL,
				'warning',
				'register_conflict',
				'Another plugin tried to register a slug that is already registered; the first owner was kept.',
				array(
					'plugin' => $slug,
					'owner'  => basename( (string) self::$plugins[ $slug ]['file'] ),
				)
			);
			return false;
		}
		self::$plugins[ $slug ] = array(
			'file'    => $file,
			'version' => $version,
		);
		return true;
	}

	/** @return string[] Registered slugs. */
	public static function registered(): array {
		return array_keys( self::$plugins );
	}

	/** Forgets all registrations (tests). */
	public static function reset(): void {
		self::$plugins  = array();
		self::$verified = null;
	}

	/** May this plugin's update check contact GitHub now? */
	public static function enabled( string $slug ): bool {
		return isset( self::$plugins[ $slug ] ) && Network::github_allowed( 'updates:' . $slug );
	}

	/** `adminkit/adminkit.php` for a registered slug. */
	private static function basename( string $slug ): string {
		return $slug . '/' . $slug . '.php';
	}

	private static function slug_of_basename( string $plugin_file ): ?string {
		foreach ( array_keys( self::$plugins ) as $slug ) {
			if ( self::basename( $slug ) === $plugin_file ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * The slug of an unpacked package, taken from its folder name.
	 *
	 * The installation path of WordPress (`Plugin_Upgrader::install`) has no `plugin` key in its hook arguments, so the
	 * folder name is the only hint. Only a REGISTERED plugin's slug counts: another plugin's installation is not touched.
	 *
	 * @param string $source Unpacked folder.
	 */
	private static function slug_of_source( string $source ): ?string {
		$dir  = rtrim( str_replace( '\\', '/', $source ), '/' );
		$name = basename( $dir );
		return isset( self::$plugins[ $name ] ) ? $name : null;
	}

	// ───────────────────────── Release information ─────────────────────────

	/**
	 * Latest release of a plugin. When the network setting is off, NO request is sent.
	 *
	 * @return array{status:string,release:?array<string,string>,time:int}|null Cache record (status: ok | none | error); null when off.
	 */
	public static function lookup( string $slug, bool $force = false ): ?array {
		$item = Catalog::find( $slug );
		if ( null === $item || ! self::enabled( $slug ) ) {
			return null;
		}
		if ( ! $force ) {
			$cached = Cache::get( self::GROUP, $slug );
			if ( is_array( $cached ) && isset( $cached['status'] ) ) {
				return $cached;
			}
		}
		$res    = wp_safe_remote_get(
			'https://api.github.com/repos/' . $item['repo'] . '/releases/latest',
			array(
				'timeout'             => 5,
				'redirection'         => 2,
				'limit_response_size' => Catalog::MAX_BODY,
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
				$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
				$rel  = Catalog::parse_release( $item, $data );
				if ( null !== $rel ) {
					$notes        = (string) ( $data['body'] ?? '' );
					$rel['notes'] = function_exists( 'mb_substr' ) ? mb_substr( $notes, 0, self::NOTES_LIMIT ) : substr( $notes, 0, self::NOTES_LIMIT );
					$rel['url']   = 'https://github.com/' . $item['repo'] . '/releases/tag/' . rawurlencode( (string) $data['tag_name'] );
					$status       = 'ok';
					$ttl          = self::TTL_OK;
				}
			}
		}
		$record = array(
			'status'  => $status,
			'release' => $rel,
			'time'    => time(),
		);
		Cache::set( self::GROUP, $slug, $record, $ttl );
		$checked          = get_option( self::OPT_CHECKED, array() );
		$checked          = is_array( $checked ) ? $checked : array();
		$checked[ $slug ] = $record['time'];
		update_option( self::OPT_CHECKED, $checked, false );
		$version = null !== $rel && isset( $rel['version'] ) ? (string) $rel['version'] : '';
		$found   = '' !== $version && version_compare( $version, self::$plugins[ $slug ]['version'], '>' );
		Log::log(
			self::CHANNEL,
			'error' === $status ? 'warning' : 'info',
			'update_check',
			'error' === $status ? 'failed' : ( $found ? 'update_found' : 'up_to_date' ),
			array(
				'plugin'  => $slug,
				'version' => $found ? $version : self::$plugins[ $slug ]['version'],
			)
		);
		return $record;
	}

	/** Newer release when there is one, otherwise null. @return array<string,string>|null */
	public static function newer( string $slug ): ?array {
		$rec = self::lookup( $slug );
		if ( null === $rec || 'ok' !== $rec['status'] || ! is_array( $rec['release'] ) ) {
			return null;
		}
		$version = isset( $rec['release']['version'] ) ? (string) $rec['release']['version'] : '';
		return '' !== $version && version_compare( $version, self::$plugins[ $slug ]['version'], '>' ) ? $rec['release'] : null;
	}

	/**
	 * What a settings screen shows for one plugin (no network call).
	 *
	 * @return array{enabled:bool,installed:string,state:string,available:?string,checked_at:int} state: off | unchecked | none | error | available | current.
	 */
	public static function status( string $slug ): array {
		$installed = self::$plugins[ $slug ]['version'] ?? '';
		$on        = self::enabled( $slug );
		$checked   = get_option( self::OPT_CHECKED, array() );
		$at        = is_array( $checked ) ? (int) ( $checked[ $slug ] ?? 0 ) : 0;
		$out       = array(
			'enabled'    => $on,
			'installed'  => $installed,
			'state'      => 'off',
			'available'  => null,
			'checked_at' => $at,
		);
		if ( ! $on ) {
			return $out;
		}
		$cached = Cache::get( self::GROUP, $slug );
		if ( ! is_array( $cached ) ) {
			$out['state'] = 'unchecked';
		} elseif ( 'none' === $cached['status'] ) {
			$out['state'] = 'none';
		} elseif ( 'ok' !== $cached['status'] || ! is_array( $cached['release'] ) ) {
			$out['state'] = 'error';
		} elseif ( version_compare( $cached['release']['version'], $installed, '>' ) ) {
			$out['state']     = 'available';
			$out['available'] = $cached['release']['version'];
		} else {
			$out['state'] = 'current';
		}
		return $out;
	}

	/** Human sentence for a `status()['state']` (escaped, may contain `<b>`). */
	public static function state_html( array $status ): string {
		switch ( $status['state'] ) {
			case 'off':
				return esc_html__( 'The update check is off; GitHub is never contacted.', 'adminkit' );
			case 'unchecked':
				return esc_html__( 'Not checked yet.', 'adminkit' );
			case 'none':
				return esc_html__( 'There is no published version yet.', 'adminkit' );
			case 'error':
				return esc_html__( 'Could not check; it will be tried again later. The plugin works normally.', 'adminkit' );
			case 'available':
				/* translators: %s: version number */
				return sprintf( esc_html__( 'A new version is available: %s. Click “Update” on the Plugins page.', 'adminkit' ), '<b>' . esc_html( (string) $status['available'] ) . '</b>' );
			default:
				return esc_html__( 'The plugin is up to date.', 'adminkit' );
		}
	}

	/**
	 * Self-contained "Check now" form for a plugin's settings screen (posts to admin-post.php; place it outside other forms).
	 *
	 * @param string $return_url Admin URL to come back to; the result is added as `rbnkit_update=checked|off`.
	 * @param string $button     Escaped button inner HTML (icon + label); empty = default label.
	 */
	public static function check_form( string $slug, string $return_url, string $button = '' ): string {
		if ( ! self::enabled( $slug ) ) {
			return '';
		}
		$button = '' !== $button ? $button : esc_html__( 'Check now', 'adminkit' );
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="slug" value="' . esc_attr( $slug ) . '">'
			. '<input type="hidden" name="return" value="' . esc_attr( $return_url ) . '">'
			. wp_nonce_field( self::ACTION, '_wpnonce', true, false )
			. '<button type="submit" class="rbn-btn">' . $button . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the caller passes escaped markup.
	}

	/** `?rbnkit_update=` result of the "Check now" redirect: checked | off | null. */
	public static function note(): ?string {
		$v = isset( $_GET[ self::NOTE_PARAM ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::NOTE_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		return in_array( $v, array( 'checked', 'off' ), true ) ? $v : null;
	}

	// ───────────────────────── WordPress hooks ─────────────────────────

	/**
	 * `update_plugins_github.com`: called during WordPress' own update check; WordPress compares versions.
	 *
	 * @param mixed                $update      Previous value.
	 * @param array<string,string> $plugin_data Plugin headers.
	 * @param string               $plugin_file Plugin file.
	 * @return mixed
	 */
	public static function offer( $update, $plugin_data, $plugin_file ) {
		$slug = is_string( $plugin_file ) ? self::slug_of_basename( $plugin_file ) : null;
		if ( null === $slug || ! self::enabled( $slug ) ) {
			return $update;
		}
		$item = Catalog::find( $slug );
		$rel  = self::newer( $slug );
		$meta = self::local_meta( $slug );
		// A release record comes from the cache and may be incomplete (a poisoned or half-written entry). Every field is
		// read with a fallback, so a missing key never produces a PHP warning and never reaches WordPress.
		$own = 'https://github.com/' . $item['repo'];
		return array_merge(
			array(
				'id'           => $own,
				'slug'         => $slug,
				'version'      => isset( $rel['version'] ) ? (string) $rel['version'] : self::$plugins[ $slug ]['version'],
				'url'          => isset( $rel['url'] ) ? (string) $rel['url'] : $own,
				'package'      => self::safe_package( $slug, $rel ),
				'icons'        => array(),
				'requires'     => $meta['requires'],
				'requires_php' => $meta['requires_php'],
			),
			'' !== $meta['tested'] ? array( 'tested' => $meta['tested'] ) : array()
		);
	}

	/**
	 * The package address of an offer. A poisoned cache must not hand a foreign address to WordPress: the address has
	 * to be the one release address of THIS plugin's own repository.
	 *
	 * @param array<string,string>|null $rel Release record (may come from the cache).
	 * @return string Package address, or an empty string when it is not ours.
	 */
	private static function safe_package( string $slug, ?array $rel ): string {
		if ( null === $rel || '' === (string) ( $rel['package'] ?? '' ) ) {
			return '';
		}
		$item = Catalog::find( $slug );
		if ( null === $item ) {
			return '';
		}
		$package = (string) $rel['package'];
		$prefix  = Catalog::PKG_BASE . Catalog::repo_name( $item ) . '/releases/download/';
		if ( 0 !== strpos( $package, $prefix ) ) {
			return '';
		}
		$version = (string) ( $rel['version'] ?? '' );
		if ( 1 !== preg_match( '/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $version ) ) {
			return '';
		}
		return $package === $prefix . $version . '/' . $slug . '-' . $version . '.zip' ? $package : '';
	}

	/**
	 * Is this the ONE release address of this plugin's own repository?
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $package Package address.
	 */
	private static function is_own_package( string $slug, string $package ): bool {
		$item = Catalog::find( $slug );
		if ( null === $item ) {
			return false;
		}
		$prefix = Catalog::PKG_BASE . Catalog::repo_name( $item ) . '/releases/download/';
		return 1 === preg_match( '#^' . preg_quote( $prefix, '#' ) . '(\d{1,4}\.\d{1,4}\.\d{1,4})/' . preg_quote( $slug, '#' ) . '-\1\.zip$#', $package );
	}

	/**
	 * The slug a package address names, when it names one of OUR plugins.
	 *
	 * The file name of a package carries its slug (`<slug>-<version>.zip`). A package that names a plugin we manage
	 * has to come from our own repository; a package of a plugin we do NOT manage is not ours and is left alone.
	 *
	 * @param string $package Package address.
	 */
	private static function slug_of_package_name( string $package ): ?string {
		$path = (string) wp_parse_url( $package, PHP_URL_PATH );
		$name = basename( $path );
		if ( 1 !== preg_match( '/^([a-z0-9\-]{2,40})-\d{1,4}\.\d{1,4}\.\d{1,4}\.zip$/', $name, $m ) ) {
			return null;
		}
		return isset( self::$plugins[ $m[1] ] ) ? $m[1] : null;
	}

	/** @return array{requires:string,requires_php:string,tested:string} */
	private static function local_meta( string $slug ): array {
		$file   = self::$plugins[ $slug ]['file'];
		$h      = get_file_data( $file, array( 'r' => 'Requires at least', 'p' => 'Requires PHP' ) );
		$readme = dirname( $file ) . '/readme.txt';
		$text   = is_readable( $readme ) ? (string) file_get_contents( $readme ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$tested = 1 === preg_match( '/^Tested up to:\s*([0-9.]+)/mi', $text, $m ) ? $m[1] : '';
		return array(
			'requires'     => $h['r'],
			'requires_php' => $h['p'],
			'tested'       => $tested,
		);
	}

	/**
	 * "View details" window. No network call; only the cached release is used.
	 *
	 * @param mixed $result Previous result.
	 * @param mixed $action Request type.
	 * @param mixed $args   Request arguments.
	 * @return mixed
	 */
	public static function info( $result, $action, $args ) {
		$slug = ( 'plugin_information' === $action && is_object( $args ) && is_string( $args->slug ?? null ) ) ? (string) $args->slug : '';
		if ( '' === $slug || ! isset( self::$plugins[ $slug ] ) || ! self::enabled( $slug ) ) {
			return $result;
		}
		$item   = Catalog::find( $slug );
		$cached = Cache::get( self::GROUP, $slug );
		$rel    = ( is_array( $cached ) && 'ok' === ( $cached['status'] ?? '' ) && is_array( $cached['release'] ?? null ) ) ? $cached['release'] : null;
		// The release record comes from the cache and may be incomplete: every field is read with a fallback, so a
		// missing key never produces a PHP warning and never reaches WordPress.
		$version = isset( $rel['version'] ) ? (string) $rel['version'] : '';
		$new     = '' !== $version && version_compare( $version, self::$plugins[ $slug ]['version'], '>' );
		$meta   = self::local_meta( $slug );
		$notes  = $rel ? (string) ( $rel['notes'] ?? '' ) : '';
		return (object) array(
			'name'          => $item['name'],
			'slug'          => $slug,
			'version'       => $new ? $version : self::$plugins[ $slug ]['version'],
			'author'        => '<a href="https://github.com/RbnCore">RBN Bilişim</a>',
			'homepage'      => 'https://github.com/' . $item['repo'],
			'requires'      => $meta['requires'],
			'requires_php'  => $meta['requires_php'],
			'tested'        => $meta['tested'],
			'download_link' => $new ? self::safe_package( $slug, $rel ) : '',
			'sections'      => array(
				'description' => esc_html( $item['tagline'] ),
				'changelog'   => '' !== $notes ? wp_kses_post( nl2br( esc_html( $notes ) ) ) : esc_html__( 'There are no release notes for this version.', 'adminkit' ),
			),
		);
	}

	/**
	 * Downloads and verifies the package; returns the temp file path when valid (WordPress unpacks it).
	 * Only addresses of a REGISTERED plugin's own repository are handled; everything else passes untouched.
	 *
	 * The gates, in this order, BEFORE any request leaves the site:
	 *
	 * 1. the central network setting (`Network::github_allowed( 'updates:SLUG' )`) — when it is off, NOT ONE request
	 *    is sent, not even the digest or the signature file;
	 * 2. WordPress' own file-modification switch (`DISALLOW_FILE_MODS`) — when file changes are closed, nothing is
	 *    downloaded;
	 * 3. the downgrade guard — a package that is not NEWER than the installed version is refused.
	 *
	 * @param mixed  $reply   Previous reply.
	 * @param string $package Package URL.
	 * @return mixed
	 */
	public static function pre_download( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) || ! self::$plugins || Installer::installing() ) {
			return $reply;
		}
		// A package that names one of OUR plugins has to come from our own repository, with our own version. A
		// foreign address for such a package is refused here (not passed on); the package of a plugin we do NOT
		// manage is left to WordPress.
		$named = self::slug_of_package_name( $package );
		if ( null !== $named && ! self::is_own_package( $named, $package ) ) {
			$error = new \WP_Error(
				'adminkit_update_foreign_package',
				__( 'Update rejected: the package address does not belong to this plugin\'s own release (repository or version). The old version was kept.', 'adminkit' )
			);
			self::log_rejected( $named, '', $error );
			return $error;
		}
		if ( 0 !== strpos( $package, Catalog::PKG_BASE ) ) {
			return $reply;
		}
		if ( 1 !== preg_match( '#^' . preg_quote( Catalog::PKG_BASE, '#' ) . '([A-Za-z0-9\-]{2,42})/releases/download/(\d{1,4}\.\d{1,4}\.\d{1,4})/([a-z0-9\-]{2,40})-\2\.zip$#', $package, $m ) ) {
			return $reply;
		}
		$slug = $m[3];
		$item = isset( self::$plugins[ $slug ] ) ? Catalog::find( $slug ) : null;
		if ( null === $item || Catalog::repo_name( $item ) !== $m[1] ) {
			return $reply; // Not one of ours (or the catalog installer handles it).
		}
		// Gate 1 — the central network setting. Off means off: no request at all, and the reason goes to the log.
		if ( ! self::enabled( $slug ) ) {
			Log::log(
				self::CHANNEL,
				'warning',
				'update_offline',
				__( 'Update stopped: the GitHub connection is turned off in the settings. The old version was kept.', 'adminkit' ),
				array(
					'plugin' => $slug,
				)
			);
			return new \WP_Error( 'adminkit_update_offline', __( 'Update stopped: the GitHub connection is turned off in the settings. The old version was kept.', 'adminkit' ) );
		}
		// Gate 2 — WordPress' own file-modification switch (the same check the catalog installer uses).
		if ( ! Installer::file_mods_allowed( 'adminkit_update' ) ) {
			Log::log(
				self::CHANNEL,
				'warning',
				'update_file_mods',
				__( 'Update stopped: WordPress file changes are turned off on this site (DISALLOW_FILE_MODS). The old version was kept.', 'adminkit' ),
				array(
					'plugin' => $slug,
				)
			);
			return new \WP_Error( 'adminkit_update_file_mods', __( 'Update stopped: WordPress file changes are turned off on this site (DISALLOW_FILE_MODS). The old version was kept.', 'adminkit' ) );
		}
		self::$verified = null;
		$version        = $m[2];
		// Gate 3 — the downgrade guard: only a NEWER version may be installed.
		if ( Installer::is_downgrade( self::$plugins[ $slug ]['version'], $version ) ) {
			Log::log(
				self::CHANNEL,
				'warning',
				'update_not_newer',
				__( 'Update stopped: the released version is not newer than the installed one. The old version was kept.', 'adminkit' ),
				array(
					'plugin'  => $slug,
					'version' => $version,
				)
			);
			return new \WP_Error( 'adminkit_update_not_newer', __( 'Update stopped: the released version is not newer than the installed one. The old version was kept.', 'adminkit' ) );
		}
		$sha = self::fetch_text( $package . '.sha256' );
		$sig = is_wp_error( $sha ) ? $sha : self::fetch_text( $package . '.sig' );
		if ( is_wp_error( $sig ) ) {
			self::log_rejected( $slug, $version, $sig );
			return $sig;
		}
		// The package download carries the size cap of the installer (`Download::package`); the digest and the
		// signature are small text files with their own cap.
		$tmp = Download::package( $package, 300 );
		if ( is_wp_error( $tmp ) ) {
			self::log_rejected( $slug, $version, $tmp );
			return $tmp;
		}
		$ok = Installer::check_package( $slug, $tmp, $version, $sha, $sig );
		if ( is_wp_error( $ok ) ) {
			wp_delete_file( $tmp );
			self::log_rejected( $slug, $version, $ok );
			return $ok;
		}
		self::$verified = $slug . '|' . $version;
		return $tmp;
	}

	/**
	 * Writes a refused update to the log. Every refusal has to be visible in the log screen: a package that was
	 * rejected (bad signature, wrong version, size overrun, unreadable digest) is otherwise a silent event.
	 *
	 * @param string         $slug    Plugin slug.
	 * @param string         $version Released version.
	 * @param \WP_Error|mixed $error   The refusal.
	 */
	private static function log_rejected( string $slug, string $version, $error ): void {
		$code    = is_wp_error( $error ) ? $error->get_error_code() : 'unknown';
		$message = is_wp_error( $error ) ? (string) $error->get_error_message() : __( 'Update rejected: the package was not installed. The old version was kept.', 'adminkit' );
		Log::log(
			self::CHANNEL,
			'warning',
			'update_rejected',
			$message,
			array(
				'plugin'  => $slug,
				'reason'  => (string) $code,
				'version' => $version,
			)
		);
	}

	/** Downloads a small text asset (digest / signature). @return string|\WP_Error */
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
			return new \WP_Error( 'adminkit_update_fetch_fail', __( 'Update stopped: the digest or signature file could not be downloaded. The old version was kept.', 'adminkit' ) );
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/**
	 * Unpacked package: root folder and main file check. Runs only for a registered plugin's update.
	 *
	 * The verification is not optional on ANY path: a package whose folder is one of OUR plugins must have passed
	 * the signature verification in `pre_download()`. The installation path of WordPress
	 * (`Plugin_Upgrader::install()`) has no plugin key in its hook arguments, so the folder name is the hint there.
	 * A plugin we do not manage (a slug we did not register) is not touched — WordPress' own flow stays as it is.
	 *
	 * @param mixed               $source        Unpacked folder.
	 * @param string              $remote_source Unpack location.
	 * @param mixed               $upgrader      Upgrader.
	 * @param array<string,mixed> $hook_extra    Extra info.
	 * @return mixed
	 */
	public static function source_selection( $source, $remote_source = '', $upgrader = null, $hook_extra = array() ) {
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		// The catalog installer verifies the package itself (the same rules, one implementation); it must not be
		// blocked here.
		if ( Installer::installing() ) {
			return $source;
		}
		$plugin = is_array( $hook_extra ) && is_string( $hook_extra['plugin'] ?? null ) ? $hook_extra['plugin'] : '';
		$slug   = '' !== $plugin ? self::slug_of_basename( $plugin ) : null;
		if ( null === $slug ) {
			$slug = self::slug_of_source( (string) $source );
		}
		if ( null === $slug ) {
			return $source; // Not one of ours: WordPress' own flow.
		}
		$verified       = self::$verified;
		self::$verified = null;
		if ( null === $verified || 0 !== strpos( $verified, $slug . '|' ) ) {
			$error = new \WP_Error( 'adminkit_update_unverified', __( 'Update rejected: the package did not pass the signature verification. The old version was kept.', 'adminkit' ) );
			self::log_rejected( $slug, '', $error );
			return $error;
		}
		$ok = Installer::check_source( $slug, (string) $source, substr( $verified, strlen( $slug ) + 1 ) );
		if ( is_wp_error( $ok ) ) {
			self::log_rejected( $slug, substr( $verified, strlen( $slug ) + 1 ), $ok );
			return $ok;
		}
		return $source;
	}

	// ───────────────────────── "Check now" ─────────────────────────

	public static function action_check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'adminkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$slug   = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( (string) $_POST['slug'] ) ) : '';
		$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( (string) $_POST['return'] ) ) : '';
		$to     = '' !== $return ? $return : admin_url( 'plugins.php' );
		if ( ! isset( self::$plugins[ $slug ] ) || ! self::enabled( $slug ) ) {
			\AdminKit\Helpers\Toast::flash( 'info', __( 'The update check is off; nothing was checked.', 'adminkit' ) );
			wp_safe_redirect( $to );
			exit;
		}
		Cache::delete( self::GROUP, $slug );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		\AdminKit\Helpers\Toast::flash( 'success', __( 'Update check finished.', 'adminkit' ) );
		wp_safe_redirect( $to );
		exit;
	}
}
