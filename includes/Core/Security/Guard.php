<?php
/**
 * Central security gate: nonce + capability for admin actions, permission callbacks for REST, origin check. ONE implementation for every RBN plugin.
 *
 * ONE implementation for every RBN plugin: an admin-post / admin-ajax handler starts with `Guard::admin_action()`,
 * a REST route carries `Guard::rest()` or `Guard::public_route()` as its `permission_callback`. A plugin never
 * writes its own nonce / capability check again.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Security;

defined( 'ABSPATH' ) || exit;

final class Guard {

	/** @var array<int, array{route:string,reason:string}> Deliberately open routes (registry, for the admin screen). */
	private static array $public_routes = array();

	/**
	 * Admin action gate: capability + nonce in one call. Call it at the top of an `admin_post_*` / `wp_ajax_*` handler.
	 *
	 * @param string $nonce_action Nonce action (`wp_nonce_field()` / `wp_create_nonce()` use the same name).
	 * @param string $cap          Capability the user must have.
	 * @param string $field        Form field that carries the nonce.
	 * @param string $response     'die' = HTML error page, 'json' = JSON error (admin-ajax).
	 * @param string $filter       Optional filter name that may widen the decision (the value is the capability).
	 */
	public static function admin_action( string $nonce_action, string $cap = 'manage_options', string $field = '_wpnonce', string $response = 'die', string $filter = '' ): void {
		if ( ! self::allowed( $cap, $filter ) ) {
			self::fail( __( 'You do not have permission for this action.', 'adminkit' ), $response );
		}
		$nonce = isset( $_REQUEST[ $field ] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST[ $field ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			self::fail( __( 'The link you followed has expired.', 'adminkit' ), $response );
		}
	}

	/**
	 * REST permission callback for a capability.
	 *
	 * @param string      $cap          Capability the user must have.
	 * @param string|null $nonce_action  When set, the `X-WP-Nonce` header must carry this nonce.
	 * @param string      $filter       Optional filter name that may widen the decision.
	 * @param string      $object_param Request parameter that carries the object id (for `edit_post`).
	 * @param string      $fallback_cap Capability used when the object id is missing.
	 */
	public static function rest( string $cap = 'manage_options', ?string $nonce_action = null, string $filter = '', string $object_param = '', string $fallback_cap = '', string $context_param = '' ): callable {
		return static function ( \WP_REST_Request $request ) use ( $cap, $nonce_action, $filter, $object_param, $fallback_cap, $context_param ) {
			if ( null !== $nonce_action ) {
				$header = (string) $request->get_header( 'x_wp_nonce' );
				if ( '' === $header || ! wp_verify_nonce( $header, $nonce_action ) ) {
					return new \WP_Error(
						'rbnkit_bad_nonce',
						__( 'The request could not be verified. Reload the page and try again.', 'adminkit' ),
						array( 'status' => 403 )
					);
				}
			}
			if ( '' !== $object_param ) {
				$id = (int) $request->get_param( $object_param );
				if ( $id > 0 ) {
					return current_user_can( $cap, $id ) ? true : self::forbidden();
				}
				if ( '' === $fallback_cap ) {
					return self::forbidden();
				}
				$cap = $fallback_cap;
			}
			$allowed = current_user_can( $cap );
			if ( '' !== $filter ) {
				$param   = '' !== $object_param ? $object_param : $context_param;
				$context = '' !== $param ? (string) $request->get_param( $param ) : '';
				$allowed = (bool) apply_filters( $filter, $allowed, $context );
			}
			return $allowed ? true : self::forbidden();
		};
	}

	/**
	 * Permission callback for a deliberately open route. The reason is mandatory and is kept in the registry.
	 *
	 * @param string $reason Why the route is open (shown in the registry).
	 */
	public static function public_route( string $reason ): callable {
		return static function ( \WP_REST_Request $request ) use ( $reason ) {
			self::remember_public( (string) $request->get_route(), $reason );
			return true;
		};
	}

	/** The open routes of this request, with their reason. @return array<int, array{route:string,reason:string}> */
	public static function public_routes(): array {
		return self::$public_routes;
	}

	/** Forgets the registry (tests). */
	public static function reset_public_routes(): void {
		self::$public_routes = array();
	}

	/**
	 * Is the request from this site? A request without an Origin header (not a browser cross-site request) is allowed.
	 */
	public static function same_origin(): bool {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_ORIGIN'] ) ) : '';
		if ( '' === $origin ) {
			return true;
		}
		$host = (string) wp_parse_url( $origin, PHP_URL_HOST );
		if ( '' === $host ) {
			return false;
		}
		return Host::same_site_pair( $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/* ------------------------------------------------------------------ internals */

	/** Capability decision, with an optional filter that may widen it. */
	private static function allowed( string $cap, string $filter ): bool {
		$allowed = current_user_can( $cap );
		if ( '' !== $filter ) {
			$allowed = (bool) apply_filters( $filter, $allowed );
		}
		return $allowed;
	}

	/** The refusal of a REST route. */
	private static function forbidden(): \WP_Error {
		return new \WP_Error(
			'rbnkit_forbidden',
			__( 'You are not allowed to do this.', 'adminkit' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/** Ends the request with a clear 403. */
	private static function fail( string $message, string $response ): void {
		if ( 'json' === $response ) {
			wp_send_json_error( array( 'message' => $message ), 403 );
		}
		wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
	}

	/** Records an open route (once per route). */
	private static function remember_public( string $route, string $reason ): void {
		foreach ( self::$public_routes as $row ) {
			if ( $row['route'] === $route ) {
				return;
			}
		}
		self::$public_routes[] = array(
			'route'  => $route,
			'reason' => $reason,
		);
	}
}
