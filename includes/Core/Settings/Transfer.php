<?php
/**
 * Settings transfer: one JSON file carries the settings of every RBN kit to another site (`docs/ayar-tasima.md`).
 *
 * There is NO automatic channel between sites — an admin downloads the file here and uploads it there, by hand.
 * This class only reads (`document()`), validates (`decode()`, `plan()`) and writes (`apply()`) the file; it prints
 * nothing and knows no screen. Which field may travel is the schema's answer (`Settings::travel()`), never this
 * class's: an unknown key is dropped, a `site_specific`/`secret` field is never written, and every value is cleaned
 * by the engine of its own kit.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Settings;

use AdminKit\Core\Settings;
use AdminKit\Kit\Catalog;

defined( 'ABSPATH' ) || exit;

final class Transfer {

	/** Marker of a settings file; a JSON file without it is not ours. */
	public const FORMAT = 'rbnkit-settings';

	/** File format version. A newer number is refused, not guessed. */
	public const VERSION = 1;

	/** Upload and download cap (1 MB). A real settings file is a few dozen KB. */
	public const MAX_BYTES = 1048576;

	/** `json_decode()` depth: the engine schema stops at 8, so a deeper file is not a settings file. */
	public const DEPTH = 16;

	/** Plugin version constants (the file records which version the settings came from). */
	private const VERSIONS = array(
		'adminkit'      => 'ADMINKIT_VERSION',
		'contenthealth' => 'CONTENTHEALTH_VERSION',
		'cookiekit'     => 'COOKIEKIT_VERSION',
		'wpseokit'      => 'WPSEOKIT_VERSION',
		'securitykit'   => 'SECURITYKIT_VERSION',
	);

	/**
	 * Kits that can travel: the catalog order, limited to the schemas registered in this request.
	 *
	 * @return array<string, string> slug => label
	 */
	public static function kits(): array {
		$out = array();
		foreach ( Catalog::items() as $item ) {
			$slug = (string) $item['settings_slug'];
			if ( Settings::registered( $slug ) ) {
				$out[ $slug ] = (string) $item['name'];
			}
		}
		// A registered kit that is not (yet) in the catalog still travels; its slug is its label.
		foreach ( Settings::slugs() as $slug ) {
			if ( ! isset( $out[ $slug ] ) ) {
				$out[ $slug ] = $slug;
			}
		}
		return $out;
	}

	/** The label of one kit (slug when the catalog does not know it). */
	public static function label( string $slug ): string {
		$kits = self::kits();
		return (string) ( $kits[ $slug ] ?? $slug );
	}

	/** The running plugin version of one kit ('' when unknown). */
	public static function version_of( string $slug ): string {
		$const = (string) ( self::VERSIONS[ $slug ] ?? '' );
		return '' !== $const && defined( $const ) ? (string) constant( $const ) : '';
	}

	/**
	 * The document an export writes. Only `portable` fields; `personal` ones when the user asked for them.
	 *
	 * @param string[] $slugs            Kits to include (a registered slug only).
	 * @param bool     $include_personal Include e-mail recipients and similar personal fields.
	 * @return array<string,mixed>
	 */
	public static function document( array $slugs, bool $include_personal = false ): array {
		$kits = array();
		foreach ( $slugs as $slug ) {
			$slug = (string) $slug;
			if ( ! Settings::registered( $slug ) ) {
				continue;
			}
			$kits[ $slug ] = array(
				'plugin_version' => self::version_of( $slug ),
				'settings'       => Settings::export_values( $slug, $include_personal ),
			);
		}
		$doc = array(
			'format'   => self::FORMAT,
			'version'  => self::VERSION,
			'exported' => gmdate( 'c' ),
			'kits'     => $kits,
			// Counts only (no field names and no values): what stayed behind, and why.
			'omitted'  => self::omitted( array_keys( $kits ), $include_personal ),
		);
		return $doc;
	}

	/**
	 * How many fields of each kit were left out, by reason. Paths and values stay on this site.
	 *
	 * @param string[] $slugs            Kits.
	 * @param bool     $include_personal Whether personal fields travelled.
	 * @return array<string, array<string,int>>
	 */
	private static function omitted( array $slugs, bool $include_personal ): array {
		$out = array();
		foreach ( $slugs as $slug ) {
			$counts = array();
			foreach ( Settings::fields( (string) $slug ) as $row ) {
				$travel = Settings::travel( (array) $row );
				if ( 'portable' === $travel || ( $include_personal && 'personal' === $travel ) ) {
					continue;
				}
				$counts[ $travel ] = ( $counts[ $travel ] ?? 0 ) + 1;
			}
			if ( $counts ) {
				ksort( $counts );
				$out[ (string) $slug ] = $counts;
			}
		}
		return $out;
	}

	/** The JSON text of a document (pretty printed, one file a human can read). */
	public static function json( array $doc ): string {
		return (string) wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}

	/** Download file name: date, never the site name. */
	public static function filename( string $date = '' ): string {
		$date = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : gmdate( 'Y-m-d' );
		return 'rbnkit-settings-' . $date . '.json';
	}

	/**
	 * Reads a file: size, encoding, JSON shape and the format/version gate. Nothing is written.
	 *
	 * A serialized PHP payload, an object (never a `unserialize()` target), invalid UTF-8, a broken JSON body, a
	 * deeper-than-schema tree and a newer format version are all refused here, with a message a human can act on.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function decode( string $raw ) {
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new \WP_Error( 'rbnkit_transfer_size', __( 'The file is larger than 1 MB; a settings file should be a few dozen KB.', 'adminkit' ) );
		}
		// A byte-order mark is not part of the JSON body.
		if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return new \WP_Error( 'rbnkit_transfer_empty', __( 'The file is empty.', 'adminkit' ) );
		}
		// Never unserialize: a PHP-serialized body is refused by its first bytes, before any parser sees it.
		if ( 1 === preg_match( '/^(?:a|O|s|C|i|d|b|N):/', $raw ) || 1 === preg_match( '/^(?:a|O|s|C):\d+:/', $raw ) ) {
			return new \WP_Error( 'rbnkit_transfer_serialized', __( 'The file is a PHP serialized value, not a settings file. Upload the JSON file the export produced.', 'adminkit' ) );
		}
		if ( 1 !== preg_match( '//u', $raw ) ) {
			return new \WP_Error( 'rbnkit_transfer_utf8', __( 'The file is not valid UTF-8.', 'adminkit' ) );
		}
		try {
			$data = json_decode( $raw, true, self::DEPTH, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return new \WP_Error( 'rbnkit_transfer_json', __( 'The file is not valid JSON (or it nests deeper than a settings file can).', 'adminkit' ) );
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'rbnkit_transfer_shape', __( 'The file is not a settings file.', 'adminkit' ) );
		}
		if ( self::FORMAT !== ( $data['format'] ?? null ) ) {
			return new \WP_Error( 'rbnkit_transfer_format', __( 'This is not an RBN Kit settings file.', 'adminkit' ) );
		}
		$version = $data['version'] ?? null;
		if ( ! is_int( $version ) || $version < 1 ) {
			return new \WP_Error( 'rbnkit_transfer_version', __( 'The file has no usable format version.', 'adminkit' ) );
		}
		if ( $version > self::VERSION ) {
			return new \WP_Error( 'rbnkit_transfer_version_new', __( 'The file comes from a newer AdminKit; update AdminKit first.', 'adminkit' ) );
		}
		if ( ! is_array( $data['kits'] ?? null ) ) {
			return new \WP_Error( 'rbnkit_transfer_kits', __( 'The file has no settings in it.', 'adminkit' ) );
		}
		foreach ( $data['kits'] as $slug => $kit ) {
			if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', (string) $slug ) || ! is_array( $kit ) ) {
				return new \WP_Error( 'rbnkit_transfer_kits', __( 'The file is damaged: one of its kits is not readable.', 'adminkit' ) );
			}
			if ( ! is_array( $kit['settings'] ?? null ) ) {
				return new \WP_Error( 'rbnkit_transfer_kits', __( 'The file is damaged: one of its kits has no settings.', 'adminkit' ) );
			}
		}
		return $data;
	}

	/**
	 * The dry run: what every kit would become. Nothing is written; the plan is what the confirm screen shows and
	 * what `apply()` writes. A kit whose field cleaning reported an error blocks the whole plan (atomicity).
	 *
	 * @param array<string,mixed> $doc              File read by `decode()`.
	 * @param bool                $include_personal Apply e-mail recipients and similar personal fields.
	 * @param bool                $include_risky    Apply lock-out-risk fields (login limit, proxy trust, whitelist).
	 * @return array<string,mixed>
	 */
	public static function plan( array $doc, bool $include_personal = false, bool $include_risky = false ): array {
		$out = array(
			'include_personal' => $include_personal,
			'include_risky'    => $include_risky,
			'exported'         => (string) ( $doc['exported'] ?? '' ),
			'kits'             => array(),
			'skipped_kits'     => array(),
			'fields'           => 0,
		);
		foreach ( (array) $doc['kits'] as $slug => $kit ) {
			$slug = (string) $slug;
			if ( ! Settings::registered( $slug ) ) {
				// The plugin is not active here: its settings cannot be checked, so nothing of it is touched.
				$out['skipped_kits'][ $slug ] = 'not_active';
				continue;
			}
			$res    = Settings::import_preview( $slug, (array) ( $kit['settings'] ?? array() ), $include_personal, $include_risky );
			$file_v = (string) ( $kit['plugin_version'] ?? '' );
			$out['kits'][ $slug ] = array(
				'label'          => self::label( $slug ),
				'plugin_version' => $file_v,
				'version_note'   => '' !== $file_v && $file_v !== self::version_of( $slug ) ? self::version_of( $slug ) : '',
				'settings'       => $res['settings'],
				'changes'        => $res['changes'],
				'skipped'        => $res['skipped'],
				'errors'         => $res['errors'],
			);
			$out['fields'] += count( $res['changes'] );
		}
		return $out;
	}

	/**
	 * Writes a plan in ONE operation: every kit was validated before the first write; if a write fails, the kits
	 * already written are put back and nothing has changed.
	 *
	 * @param array<string,mixed> $plan Result of `plan()`.
	 * @return array<string,int>|\WP_Error Fields written per kit.
	 */
	public static function apply( array $plan ) {
		$targets = array();
		foreach ( (array) ( $plan['kits'] ?? array() ) as $slug => $kit ) {
			$slug = (string) $slug;
			if ( ! empty( $kit['errors'] ) ) {
				return new \WP_Error( 'rbnkit_transfer_invalid', __( 'The file has a value that this kit would reject; nothing was changed.', 'adminkit' ) );
			}
			if ( empty( $kit['changes'] ) ) {
				continue;
			}
			$targets[ $slug ] = (array) $kit['settings'];
		}
		if ( ! $targets ) {
			return array();
		}
		$before = array();
		foreach ( array_keys( $targets ) as $slug ) {
			$before[ $slug ] = Settings::all( $slug );
		}
		$written = array();
		try {
			foreach ( $targets as $slug => $settings ) {
				if ( ! Settings::import_write( $slug, $settings ) ) {
					throw new \RuntimeException( 'import write refused' );
				}
				$written[ $slug ] = 1;
			}
		} catch ( \Throwable $e ) {
			foreach ( array_keys( $written ) as $slug ) {
				Settings::import_write( $slug, (array) $before[ $slug ] );
			}
			return new \WP_Error( 'rbnkit_transfer_write', __( 'A kit could not be written; the settings that had been written were put back. Nothing was changed.', 'adminkit' ) );
		}
		$counts = array();
		foreach ( array_keys( $written ) as $slug ) {
			$counts[ $slug ] = count( (array) $plan['kits'][ $slug ]['changes'] );
		}
		return $counts;
	}
}
