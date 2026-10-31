<?php
/**
 * AJAX entry point of the instant switches: nonce + capability + rate limit + input whitelist, then `Toggle::apply()`.
 *
 * Phase 2 (WPRJ-718): registered in `Plugin::boot()` as `wp_ajax_adminkit_toggle`; the screen receives `ajaxUrl`,
 * `ajaxAction` and `toggleNonce` through the `RbnUiI18n` localization (see docs/aninda-anahtar.md).
 *
 * Request (POST, admin-ajax): action=adminkit_toggle, _wpnonce, slug, path, value (1|0|true|false).
 * Success: HTTP 200 `{ok:true, slug, path, value, changed, label, module?}`.
 * Failure: HTTP 400/403/404/405/409/429 `{ok:false, code, message}`.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Settings;

use AdminKit\Core\Security\Guard;
use AdminKit\Core\Security\RateLimit;
use AdminKit\Core\Settings as Engine;

defined( 'ABSPATH' ) || exit;

final class ToggleEndpoint {

	/** admin-ajax action (`wp_ajax_adminkit_toggle` once Phase 2 registers it). */
	public const ACTION = 'adminkit_toggle';

	/** Nonce action the form/JS creates and Guard verifies. */
	public const NONCE = 'adminkit_toggle';

	/** Capability of the route (the settings screens are administrator-only). */
	public const CAP = 'manage_options';

	/** Field carrying the nonce (Guard's default). */
	public const NONCE_FIELD = '_wpnonce';

	/** Hits allowed per user inside the window. */
	public const LIMIT = 60;

	/** Window length in seconds. */
	public const WINDOW = 60;

	/** Rate-limit counter key prefix; `RateLimit` digests it before storing. */
	private const RATE_KEY = 'adminkit_toggle:';

	public static function handle(): void {
		if ( ! self::is_post() ) {
			self::fail( 'rbnkit_toggle_method', __( 'The request must be a POST.', 'adminkit' ), 405 );
		}
		Guard::admin_action( self::NONCE, self::CAP, self::NONCE_FIELD, 'json' );

		if ( self::limited() ) {
			self::fail( 'rbnkit_toggle_rate_limited', __( 'Too many changes; wait a minute and try again.', 'adminkit' ), 429 );
		}

		$slug = strtolower( self::post_string( 'slug' ) );
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', $slug ) || ! in_array( $slug, Engine::slugs(), true ) ) {
			self::fail( 'rbnkit_toggle_unknown_slug', __( 'Unknown settings kit.', 'adminkit' ), 400 );
		}

		$path = strtolower( self::post_string( 'path' ) );
		if ( 1 !== preg_match( '/^[a-z0-9_.]{1,120}$/', $path ) ) {
			self::fail( 'rbnkit_toggle_bad_path', __( 'The setting path is not valid.', 'adminkit' ), 400 );
		}

		$value = self::to_bool( $_POST['value'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification -- Guard verified the nonce above.
		if ( null === $value ) {
			self::fail( 'rbnkit_toggle_bad_value', __( 'The value must be true or false.', 'adminkit' ), 400 );
		}

		$result = Toggle::apply( $slug, $path, $value );
		if ( is_wp_error( $result ) ) {
			$data   = (array) $result->get_error_data();
			$status = (int) ( $data['status'] ?? 400 );
			self::fail( $result->get_error_code(), $result->get_error_message(), $status );
		}

		wp_send_json( array_merge( array( 'ok' => true ), $result ) );
	}

	/* ------------------------------------------------------------------ internals */

	private static function is_post(): bool {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] );
	}

	/** One counter per signed-in user; the screen sends one request per confirmed switch. */
	private static function limited(): bool {
		$user   = (int) get_current_user_id();
		$limit  = max( 1, (int) apply_filters( 'rbnkit_core_toggle_rate_limit', self::LIMIT, $user ) );
		$window = max( 1, (int) apply_filters( 'rbnkit_core_toggle_rate_window', self::WINDOW ) );
		return RateLimit::too_many( self::RATE_KEY . $user, $limit, $window );
	}

	private static function post_string( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** Strict value parse: only `1`, `0`, `true`, `false` (or a real bool/int from an internal caller). */
	private static function to_bool( $raw ): ?bool {
		if ( is_bool( $raw ) ) {
			return $raw;
		}
		if ( is_int( $raw ) ) {
			return 0 === $raw ? false : ( 1 === $raw ? true : null );
		}
		if ( is_string( $raw ) ) {
			$value = strtolower( trim( $raw ) );
			if ( '1' === $value || 'true' === $value ) {
				return true;
			}
			if ( '0' === $value || 'false' === $value ) {
				return false;
			}
		}
		return null;
	}

	/** JSON refusal; `wp_send_json()` ends the request (`wp_die`), exactly like Guard's own JSON failure path. */
	private static function fail( string $code, string $message, int $status ): void {
		wp_send_json(
			array(
				'ok'      => false,
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
