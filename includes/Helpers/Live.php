<?php
/**
 * AdminKit Helpers: marks a screen region that depends on a setting as "live".
 *
 * When an instant switch (`data-rbn-instant`) is applied, `rbn-admin-ui.js` fetches the same screen again and swaps
 * every region carrying these attributes, so the panel appears or disappears without a page reload. A screen never
 * writes the attributes by hand; this is the ONE place that prints them (WPRJ-738).
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Helpers;

defined( 'ABSPATH' ) || exit;

final class Live {

	/**
	 * Attribute string for the region's root element (already escaped, starts with a space).
	 *
	 * @param string   $id      Unique region id on the screen (`[a-z0-9_-]`); the swap pairs old and new regions by it.
	 * @param string[] $handles Script handles the region needs (`adminkit-dbmaint`). When the screen was loaded with the
	 *                          region off, those scripts are missing; the swap loads them from the fresh page.
	 * @param string[] $paths   Optional: swap only when one of these switch paths (`data-path`) was changed. Empty = any switch.
	 * @param string   $label   Optional: announced to screen readers after the swap is done (already translated).
	 */
	public static function attrs( string $id, array $handles = array(), array $paths = array(), string $label = '' ): string {
		$id = preg_replace( '/[^a-z0-9_\-]/i', '', $id );
		if ( null === $id || '' === $id ) {
			return '';
		}
		$out = ' data-rbn-live="' . esc_attr( $id ) . '"';
		$clean = static function ( array $list, string $pattern ): string {
			$list = array_filter( array_map( 'strval', $list ), static fn( string $v ): bool => 1 === preg_match( $pattern, $v ) );
			return implode( ' ', $list );
		};
		$handles = $clean( $handles, '/^[a-z0-9_\-]+$/i' );
		$paths   = $clean( $paths, '/^[a-z0-9_.\-]+$/i' );
		if ( '' !== $handles ) {
			$out .= ' data-rbn-live-scripts="' . esc_attr( $handles ) . '"';
		}
		if ( '' !== $paths ) {
			$out .= ' data-rbn-live-paths="' . esc_attr( $paths ) . '"';
		}
		if ( '' !== $label ) {
			$out .= ' data-rbn-live-label="' . esc_attr( $label ) . '"';
		}
		return $out;
	}
}
