<?php
/**
 * REST: GET rbnkit/v1/logs/<channel> (read only, `manage_options`).
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core;

defined( 'ABSPATH' ) || exit;

final class LogRest {

	public const NAMESPACE = 'rbnkit/v1';

	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/logs/(?P<channel>[a-z0-9_]+\.[a-z0-9_]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => array( self::class, 'permission' ),
				'args'                => array(
					'level'    => array(
						'type' => 'string',
						'enum' => array( 'debug', 'info', 'warning', 'error' ),
					),
					'type'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'from'     => array(
						'type'    => 'string',
						'pattern' => '^\d{4}-\d{2}-\d{2}$',
					),
					'to'       => array(
						'type'    => 'string',
						'pattern' => '^\d{4}-\d{2}-\d{2}$',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 8,
						'minimum' => 1,
						'maximum' => 50,
					),
				),
			)
		);
	}

	/** @return true|\WP_Error */
	public static function permission( \WP_REST_Request $request ) {
		/**
		 * Who may read a log channel through REST.
		 *
		 * @param bool   $allowed Default: the user can `manage_options`.
		 * @param string $channel Channel id.
		 */
		if ( apply_filters( 'rbnkit_core_can_read_logs', current_user_can( 'manage_options' ), (string) $request['channel'] ) ) {
			return true;
		}
		return new \WP_Error( 'rbnkit_forbidden', __( 'You are not allowed to read this log.', 'adminkit' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function handle( \WP_REST_Request $request ) {
		$channel = (string) $request['channel'];
		if ( ! isset( Log::channels()[ $channel ] ) ) {
			return new \WP_Error( 'rbnkit_unknown_channel', __( 'Unknown log channel.', 'adminkit' ), array( 'status' => 404 ) );
		}
		$result = Log::query(
			$channel,
			array(
				'level'    => (string) $request->get_param( 'level' ),
				'type'     => (string) $request->get_param( 'type' ),
				'from'     => (string) $request->get_param( 'from' ),
				'to'       => (string) $request->get_param( 'to' ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
			)
		);
		return rest_ensure_response(
			array(
				'status' => 'ok',
				'total'  => $result['total'],
				'items'  => $result['items'],
			)
		);
	}
}
