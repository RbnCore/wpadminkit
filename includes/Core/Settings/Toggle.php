<?php
/**
 * Instant switch server side: ONE boolean setting is written on its own, without the settings form.
 *
 * The patron's rule (31.10.2026): turning a kit switch asks for confirmation and then applies immediately — no
 * "Save" press. This class is the only writer for that path: it accepts a schema path of type `bool`/`flag`, cleans
 * and stores it through the engine's own PATCH route (so `on_save` side effects, the change log callback and the
 * `rbnkit_core_settings_saved` action behave exactly as a normal save), and never touches a neighbouring field.
 *
 * A kit whose schema requires a form id (`form_required`, CookieKit and SecurityKit today) refuses a PATCH write with
 * "the form was not recognised"; the engine's rule is not bypassed — the same field is then written in FORM mode with
 * every current value of that form posted back, so no other field of the form can be reset.
 *
 * Phase 2 (WPRJ-718): `ToggleEndpoint` is wired on `wp_ajax_adminkit_toggle`; screens mark their switches with the
 * attributes `Form::instant()` derives from `switchable()` — one rule for what may be switched from here.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Settings;

use AdminKit\Core\Log;
use AdminKit\Core\Settings as Engine;
use AdminKit\Registry;

defined( 'ABSPATH' ) || exit;

final class Toggle {

	/** Central log channel (`plugin.name`), registered lazily: Phase 1 does not touch the core boot file. */
	public const CHANNEL = 'adminkit.toggle';

	/** Event code of a stored row. */
	public const EVENT = 'toggle';

	/** Fields of these travel classes are never switchable from an instant request. */
	private const BLOCKED_TRAVEL = array( 'internal', 'secret', 'site_specific' );

	/**
	 * Applies one boolean value to one schema path.
	 *
	 * @param string $slug  Registered engine slug (`adminkit`, `wpseokit`, `securitykit`, `cookiekit`, `contenthealth`).
	 * @param string $path  Dotted schema path (`modules.toast_notices.enabled`, `headers.enabled`, …).
	 * @param bool   $value Wanted value.
	 * @return array{ok:bool,slug:string,path:string,value:bool,changed:bool,label:string,module?:array{id:string,enabled:bool,title:string}}|\WP_Error
	 */
	/**
	 * The schema row when a path is an instant-switchable boolean, else null.
	 *
	 * Screens use this to mark an input: the same rule that `apply()` enforces decides whether the switch may go
	 * instant, so a screen can pass every switch through it and only the valid ones light up.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function switchable( string $slug, string $path ): ?array {
		$checked = self::check( $slug, $path );
		return is_wp_error( $checked ) ? null : $checked['row'];
	}

	public static function apply( string $slug, string $path, bool $value ): array|\WP_Error {
		$checked = self::check( $slug, $path );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$slug = $checked['slug'];
		$row  = $checked['row'];

		// Permission: the kit schema states it at slug level; a field/section level never existed. `update()` checks
		// the schema's own capability again, so this gate can only be stricter, never weaker.
		$cap = (string) apply_filters( 'rbnkit_core_toggle_capability', 'manage_options', $slug, $path );
		if ( '' !== $cap && function_exists( 'current_user_can' ) && ! current_user_can( $cap ) ) {
			return self::error( 'rbnkit_toggle_forbidden', __( 'You are not allowed to change this setting.', 'adminkit' ), 403 );
		}

		$old = self::to_bool( $row, Engine::get( $slug, $path ) );
		if ( $old === $value ) {
			// Idempotent: nothing is written, no log row, no side effect.
			return self::result( $slug, $path, $row, $value, false );
		}

		$input  = self::put( array(), $path, self::encode( $row, $value ) );
		$result = Engine::update( $slug, $input );

		// A form-required schema refuses the PATCH write and writes nothing (`changed` stays false). Repeat it through
		// the engine's FORM route, posting the current value of every field of that form: no neighbour can be reset.
		if ( empty( $result['changed'] ) && ! empty( $row['forms'] ) ) {
			$form = (string) $row['forms'][0];
			$result = Engine::update( $slug, self::form_input( $slug, $form, $path, self::encode( $row, $value ) ), array( 'form' => $form ) );
		}

		$now = self::to_bool( $row, Engine::get( $slug, $path ) );
		if ( empty( $result['changed'] ) || $now !== $value ) {
			return self::error(
				'rbnkit_toggle_write_failed',
				__( 'The setting could not be changed.', 'adminkit' ),
				409,
				array(
					'slug' => $slug,
					'path' => $path,
				)
			);
		}

		self::log( $slug, $path, $old, $now );

		return self::result( $slug, $path, $row, $now, true );
	}

	/* ------------------------------------------------------------------ internals */

	/**
	 * Slug + path + schema row, or the precise error the request is refused with.
	 *
	 * @return array{slug:string,row:array<string,mixed>}|\WP_Error
	 */
	private static function check( string $slug, string $path ): array|\WP_Error {
		$slug = strtolower( trim( $slug ) );
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', $slug ) || ! Engine::registered( $slug ) ) {
			return self::error( 'rbnkit_toggle_unknown_slug', __( 'Unknown settings kit.', 'adminkit' ), 404 );
		}
		if ( ! self::path_shape( $path ) ) {
			return self::error( 'rbnkit_toggle_bad_path', __( 'The setting path is not valid.', 'adminkit' ), 400 );
		}
		$fields = Engine::fields( $slug );
		if ( ! isset( $fields[ $path ] ) ) {
			return self::error( 'rbnkit_toggle_unknown_field', __( 'Unknown setting.', 'adminkit' ), 400 );
		}
		$row = (array) $fields[ $path ];
		if ( ! in_array( (string) $row['type'], array( 'bool', 'flag' ), true ) ) {
			return self::error( 'rbnkit_toggle_not_switchable', __( 'This setting is not an on/off switch.', 'adminkit' ), 400 );
		}
		if ( in_array( Engine::travel( $row ), self::BLOCKED_TRAVEL, true ) ) {
			return self::error( 'rbnkit_toggle_not_switchable', __( 'This setting cannot be switched from here.', 'adminkit' ), 400 );
		}
		return array(
			'slug' => $slug,
			'row'  => $row,
		);
	}

	/** Path shape: lower-case dotted schema keys only, bounded. */
	private static function path_shape( string $path ): bool {
		return 1 === preg_match( '/^[a-z0-9_.]{1,120}$/', $path )
			&& '.' !== substr( $path, 0, 1 )
			&& '.' !== substr( $path, -1 )
			&& false === strpos( $path, '..' );
	}

	/** Stored value of a boolean row, as a real bool (a `flag` row stores 1/0). */
	private static function to_bool( array $row, $raw ): bool {
		return 'flag' === $row['type'] ? ( 0 !== (int) $raw ) : (bool) $raw;
	}

	/** Input value a boolean row expects (`flag` rows keep their 1/0 shape). */
	private static function encode( array $row, bool $value ) {
		return 'flag' === $row['type'] ? ( $value ? 1 : 0 ) : $value;
	}

	/**
	 * FORM-mode input: every field the form may write, at its current value; the target path carries the new value.
	 *
	 * The path set mirrors the engine's own `paths()` rule: internal fields never, a field of another form never, a
	 * field without a form list always.
	 *
	 * @return array<string, mixed>
	 */
	private static function form_input( string $slug, string $form, string $path, $value ): array {
		$input = array();
		$all   = Engine::all( $slug );
		foreach ( Engine::fields( $slug ) as $field_path => $row ) {
			$field_path = (string) $field_path;
			$row        = (array) $row;
			if ( ! empty( $row['internal'] ) ) {
				continue;
			}
			if ( ! empty( $row['forms'] ) && ! in_array( $form, (array) $row['forms'], true ) ) {
				continue;
			}
			$current = $field_path === $path ? $value : Engine::value_at( $all, $field_path, $row['default'] );
			$input   = self::put( $input, $field_path, $current );
		}
		return $input;
	}

	/** Writes one dotted path into a nested array copy. */
	private static function put( array $tree, string $path, $value ): array {
		$parts = explode( '.', $path );
		$ref   = &$tree;
		$last  = count( $parts ) - 1;
		foreach ( $parts as $i => $part ) {
			if ( $i === $last ) {
				$ref[ $part ] = $value;
				break;
			}
			if ( ! isset( $ref[ $part ] ) || ! is_array( $ref[ $part ] ) ) {
				$ref[ $part ] = array();
			}
			$ref = &$ref[ $part ];
		}
		unset( $ref );
		return $tree;
	}

	/**
	 * Response shape the screen consumes. `module` is filled for a module switch: AdminKit's own
	 * `modules.<id>.enabled` (chip/counter) and a kit's top-level `<id>.enabled` (SecurityKit, WPSeoKit).
	 */
	private static function result( string $slug, string $path, array $row, bool $value, bool $changed ): array {
		$module = self::module_info( $path, $value );
		$out    = array(
			'ok'      => true,
			'slug'    => $slug,
			'path'    => $path,
			'value'   => $value,
			'changed' => $changed,
			'label'   => null !== $module ? (string) $module['title'] : self::label( $row, $path ),
		);
		if ( null !== $module ) {
			$out['module'] = $module;
		}
		return $out;
	}

	/**
	 * @return array{id:string,enabled:bool,title:string}|null
	 */
	private static function module_info( string $path, bool $value ): ?array {
		if ( 1 === preg_match( '/^modules\.([a-z0-9_]+)\.enabled$/', $path, $m ) ) {
			$title = '';
			if ( class_exists( Registry::class ) ) {
				$modules = Registry::all();
				if ( isset( $modules[ $m[1] ] ) ) {
					$title = (string) $modules[ $m[1] ]->title();
				}
			}
			return array(
				'id'      => $m[1],
				'enabled' => $value,
				'title'   => '' !== $title ? $title : self::humanize( $m[1] ),
			);
		}
		if ( 1 === preg_match( '/^([a-z0-9_]+)\.enabled$/', $path, $m ) ) {
			return array(
				'id'      => $m[1],
				'enabled' => $value,
				'title'   => self::humanize( $m[1] ),
			);
		}
		return null;
	}

	/** Declared field label, else the readable last path segment. */
	private static function label( array $row, string $path ): string {
		$label = trim( (string) ( $row['label'] ?? '' ) );
		if ( '' !== $label ) {
			return $label;
		}
		$pos = strrpos( $path, '.' );
		return self::humanize( false === $pos ? $path : substr( $path, $pos + 1 ) );
	}

	private static function humanize( string $key ): string {
		$text = str_replace( '_', ' ', $key );
		return function_exists( 'mb_strtoupper' ) && function_exists( 'mb_substr' )
			? mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 )
			: ucfirst( $text );
	}

	/** One row per real change: kit + path + old/new booleans. No person, no secret, no free text. */
	private static function log( string $slug, string $path, bool $from, bool $to ): void {
		if ( ! isset( Log::channels()[ self::CHANNEL ] ) ) {
			Log::register_channel( 'adminkit', 'toggle', 90, 200 );
		}
		Log::log(
			self::CHANNEL,
			'info',
			self::EVENT,
			$slug . ' ' . $path,
			array(
				'kit'  => $slug,
				'path' => $path,
				'from' => $from,
				'to'   => $to,
			)
		);
	}

	private static function error( string $code, string $message, int $status, array $data = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
	}
}
