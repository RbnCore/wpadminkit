<?php
/**
 * RBN Kit → Genel bakış: "Kurulu", "Eklenti kataloğu", "Cache" ve "Logs" sekmeleri.
 *
 * `rbn_kit_overview_callback` süzgeciyle ortak menü yardımcısına bağlanır; AdminKit yoksa yardımcının en küçük listesi kalır.
 * Sekmeler gerçek bağlantıdır (`?page=rbn-kit&tab=kurulu|katalog|cache|logs`); ortak kit betiği (`rbn-admin-ui`) ok tuşlarını,
 * anlık geçişi ve adres senkronunu ekler. Arayüz yalnız ortak kitin sınıflarını kullanır; burada yalnız düzen (`ak-`) vardır.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Kit;

use AdminKit\Assets;
use AdminKit\Core\Admin;
use AdminKit\Core\Network;
use AdminKit\Helpers\Header;

defined( 'ABSPATH' ) || exit;

final class Overview {

	public const PAGE = 'rbn-kit';

	public static function hooks(): void {
		add_filter( 'rbn_kit_overview_callback', array( self::class, 'callback' ) );
		add_action( 'admin_init', array( self::class, 'handle_redirect_notices' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		Installer::init();
	}

	/** Post-Redirect-Get bildirimleri: URL'den parametreleri temizler, Toast::flash'a taşır. */
	public static function handle_redirect_notices(): void {
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}
		$redirect = false;
		if ( isset( $_GET['cleared'] ) ) {
			$n = absint( $_GET['cleared'] );
			if ( $n > 0 ) {
				\AdminKit\Helpers\Toast::flash( 'success', sprintf( _n( '%d cache group cleared.', '%d cache groups cleared.', $n, 'adminkit' ), $n ) );
			} else {
				\AdminKit\Helpers\Toast::flash( 'warning', __( 'No cache group was cleared.', 'adminkit' ) );
			}
			$redirect = true;
		}
		if ( isset( $_GET['rbnkit_update'] ) ) {
			$v = sanitize_key( wp_unslash( (string) $_GET['rbnkit_update'] ) );
			if ( 'checked' === $v ) {
				\AdminKit\Helpers\Toast::flash( 'success', __( 'Update check finished.', 'adminkit' ) );
			} elseif ( 'off' === $v ) {
				\AdminKit\Helpers\Toast::flash( 'info', __( 'The update check is off; nothing was checked.', 'adminkit' ) );
			}
			$redirect = true;
		}
		if ( $redirect ) {
			wp_safe_redirect( remove_query_arg( array( 'cleared', 'rbnkit_update' ) ) );
			exit;
		}
	}

	/** @param mixed $current Önceki çizici. */
	public static function callback( $current ) {
		unset( $current );
		return array( self::class, 'render' );
	}

	/** Sekme anahtarları (sıra = görünüm sırası). */
	public const TABS = array( 'kurulu', 'katalog', 'cache', 'logs' );

	public static function url( string $tab = 'kurulu', array $args = array() ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => in_array( $tab, self::TABS, true ) ? $tab : 'kurulu',
			) + $args,
			admin_url( 'admin.php' )
		);
	}

	private static function is_screen( string $hook ): bool {
		$screen = \RbnKitMenu::hook( self::PAGE );
		return '' !== $screen && $screen === $hook;
	}

	public static function assets( string $hook ): void {
		if ( ! self::is_screen( $hook ) ) {
			return;
		}
		wp_enqueue_style( Assets::UI );
		wp_enqueue_script( Assets::UI );
		wp_enqueue_style( Admin::HANDLE );
		wp_enqueue_script( Admin::HANDLE );
		wp_enqueue_script( 'adminkit-kit', ADMINKIT_URL . 'assets/kit.js', array( Assets::UI ), Assets::ver( 'kit.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	}

	/** Ayar sayfası adresi: RBN Kit alt öğesiyse admin.php, değilse Ayarlar altı sayfa. '' = bilinmiyor. */
	public static function settings_url( array $item ): string {
		$slug = $item['settings_slug'];
		if ( '' !== \RbnKitMenu::hook( $slug ) ) {
			return admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
		}
		global $submenu;
		foreach ( (array) ( $submenu['options-general.php'] ?? array() ) as $row ) {
			if ( isset( $row[2] ) && $row[2] === $slug ) {
				return admin_url( 'options-general.php?page=' . rawurlencode( $slug ) );
			}
		}
		return '';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'adminkit' ), '', array( 'response' => 403 ) );
		}
		$tab   = isset( $_GET['tab'] ) && in_array( $_GET['tab'], self::TABS, true ) ? (string) $_GET['tab'] : 'kurulu'; // phpcs:ignore WordPress.Security.NonceVerification -- yalnız sekme seçimi.
		$note  = Installer::take_note();
		$items = Catalog::items();
		$rows  = array();
		foreach ( $items as $item ) {
			$rows[] = array(
				'item'  => $item,
				'state' => Catalog::state( $item ),
				'rel'   => Catalog::release( $item ),
			);
		}
		$tabs  = array(
			'kurulu'  => __( 'Installed', 'adminkit' ),
			'katalog' => __( 'Plugin catalog', 'adminkit' ),
			'cache'   => __( 'Cache', 'adminkit' ),
			'logs'    => __( 'Logs', 'adminkit' ),
		);
		$icons = array( 'kurulu' => 'home', 'katalog' => 'down', 'cache' => 'refresh', 'logs' => 'list' );
		?>
		<div class="wrap rbn-wrap"<?php echo Assets::tone_attr(); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<?php echo Header::render( array( 'name' => 'RBN Kit', 'title' => (string) ( $tabs[ $tab ] ?? '' ), 'title_hidden' => true, 'version' => ADMINKIT_VERSION, 'status' => array( 'label' => __( 'Active', 'adminkit' ), 'tone' => 'ok' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Header::render() kaçışlar. ?>
			<?php
			if ( null !== $note ) {
				$ok   = 'hata' !== $note['code'];
				$text = $ok ? Installer::note_text( $note['code'] ) : $note['message'];
				echo Assets::note( $ok ? 'ok' : 'err', '' !== $text ? $text : __( 'The action could not be completed.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::note() kaçışlar.
			}
			?>
			<div class="rbn-tabs" role="tablist" aria-label="<?php esc_attr_e( 'RBN Kit tabs', 'adminkit' ); ?>" data-rbn-tabs>
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a role="tab" id="rbn-kit-tab-<?php echo esc_attr( $key ); ?>" class="rbn-tabs__tab" href="<?php echo esc_url( self::url( $key ) ); ?>" data-key="<?php echo esc_attr( $key ); ?>" aria-controls="rbn-kit-panel-<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $tab === $key ? 'true' : 'false'; ?>" tabindex="<?php echo $tab === $key ? '0' : '-1'; ?>"><?php echo Assets::icon( $icons[ $key ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::icon() kaçışlar. ?><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
			<?php foreach ( $tabs as $key => $label ) : ?>
				<div role="tabpanel" id="rbn-kit-panel-<?php echo esc_attr( $key ); ?>" class="rbn-tabpanel" aria-labelledby="rbn-kit-tab-<?php echo esc_attr( $key ); ?>" tabindex="0"<?php echo $tab === $key ? '' : ' hidden'; ?>>
					<?php
					if ( 'kurulu' === $key ) {
						self::installed_panel( $rows );
					} elseif ( 'katalog' === $key ) {
						self::catalog_panel( $rows );
					} elseif ( 'cache' === $key ) {
						Admin::cache_panel();
					} else {
						Admin::logs_panel();
					}
					?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/** @param array<int, array<string, mixed>> $rows Katalog satırları. */
	private static function installed_panel( array $rows ): void {
		$any = false;
		echo '<ul class="rbn-cards">';
		foreach ( $rows as $row ) {
			$item  = $row['item'];
			$state = $row['state'];
			if ( ! $state['installed'] ) {
				continue;
			}
			$any    = true;
			$update = is_array( $row['rel'] ) && 'ok' === $row['rel']['status'] && is_array( $row['rel']['release'] ) && version_compare( $row['rel']['release']['version'], $state['version'], '>' ) ? $row['rel']['release']['version'] : '';
			$url    = $state['active'] ? self::settings_url( $item ) : '';
			echo '<li class="rbn-card"><div class="rbn-card__h"><div class="rbn-idrow"><span class="rbn-icobox">' . Assets::icon( $item['icon'] ) . '</span><div><h2>' . esc_html( $item['name'] ) . '</h2><div class="rbn-card__ver"><span>' . esc_html( 'v' . $state['version'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::icon() kaçışlar.
			if ( '' !== $update ) {
				/* translators: %s: new version */
				echo '<span class="rbn-badge rbn-badge--warn">' . esc_html( sprintf( __( 'Update available: %s', 'adminkit' ), $update ) ) . '</span>';
			}
			echo '</div></div></div></div><div class="rbn-card__foot rbn-card__foot--split"><div class="rbn-card__st">';
			echo '<span class="rbn-chip' . ( $state['active'] ? ' rbn-chip--ok' : '' ) . '"><span class="rbn-dot"></span>' . esc_html( $state['active'] ? __( 'Active', 'adminkit' ) : __( 'Not active', 'adminkit' ) ) . '</span>';
			echo '</div><div class="rbn-card__ops">';
			if ( ! $state['active'] ) {
				self::activate_form( $item );
			} elseif ( '' !== $url ) {
				echo '<a class="rbn-btn rbn-btn--sm" href="' . esc_url( $url ) . '">' . esc_html__( 'Go to settings', 'adminkit' ) . '<span class="rbn-sr"> — ' . esc_html( $item['name'] ) . '</span></a>';
			}
			echo '</div></div></li>';
		}
		echo '</ul>';
		if ( ! $any ) {
			echo '<div class="rbn-empty"><span class="rbn-empty__i">' . Assets::icon( 'inbox' ) . '</span><h2>' . esc_html__( 'No RBN plugins are installed.', 'adminkit' ) . '</h2></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::icon() kaçışlar.
		}
	}

	/** @param array<int, array<string, mixed>> $rows Katalog satırları. */
	private static function catalog_panel( array $rows ): void {
		$live = Network::github_allowed( 'catalog' );
		echo '<p class="rbn-lead">' . esc_html__( 'WordPress plugins by RBN. Installation takes one click; a package is not installed if its SHA-256 digest and signature cannot be verified.', 'adminkit' ) . '</p>';
		if ( ! $live ) {
			echo Assets::note( 'info', __( 'The catalog is offline: the GitHub lookup is turned off in the AdminKit settings. This page makes no network request; the “Install” buttons are disabled.', 'adminkit' ), '<a class="rbn-btn rbn-btn--sm" href="' . esc_url( \AdminKit\SettingsPage::url() . '#adminkit-network' ) . '">' . esc_html__( 'Open the setting', 'adminkit' ) . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::note() kaçışlar.
		}
		if ( ! Installer::file_mods_allowed() ) {
			echo Assets::note( 'info', __( 'Plugin installation is turned off on this site (DISALLOW_FILE_MODS); the “Install” and “Activate” buttons are not shown.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::note() kaçışlar.
		}
		echo '<ul class="rbn-cards">';
		foreach ( $rows as $row ) {
			self::card( $row );
		}
		echo '</ul>';
	}

	/** @param array<string, mixed> $row Katalog satırı. */
	private static function card( array $row ): void {
		$item  = $row['item'];
		$state = $row['state'];
		$rel   = $row['rel'];
		echo '<li class="rbn-card" id="rbn-kit-' . esc_attr( $item['id'] ) . '"><div class="rbn-card__h"><div class="rbn-idrow"><span class="rbn-icobox">' . Assets::icon( $item['icon'] ) . '</span><h2>' . esc_html( $item['name'] ) . '</h2></div></div><div class="rbn-card__b"><p>' . esc_html( $item['tagline'] ) . '</p></div><div class="rbn-card__foot rbn-card__foot--split"><div class="rbn-card__st">'; // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::icon() kaçışlar.
		if ( $state['installed'] ) {
			echo '<span class="rbn-badge rbn-badge--ok">' . Assets::icon( 'check' ) . esc_html__( 'Installed', 'adminkit' ) . '</span></div><div class="rbn-card__ops">'; // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::icon() kaçışlar.
			if ( ! $state['active'] ) {
				if ( Installer::file_mods_allowed() ) {
					self::activate_form( $item );
				}
			} else {
				$url = self::settings_url( $item );
				if ( '' !== $url ) {
					echo '<a class="rbn-btn rbn-btn--link rbn-btn--sm" href="' . esc_url( $url ) . '">' . esc_html__( 'Go to settings', 'adminkit' ) . '<span class="rbn-sr"> — ' . esc_html( $item['name'] ) . '</span></a>';
				}
			}
		} elseif ( 'released' !== $item['status'] ) {
			echo '<span class="rbn-badge rbn-badge--mute">' . esc_html__( 'Coming soon', 'adminkit' ) . '</span></div><div class="rbn-card__ops">';
		} elseif ( ! Installer::file_mods_allowed() || ! current_user_can( 'install_plugins' ) ) {
			echo '<span class="rbn-help">' . esc_html__( 'Plugins cannot be installed on this site.', 'adminkit' ) . '</span></div><div class="rbn-card__ops">';
		} else {
			$ready = is_array( $rel ) && 'ok' === $rel['status'] && is_array( $rel['release'] );
			if ( ! $ready ) {
				$why = ! Network::github_allowed( 'catalog' ) ? __( 'Offline mode', 'adminkit' ) : ( is_array( $rel ) && 'none' === $rel['status'] ? __( 'No release found', 'adminkit' ) : __( 'Could not get version information', 'adminkit' ) );
				echo '<span class="rbn-help">' . esc_html( $why ) . '</span>';
			}
			echo '</div><div class="rbn-card__ops">';
			if ( $ready ) {
				$label = sprintf( /* translators: %s: version */ __( 'Install (v%s)', 'adminkit' ), $rel['release']['version'] );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( Installer::ACT_INSTAL ) . '" /><input type="hidden" name="id" value="' . esc_attr( $item['id'] ) . '" />' . wp_nonce_field( Installer::ACT_INSTAL . '_' . $item['id'], '_wpnonce', true, false ) . '<button type="submit" class="rbn-btn rbn-btn--pri rbn-btn--sm">' . esc_html( $label ) . '<span class="rbn-sr"> — ' . esc_html( $item['name'] ) . '</span></button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput -- parçalar kaçışlı.
			} else {
				echo '<button type="button" class="rbn-btn rbn-btn--sm" disabled>' . esc_html__( 'Install', 'adminkit' ) . '<span class="rbn-sr"> — ' . esc_html( $item['name'] ) . '</span></button>';
			}
		}
		echo '</div></div></li>';
	}

	/** @param array<string, string> $item Katalog öğesi. */
	private static function activate_form( array $item ): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! Installer::file_mods_allowed() ) {
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( Installer::ACT_ACTIVE ) . '" /><input type="hidden" name="id" value="' . esc_attr( $item['id'] ) . '" />' . wp_nonce_field( Installer::ACT_ACTIVE . '_' . $item['id'], '_wpnonce', true, false ) . '<button type="submit" class="rbn-btn rbn-btn--pri rbn-btn--sm">' . esc_html__( 'Activate', 'adminkit' ) . '<span class="rbn-sr"> — ' . esc_html( $item['name'] ) . '</span></button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput -- parçalar kaçışlı.
	}
}
