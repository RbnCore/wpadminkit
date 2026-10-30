<?php
/**
 * Central settings engine: ONE typed schema per plugin, cleaned and stored in ONE option row.
 *
 * A plugin declares its schema (field path, type, default, limits, permission form) and nothing else: storing,
 * reading, per-type cleaning, partial-update rules, default merging, reset and the change log all live here.
 * The option name and the stored shape of every plugin stay as they are — the engine reads the data that is
 * already in the database; there is no migration and no compatibility bridge.
 *
 * Contracts (docs/ayar-motoru.md):
 * - `update()` has two modes: PATCH (an absent field keeps its old value) and FORM (`'form' => <id>`: an absent
 *   checkbox is off, an absent list is empty; fields of another form are untouched).
 * - `internal` fields are never taken from input; only `set()` / `on_save` write them.
 * - A field marked `invalid => keep` keeps its old value when the submission cannot be cleaned.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core;

use AdminKit\Core\Security\Host;

defined( 'ABSPATH' ) || exit;

final class Settings {

	/** Longest single-line text value, in characters. */
	public const TEXT_MAX = 300;

	/** Field types the engine knows how to clean. */
	private const TYPES = array( 'bool', 'flag', 'int', 'text', 'text_multi', 'color', 'url', 'link', 'host', 'host_port', 'enum', 'list', 'map' );

	/** Guards against a schema that nests forever. */
	private const MAX_DEPTH = 8;

	/** Marker for "the path is not in the input" (a stored null is a real value). */
	private const MISSING = "\0__adminkit_missing__\0";

	/** @var array<string, array<string, mixed>> Registered schemas (raw until first use). */
	private static array $schemas = array();

	/** @var array<string, array<string, mixed>> Values read in this request. */
	private static array $cache = array();

	/** @var array<string, array<string, string>> Last errors per plugin: error id => message. */
	private static array $errors = array();

	/** @var array<string, bool> Options the engine is writing right now (stops the sanitize filter re-entering). */
	private static array $writing = array();

	/**
	 * Registers one plugin schema. The first registration of a slug wins (one schema, one engine).
	 *
	 * @param string              $slug Plugin text-domain slug (`[a-z0-9_]`).
	 * @param array<string,mixed> $args option, schema (array or callable), forms, form_field, form_required,
	 *                                  capability, autoload (true/false/null: WordPress decides), read_clean,
	 *                                  log, on_save, messages (array or callable).
	 */
	public static function register( string $slug, array $args ): bool {
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', $slug ) ) {
			_doing_it_wrong( __METHOD__, 'Settings slug must match [a-z0-9_]{1,40}.', '0.1.1' );
			return false;
		}
		if ( isset( self::$schemas[ $slug ] ) ) {
			_doing_it_wrong( __METHOD__, 'Settings schema [' . esc_html( $slug ) . '] is already registered; the first registration wins.', '0.1.1' );
			return false;
		}
		$option = (string) ( $args['option'] ?? '' );
		if ( '' === $option ) {
			_doing_it_wrong( __METHOD__, 'Settings schema [' . esc_html( $slug ) . '] needs an option name.', '0.1.1' );
			return false;
		}
		self::$schemas[ $slug ] = array(
			'slug'          => $slug,
			'option'        => $option,
			'raw_schema'    => $args['schema'] ?? array(),
			'forms'         => array_values( array_map( 'strval', (array) ( $args['forms'] ?? array() ) ) ),
			'form_field'    => (string) ( $args['form_field'] ?? '' ),
			'form_required' => (bool) ( $args['form_required'] ?? false ),
			'capability'    => (string) ( $args['capability'] ?? 'manage_options' ),
			'autoload'      => isset( $args['autoload'] ) && is_bool( $args['autoload'] ) ? $args['autoload'] : null,
			'read_clean'    => (bool) ( $args['read_clean'] ?? false ),
			'log'           => is_callable( $args['log'] ?? null ) ? $args['log'] : null,
			'on_save'       => is_callable( $args['on_save'] ?? null ) ? $args['on_save'] : null,
			'messages'      => ( is_array( $args['messages'] ?? null ) || is_callable( $args['messages'] ?? null ) ) ? $args['messages'] : array(),
		);
		return true;
	}

	/** Is a schema registered under this slug? */
	public static function registered( string $slug ): bool {
		return isset( self::$schemas[ $slug ] );
	}

	/** The option name of a registered schema. */
	public static function option( string $slug ): string {
		$def = self::def( $slug );
		return null === $def ? '' : (string) $def['option'];
	}

	/**
	 * The declared schema, nested, as given (map sub-rows included).
	 *
	 * @return array<string, mixed>
	 */
	public static function schema( string $slug ): array {
		$def = self::def( $slug );
		return null === $def ? array() : (array) $def['schema'];
	}

	/**
	 * Every field as a flat row, path => definition. Used by screens and tests.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields( string $slug ): array {
		$def = self::def( $slug );
		return null === $def ? array() : (array) $def['flat'];
	}

	/**
	 * The default value set, nested, exactly as declared.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults( string $slug ): array {
		$def = self::def( $slug );
		return null === $def ? array() : (array) $def['defaults'];
	}

	/**
	 * Stored settings merged over the defaults.
	 *
	 * With `read_clean` (the WPSeoKit contract) every known field is cleaned while reading too, and unknown keys
	 * are dropped; without it the stored value is returned as it is (CookieKit/ContentHealth keep raw values).
	 *
	 * @return array<string, mixed>
	 */
	public static function all( string $slug ): array {
		if ( isset( self::$cache[ $slug ] ) ) {
			return (array) self::$cache[ $slug ];
		}
		$def = self::def( $slug );
		if ( null === $def ) {
			return array();
		}
		$stored = get_option( (string) $def['option'], null );
		$stored = is_array( $stored ) ? $stored : array();
		$value  = $def['read_clean'] ? self::clean_all( $def, $stored ) : self::merge( (array) $def['defaults'], $stored );
		self::$cache[ $slug ] = $value;
		return $value;
	}

	/**
	 * One value by dotted path (`a.b.c`). A missing path returns the given default.
	 *
	 * @param mixed $default Used when the path does not exist (or the walk reaches a scalar).
	 * @return mixed
	 */
	public static function get( string $slug, string $path = '', $default = null ) {
		$all = self::all( $slug );
		if ( '' === $path ) {
			return $all;
		}
		$found = self::lookup( $all, explode( '.', $path ), self::MISSING );
		return self::MISSING === $found ? $default : $found;
	}

	/**
	 * Writes one path after cleaning it (code-side writes: wizard, flags). Other paths are untouched.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function set( string $slug, string $path, $value ): bool {
		$def = self::def( $slug );
		if ( null === $def || ! isset( $def['flat'][ $path ] ) ) {
			return false;
		}
		$row    = (array) $def['flat'][ $path ];
		$all    = self::all( $slug );
		$old    = self::lookup( $all, explode( '.', $path ), $row['default'] );
		$errors = array();
		$res    = self::clean( $row, $value, $old, $errors );
		self::write( $def, self::put( $all, $path, $res['value'] ) );
		self::reset_cache( $slug );
		self::$errors[ $slug ] = $errors;
		return true;
	}

	/**
	 * Applies an input set and stores the result.
	 *
	 * @param array<string,mixed> $input Raw input (dotted keys are read as nested arrays).
	 * @param array<string,mixed> $args  `form` => a form id switches from PATCH to FORM mode.
	 * @return array{settings:array<string,mixed>,errors:array<string,string>,changed:bool}
	 */
	public static function update( string $slug, array $input, array $args = array() ): array {
		$def = self::def( $slug );
		if ( null === $def ) {
			return array(
				'settings' => array(),
				'errors'   => array(),
				'changed'  => false,
			);
		}
		// Permission is the schema's statement, checked in one place for every route (form, REST, admin-post).
		if ( '' !== $def['capability'] && function_exists( 'current_user_can' ) && ! current_user_can( (string) $def['capability'] ) ) {
			return array(
				'settings' => self::all( $slug ),
				'errors'   => array(),
				'changed'  => false,
			);
		}
		$form    = (string) ( $args['form'] ?? '' );
		$unknown = '' !== $form
			? ( $def['forms'] && ! in_array( $form, (array) $def['forms'], true ) )
			: ( ! empty( $def['form_required'] ) );
		if ( $unknown ) {
			$id     = (string) ( $def['messages']['unknown_form_id'] ?? 'form' );
			$msg    = (string) ( $def['messages']['unknown_form'] ?? '' );
			$errors = '' === $msg ? array() : array( $id => $msg );
			self::$errors[ $slug ] = $errors;
			return array(
				'settings' => self::all( $slug ),
				'errors'   => $errors,
				'changed'  => false,
			);
		}

		$before = self::all( $slug );
		$after  = $before;
		$errors = array();
		foreach ( self::paths( $def, $form ) as $path ) {
			$row = (array) $def['flat'][ $path ];
			$raw = self::lookup( $input, explode( '.', $path ), self::MISSING );
			$old = self::lookup( $before, explode( '.', $path ), $row['default'] );
			if ( self::MISSING === $raw ) {
				// PATCH: an absent path keeps its old value. FORM: a checkbox that is not posted is off.
				if ( '' === $form ) {
					continue;
				}
				$after = self::put( $after, $path, self::absent( $row, $old ) );
				continue;
			}
			$res   = self::clean( $row, $raw, $old, $errors );
			$after = self::put( $after, $path, $res['value'] );
		}

		// Plugin side effects (revision bump, wizard flag, form-only helpers) patch known paths here.
		if ( null !== $def['on_save'] ) {
			$patch = (array) call_user_func( $def['on_save'], $before, $after, $input, $form );
			foreach ( $patch as $path => $value ) {
				if ( isset( $def['flat'][ (string) $path ] ) ) {
					$after = self::put( $after, (string) $path, $value );
				}
			}
		}

		$changed = $after !== $before;
		self::write( $def, $after );
		self::reset_cache( $slug );
		self::$errors[ $slug ] = $errors;
		if ( $changed && null !== $def['log'] ) {
			call_user_func( $def['log'], $before, $after, $form );
		}
		if ( $changed ) {
			/**
			 * Fires after one plugin's settings were written and changed.
			 *
			 * @param string $slug   Plugin slug.
			 * @param string $form   Form id ('' in PATCH mode).
			 * @param array  $before Settings before the write.
			 * @param array  $after  Settings after the write.
			 */
			do_action( 'rbnkit_core_settings_saved', $slug, $form, $before, $after );
		}
		return array(
			'settings' => $after,
			'errors'   => $errors,
			'changed'  => $changed,
		);
	}

	/**
	 * Type check of a partial input set (no cleaning, no write). Messages are the ones the REST route returns.
	 *
	 * @param array<string,mixed> $partial Incoming data.
	 * @return array<string, string> Path (or section) => message; empty when valid.
	 */
	public static function validate( string $slug, array $partial ): array {
		$def = self::def( $slug );
		if ( null === $def ) {
			return array();
		}
		$errors = array();
		foreach ( array_keys( (array) $def['flat'] ) as $path ) {
			$path = (string) $path;
			$row  = (array) $def['flat'][ $path ];
			$raw  = self::lookup( $partial, explode( '.', $path ), self::MISSING );
			if ( self::MISSING === $raw ) {
				continue;
			}
			// A section that is not an object is one error, not one per key.
			$parts = explode( '.', $path );
			if ( count( $parts ) > 1 ) {
				$section = self::lookup( $partial, array( $parts[0] ), self::MISSING );
				if ( self::MISSING !== $section && ! is_array( $section ) ) {
					$errors[ $parts[0] ] = 'A section must be an object.';
					continue;
				}
			}
			$msg = self::type_error( $row, $raw );
			if ( '' !== $msg ) {
				$errors[ $path ] = $msg;
			}
		}
		return $errors;
	}

	/**
	 * Resets one path (or whole section, or everything) to the declared defaults.
	 *
	 * @param string $path '' = every field, `section` = its fields, `section.key` = one field.
	 */
	public static function reset( string $slug, string $path = '' ): bool {
		$def = self::def( $slug );
		if ( null === $def ) {
			return false;
		}
		$all = self::all( $slug );
		foreach ( (array) $def['flat'] as $p => $row ) {
			$p = (string) $p;
			// Internal fields (revision, wizard flag) are not part of "back to defaults"; an exact path may still reset one.
			if ( ! empty( $row['internal'] ) && $p !== $path ) {
				continue;
			}
			if ( '' === $path || $p === $path || 0 === strpos( $p, $path . '.' ) ) {
				$all = self::put( $all, $p, $row['default'] );
			}
		}
		self::write( $def, $all );
		self::reset_cache( $slug );
		return true;
	}

	/**
	 * Registers the option with WordPress (`options.php` route). The sanitize callback is the engine itself.
	 *
	 * @param string $group Settings group used by `settings_fields()` in the form.
	 */
	public static function register_option( string $slug, string $group ): bool {
		$def = self::def( $slug );
		if ( null === $def ) {
			return false;
		}
		if ( null === get_option( (string) $def['option'], null ) ) {
			add_option( (string) $def['option'], (array) $def['defaults'], '', $def['autoload'] );
		}
		register_setting(
			$group,
			(string) $def['option'],
			array(
				'type'              => 'array',
				'default'           => (array) $def['defaults'],
				'sanitize_callback' => static function ( $input ) use ( $slug ): array {
					$def = self::def( $slug );
					// The engine's own write goes through update_option(), which would call this filter again.
					if ( null !== $def && isset( self::$writing[ (string) $def['option'] ] ) ) {
						return is_array( $input ) ? $input : array();
					}
					$form = '';
					if ( null !== $def && '' !== $def['form_field'] && is_array( $input ) && isset( $input[ $def['form_field'] ] ) ) {
						$form = sanitize_key( (string) $input[ $def['form_field'] ] );
					}
					$res = self::update( $slug, is_array( $input ) ? $input : array(), '' === $form ? array() : array( 'form' => $form ) );
					if ( null !== $def ) {
						foreach ( $res['errors'] as $id => $message ) {
							add_settings_error( (string) $def['option'], (string) $id, (string) $message, 'error' );
						}
					}
					return (array) $res['settings'];
				},
			)
		);
		return true;
	}

	/** Errors of the last write (error id => message). */
	public static function last_errors( string $slug = '' ): array {
		return '' === $slug ? self::$errors : ( self::$errors[ $slug ] ?? array() );
	}

	/** Test helper: forgets the in-request value cache of one schema (or every schema). */
	public static function reset_cache( string $slug = '' ): void {
		if ( '' === $slug ) {
			self::$cache = array();
			return;
		}
		unset( self::$cache[ $slug ] );
	}

	/** Test helper: forgets registrations, values and errors (stored options are not touched). */
	public static function reset_all(): void {
		self::$schemas = array();
		self::$cache   = array();
		self::$errors  = array();
	}

	/* ------------------------------------------------------------------ public cleaners (one copy, shared) */

	/**
	 * Plain single-line text. Deliberately NOT sanitize_text_field(): that strips percent-hex pairs (the "da" in
	 * %date%) and would corrupt template variables.
	 *
	 * @param mixed $raw Raw value.
	 */
	public static function clean_text( $raw, int $max = self::TEXT_MAX ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$text = wp_strip_all_tags( (string) wp_check_invalid_utf8( (string) $raw ) );
		$text = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) );
		return self::cut( $text, $max );
	}

	/**
	 * Multi-line text: line breaks survive (a textarea round-trip must not lose them).
	 *
	 * @param mixed $raw Raw value.
	 */
	public static function clean_text_multi( $raw, int $max = self::TEXT_MAX ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$text = wp_strip_all_tags( (string) wp_check_invalid_utf8( (string) $raw ) );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $text ) );
		return self::cut( $text, $max );
	}

	/** An http/https URL, or an empty string. @param mixed $raw Raw value. */
	public static function clean_url( $raw ): string {
		return is_string( $raw ) ? (string) esc_url_raw( trim( $raw ), array( 'http', 'https' ) ) : '';
	}

	/**
	 * A bare host name (letters, digits, dots, hyphens; lower case; no scheme, path, port or user), or ''.
	 *
	 * @param mixed $raw Raw value.
	 */
	public static function clean_host( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$host = strtolower( trim( $raw ) );
		if ( strlen( $host ) > 253 || 1 !== preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host ) ) {
			return '';
		}
		if ( 1 === preg_match( '/^[0-9.]+$/', $host ) || Host::is_ip( $host ) ) {
			return '';
		}
		return $host;
	}

	/** `host` or `host:port`, lower case. @param mixed $raw Raw value. */
	public static function clean_host_port( $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9.\-:]/', '', trim( (string) $raw ) ) );
	}

	/** `#rrggbb` / `#rgb`, or ''. @param mixed $raw Raw value. */
	public static function clean_color( $raw ): string {
		$hex = sanitize_hex_color( trim( (string) ( is_scalar( $raw ) ? $raw : '' ) ) );
		return null === $hex ? '' : (string) $hex;
	}

	/**
	 * A site path (`/gizlilik/`) or an http(s) URL on this site's own host. Null when the value is not one of those.
	 *
	 * @param mixed $raw Raw value.
	 */
	public static function link_value( $raw ): ?string {
		$value = trim( (string) ( is_scalar( $raw ) ? $raw : '' ) );
		if ( '' === $value ) {
			return '';
		}
		if ( strlen( $value ) > self::TEXT_MAX || false !== strpos( $value, '\\' ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
			return null;
		}
		if ( 0 === strpos( $value, '/' ) ) {
			return 0 === strpos( $value, '//' ) ? null : '/' . ltrim( self::clean_text( $value ), '/' );
		}
		$clean = self::clean_url( $value );
		if ( '' === $clean ) {
			return null;
		}
		$host = Host::host( $clean );
		$mine = Host::host( home_url() );
		return ( '' !== $host && '' !== $mine && 0 === strcasecmp( $host, $mine ) ) ? $clean : null;
	}

	/**
	 * Line-per-item (and comma) input to a clean list. Commas are NOT split for regex patterns ({0,220} survives).
	 *
	 * @param mixed $raw    Raw input.
	 * @param bool  $commas Split on commas too.
	 * @return array<int, string>
	 */
	public static function lines( $raw, bool $commas = true ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( $commas ? '/\r\n|\r|\n|,/' : '/\r\n|\r|\n/', $raw ) ?: array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $line ) {
			$line = trim( (string) ( is_scalar( $line ) ? $line : '' ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ internals */

	/**
	 * Is a registered definition (schema, messages) a callable rather than data? An array is data unless it is the
	 * two-string form `array( Class::class, 'method' )` / `array( $object, 'method' )`.
	 *
	 * @param mixed $value Registered definition.
	 */
	private static function is_definition_callable( $value ): bool {
		if ( ! is_callable( $value ) ) {
			return false;
		}
		if ( ! is_array( $value ) ) {
			return true;
		}
		return 2 === count( $value ) && isset( $value[0], $value[1] ) && is_string( $value[1] );
	}

	/** Resolves a registration once: callable schema, flat rows, defaults. */
	private static function def( string $slug ): ?array {
		if ( ! isset( self::$schemas[ $slug ] ) ) {
			_doing_it_wrong( __METHOD__, 'Settings schema [' . esc_html( substr( (string) preg_replace( '/[^a-z0-9_]/', '', $slug ), 0, 40 ) ) . '] is not registered.', '0.1.1' );
			return null;
		}
		$def = self::$schemas[ $slug ];
		if ( isset( $def['flat'] ) ) {
			return $def;
		}
		$schema = $def['raw_schema'];
		// A schema may be a closure, a function name or `array( Class::class, 'method' )`; only a plain map is data.
		if ( self::is_definition_callable( $schema ) ) {
			$schema = call_user_func( $schema );
		}
		$flat = array();
		self::flatten( (array) $schema, '', $flat, 0 );
		// Messages may be a callable: a plugin's `__()` must not run before `init` (translations load too early).
		$messages = $def['messages'];
		if ( self::is_definition_callable( $messages ) ) {
			$messages = call_user_func( $messages );
		}
		$def['schema']   = (array) $schema;
		$def['flat']     = $flat;
		$def['defaults'] = self::build_defaults( $flat );
		$def['messages'] = is_array( $messages ) ? $messages : array();
		self::$schemas[ $slug ] = $def;
		return $def;
	}

	/**
	 * Walks a nested schema: a node with `type` is a field, anything else is a section.
	 *
	 * @param array<string,mixed>              $node   Schema node.
	 * @param array<string, array<string,mixed>> $flat  Collected rows.
	 */
	private static function flatten( array $node, string $prefix, array &$flat, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		foreach ( $node as $key => $child ) {
			if ( ! is_string( $key ) || ! is_array( $child ) ) {
				continue;
			}
			$path = '' === $prefix ? $key : $prefix . '.' . $key;
			if ( array_key_exists( 'type', $child ) ) {
				$flat[ $path ] = self::row( $path, $child );
				continue;
			}
			self::flatten( $child, $path, $flat, $depth + 1 );
		}
	}

	/**
	 * Normalizes one declared field into the row the engine works with.
	 *
	 * @param array<string,mixed> $f Declared field.
	 * @return array<string,mixed>
	 */
	private static function row( string $path, array $f ): array {
		$type = (string) ( $f['type'] ?? 'text' );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = 'text';
		}
		$row = array(
			'path'        => $path,
			'type'        => $type,
			'default'     => $f['default'] ?? self::zero( $type ),
			'min'         => isset( $f['min'] ) ? (int) $f['min'] : null,
			'max'         => isset( $f['max'] ) ? (int) $f['max'] : null,
			'max_len'     => isset( $f['max_len'] ) ? max( 1, (int) $f['max_len'] ) : self::TEXT_MAX,
			'values'      => is_array( $f['values'] ?? null ) ? $f['values'] : array(),
			'keys'        => is_array( $f['keys'] ?? null ) ? $f['keys'] : array(),
			'key'         => (string) ( $f['key'] ?? 'slug' ),
			'split'       => 'lines' === ( $f['split'] ?? '' ) ? 'lines' : 'both',
			'max_entries' => isset( $f['max_entries'] ) ? max( 1, (int) $f['max_entries'] ) : 50,
			'value'       => null,
			'fields'      => array(),
			'filter'      => is_callable( $f['filter'] ?? null ) ? $f['filter'] : null,
			'forms'       => array_values( array_map( 'strval', (array) ( $f['forms'] ?? array() ) ) ),
			'internal'    => (bool) ( $f['internal'] ?? false ),
			'invalid'     => 'keep' === ( $f['invalid'] ?? '' ) ? 'keep' : 'clamp',
			'empty'       => in_array( $f['empty'] ?? 'keep', array( 'keep', 'default', 'old' ), true ) ? (string) ( $f['empty'] ?? 'keep' ) : 'keep',
			'clean'       => is_callable( $f['clean'] ?? null ) ? $f['clean'] : null,
			'validate'    => is_callable( $f['validate'] ?? null ) ? $f['validate'] : null,
			'error_id'    => (string) ( $f['error_id'] ?? $path ),
			'label'       => (string) ( $f['label'] ?? '' ),
			'message'     => (string) ( $f['message'] ?? '' ),
		);
		if ( 'map' === $type ) {
			if ( is_array( $f['value'] ?? null ) ) {
				$row['value'] = self::row( $path . '.*', (array) $f['value'] );
			}
			foreach ( (array) ( $f['fields'] ?? array() ) as $name => $sub ) {
				if ( is_string( $name ) && is_array( $sub ) ) {
					$row['fields'][ $name ] = self::row( $path . '.*.' . $name, $sub );
				}
			}
		}
		// A declared default must already have the shape the type stores.
		if ( in_array( $type, array( 'list', 'map' ), true ) && ! is_array( $row['default'] ) ) {
			$row['default'] = array();
		}
		if ( 'bool' === $type ) {
			$row['default'] = (bool) $row['default'];
		} elseif ( 'flag' === $type ) {
			$row['default'] = empty( $row['default'] ) ? 0 : 1;
		} elseif ( 'int' === $type ) {
			$row['default'] = (int) $row['default'];
		} elseif ( ! in_array( $type, array( 'list', 'map' ), true ) ) {
			$row['default'] = (string) $row['default'];
		}
		return $row;
	}

	/** The empty value of a type (a missing `default` key). */
	private static function zero( string $type ) {
		if ( 'bool' === $type ) {
			return false;
		}
		if ( 'flag' === $type || 'int' === $type ) {
			return 0;
		}
		if ( in_array( $type, array( 'list', 'map' ), true ) ) {
			return array();
		}
		return '';
	}

	/**
	 * Nested defaults built from the flat rows.
	 *
	 * @param array<string, array<string,mixed>> $flat Rows.
	 * @return array<string, mixed>
	 */
	private static function build_defaults( array $flat ): array {
		$out = array();
		foreach ( $flat as $path => $row ) {
			$out = self::put( $out, (string) $path, $row['default'] );
		}
		return $out;
	}

	/**
	 * Reads one dotted path out of any nested array (a plugin's own repository can reuse this instead of
	 * writing a second path walker).
	 *
	 * @param array<string,mixed> $data    Nested array.
	 * @param string              $path    Dotted path (`a.b.c`).
	 * @param mixed               $default Returned when the path is missing.
	 * @return mixed
	 */
	public static function value_at( array $data, string $path, $default = null ) {
		return self::lookup( $data, explode( '.', (string) $path ), $default );
	}

	/**
	 * Reads a dotted value; the MISSING marker tells "absent" apart from a stored null.
	 *
	 * @param mixed $node   Tree or leaf.
	 * @param array<int,string> $parts Path parts.
	 * @param mixed $default Returned when the walk fails.
	 * @return mixed
	 */
	private static function lookup( $node, array $parts, $default ) {
		foreach ( $parts as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return $default;
			}
			$node = $node[ $part ];
		}
		return $node;
	}

	/**
	 * Writes one dotted value into a copy of the tree.
	 *
	 * @param array<string,mixed> $tree  Tree.
	 * @param mixed               $value Value.
	 * @return array<string,mixed>
	 */
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
	 * Stored values over the defaults: an associative array is merged key by key, a list replaces the default.
	 *
	 * @param array<string,mixed> $defaults Defaults.
	 * @param array<string,mixed> $stored   Stored.
	 * @return array<string,mixed>
	 */
	private static function merge( array $defaults, array $stored ): array {
		$out = $defaults;
		foreach ( $stored as $key => $value ) {
			$is_assoc = isset( $out[ $key ] ) && is_array( $out[ $key ] ) && ! self::is_list( (array) $out[ $key ] );
			$out[ $key ] = $is_assoc && is_array( $value ) ? self::merge( (array) $out[ $key ], $value ) : $value;
		}
		return $out;
	}

	/**
	 * Is an array a plain list (`0..n-1` keys)? A list replaces the default, an associative array is merged.
	 *
	 * @param array<int|string, mixed> $array Array.
	 */
	private static function is_list( array $array ): bool {
		$i = 0;
		foreach ( $array as $key => $unused ) {
			if ( $key !== $i ) {
				return false;
			}
			++$i;
		}
		return true;
	}

	/**
	 * Read-time cleaning (`read_clean`): schema fields only, every value cleaned, unknown keys dropped.
	 *
	 * @param array<string,mixed> $def    Resolved schema.
	 * @param array<string,mixed> $stored Stored value.
	 * @return array<string,mixed>
	 */
	private static function clean_all( array $def, array $stored ): array {
		$out    = array();
		$errors = array();
		foreach ( (array) $def['flat'] as $path => $row ) {
			$path = (string) $path;
			$raw  = self::lookup( $stored, explode( '.', $path ), self::MISSING );
			if ( self::MISSING === $raw ) {
				$out = self::put( $out, $path, $row['default'] );
				continue;
			}
			$res = self::clean( (array) $row, $raw, $row['default'], $errors );
			$out = self::put( $out, $path, $res['value'] );
		}
		return $out;
	}

	/**
	 * Paths one write may touch: every field of the form (or every field in PATCH mode); internal fields never.
	 *
	 * @param array<string,mixed> $def  Resolved schema.
	 * @return array<int, string>
	 */
	private static function paths( array $def, string $form ): array {
		$paths = array();
		foreach ( (array) $def['flat'] as $path => $row ) {
			if ( ! empty( $row['internal'] ) ) {
				continue;
			}
			if ( '' !== $form && ! empty( $row['forms'] ) && ! in_array( $form, (array) $row['forms'], true ) ) {
				continue;
			}
			$paths[] = (string) $path;
		}
		return $paths;
	}

	/** What a missing field becomes in FORM mode. @param mixed $old Old value. @return mixed */
	private static function absent( array $row, $old ) {
		if ( 'bool' === $row['type'] ) {
			return false;
		}
		if ( 'flag' === $row['type'] ) {
			return 0;
		}
		if ( in_array( $row['type'], array( 'list', 'map' ), true ) ) {
			return array();
		}
		// A text/number field that the form does not post keeps its value: a hidden field must never be wiped.
		return $old;
	}

	/**
	 * Cleans one raw value for one field and records a user-facing error when the row has a message.
	 *
	 * @param array<string,mixed>       $row    Field row.
	 * @param mixed                     $raw    Raw input.
	 * @param mixed                     $old    Old value (kept by `invalid => keep`).
	 * @param array<string, string>     $errors Collected errors (error id => message).
	 * @return array{value:mixed,ok:bool,notice:bool}
	 */
	private static function clean( array $row, $raw, $old, array &$errors ): array {
		$value  = $row['default'];
		$failed = false;
		$notice = false;

		switch ( $row['type'] ) {
			case 'bool':
				$value = (bool) filter_var( is_scalar( $raw ) ? $raw : false, FILTER_VALIDATE_BOOLEAN );
				break;
			case 'flag':
				$flag  = is_scalar( $raw ) ? trim( (string) $raw ) : '';
				$value = ( '' === $flag || '0' === $flag || 'false' === $flag ) ? 0 : 1;
				break;
			case 'int':
				$num = is_int( $raw ) ? $raw : ( is_bool( $raw ) ? 0 : null );
				if ( null === $num ) {
					$text = is_scalar( $raw ) ? trim( (string) $raw ) : '';
					if ( 1 === preg_match( '/^-?\d{1,15}$/', $text ) ) {
						$num = (int) $text;
					} else {
						$num    = 0;
						$notice = true;
					}
				}
				$value = $num;
				if ( null !== $row['min'] ) {
					$value = max( (int) $row['min'], (int) $value );
				}
				if ( null !== $row['max'] ) {
					$value = min( (int) $row['max'], (int) $value );
				}
				if ( $value !== $num ) {
					$notice = true;
				}
				break;
			case 'text':
				$value = self::clean_text( $raw, (int) $row['max_len'] );
				if ( '' === $value ) {
					if ( 'default' === $row['empty'] ) {
						$value = (string) $row['default'];
					} elseif ( 'old' === $row['empty'] ) {
						$value = is_scalar( $old ) ? (string) $old : '';
					}
				}
				break;
			case 'text_multi':
				$value = self::clean_text_multi( $raw, (int) $row['max_len'] );
				if ( '' === $value ) {
					if ( 'default' === $row['empty'] ) {
						$value = (string) $row['default'];
					} elseif ( 'old' === $row['empty'] ) {
						$value = is_scalar( $old ) ? (string) $old : '';
					}
				}
				break;
			case 'color':
				$text = trim( (string) ( is_scalar( $raw ) ? $raw : '' ) );
				if ( '' === $text ) {
					$value = '';
					break;
				}
				$hex = self::clean_color( $text );
				if ( '' === $hex ) {
					$failed = true;
				} else {
					$value = $hex;
				}
				break;
			case 'url':
				$text  = trim( (string) ( is_scalar( $raw ) ? $raw : '' ) );
				$value = self::clean_url( $text );
				$failed = ( '' !== $text && '' === $value );
				break;
			case 'link':
				$link = self::link_value( $raw );
				if ( null === $link ) {
					$failed = true;
				} else {
					$value = $link;
				}
				break;
			case 'host':
				$text  = trim( (string) ( is_scalar( $raw ) ? $raw : '' ) );
				$value = self::clean_host( $text );
				$failed = ( '' !== $text && '' === $value );
				break;
			case 'host_port':
				$value = self::clean_host_port( $raw );
				break;
			case 'enum':
				$text  = trim( (string) ( is_scalar( $raw ) ? $raw : '' ) );
				$value = self::allowed( (array) $row['values'], $text ) ? $text : (string) $row['default'];
				$failed = ( $value !== $text );
				break;
			case 'list':
				$value = self::clean_list( $row, $raw, $notice );
				break;
			case 'map':
				$value = self::clean_map( $row, $raw, $errors );
				break;
		}

		if ( $failed && 'keep' === $row['invalid'] ) {
			$value = $old;
		} elseif ( $failed ) {
			$value = $row['default'];
		}

		if ( null !== $row['clean'] ) {
			$cleaner = $row['clean'];
			$value   = $cleaner( $value, $old, (string) $row['path'], $errors );
		}
		if ( null !== $row['validate'] ) {
			$message = (string) call_user_func( $row['validate'], $value );
			if ( '' !== $message ) {
				$errors[ (string) $row['error_id'] ] = $message;
			}
		}
		if ( ( $failed || $notice ) && '' !== $row['message'] ) {
			$errors[ (string) $row['error_id'] ] = (string) $row['message'];
		}
		return array(
			'value'  => $value,
			'ok'     => ! $failed,
			'notice' => $notice,
		);
	}

	/**
	 * List cleaning: line/comma input, per-item slug or text, whitelist, dedupe, cap.
	 *
	 * @param array<string,mixed> $row    Field row.
	 * @param mixed               $raw    Raw input.
	 * @param bool                $notice Set when an item was rejected.
	 * @return array<int, mixed>
	 */
	private static function clean_list( array $row, $raw, bool &$notice ): array {
		$items = self::lines( $raw, 'both' === $row['split'] );
		$out   = array();
		$kind  = (string) $row['key'];
		foreach ( $items as $item ) {
			// `raw` = the item is source text (a regex pattern): trim and cut only, no tag stripping.
			if ( 'text' === $kind ) {
				$value = self::clean_text( $item, (int) $row['max_len'] );
			} elseif ( 'raw' === $kind ) {
				$value = self::cut( trim( $item ), (int) $row['max_len'] );
			} else {
				$value = sanitize_key( $item );
			}
			if ( '' === $value ) {
				continue;
			}
			if ( ! empty( $row['keys'] ) && ! self::allowed( (array) $row['keys'], $value ) ) {
				$notice = true;
				continue;
			}
			$out[ $value ] = $value;
		}
		return array_slice( array_values( $out ), 0, (int) $row['max_entries'] );
	}

	/**
	 * Map cleaning: key shape, key whitelist, per-row value (`value` row or `fields`), row filter, cap.
	 *
	 * @param array<string,mixed>   $row    Field row.
	 * @param mixed                 $raw    Raw input.
	 * @param array<string, string> $errors Collected errors.
	 * @return array<int|string, mixed>
	 */
	private static function clean_map( array $row, $raw, array &$errors ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $key => $value ) {
			if ( count( $out ) >= (int) $row['max_entries'] ) {
				break;
			}
			if ( 'int' === $row['key'] ) {
				$key = (int) $key;
				if ( $key <= 0 ) {
					continue;
				}
			} elseif ( 'slug' === $row['key'] ) {
				$key = sanitize_key( (string) $key );
				if ( '' === $key ) {
					continue;
				}
			} else {
				$key = self::clean_text( $key, 60 );
				if ( '' === $key ) {
					continue;
				}
			}
			if ( ! empty( $row['keys'] ) && ! self::allowed( (array) $row['keys'], (string) $key ) ) {
				continue;
			}
			if ( null !== $row['filter'] && ! call_user_func( $row['filter'], $key, $value ) ) {
				continue;
			}
			if ( is_array( $row['value'] ) ) {
				$sub = self::clean( (array) $row['value'], $value, $row['value']['default'], $errors );
				if ( '' === (string) $sub['value'] ) {
					continue; // url_map: an empty profile is not stored.
				}
				$out[ $key ] = $sub['value'];
				continue;
			}
			if ( ! empty( $row['fields'] ) ) {
				$line = array();
				foreach ( (array) $row['fields'] as $name => $sub ) {
					$cell        = is_array( $value ) ? ( $value[ $name ] ?? '' ) : '';
					$clean       = self::clean( (array) $sub, $cell, $sub['default'], $errors );
					$line[ $name ] = $clean['value'];
				}
				$out[ $key ] = $line;
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Is the value in a declared set (a plain list or a `value => label` map)?
	 *
	 * @param array<string|int, mixed> $set Declared set.
	 */
	private static function allowed( array $set, string $value ): bool {
		if ( array_key_exists( $value, $set ) ) {
			return true;
		}
		foreach ( $set as $item ) {
			if ( is_scalar( $item ) && (string) $item === $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The type message of one value (the WPSeoKit contract: the REST route returns these strings).
	 *
	 * @param array<string,mixed> $row Field row.
	 * @param mixed               $raw Raw value.
	 */
	private static function type_error( array $row, $raw ): string {
		switch ( $row['type'] ) {
			case 'bool':
			case 'flag':
				return ( is_bool( $raw ) || in_array( $raw, array( 0, 1, '0', '1', 'true', 'false' ), true ) ) ? '' : 'Expected a boolean.';
			case 'int':
				return ( is_int( $raw ) || ( is_string( $raw ) && 1 === preg_match( '/^-?\d{1,15}$/', $raw ) ) ) ? '' : 'Expected an integer.';
			case 'enum':
				return ( is_string( $raw ) && self::allowed( (array) $row['values'], $raw ) ) ? '' : 'Value is not one of the allowed options.';
			case 'list':
				return is_array( $raw ) ? '' : 'Expected a list.';
			case 'map':
				return is_array( $raw ) ? '' : 'Expected an object.';
		}
		return is_string( $raw ) ? '' : 'Expected a string.';
	}

	/** Cuts a text to a character limit. */
	private static function cut( string $text, int $max ): string {
		$max = max( 1, $max );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * Writes the option. The autoload flag is only chosen when the row is created; an existing row keeps its flag.
	 *
	 * @param array<string,mixed> $def   Resolved schema.
	 * @param array<string,mixed> $value Full settings value.
	 */
	private static function write( array $def, array $value ): void {
		$option = (string) $def['option'];
		// update_option()/add_option() run the `sanitize_option_<option>` filter (our own callback); the flag keeps
		// the engine from re-entering itself while it writes its own result.
		self::$writing[ $option ] = true;
		try {
			if ( null === get_option( $option, null ) ) {
				add_option( $option, $value, '', $def['autoload'] );
				return;
			}
			update_option( $option, $value );
		} finally {
			unset( self::$writing[ $option ] );
		}
	}
}
