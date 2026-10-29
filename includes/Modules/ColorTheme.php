<?php
/**
 * Modül 5 — Renk teması: yönetim paneli için hazır temalar ve özel renk (kullanıcı bazlı, canlı önizleme, WCAG AA).
 *
 * Yalnız yönetim panelinde CSS özel değişkenleri ve tek `<style id="adminkit-color">` üretir; ön yüze çıktı yoktur.
 * Modül kapalıyken WordPress'in kendi "Yönetici renk düzeni" seçimi aynen çalışır.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Modules;

use AdminKit\Assets;
use AdminKit\Color;
use AdminKit\Module;
use AdminKit\Settings;
use AdminKit\SettingsPage;

defined( 'ABSPATH' ) || exit;

final class ColorTheme implements Module {

	public const META    = 'adminkit_color_theme';
	public const PAGE    = 'adminkit-colors';
	public const NONCE   = 'adminkit_ct';
	private const FIELDS = array( 'scheme', 'top', 'side', 'accent', 'shell' );

	/** @var string Kişisel renk sayfasının kanca soneki. */
	private static string $page_hook = '';

	public function id(): string {
		return 'color_theme';
	}

	public function title(): string {
		return __( 'Color theme', 'adminkit' );
	}

	public function description(): string {
		return __( 'Changes the colors of the admin bar, side menu and links. Pick a ready theme or your own colors, per user, with a live preview.', 'adminkit' );
	}

	public function default_enabled(): bool {
		return false;
	}

	/** Kart anahtarının görünen adı (tek kaynak: modül aç/kapa anahtarı; ikinci bir anahtar yoktur). */
	public function switch_label(): string {
		return __( 'Use the color theme', 'adminkit' );
	}

	/** Anahtar kapalıyken kartta duran tek satırlık yönlendirme. */
	public function off_hint(): string {
		return __( 'To use it, turn the switch on and press Save.', 'adminkit' );
	}

	public function settings_schema(): array {
		$labels = array( 'wp' => __( 'WordPress default', 'adminkit' ) );
		foreach ( Color::themes() as $id => $t ) {
			$labels[ $id ] = $t['name'];
		}
		$labels['custom'] = __( 'Custom', 'adminkit' );
		return array(
			array(
				'key'     => 'allow_users',
				'type'    => 'checkbox',
				'label'   => __( 'Let users choose their own colors', 'adminkit' ),
				'default' => true,
				'help'    => __( 'Users pick their colors under Users → Admin colors. The choice below is the default for everyone who has not picked one.', 'adminkit' ),
			),
			array(
				'key'     => 'scheme',
				'type'    => 'select',
				'label'   => __( 'Default theme', 'adminkit' ),
				'default' => 'wp',
				'options' => $labels,
				'hidden'  => true,
			),
			array(
				'key'     => 'top',
				'type'    => 'color',
				'label'   => __( 'Top bar', 'adminkit' ),
				'default' => '#1d2327',
				'hidden'  => true,
			),
			array(
				'key'     => 'side',
				'type'    => 'color',
				'label'   => __( 'Side menu', 'adminkit' ),
				'default' => '#23282d',
				'hidden'  => true,
			),
			array(
				'key'     => 'accent',
				'type'    => 'color',
				'label'   => __( 'Accent', 'adminkit' ),
				'default' => '#2145e6',
				'hidden'  => true,
			),
			array(
				'key'     => 'shell',
				'type'    => 'select',
				'label'   => __( 'Shell tone', 'adminkit' ),
				'default' => 'koyu',
				'options' => array(
					'koyu' => __( 'Dark', 'adminkit' ),
					'acik' => __( 'Light', 'adminkit' ),
				),
				'hidden'  => true,
			),
			array(
				'key'    => 'ui',
				'type'   => 'custom',
				'label'  => __( 'Default colors', 'adminkit' ),
				'render' => array( $this, 'render_default_ui' ),
			),
		);
	}

	public function boot( array $settings ): void {
		add_action( 'admin_head', array( self::class, 'print_style' ), 99 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_post_adminkit_color_save', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_adminkit_color_reset', array( self::class, 'handle_reset' ) );
		if ( ! empty( $settings['allow_users'] ) ) {
			add_action( 'admin_menu', array( self::class, 'menu' ) );
		}
	}

	/** Modülün doğrulanmış ayarları (yalnız modül açıkken anlamlı). */
	private static function settings(): array {
		return Settings::for_module( new self() );
	}

	/**
	 * Bu kullanıcıya uygulanacak seçim.
	 *
	 * @return array{0: array<string, string>|null, 1: string} [seçim ya da null (WP düzeni), kaynak user|default]
	 */
	public static function resolve( array $settings, int $user_id ): array {
		$raw    = $settings;
		$source = 'default';
		if ( ! empty( $settings['allow_users'] ) && $user_id > 0 ) {
			$meta = get_user_meta( $user_id, self::META, true );
			if ( is_array( $meta ) && isset( $meta['scheme'] ) ) {
				$raw    = $meta;
				$source = 'user';
			}
		}
		return array( Color::normalize( $raw, $settings ), $source );
	}

	public static function print_style(): void {
		list( $scheme, $source ) = self::resolve( self::settings(), get_current_user_id() );
		if ( ! $scheme ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$editor = $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
		// Yüksek karşıtlık modunda hiçbir zaman; sistem koyu temasında yalnız yönetici varsayılanı pasif (kişisel seçim açıkça istenmiştir).
		$cond = '(not (forced-colors: active))' . ( 'user' === $source ? '' : ' and (not (prefers-color-scheme: dark))' );
		echo '<style id="adminkit-color">@media ' . $cond . '{' . wp_strip_all_tags( Color::css( $scheme, $editor ) ) . '}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- yalnız doğrulanmış hex.
	}

	public static function menu(): void {
		self::$page_hook = (string) add_users_page( __( 'Admin colors', 'adminkit' ), __( 'Admin colors', 'adminkit' ), 'read', self::PAGE, array( self::class, 'render_page' ) );
	}

	/**
	 * Arayüz varlıkları (stil, önizleme ve kontrast betiği). Kapı yalnız ekran kimliğidir: modül açık olsun olmasın
	 * Ayarlar → AdminKit ekranı tam arayüzle gelir. Modül açıkken `boot()` de çağırır; ikinci çağrı bir şey eklemez.
	 */
	public static function enqueue( string $hook ): void {
		if ( ( '' === SettingsPage::screen() || SettingsPage::screen() !== $hook ) && ( '' === self::$page_hook || self::$page_hook !== $hook ) ) {
			return;
		}
		wp_enqueue_style( Assets::UI );
		wp_enqueue_script( Assets::UI );
		if ( wp_script_is( 'adminkit-colortheme', 'enqueued' ) ) {
			return;
		}
		$themes = array();
		foreach ( array( 'wp' => Color::wp_default() ) + Color::themes() as $id => $t ) {
			$themes[ $id ] = array(
				'top'    => $t['top'],
				'side'   => $t['side'],
				'accent' => $t['accent'],
				'bg'     => $t['bg'],
				'shell'  => $t['shell'],
			);
		}
		Assets::load(
			'colortheme',
			array(
				'themes' => $themes,
				'tpl'    => Color::template( false ),
				'min'    => Color::MIN_RATIO,
				'bg'     => Color::PAGE_BG,
				'i18n'   => array(
					'pass'    => __( 'Pass', 'adminkit' ),
					'fail'    => __( 'Fail', 'adminkit' ),
					'invalid' => __( 'Write each color as #rrggbb to see the contrast.', 'adminkit' ),
					'low'     => __( 'Too low — applied as %s instead', 'adminkit' ),
					'fix'     => __( 'Fix', 'adminkit' ),
					'menu'    => __( 'Side menu text on menu', 'adminkit' ),
					'bar'     => __( 'Top bar text on bar', 'adminkit' ),
					'link'    => __( 'Accent (links, buttons) on white', 'adminkit' ),
					'page'    => __( 'Accent on page background', 'adminkit' ),
					'preview' => __( 'Preview — not saved yet', 'adminkit' ),
					'saved'   => __( 'Showing the saved colors', 'adminkit' ),
				),
			)
		);
	}

	// ---- Arayüz -------------------------------------------------------------------------------------------

	/** Ayarlar → AdminKit kartındaki yönetici varsayılanı. */
	public function render_default_ui( string $base, array $settings ): void {
		self::widget( $base, $settings, 'default' );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these colors.', 'adminkit' ), '', array( 'response' => 403 ) );
		}
		$settings = self::settings();
		$allowed  = ! empty( $settings['allow_users'] );
		$meta     = get_user_meta( get_current_user_id(), self::META, true );
		$values   = is_array( $meta ) && isset( $meta['scheme'] ) ? array_merge( $settings, $meta ) : $settings;
		$values['scheme'] = in_array( $values['scheme'] ?? '', Color::scheme_ids(), true ) ? $values['scheme'] : 'wp';
		?>
		<div class="wrap rbn-wrap"<?php echo Assets::tone_attr(); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<div class="rbn-head"><h1><?php esc_html_e( 'Admin colors', 'adminkit' ); ?></h1></div>
			<?php // phpcs:ignore WordPress.Security.NonceVerification ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<?php echo Assets::note( 'ok', __( 'Your colors were saved.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php elseif ( isset( $_GET['reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<?php echo Assets::note( 'ok', __( 'Your colors were reset.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endif; ?>
			<?php if ( ! $allowed ) : ?>
				<p class="rbn-lead"><?php esc_html_e( 'The site administrator chose the admin colors. You cannot change them.', 'adminkit' ); ?></p>
			<?php else : ?>
				<p class="rbn-lead"><?php esc_html_e( 'Only you see these colors. The preview changes this page right away; nothing is saved until you press Save.', 'adminkit' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="adminkit_color_save" />
					<?php wp_nonce_field( self::NONCE, 'adminkit_ct_nonce' ); ?>
					<?php self::widget( 'adminkit_ct', $values, 'user' ); ?>
					<p class="ak-ct__actions">
						<button type="submit" class="rbn-btn rbn-btn--pri"><?php esc_html_e( 'Save', 'adminkit' ); ?></button>
						<button type="button" class="rbn-btn" data-ck-cancel><?php esc_html_e( 'Cancel preview', 'adminkit' ); ?></button>
						<button type="submit" class="rbn-btn" name="action" value="adminkit_color_reset" formnovalidate><?php esc_html_e( 'Back to default', 'adminkit' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Tema ızgarası + özel renkler + kontrast tablosu (JS bunları canlı önizler).
	 *
	 * @param array<string, mixed> $v Geçerli değerler (scheme, top, side, accent, shell).
	 */
	private static function widget( string $base, array $v, string $ctx ): void {
		$scheme = (string) ( $v['scheme'] ?? 'wp' );
		$all    = array( 'wp' => Color::wp_default() ) + Color::themes() + array( 'custom' => array( 'name' => __( 'Custom', 'adminkit' ) ) );
		$fields = array(
			'top'    => __( 'Top bar', 'adminkit' ),
			'side'   => __( 'Side menu', 'adminkit' ),
			'accent' => __( 'Accent', 'adminkit' ),
		);
		// Özel kartın şeridi seçili özel renkleri gösterir (JS alanlar değiştikçe günceller).
		$all['custom'] += array(
			'top'    => Color::hex( $v['top'] ?? '' ) ?: '#1d2327',
			'side'   => Color::hex( $v['side'] ?? '' ) ?: '#23282d',
			'accent' => Color::hex( $v['accent'] ?? '' ) ?: '#2145e6',
			'bg'     => Color::PAGE_BG,
		);
		$stored = get_option( Settings::OPTION, array() );
		$on     = is_array( $stored ) && ! empty( $stored['modules']['color_theme']['enabled'] );
		?>
		<div class="ak-ct" data-ck-ctx="<?php echo esc_attr( $ctx ); ?>">
			<?php if ( 'default' === $ctx && ! $on && 'wp' !== $scheme ) : ?>
				<?php echo Assets::note( 'warn', __( 'Your chosen theme is saved but not applied, because the color theme is off. Turn the switch on and press Save to apply it.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endif; ?>
			<fieldset class="ak-ct__themes" role="radiogroup">
				<legend><?php esc_html_e( 'Theme', 'adminkit' ); ?></legend>
				<?php foreach ( $all as $id => $t ) : ?>
					<label class="ak-ct__opt" data-ck-card="<?php echo esc_attr( (string) $id ); ?>">
						<input type="radio" name="<?php echo esc_attr( $base . '[scheme]' ); ?>" value="<?php echo esc_attr( (string) $id ); ?>" data-ck="scheme" <?php checked( $scheme, $id ); ?> />
						<span class="ak-ct__sw" aria-hidden="true">
							<i style="background:<?php echo esc_attr( $t['top'] ); ?>"></i><i style="background:<?php echo esc_attr( $t['side'] ); ?>"></i><i style="background:<?php echo esc_attr( $t['accent'] ); ?>"></i><i style="background:<?php echo esc_attr( $t['bg'] ); ?>"></i>
						</span>
						<span class="ak-ct__nm"><?php echo esc_html( (string) $t['name'] ); ?></span>
						<span class="ak-ct__tick" aria-hidden="true"></span>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<fieldset class="ak-ct__custom">
				<legend><?php esc_html_e( 'Custom colors', 'adminkit' ); ?></legend>
				<?php foreach ( $fields as $key => $label ) : ?>
					<div class="ak-ct__row">
						<label for="adminkit-<?php echo esc_attr( $ctx . '-' . $key ); ?>"><?php echo esc_html( $label ); ?></label>
						<input type="color" value="<?php echo esc_attr( (string) ( Color::hex( $v[ $key ] ?? '' ) ?: '#000000' ) ); ?>" data-ck="<?php echo esc_attr( $key ); ?>-pick" aria-label="<?php echo esc_attr( $label ); ?>" />
						<input type="text" class="rbn-input rbn-input--s rbn-input--mono" id="adminkit-<?php echo esc_attr( $ctx . '-' . $key ); ?>" name="<?php echo esc_attr( $base . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) ( Color::hex( $v[ $key ] ?? '' ) ?: '' ) ); ?>" maxlength="7" size="9" pattern="#[0-9a-fA-F]{6}" data-ck="<?php echo esc_attr( $key ); ?>" />
					</div>
				<?php endforeach; ?>
				<div class="ak-ct__row">
					<label for="adminkit-<?php echo esc_attr( $ctx ); ?>-shell"><?php esc_html_e( 'Shell tone', 'adminkit' ); ?></label>
					<select class="rbn-select" id="adminkit-<?php echo esc_attr( $ctx ); ?>-shell" name="<?php echo esc_attr( $base . '[shell]' ); ?>" data-ck="shell">
						<option value="koyu" <?php selected( (string) ( $v['shell'] ?? 'koyu' ), 'koyu' ); ?>><?php esc_html_e( 'Dark (white text)', 'adminkit' ); ?></option>
						<option value="acik" <?php selected( (string) ( $v['shell'] ?? 'koyu' ), 'acik' ); ?>><?php esc_html_e( 'Light (dark text)', 'adminkit' ); ?></option>
					</select>
				</div>
			</fieldset>
			<div class="ak-ct__rep">
				<strong><?php esc_html_e( 'Contrast (WCAG AA needs at least 4.5:1)', 'adminkit' ); ?></strong>
				<ul data-ck-report aria-live="polite"></ul>
				<p class="rbn-help ak-ct__st" data-ck-status role="status"></p>
			</div>
			<?php if ( 'default' === $ctx ) : ?>
				<p>
					<button type="button" class="rbn-btn" data-ck-cancel><?php esc_html_e( 'Cancel preview', 'adminkit' ); ?></button>
					<?php wp_nonce_field( self::NONCE, 'adminkit_ct_nonce', false ); ?>
					<?php // Ayar formunun gizli "action=update" alanını, sonra gelen düğme değeri ezer. ?>
					<button type="submit" class="rbn-btn" name="action" value="adminkit_color_reset" formaction="<?php echo esc_url( admin_url( 'admin-post.php?scope=default' ) ); ?>" formnovalidate><?php esc_html_e( 'Back to default', 'adminkit' ); ?></button>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ---- İşleyiciler --------------------------------------------------------------------------------------

	/** Ham form → saklanacak kişisel seçim. */
	public static function clean_choice( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array( 'scheme' => in_array( $raw['scheme'] ?? '', Color::scheme_ids(), true ) ? (string) $raw['scheme'] : 'wp' );
		foreach ( array( 'top' => '#1d2327', 'side' => '#23282d', 'accent' => '#2145e6' ) as $k => $def ) {
			$out[ $k ] = Color::hex( $raw[ $k ] ?? '' ) ?: $def;
		}
		$out['shell'] = ( $raw['shell'] ?? '' ) === 'acik' ? 'acik' : 'koyu';
		return $out;
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these colors.', 'adminkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE, 'adminkit_ct_nonce' );
		if ( empty( self::settings()['allow_users'] ) ) {
			wp_die( esc_html__( 'The site administrator chose the admin colors. You cannot change them.', 'adminkit' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- doğrulandı; clean_choice her alanı doğrular.
		update_user_meta( get_current_user_id(), self::META, self::clean_choice( wp_unslash( $_POST['adminkit_ct'] ?? array() ) ) );
		wp_safe_redirect( add_query_arg( 'saved', '1', admin_url( 'users.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function handle_reset(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these colors.', 'adminkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE, 'adminkit_ct_nonce' );
		// phpcs:ignore WordPress.Security.NonceVerification -- doğrulandı.
		$scope = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'user';
		if ( 'default' === $scope ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You are not allowed to manage these settings.', 'adminkit' ), '', array( 'response' => 403 ) );
			}
			$stored = get_option( Settings::OPTION, array() );
			if ( is_array( $stored ) && isset( $stored['modules']['color_theme'] ) && is_array( $stored['modules']['color_theme'] ) ) {
				foreach ( self::FIELDS as $key ) {
					unset( $stored['modules']['color_theme'][ $key ] );
				}
				update_option( Settings::OPTION, $stored );
			}
			wp_safe_redirect( SettingsPage::url() );
			exit;
		}
		delete_user_meta( get_current_user_id(), self::META );
		wp_safe_redirect( add_query_arg( 'reset', '1', admin_url( 'users.php?page=' . self::PAGE ) ) );
		exit;
	}
}
