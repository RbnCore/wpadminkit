<?php
/**
 * The ONE update card body of every RBN plugin (core API level 4).
 *
 * A plugin settings screen calls `UpdateCard::render( $slug, array( 'return_url' => ... ) )`; the card reads the central
 * updater (`Updater::status()`), so the state sentence, the last check time and the "Check now" form (nonce + Guard)
 * live in ONE place. Markup and text come from the shared kit (`rbn-admin-ui`, `adminkit` text domain), so all four
 * kits draw the same card — a plugin passes only its slug and the address the form comes back to.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Kit;

use AdminKit\Assets;
use AdminKit\Core\Updater;

defined( 'ABSPATH' ) || exit;

final class UpdateCard {

	/**
	 * Card body: installed version, state, last check, notes and the "Check now" form.
	 *
	 * The returned HTML is already escaped; echo it inside the caller's card (`rbn-card__b`). The form posts to
	 * `admin-post.php` (`adminkit_update_check`), so it must sit outside other forms.
	 *
	 * @param string               $slug Plugin folder = updater slug (`adminkit`, `wpseokit`, ...).
	 * @param array<string, mixed> $args {
	 *     @type string $return_url Admin URL the form comes back to (required; use the plugin's own page URL).
	 *     @type string $button     Escaped button inner HTML; empty = refresh icon + "Check now".
	 * }
	 */
	public static function render( string $slug, array $args = array() ): string {
		$status = Updater::status( $slug );
		$last   = (int) $status['checked_at'] > 0
			? esc_html( wp_date( 'd.m.Y H:i', (int) $status['checked_at'] ) )
			: esc_html__( 'never', 'adminkit' );
		$button = isset( $args['button'] ) && '' !== (string) $args['button']
			? (string) $args['button']
			: Assets::icon( 'refresh' ) . esc_html__( 'Check now', 'adminkit' );

		$html  = '<div class="rbn-update">';
		$html .= '<div class="rbn-field"><span class="rbn-label">' . esc_html__( 'Installed version', 'adminkit' ) . '</span><b>' . esc_html( (string) $status['installed'] ) . '</b></div>';
		$html .= '<div class="rbn-field"><span class="rbn-label">' . esc_html__( 'Update status', 'adminkit' ) . '</span><p>' . Updater::state_html( $status ) . '</p></div>';
		$html .= '<div class="rbn-field"><span class="rbn-label">' . esc_html__( 'Last check', 'adminkit' ) . '</span><span>' . $last . '</span></div>';
		$html .= '<p class="rbn-help">' . esc_html__( 'The package is verified with its SHA-256 digest and Ed25519 signature before installation; a package that cannot be verified is not installed. Automatic updates are turned on or off with the “Enable auto-updates” link on the Plugins page.', 'adminkit' ) . '</p>';
		$html .= '<p class="rbn-help">' . esc_html__( 'Only a standard GET request is sent; your site address or user information is not sent.', 'adminkit' ) . '</p>';
		$html .= '<p class="rbn-help"><a href="' . esc_url( admin_url( 'admin.php?page=adminkit#adminkit-network' ) ) . '">' . esc_html__( 'Update checks follow the RBN Kit network setting.', 'adminkit' ) . '</a></p>';

		$form = Updater::check_form( $slug, (string) ( $args['return_url'] ?? '' ), $button );
		if ( '' !== $form ) {
			$html .= '<div class="rbn-form-actions">' . $form . '</div>';
		}
		return $html . '</div>';
	}
}
