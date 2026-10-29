<?php
/**
 * AdminKit Helpers: SVG sprite and icon output.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Helpers;

defined( 'ABSPATH' ) || exit;

final class Icons {

	private static bool $sprite_done = false;

	/**
	 * Outputs the consolidated SVG symbol sprite once per request.
	 *
	 * @param string|null $fallback_file Optional fallback SVG sprite file path if AdminKit sprite is unavailable.
	 */
	public static function sprite( ?string $fallback_file = null ): void {
		if ( self::$sprite_done ) {
			return;
		}
		self::$sprite_done = true;

		$file = defined( 'ADMINKIT_DIR' ) ? ADMINKIT_DIR . 'assets/ui/rbn-icons.svg' : '';
		if ( '' !== $file && is_readable( $file ) ) {
			echo (string) file_get_contents( $file ); // phpcs:ignore WordPress.Security.EscapeOutput,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fixed internal SVG.
			return;
		}

		if ( null !== $fallback_file && is_readable( $fallback_file ) ) {
			echo (string) file_get_contents( $fallback_file ); // phpcs:ignore WordPress.Security.EscapeOutput,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fixed internal SVG.
		}
	}

	/**
	 * Returns an SVG markup referencing a symbol in the sprite.
	 *
	 * @param string $name Symbol name without the 'i-' prefix.
	 */
	public static function icon( string $name ): string {
		return '<svg class="rbn-ico" aria-hidden="true"><use href="#i-' . esc_attr( $name ) . '"/></svg>';
	}

	/**
	 * Returns the color tone ('koyu' or 'acik') based on user color scheme or dark mode plugins.
	 */
	public static function tone(): string {
		$dark = false;
		if ( function_exists( 'get_user_option' ) ) {
			$dark = 'midnight' === (string) get_user_option( 'admin_color' );
		}
		if ( defined( 'WP_DARK_MODE_VERSION' ) || defined( 'WP_DARK_MODE_PLUGIN_FILE' ) ) {
			$dark = true;
		}
		$tone = (string) apply_filters( 'rbnkit_tone', $dark ? 'koyu' : 'acik' );
		return 'koyu' === $tone ? 'koyu' : 'acik';
	}

	/**
	 * Returns the data-tone attribute if dark mode is active.
	 */
	public static function tone_attr(): string {
		return 'koyu' === self::tone() ? ' data-tone="koyu"' : '';
	}
}
