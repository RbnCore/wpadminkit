<?php
/**
 * Central network permission: one setting decides whether RBN plugins may contact GitHub (update checks, plugin catalog).
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core;

defined( 'ABSPATH' ) || exit;

final class Network {

	/** Option: '1' = RBN plugins may contact GitHub (default), '0' = no request is sent. Not autoloaded. */
	public const OPT_GITHUB = 'adminkit_network_github';

	/**
	 * May an RBN plugin contact GitHub now?
	 *
	 * @param string $context Who asks: 'catalog' or 'updates:<plugin>'.
	 */
	public static function github_allowed( string $context = '' ): bool {
		$allowed = '0' !== (string) get_option( self::OPT_GITHUB, '1' );
		/**
		 * Filters the GitHub permission. A filter may only narrow or widen per context; the setting is the default.
		 *
		 * @param bool   $allowed Setting value.
		 * @param string $context 'catalog' or 'updates:<plugin>'.
		 */
		return (bool) apply_filters( 'rbnkit_core_github_allowed', $allowed, $context );
	}

	/**
	 * Settings-page sanitizer: only '1' is on; an unchecked (unsent) box is off.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize( $value ): string {
		return '1' === (string) $value ? '1' : '0';
	}
}
