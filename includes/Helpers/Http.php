<?php
/**
 * AdminKit Helpers: Safe HTTP GET transport for small text assets.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Helpers;

defined( 'ABSPATH' ) || exit;

final class Http {

	/**
	 * Safely fetches a small remote text file (e.g. checksum or detached signature) with timeout and size cap.
	 *
	 * @param string $url       Remote resource URL.
	 * @param int    $max_bytes Maximum response body size in bytes.
	 * @param int    $timeout   Timeout in seconds.
	 * @return string|\WP_Error Response body string or WP_Error on failure.
	 */
	public static function get_text( string $url, int $max_bytes = 4096, int $timeout = 15 ) {
		$ver = defined( 'ADMINKIT_VERSION' ) ? ADMINKIT_VERSION : '0.1.0';
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
				'limit_response_size' => $max_bytes,
				'user-agent'          => 'AdminKit/' . $ver,
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new \WP_Error(
				'adminkit_fetch_fail',
				__( 'Could not download the digest or signature file. Nothing was installed.', 'adminkit' )
			);
		}
		return (string) wp_remote_retrieve_body( $res );
	}
}
