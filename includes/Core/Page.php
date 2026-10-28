<?php
/**
 * RBN Kit → Cache & Logs: a plain overview of cache groups and log channels, plus "Clear cache".
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core;

defined( 'ABSPATH' ) || exit;

final class Page {

	private const SLUG   = 'adminkit-core';
	private const ACTION = 'adminkit_core_clear';

	public static function hooks(): void {
		\RbnKitMenu::add(
			array(
				'slug'       => self::SLUG,
				'title'      => __( 'Cache & Logs', 'adminkit' ),
				'menu_title' => __( 'Cache & Logs', 'adminkit' ),
				'cap'        => 'manage_options',
				'callback'   => array( self::class, 'render' ),
				'version'    => ADMINKIT_VERSION,
			)
		);
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'clear' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public static function can_manage(): bool {
		/**
		 * Who may open the Cache & Logs page and clear caches.
		 *
		 * @param bool $allowed Default: the user can `manage_options`.
		 */
		return (bool) apply_filters( 'rbnkit_core_can_manage', current_user_can( 'manage_options' ) );
	}

	public static function assets( string $hook ): void {
		$screen = \RbnKitMenu::hook( self::SLUG );
		if ( '' !== $screen && $screen === $hook ) {
			wp_enqueue_style( 'adminkit-admin', ADMINKIT_URL . 'assets/admin.css', array(), \AdminKit\Assets::ver( 'admin.css' ) );
		}
	}

	/** admin-post handler: clears one group (`group` = id) or all groups (`group` empty). */
	public static function clear(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to clear the cache.', 'adminkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$group = isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['group'] ) ) : '';
		$done  = '' === $group ? Cache::flush_all() : ( Cache::flush_group( $group ) ? 1 : 0 );
		wp_safe_redirect( self::url( array( 'cleared' => $done ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'adminkit' ), '', array( 'response' => 403 ) );
		}
		$stats    = Cache::stats();
		$channels = Log::channels();
		$cleared  = isset( $_GET['cleared'] ) ? (int) $_GET['cleared'] : -1; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		?>
		<div class="wrap rbn-wrap">
			<div class="rbn-head"><div class="rbn-head__id"><h1><?php esc_html_e( 'Cache & Logs', 'adminkit' ); ?></h1><span class="rbn-chip"><?php echo esc_html( 'v' . ADMINKIT_VERSION ); ?></span></div></div>
			<?php if ( $cleared >= 0 ) : ?>
				<div class="notice notice-success inline" role="status"><p>
					<?php
					/* translators: %d: number of cleared cache groups */
					echo esc_html( sprintf( _n( '%d cache group cleared.', '%d cache groups cleared.', $cleared, 'adminkit' ), $cleared ) );
					?>
				</p></div>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Cache groups', 'adminkit' ); ?></h2>
			<?php if ( ! $stats ) : ?>
				<p class="rbn-muted"><?php esc_html_e( 'No cache group is registered.', 'adminkit' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Group', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Storage', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Lifetime (s)', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Version', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Writes since last clear', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last cleared', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'adminkit' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $stats as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['id'] ); ?></td>
							<td><?php echo esc_html( $row['driver'] ); ?></td>
							<td><?php echo esc_html( (string) $row['ttl'] ); ?></td>
							<td><?php echo esc_html( (string) $row['version'] ); ?></td>
							<td><?php echo esc_html( (string) $row['sets'] ); ?></td>
							<td><?php echo $row['flushed_at'] ? esc_html( wp_date( 'Y-m-d H:i', (int) $row['flushed_at'] ) ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php self::clear_form( $row['id'], __( 'Clear', 'adminkit' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><?php self::clear_form( '', __( 'Clear all cache', 'adminkit' ), true ); ?></p>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Log channels', 'adminkit' ); ?></h2>
			<?php if ( ! $channels ) : ?>
				<p class="rbn-muted"><?php esc_html_e( 'No log channel is registered.', 'adminkit' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Channel', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Levels', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Keep (days)', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Row limit', 'adminkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Rows now', 'adminkit' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $channels as $id => $def ) : ?>
						<tr>
							<td><?php echo esc_html( $id ); ?></td>
							<td><?php echo esc_html( implode( ', ', $def['levels'] ) ); ?></td>
							<td><?php echo esc_html( (string) $def['retention_days'] ); ?></td>
							<td><?php echo esc_html( (string) $def['max_rows'] ); ?></td>
							<td><?php echo esc_html( (string) Log::count( $id ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function clear_form( string $group, string $label, bool $primary = false ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adminkit-core-clear">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( self::ACTION ) ); ?>" />
			<button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
