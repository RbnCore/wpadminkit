<?php
/**
 * RBN Kit → AdminKit: her modül bir kart (ad, açıklama, aç/kapat anahtarı, ayarlar).
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit;

use AdminKit\Helpers\Header;

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
				'version'    => ADMINKIT_VERSION,
			)
		);
		add_action( 'admin_init', array( self::class, 'handle_redirect_notices' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/** Post-Redirect-Get bildirimleri: URL'den parametreleri temizler, Toast::flash'a taşır. */
	public static function handle_redirect_notices(): void {
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		if ( ! empty( $_GET['settings-updated'] ) ) {
			Helpers\Toast::flash( 'success', __( 'Settings saved.', 'adminkit' ) );
			wp_safe_redirect( remove_query_arg( 'settings-updated' ) );
			exit;
		}
		if ( isset( $_GET['rbnkit_update'] ) ) {
			$v = sanitize_key( wp_unslash( (string) $_GET['rbnkit_update'] ) );
			if ( 'checked' === $v ) {
				Helpers\Toast::flash( 'success', __( 'Update check finished.', 'adminkit' ) );
			} elseif ( 'off' === $v ) {
				Helpers\Toast::flash( 'info', __( 'The update check is off; nothing was checked.', 'adminkit' ) );
			}
			wp_safe_redirect( remove_query_arg( 'rbnkit_update' ) );
			exit;
		}
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
		wp_enqueue_style( Assets::UI );
		wp_enqueue_script( Assets::UI );
		wp_enqueue_style( 'adminkit-admin', ADMINKIT_URL . 'assets/admin.css', array( Assets::UI ), Assets::ver( 'admin.css' ) );
		// Renk kartının arayüzü (palet kartları, önizleme, kontrast) modül açık/kapalı olsa da bu ekranda hep gelir.
		Modules\ColorTheme::enqueue( $hook );
		Assets::load( 'settings', array(), false );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'adminkit' ), '', array( 'response' => 403 ) );
		}
		$modules = Registry::all();
		$open    = 0;
		$small   = array();
		$big     = array();
		// Büyük ayar arayüzü olan modül (tab_label() tanımlı) kendi sekmesini alır; küçükler "Modules" sekmesinde kalır.
		$tabs = array( 'modules' => __( 'Modules', 'adminkit' ) );
		foreach ( $modules as $module ) {
			$open += Settings::for_module( $module )['enabled'] ? 1 : 0;
			if ( method_exists( $module, 'tab_label' ) ) {
				$big[]                 = $module;
				$tabs[ $module->id() ] = (string) $module->tab_label();
			} else {
				$small[] = $module;
			}
		}
		// Modüller · Renk teması (büyük modül sekmeleri) · Genel: en sık kullanılandan en nadire.
		$tabs['general'] = __( 'General', 'adminkit' );
		$tab = isset( $_GET['tab'] ) && isset( $tabs[ (string) $_GET['tab'] ] ) ? (string) $_GET['tab'] : 'modules'; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- yalnız sekme seçimi, anahtar listeyle eşlenir.
		?>
		<div class="wrap rbn-wrap"<?php echo Assets::tone_attr(); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<?php
			echo Header::render( // phpcs:ignore WordPress.Security.EscapeOutput -- Header::render() kaçışlar.
				array(
					'name'    => 'AdminKit',
					'version' => ADMINKIT_VERSION,
					/* translators: 1: open module count, 2: total module count */
					'status'  => array( 'label' => sprintf( __( '%1$d of %2$d modules on', 'adminkit' ), $open, count( $modules ) ), 'tone' => 'ok' ),
				)
			);
			?>
			<p class="rbn-lead"><?php esc_html_e( 'Each module adds one feature to the admin area. Turn on only what you need. Nothing here changes the public site.', 'adminkit' ); ?></p>
			<div class="rbn-tabs" role="tablist" aria-label="<?php esc_attr_e( 'AdminKit settings tabs', 'adminkit' ); ?>" data-rbn-tabs>
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a role="tab" id="adminkit-tab-<?php echo esc_attr( $key ); ?>" class="rbn-tabs__tab" href="<?php echo esc_url( add_query_arg( 'tab', $key, self::url() ) ); ?>" data-key="<?php echo esc_attr( $key ); ?>" aria-controls="adminkit-panel-<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $tab === $key ? 'true' : 'false'; ?>" tabindex="<?php echo $tab === $key ? '0' : '-1'; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
			<form id="adminkit-settings-form" method="post" action="options.php">
				<?php settings_fields( Settings::GROUP ); ?>
				<?php foreach ( $tabs as $key => $label ) : ?>
					<?php if ( 'general' === $key ) { continue; } ?>
					<div role="tabpanel" id="adminkit-panel-<?php echo esc_attr( $key ); ?>" class="rbn-tabpanel" aria-labelledby="adminkit-tab-<?php echo esc_attr( $key ); ?>" tabindex="0"<?php echo $tab === $key ? '' : ' hidden'; ?>>
						<ul class="rbn-cards ak-cards<?php echo 'modules' === $key ? '' : ' ak-cards--one'; ?>">
							<?php foreach ( 'modules' === $key ? $small : array_filter( $big, static fn( Module $m ): bool => $m->id() === $key ) as $module ) : ?>
								<?php self::card( $module ); ?>
							<?php endforeach; ?>
						</ul>
						<div class="rbn-form-actions">
							<button type="submit" class="rbn-btn rbn-btn--pri"><?php esc_html_e( 'Save changes', 'adminkit' ); ?></button>
							<?php if ( 'color_theme' === $key ) : ?>
								<button type="button" class="rbn-btn" data-ck-cancel><?php esc_html_e( 'Cancel preview', 'adminkit' ); ?></button>
								<button type="submit" form="adminkit-color-reset-default" class="rbn-btn rbn-btn--danger" data-rbn-confirm="<?php esc_attr_e( 'Reset colors to default? Your custom color settings will be deleted; this action cannot be undone.', 'adminkit' ); ?>" data-rbn-confirm-title="<?php esc_attr_e( 'Back to default', 'adminkit' ); ?>" data-rbn-confirm-ok="<?php esc_attr_e( 'Back to default', 'adminkit' ); ?>"><?php esc_html_e( 'Back to default', 'adminkit' ); ?></button>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</form>
			<form id="adminkit-color-reset-default" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?scope=default' ) ); ?>" style="display:none">
				<input type="hidden" name="action" value="adminkit_color_reset" />
				<?php wp_nonce_field( Modules\ColorTheme::NONCE, 'adminkit_ct_nonce' ); ?>
			</form>
			<div role="tabpanel" id="adminkit-panel-general" class="rbn-tabpanel" aria-labelledby="adminkit-tab-general" tabindex="0"<?php echo $tab === 'general' ? '' : ' hidden'; ?>>
				<?php self::network_section(); ?>
				<?php self::updates_card(); ?>
			</div>
		</div>
		<?php
	}

	/** Central network setting (not a module): one line, shared by every RBN plugin. */
	private static function network_section(): void {
		?>
		<section class="ak-network" id="adminkit-network" aria-labelledby="adminkit-network-t">
			<h2 id="adminkit-network-t"><?php esc_html_e( 'Network', 'adminkit' ); ?></h2>
			<div class="rbn-field">
				<label class="rbn-check"><input type="checkbox" name="<?php echo esc_attr( Core\Network::OPT_GITHUB ); ?>" form="adminkit-settings-form" value="1" <?php checked( Core\Network::github_allowed() ); ?> aria-describedby="adminkit-network-h" /> <?php esc_html_e( 'Allow RBN plugins to check GitHub for updates and the plugin catalog', 'adminkit' ); ?></label>
				<span class="rbn-help" id="adminkit-network-h"><?php esc_html_e( 'Off: no network request is sent; update checks and catalog installs are disabled for all RBN plugins.', 'adminkit' ); ?></span>
			</div>
			<div class="rbn-form-actions"><button type="submit" form="adminkit-settings-form" class="rbn-btn rbn-btn--pri"><?php esc_html_e( 'Save changes', 'adminkit' ); ?></button></div>
		</section>
		<?php
	}

	/** Version / update check card. The body is the ONE shared card (Kit\UpdateCard); it sits outside the settings form. */
	private static function updates_card(): void {
		?>
		<section class="rbn-card ak-updates" id="adminkit-updates" aria-labelledby="adminkit-updates-t">
			<div class="rbn-card__h">
				<h2 id="adminkit-updates-t"><?php esc_html_e( 'Updates', 'adminkit' ); ?></h2>
			</div>
			<div class="rbn-card__b">
				<?php echo Kit\UpdateCard::render( 'adminkit', array( 'return_url' => add_query_arg( 'tab', 'general', self::url() ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UpdateCard escapes its own output. ?>
			</div>
		</section>
		<?php
	}

	private static function card( Module $module ): void {
		$id       = $module->id();
		$settings = Settings::for_module( $module );
		$base     = Settings::OPTION . '[modules][' . $id . ']';
		$on       = (bool) $settings['enabled'];
		// İsteğe bağlı: kartın anahtarına görünen ad ve kapalıyken tek satırlık yönlendirme (Module arayüzüne dahil değildir).
		$label = method_exists( $module, 'switch_label' ) ? (string) $module->switch_label() : '';
		$hint  = method_exists( $module, 'off_hint' ) ? (string) $module->off_hint() : '';
		?>
		<li class="rbn-card" id="adminkit-<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $base . '[present]' ); ?>" value="1" />
			<div class="rbn-card__h ak-card-top">
				<div>
					<h2><?php echo esc_html( $module->title() ); ?></h2>
					<p><?php echo esc_html( $module->description() ); ?></p>
				</div>
				<label class="rbn-switch">
					<input type="checkbox" role="switch" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="1" <?php checked( $on ); ?> />
					<span class="rbn-switch__x" aria-hidden="true"></span>
					<span class="ak-switch-txt">
						<?php if ( $label ) : ?>
							<?php echo esc_html( $label ); ?>
						<?php else : ?>
							<span class="rbn-switch__st" data-on="<?php echo esc_attr__( 'On', 'adminkit' ); ?>" data-off="<?php echo esc_attr__( 'Off', 'adminkit' ); ?>"><?php echo esc_html( $on ? __( 'On', 'adminkit' ) : __( 'Off', 'adminkit' ) ); ?></span>
							<span class="rbn-sr"> — <?php echo esc_html( $module->title() ); ?></span>
						<?php endif; ?>
					</span>
				</label>
			</div>
			<?php if ( $hint ) : ?>
				<p class="rbn-help ak-offhint"><?php echo esc_html( $hint ); ?></p>
			<?php endif; ?>
			<?php if ( $module->settings_schema() ) : ?>
				<div class="rbn-card__b">
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
		$help  = isset( $field['help'] ) ? '<span class="rbn-help" id="' . esc_attr( $fid ) . '-h">' . esc_html( (string) $field['help'] ) . '</span>' : '';
		$desc  = isset( $field['help'] ) ? ' aria-describedby="' . esc_attr( $fid ) . '-h"' : '';
		echo '<div class="rbn-field">';
		switch ( $field['type'] ) {
			case 'custom':
				call_user_func( $field['render'], $base, $settings );
				break;
			case 'color':
				echo '<label for="' . esc_attr( $fid ) . '">' . esc_html( (string) $field['label'] ) . '</label><input type="text" class="rbn-input rbn-input--s rbn-input--mono" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" maxlength="7" size="9" />' . $help; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'checkbox':
				$locked = ! empty( $field['locked'] );
				echo '<label class="rbn-check"><input type="checkbox" id="' . esc_attr( $fid ) . '"' . $desc . ( $locked ? ' checked disabled' : ' name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $value ), true, false ) ) . ' /> ' . esc_html( (string) $field['label'] ) . '</label>' . $help; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'number':
				echo '<label for="' . esc_attr( $fid ) . '">' . esc_html( (string) $field['label'] ) . '</label>';
				echo '<span class="ak-num"><input type="number" class="rbn-input rbn-input--s" id="' . esc_attr( $fid ) . '"' . $desc . ' name="' . esc_attr( $name ) . '" min="' . (int) $field['min'] . '" max="' . (int) $field['max'] . '" step="' . (int) ( $field['step'] ?? 1 ) . '" value="' . (int) $value . '" />' . ( isset( $field['unit'] ) ? ' <span class="rbn-muted">' . esc_html( (string) $field['unit'] ) . '</span>' : '' ) . '</span>' . $help; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'select':
				echo '<label for="' . esc_attr( $fid ) . '">' . esc_html( (string) $field['label'] ) . '</label><select class="rbn-select" id="' . esc_attr( $fid ) . '"' . $desc . ' name="' . esc_attr( $name ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
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
