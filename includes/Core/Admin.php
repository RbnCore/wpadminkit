<?php
/**
 * RBN Kit → Overview → "Cache" and "Logs" tabs: cache group table with Clear buttons, and the shared log list component.
 *
 * The log list is a registered script/style pair (`adminkit-core-logs`); any RBN plugin can enqueue the handle and print one
 * `<div data-rbnkit-logs data-channel="plugin.name">` (see docs/cekirdek.md §9).
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const ACTION = 'adminkit_core_clear';
	public const HANDLE = 'adminkit-core-logs';

	public static function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'clear' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'register_assets' ), 5 );
	}

	public static function can_manage(): bool {
		/**
		 * Who may open the Cache tab and clear caches.
		 *
		 * @param bool $allowed Default: the user can `manage_options`.
		 */
		return (bool) apply_filters( 'rbnkit_core_can_manage', current_user_can( 'manage_options' ) );
	}

	/** Registers (does not enqueue) the reusable log list component for every admin screen. */
	public static function register_assets(): void {
		// Stil dosyası yok: bileşen ortak kitin `.rbn-log*` sınıflarını kullanır; tutamaç yalnız kiti bağımlılık olarak getirir.
		wp_register_style( self::HANDLE, false, array( \AdminKit\Assets::UI ), \AdminKit\Assets::ver( 'ui/rbn-admin-ui.css' ) );
		wp_register_script( self::HANDLE, ADMINKIT_URL . 'assets/logs.js', array( \AdminKit\Assets::UI ), \AdminKit\Assets::ver( 'logs.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
		wp_localize_script(
			self::HANDLE,
			'rbnkitLogs',
			array(
				'root'  => esc_url_raw( rest_url( LogRest::NAMESPACE . '/logs/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'time'       => __( 'Time', 'adminkit' ),
					'level'      => __( 'Level', 'adminkit' ),
					'event'      => __( 'Event', 'adminkit' ),
					'message'    => __( 'Message', 'adminkit' ),
					'context'    => __( 'Context', 'adminkit' ),
					'channel'    => __( 'Log channel', 'adminkit' ),
					'allLevels'  => __( 'All levels', 'adminkit' ),
					'eventType'  => __( 'Event type', 'adminkit' ),
					'from'       => __( 'From', 'adminkit' ),
					'to'         => __( 'To', 'adminkit' ),
					'reset'      => __( 'Reset filters', 'adminkit' ),
					'loading'    => __( 'Loading log entries…', 'adminkit' ),
					'empty'      => __( 'There are no log entries yet.', 'adminkit' ),
					'noMatch'    => __( 'No entries match these filters.', 'adminkit' ),
					'error'      => __( 'The log could not be loaded.', 'adminkit' ),
					'retry'      => __( 'Try again', 'adminkit' ),
					'prev'       => __( 'Previous page', 'adminkit' ),
					'next'       => __( 'Next page', 'adminkit' ),
					'show'       => __( 'Show details', 'adminkit' ),
					'hide'       => __( 'Hide details', 'adminkit' ),
					'noContext'  => __( 'No details', 'adminkit' ),
					'debug'      => __( 'Debug', 'adminkit' ),
					'info'       => __( 'Info', 'adminkit' ),
					'warning'    => __( 'Warning', 'adminkit' ),
					'errorLevel' => __( 'Error', 'adminkit' ),
					/* translators: 1: current page, 2: total pages, 3: total entries */
					'pageOf'     => __( 'Page %1$s of %2$s · Total: %3$s', 'adminkit' ),
				),
			)
		);
	}

	/** admin-post handler: clears one group (`group` = id) or all groups (`group` empty). */
	public static function clear(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to clear the cache.', 'adminkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$group = isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['group'] ) ) : '';
		$done  = '' === $group ? Cache::flush_all() : ( Cache::flush_group( $group ) ? 1 : 0 );
		wp_safe_redirect( \AdminKit\Kit\Overview::url( 'cache', array( 'cleared' => $done ) ) );
		exit;
	}

	/** Result line after a clear (`?cleared=N`). */
	public static function cleared_note(): void {
		if ( ! isset( $_GET['cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display only.
			return;
		}
		$n = absint( $_GET['cleared'] ); // phpcs:ignore WordPress.Security.NonceVerification
		/* translators: %d: number of cleared cache groups */
		$text = $n > 0 ? sprintf( _n( '%d cache group cleared.', '%d cache groups cleared.', $n, 'adminkit' ), $n ) : __( 'No cache group was cleared.', 'adminkit' );
		echo \AdminKit\Assets::note( $n > 0 ? 'ok' : 'warn', $text ); // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::note() kaçışlar.
	}

	public static function cache_panel(): void {
		if ( ! self::can_manage() ) {
			echo \AdminKit\Assets::note( 'warn', __( 'You are not allowed to manage the cache.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::note() kaçışlar.
			return;
		}
		$stats   = Cache::stats();
		$plugins = array_values( array_unique( wp_list_pluck( $stats, 'plugin' ) ) );
		sort( $plugins );
		echo '<p class="rbn-lead">' . esc_html__( 'Cache groups registered by RBN plugins. Clearing a group makes its next request rebuild the data.', 'adminkit' ) . '</p>';
		if ( ! $stats ) {
			echo self::empty_state( __( 'No cache group is registered.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- empty_state() kaçışlar.
			return;
		}
		echo '<div class="rbn-bar">';
		if ( count( $plugins ) > 1 ) {
			echo '<div class="rbn-field"><label for="rbn-kit-cache-plugin">' . esc_html__( 'Plugin', 'adminkit' ) . '</label><select id="rbn-kit-cache-plugin" class="rbn-select" data-rbn-row-filter="rbn-kit-cache-rows"><option value="">' . esc_html__( 'All plugins', 'adminkit' ) . '</option>';
			foreach ( $plugins as $plugin ) {
				echo '<option value="' . esc_attr( $plugin ) . '">' . esc_html( $plugin ) . '</option>';
			}
			echo '</select></div>';
		}
		self::clear_form( '', __( 'Clear all caches', 'adminkit' ), __( 'Clear every cache group?', 'adminkit' ), true );
		echo '</div>';
		?>
		<section class="rbn-card"><div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw">
		<table class="rbn-table rbn-table--stack">
			<caption class="rbn-sr"><?php esc_html_e( 'Cache groups', 'adminkit' ); ?></caption>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Plugin', 'adminkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Group', 'adminkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Lifetime', 'adminkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Writes', 'adminkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last cleared', 'adminkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Version', 'adminkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Action', 'adminkit' ); ?></th>
			</tr></thead>
			<tbody id="rbn-kit-cache-rows">
			<?php foreach ( $stats as $row ) : ?>
				<tr data-plugin="<?php echo esc_attr( $row['plugin'] ); ?>">
					<th scope="row" data-label="<?php esc_attr_e( 'Plugin', 'adminkit' ); ?>"><?php echo esc_html( $row['plugin'] ); ?></th>
					<td data-label="<?php esc_attr_e( 'Group', 'adminkit' ); ?>"><?php echo esc_html( $row['name'] ); ?></td>
					<td data-label="<?php esc_attr_e( 'Lifetime', 'adminkit' ); ?>"><?php echo esc_html( human_time_diff( 0, (int) $row['ttl'] ) ); ?></td>
					<td class="rbn-num" data-label="<?php esc_attr_e( 'Writes', 'adminkit' ); ?>"><?php echo esc_html( number_format_i18n( (int) $row['sets'] ) ); ?></td>
					<td data-label="<?php esc_attr_e( 'Last cleared', 'adminkit' ); ?>"><?php echo $row['flushed_at'] ? esc_html( wp_date( 'Y-m-d H:i', (int) $row['flushed_at'] ) ) : '<span aria-hidden="true">&mdash;</span><span class="rbn-sr">' . esc_html__( 'Never', 'adminkit' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- parçalar kaçışlı. ?></td>
					<td data-label="<?php esc_attr_e( 'Version', 'adminkit' ); ?>"><?php echo esc_html( (string) $row['version'] ); ?></td>
					<td data-label="<?php esc_attr_e( 'Action', 'adminkit' ); ?>">
						<?php
						/* translators: %s: cache group id */
						self::clear_form( $row['id'], __( 'Clear', 'adminkit' ), sprintf( __( 'Clear the cache group %s?', 'adminkit' ), $row['id'] ), false, $row['id'] );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div></div></section>
		<div class="rbn-empty" id="rbn-kit-cache-none" hidden><h2><?php esc_html_e( 'No cache group for this plugin.', 'adminkit' ); ?></h2></div>
		<?php
	}

	public static function logs_panel(): void {
		$channels = Log::channels();
		echo '<p class="rbn-lead">' . esc_html__( 'Recent events written by RBN plugins. Personal data is removed before an entry is stored.', 'adminkit' ) . '</p>';
		if ( ! $channels ) {
			echo self::empty_state( __( 'No log channel is registered.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- empty_state() kaçışlar.
			return;
		}
		$list = array();
		foreach ( $channels as $id => $def ) {
			$list[] = array(
				'id'     => $id,
				'plugin' => $def['plugin'],
				'name'   => $def['name'],
				'levels' => array_values( $def['levels'] ),
			);
		}
		echo '<div data-rbnkit-logs data-channels="' . esc_attr( (string) wp_json_encode( $list ) ) . '" data-channel="' . esc_attr( (string) $list[0]['id'] ) . '" data-per-page="8" data-filters="1"></div>';
	}

	/** Kit boş durumu (`.rbn-empty`). */
	private static function empty_state( string $title ): string {
		return '<div class="rbn-empty"><span class="rbn-empty__i">' . \AdminKit\Assets::icon( 'inbox' ) . '</span><h2>' . esc_html( $title ) . '</h2></div>';
	}

	private static function clear_form( string $group, string $label, string $confirm, bool $primary = false, string $sr = '' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-rbn-confirm="<?php echo esc_attr( $confirm ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( self::ACTION ) ); ?>" />
			<button type="submit" class="rbn-btn<?php echo $primary ? ' rbn-btn--pri' : ''; ?>"><?php echo esc_html( $label ); ?><?php echo '' !== $sr ? '<span class="rbn-sr"> — ' . esc_html( $sr ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
		</form>
		<?php
	}
}
