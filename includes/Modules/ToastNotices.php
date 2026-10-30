<?php
/**
 * Modül 6 — Toast bildirimleri: WordPress'in temel bildirimlerini toast'a çevirir, toast konumunu seçtirir.
 * Başka eklentilerin bildirimlerine HİÇBİR ayarla dokunulmaz; hata, uyarı ve düğmeli bildirim yerinde kalır.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Modules;

use AdminKit\Assets;
use AdminKit\Module;

defined( 'ABSPATH' ) || exit;

final class ToastNotices implements Module {

	private const META = 'adminkit_toast_pos';

	private const POSITIONS = array( 'bottom-right', 'top-right', 'bottom-left', 'top-left' );

	/** Çekirdeğin kendi bildirimini bastığı ekran tabanları: `#message` yalnız bunlarda çekirdek sayılır. */
	private const CORE_BASES = array( 'post', 'edit', 'upload', 'users', 'user', 'user-edit', 'profile', 'edit-tags', 'term', 'edit-comments', 'comment', 'themes', 'plugins', 'nav-menus', 'link-manager', 'link' );

	/** Çekirdeğin yönlendirmeden sonra eklediği sonuç bayrakları. */
	private const FLAGS = array( 'updated', 'message', 'settings-updated', 'update', 'deleted', 'trashed', 'untrashed', 'added', 'removed', 'spammed', 'unspammed', 'approved', 'unapproved', 'activate', 'deactivate', 'activate-multi', 'deactivate-multi' );

	/** @var array<string, mixed> */
	private array $settings = array(
		'convert'  => 'off',
		'position' => 'bottom-right',
	);

	public function id(): string {
		return 'toast_notices';
	}

	public function title(): string {
		return __( 'Toast notifications', 'adminkit' );
	}

	public function description(): string {
		return __( 'Shows WordPress core confirmations as toasts and lets you choose where toasts appear. Notices from other plugins are never touched.', 'adminkit' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'convert',
				'type'    => 'select',
				'label'   => __( 'Notices to show as toasts', 'adminkit' ),
				'default' => 'off',
				'options' => array(
					'off'         => __( 'Off (notices stay where WordPress puts them)', 'adminkit' ),
					'wp_and_kits' => __( 'WordPress core confirmations and RBN kit messages', 'adminkit' ),
				),
				'help'    => __( 'Only plain success and info confirmations are converted. Errors, warnings, notices with buttons or links, and notices from other plugins always stay in place.', 'adminkit' ),
			),
			array(
				'key'     => 'position',
				'type'    => 'select',
				'label'   => __( 'Toast position', 'adminkit' ),
				'default' => 'bottom-right',
				'options' => self::position_labels(),
				'help'    => __( 'Applies to every RBN kit toast. Each user can pick their own on their profile page.', 'adminkit' ),
			),
		);
	}

	/** @return array<string, string> */
	private static function position_labels(): array {
		return array(
			'bottom-right' => __( 'Bottom right', 'adminkit' ),
			'top-right'    => __( 'Top right', 'adminkit' ),
			'bottom-left'  => __( 'Bottom left', 'adminkit' ),
			'top-left'     => __( 'Top left', 'adminkit' ),
		);
	}

	public function boot( array $settings ): void {
		$this->settings = $settings;
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'show_user_profile', array( $this, 'profile_field' ) );
		add_action( 'edit_user_profile', array( $this, 'profile_field' ) );
		add_action( 'personal_options_update', array( $this, 'profile_save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'profile_save' ) );
		if ( 'wp_and_kits' === $settings['convert'] ) {
			add_action( 'admin_head', array( $this, 'print_guard' ), 1 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
			foreach ( array( 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' ) as $hook ) {
				add_action( $hook, 'ob_start', 0 );
				add_action( $hook, array( $this, 'notices_end' ), PHP_INT_MAX );
			}
		}
	}

	/**
	 * Çekirdeğin ekran içi `#message` kutusu `admin_notices` ile basılmaz; o kancadan `#message` çıktıysa kutunun kaynağı
	 * belirsizdir (başka eklenti olabilir): işaret konur, çevirici hiçbir `#message`'a dokunmaz.
	 */
	public function notices_end(): void {
		$html = (string) ob_get_clean();
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- kancaların kendi çıktısı aynen geri verilir.
		if ( preg_match( '/\bid\s*=\s*(["\'])message\1/i', $html ) ) {
			echo '<i id="adminkit-tc-ambiguous" hidden></i>';
		}
	}

	/** Etkin konum: kullanıcı tercihi site ayarını ezer. */
	public function position(): string {
		$user = (string) get_user_meta( get_current_user_id(), self::META, true );
		$site = (string) $this->settings['position'];
		$pick = in_array( $user, self::POSITIONS, true ) ? $user : $site;
		return in_array( $pick, self::POSITIONS, true ) ? $pick : 'bottom-right';
	}

	public function body_class( string $classes ): string {
		$pos = $this->position();
		return 'bottom-right' === $pos ? $classes : $classes . ' rbn-toast-' . $pos;
	}

	public function profile_field( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$own = (string) get_user_meta( $user->ID, self::META, true );
		echo '<table class="form-table" role="presentation"><tr><th><label for="adminkit_toast_pos">' . esc_html__( 'Toast position', 'adminkit' ) . '</label></th><td><select name="adminkit_toast_pos" id="adminkit_toast_pos">';
		echo '<option value=""' . selected( $own, '', false ) . '>' . esc_html__( 'Site default', 'adminkit' ) . '</option>';
		foreach ( self::position_labels() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $own, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Where AdminKit toasts appear for you. Overrides the site setting.', 'adminkit' ) . '</p></td></tr></table>';
	}

	/** WordPress profil formunun nonce'u ve yetkisi bu kancadan önce çekirdekte doğrulanır. */
	public function profile_save( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['adminkit_toast_pos'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$pick = sanitize_key( wp_unslash( $_POST['adminkit_toast_pos'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( in_array( $pick, self::POSITIONS, true ) ) {
			update_user_meta( $user_id, self::META, $pick );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}

	/**
	 * Bu istekte çekirdek bildirimi beklenen ekran mı? (JS ile aynı kural.)
	 *
	 * @return array{core: bool, settings: bool}
	 */
	private function expectation(): array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$base   = $screen ? (string) $screen->base : '';
		$flag   = false;
		foreach ( self::FLAGS as $name ) {
			if ( isset( $_GET[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- yalnız bayrağın varlığı okunur.
				$flag = true;
			}
		}
		return array(
			'core'     => $flag && in_array( $base, self::CORE_BASES, true ),
			'settings' => isset( $_GET['settings-updated'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * Titremeyi önler: yalnız çekirdek kuralıyla eşleşen kutular çizilmeden gizlenir.
	 * Betik gelmezse 4 sn sonra gizleme kalkar ve kutu görünür kalır.
	 */
	public function print_guard(): void {
		$want = $this->expectation();
		$sel  = array();
		$safe = ':not(.notice-error):not(.error):not(.notice-warning):not(.no-toast):not(.rbn-tc-keep):not(:has(a,button,form,input,select,textarea))';
		if ( $want['core'] ) {
			$sel[] = 'html.rbn-tc #wpbody-content #message.updated' . $safe;
			$sel[] = 'html.rbn-tc #wpbody-content #message.notice-success' . $safe;
		}
		if ( $want['settings'] ) {
			$sel[] = 'html.rbn-tc #wpbody-content #setting-error-settings_updated' . $safe;
		}
		if ( ! $sel ) {
			return;
		}
		echo '<style id="adminkit-toast-guard">' . implode( ',', $sel ) . '{display:none!important}</style>';
		echo '<script>(function(d){d.classList.add("rbn-tc");setTimeout(function(){d.classList.remove("rbn-tc")},4000)}(document.documentElement))</script>';
	}

	public function enqueue(): void {
		wp_enqueue_style( Assets::UI );
		wp_enqueue_script( Assets::UI );
		$want = $this->expectation();
		wp_enqueue_script(
			'adminkit-toastconvert',
			ADMINKIT_URL . 'assets/toastconvert.js',
			array( Assets::UI ),
			Assets::ver( 'toastconvert.js' ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_add_inline_script(
			'adminkit-toastconvert',
			'window.AdminKit=window.AdminKit||{};window.AdminKit.toastconvert=' . wp_json_encode(
				array(
					'core'     => $want['core'],
					'settings' => $want['settings'],
					'saved'    => __( 'Settings saved.' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- çekirdeğin kendi metni, karşılaştırma içindir.
				)
			) . ';',
			'before'
		);
	}
}
