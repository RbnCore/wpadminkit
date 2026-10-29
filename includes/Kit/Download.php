<?php
/**
 * Package download with a hard size cap. One implementation for both download paths (central updater and catalog
 * installer); the callers only decide WHICH address is allowed, this class decides HOW MUCH may arrive.
 *
 * - Before the body is transferred the size is asked for (`HEAD`); a package that is announced too big is refused
 *   without a single byte of body being transferred.
 * - The transfer itself carries a byte cap (`limit_response_size`): the HTTP transport cuts the body while it arrives,
 *   so an oversized package never fills the disk.
 * - After the transfer the file size is measured again: a body that was cut is refused and the temporary file is
 *   deleted (no leftovers). The second line of defence is `Installer::check_package()`, which measures the file again.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Kit;

defined( 'ABSPATH' ) || exit;

final class Download {

	/** How many bytes may arrive past the limit before the transfer is stopped. */
	private const CUT_MARGIN = 1;

	/**
	 * Downloads a package into a temporary file and returns its path.
	 *
	 * @param string $url     Package address.
	 * @param int    $timeout Timeout in seconds.
	 * @return string|\WP_Error Temporary file path, or an error (the temporary file is deleted on every error).
	 */
	public static function package( string $url, int $timeout = 300 ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$limit = Installer::MAX_ZIP;

		// 1) The announced size. A package that is too big is refused before its body is transferred.
		$announced = self::announced_size( $url, min( 15, $timeout ) );
		if ( null !== $announced && $announced > $limit ) {
			return new \WP_Error( 'adminkit_bad_size', __( 'The package size is outside the expected range. Nothing was installed.', 'adminkit' ) );
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$tmp  = wp_tempnam( '' !== $path ? basename( $path ) : '' );
		if ( ! $tmp ) {
			return new \WP_Error( 'adminkit_download_fail', __( 'Could not download the package. Nothing was installed.', 'adminkit' ) );
		}
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $limit + self::CUT_MARGIN,
				'user-agent'          => 'AdminKit/' . ADMINKIT_VERSION,
			)
		);
		if ( is_wp_error( $res ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'adminkit_download_fail', __( 'Could not download the package. Nothing was installed.', 'adminkit' ) );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'adminkit_download_fail', __( 'Could not download the package. Nothing was installed.', 'adminkit' ) );
		}
		// 2) The received size. A body that was cut by the transport stops here; the temporary file goes away.
		$size = is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
		if ( $size > $limit ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'adminkit_bad_size', __( 'The package size is outside the expected range. Nothing was installed.', 'adminkit' ) );
		}
		return $tmp;
	}

	/**
	 * The size the server announces, in bytes. Null when the server does not say (the download then runs with the
	 * transport cap only).
	 *
	 * @param string $url     Package address.
	 * @param int    $timeout Timeout in seconds.
	 * @return int|null
	 */
	private static function announced_size( string $url, int $timeout ): ?int {
		$res = wp_safe_remote_head(
			$url,
			array(
				'timeout'             => $timeout,
				'limit_response_size' => 1024,
				'user-agent'          => 'AdminKit/' . ADMINKIT_VERSION,
			)
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$headers = wp_remote_retrieve_headers( $res );
		$all     = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : array();
		$length  = is_array( $all ) ? (string) ( $all['content-length'] ?? '' ) : '';
		$length  = trim( $length );
		return ctype_digit( $length ) ? (int) $length : null;
	}
}