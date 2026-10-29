<?php
/**
 * AdminKit Helpers: RBN Kit menu registration, page URLs, legacy redirects and action links.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Helpers;

defined( 'ABSPATH' ) || exit;

final class Menu {

	/**
	 * Registers a page under the single RBN Kit top-level menu.
	 *
	 * @param array<string, mixed> $item slug, title, menu_title, cap, callback, version.
	 */
	public static function add( array $item ): void {
		if ( class_exists( 'RbnKitMenu' ) ) {
			\RbnKitMenu::add( $item );
		}
	}

	/**
	 * Returns the screen hook identifier for a registered plugin page.
	 *
	 * @param string $slug Submenu slug.
	 */
	public static function hook( string $slug ): string {
		if ( class_exists( 'RbnKitMenu' ) ) {
			return \RbnKitMenu::hook( $slug );
		}
		return '';
	}

	/**
	 * Generates the admin URL for a plugin page.
	 *
	 * @param string               $slug Page slug.
	 * @param array<string, mixed> $args Additional query parameters.
	 */
	public static function page_url( string $slug, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Prepends an action link to the plugin action links array.
	 *
	 * @param array<int, string> $links Current action links.
	 * @param string             $url   Target URL.
	 * @param string             $label Link label.
	 * @return array<int, string>
	 */
	public static function action_link( array $links, string $url, string $label ): array {
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' );
		return $links;
	}
}
