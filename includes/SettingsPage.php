<?php
/**
 * RBN Kit → AdminKit: her modül bir kart (ad, açıklama, aç/kapat anahtarı, ayarlar).
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	private const SLUG = 'adminkit';

	public static function hooks(): void {
		\RbnKitMenu::add(
			array(
				'slug'       => self::SLUG,
				'title'      => __( 'AdminKit', 'adminkit' ),
				'menu_title' => __( 'AdminKit', 'adminkit' ),
				'cap'        => 'manage_options',
				'callback'   => array( self::class, 'render' ),
				'order'      => 90,
				'version'    => ADMINKIT_VERSION,
			)
		);
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/** Ayar sayfasının adresi (RBN Kit → AdminKit). */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/** Bu sayfanın ekran kimliği (varlık kapıları için); menü kaydedilmediyse boş dize. */
	public static function screen(): string {
		return \RbnKitMenu::hook( self::SLUG );
	}

	/**
	 * @param string[] $links Eklenti satırı bağlantıları.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'adminkit' ) . '</a>' );
		return $links;
	}

	public static function assets( string $hook ): void {
		if ( '' === self::screen() || self::screen() !== $hook ) {
			return;
		}
		wp_enqueue_style( 'adminkit-admin', ADMINKIT_URL . 'assets/admin.css', array(), Assets::ver( 'admin.css' ) );
	}

	/** Koyu ton: WP "Gece yarısı" şeması; süzgeçle ezilebilir. */
	private static function tone(): string {
		$tone = (string) apply_filters( 'adminkit_tone', 'midnight' === (string) get_user_option( 'admin_color' ) ? 'koyu' : 'acik' );
		return 'koyu' === $tone ? ' data-tone="koyu"' : '';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'adminkit' ), '', array( 'response' => 403 ) );
		}
		$modules = Registry::all();
		$open    = 0;
		foreach ( $modules as $module ) {
			$open += Settings::for_module( $module )['enabled'] ? 1 : 0;
		}
		?>
		<div class="wrap rbn-wrap"<?php echo self::tone(); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<div class="rbn-head">
				<div class="rbn-head__id">
					<h1><?php echo esc_html__( 'AdminKit', 'adminkit' ); ?></h1>
					<span class="rbn-chip"><?php echo esc_html( 'v' . ADMINKIT_VERSION ); ?></span>
					<span class="rbn-chip rbn-chip--ok"><span class="rbn-dot"></span>
						<?php
						/* translators: 1: open module count, 2: total module count */
						echo esc_html( sprintf( __( '%1$d of %2$d modules on', 'adminkit' ), $open, count( $modules ) ) );
						?>
					</span>
				</div>
			</div>
			<p class="rbn-muted"><?php esc_html_e( 'Each module adds one feature to the admin area. Turn on only what you need. Nothing here changes the public site.', 'adminkit' ); ?></p>
			<form method="post" action="options.php" class="adminkit-form">
				<?php settings_fields( Settings::GROUP ); ?>
				<ul class="rbn-cards">
					<?php foreach ( $modules as $module ) : ?>
						<?php self::card( $module ); ?>
					<?php endforeach; ?>
				</ul>
				<?php submit_button( __( 'Save changes', 'adminkit' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function card( Module $module ): void {
		$id       = $module->id();
		$settings = Settings::for_module( $module );
		$base     = Settings::OPTION . '[modules][' . $id . ']';
		$on       = (bool) $settings['enabled'];
		?>
		<li class="rbn-card" id="adminkit-<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $base . '[present]' ); ?>" value="1" />
			<div class="rbn-card__top">
				<div>
					<h2><?php echo esc_html( $module->title() ); ?></h2>
					<p class="rbn-muted"><?php echo esc_html( $module->description() ); ?></p>
				</div>
				<label class="rbn-switch">
					<input type="checkbox" role="switch" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="1" <?php checked( $on ); ?> />
					<span class="rbn-switch__ui" aria-hidden="true"></span>
					<span class="rbn-switch__txt">
						<?php echo esc_html( $on ? __( 'On', 'adminkit' ) : __( 'Off', 'adminkit' ) ); ?>
						<span class="rbn-sr"> — <?php echo esc_html( $module->title() ); ?></span>
					</span>
				</label>
			</div>
			<?php if ( $module->settings_schema() ) : ?>
				<div class="rbn-card__body">
					<?php foreach ( $module->settings_schema() as $field ) : ?>
						<?php
						if ( empty( $field['hidden'] ) ) {
							self::field( $field, $base, $settings );
						}
						?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</li>
		<?php
	}

	/**
	 * @param array<string, mixed> $field    Alan tanımı.
	 * @param string               $base     Ad öneki.
	 * @param array<string, mixed> $settings Modül ayarları.
	 */
	private static function field( array $field, string $base, array $settings ): void {
		$key   = (string) $field['key'];
		$name  = $base . '[' . $key . ']';
		$fid   = 'adminkit-' . sanitize_html_class( str_replace( array( '[', ']' ), '-', $name ) );
		$value = $settings[ $key ] ?? Settings::default_of( $field );
		$help  = isset( $field['help'] ) ? '<span class="rbn-muted rbn-help" id="' . esc_attr( $fid ) . '-h">' . esc_html( (string) $field['help'] ) . '</span>' : '';
		$desc  = isset( $field['help'] ) ? ' aria-describedby="' . esc_attr( $fid ) . '-h"' : '';
		echo '<div class="rbn-field">';
		switch ( $field['type'] ) {
			case 'custom':
				call_user_func( $field['render'], $base, $settings );
				break;
			case 'color':
				echo '<label for="' . esc_attr( $fid ) . '">' . esc_html( (string) $field['label'] ) . '</label><input type="text" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" maxlength="7" size="9" />' . $help; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'checkbox':
				$locked = ! empty( $field['locked'] );
				echo '<label class="rbn-check"><input type="checkbox" id="' . esc_attr( $fid ) . '"' . $desc . ( $locked ? ' checked disabled' : ' name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $value ), true, false ) ) . ' /> ' . esc_html( (string) $field['label'] ) . '</label>' . $help; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'number':
				echo '<label for="' . esc_attr( $fid ) . '">' . esc_html( (string) $field['label'] ) . '</label>';
				echo '<span class="rbn-num"><input type="number" class="small-text" id="' . esc_attr( $fid ) . '"' . $desc . ' name="' . esc_attr( $name ) . '" min="' . (int) $field['min'] . '" max="' . (int) $field['max'] . '" step="' . (int) ( $field['step'] ?? 1 ) . '" value="' . (int) $value . '" />' . ( isset( $field['unit'] ) ? ' <span class="rbn-muted">' . esc_html( (string) $field['unit'] ) . '</span>' : '' ) . '</span>' . $help; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'select':
				echo '<label for="' . esc_attr( $fid ) . '">' . esc_html( (string) $field['label'] ) . '</label><select id="' . esc_attr( $fid ) . '"' . $desc . ' name="' . esc_attr( $name ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
				foreach ( (array) $field['options'] as $opt => $label ) {
					echo '<option value="' . esc_attr( (string) $opt ) . '"' . selected( (string) $value, (string) $opt, false ) . '>' . esc_html( (string) $label ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</select>' . $help; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'checklist':
				$options = is_callable( $field['options'] ) ? (array) call_user_func( $field['options'] ) : (array) $field['options'];
				echo '<fieldset><legend>' . esc_html( (string) $field['label'] ) . '</legend>' . $help; // phpcs:ignore WordPress.Security.EscapeOutput
				if ( ! $options ) {
					echo '<p class="rbn-muted">' . esc_html__( 'Nothing to choose from.', 'adminkit' ) . '</p>';
				}
				foreach ( $options as $opt => $label ) {
					echo '<label class="rbn-check"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( (string) $opt ) . '"' . checked( in_array( (string) $opt, (array) $value, true ), true, false ) . ' /> ' . esc_html( (string) $label ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</fieldset>';
				break;
		}
		echo '</div>';
	}
}
