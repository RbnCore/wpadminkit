<?php
/**
 * RBN Kit → Genel bakış: "Kurulu" ve "Eklenti kataloğu" sekmeleri.
 *
 * `rbn_kit_overview_callback` süzgeciyle ortak menü yardımcısına bağlanır; AdminKit yoksa yardımcının en küçük listesi kalır.
 * Sekmeler gerçek bağlantıdır (`?page=rbn-kit&tab=kurulu|katalog`); betik yalnız ok tuşlarını ve anlık geçişi ekler.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Kit;

defined( 'ABSPATH' ) || exit;

final class Overview {

	public const PAGE = 'rbn-kit';

	public static function hooks(): void {
		add_filter( 'rbn_kit_overview_callback', array( self::class, 'callback' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		Installer::init();
	}

	/** @param mixed $current Önceki çizici. */
	public static function callback( $current ) {
		unset( $current );
		return array( self::class, 'render' );
	}

	public static function url( string $tab = 'kurulu' ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => 'katalog' === $tab ? 'katalog' : 'kurulu',
			),
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
		wp_enqueue_style( 'adminkit-admin', ADMINKIT_URL . 'assets/admin.css', array(), \AdminKit\Assets::ver( 'admin.css' ) );
		wp_enqueue_style( 'adminkit-kit', ADMINKIT_URL . 'assets/kit.css', array( 'adminkit-admin', 'dashicons' ), \AdminKit\Assets::ver( 'kit.css' ) );
		wp_enqueue_script( 'adminkit-kit', ADMINKIT_URL . 'assets/kit.js', array(), \AdminKit\Assets::ver( 'kit.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	}

	/** Koyu ton: WP "Gece yarısı" şeması. */
	private static function tone(): string {
		$tone = (string) apply_filters( 'adminkit_tone', 'midnight' === (string) get_user_option( 'admin_color' ) ? 'koyu' : 'acik' );
		return 'koyu' === $tone ? ' data-tone="koyu"' : '';
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
			wp_die( esc_html__( 'Bu sayfayı görme yetkiniz yok.', 'adminkit' ), '', array( 'response' => 403 ) );
		}
		$tab   = isset( $_GET['tab'] ) && 'katalog' === $_GET['tab'] ? 'katalog' : 'kurulu'; // phpcs:ignore WordPress.Security.NonceVerification -- yalnız sekme seçimi.
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
		$tabs = array(
			'kurulu'  => __( 'Kurulu', 'adminkit' ),
			'katalog' => __( 'Eklenti kataloğu', 'adminkit' ),
		);
		?>
		<div class="wrap rbn-wrap adminkit-kit"<?php echo self::tone(); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<div class="rbn-head"><div class="rbn-head__id"><h1>RBN Kit</h1><span class="rbn-chip"><?php echo esc_html( 'AdminKit v' . ADMINKIT_VERSION ); ?></span></div></div>
			<?php if ( null !== $note ) : ?>
				<?php
				$ok   = 'hata' !== $note['code'];
				$text = $ok ? Installer::note_text( $note['code'] ) : $note['message'];
				?>
				<div class="rbn-kit__note <?php echo $ok ? 'is-ok' : 'is-err'; ?>" role="<?php echo $ok ? 'status' : 'alert'; ?>"><?php echo esc_html( '' !== $text ? $text : __( 'İşlem tamamlanamadı.', 'adminkit' ) ); ?></div>
			<?php endif; ?>
			<div class="rbn-kit__tabs" role="tablist" aria-label="<?php esc_attr_e( 'RBN Kit sekmeleri', 'adminkit' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a role="tab" id="rbn-kit-tab-<?php echo esc_attr( $key ); ?>" class="rbn-kit__tab" href="<?php echo esc_url( self::url( $key ) ); ?>" aria-controls="rbn-kit-panel-<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $tab === $key ? 'true' : 'false'; ?>" tabindex="<?php echo $tab === $key ? '0' : '-1'; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
			<div role="tabpanel" id="rbn-kit-panel-kurulu" class="rbn-kit__panel" aria-labelledby="rbn-kit-tab-kurulu"<?php echo 'kurulu' === $tab ? '' : ' hidden'; ?>>
				<?php self::installed_panel( $rows ); ?>
			</div>
			<div role="tabpanel" id="rbn-kit-panel-katalog" class="rbn-kit__panel" aria-labelledby="rbn-kit-tab-katalog"<?php echo 'katalog' === $tab ? '' : ' hidden'; ?>>
				<?php self::catalog_panel( $rows ); ?>
			</div>
		</div>
		<?php
	}

	/** @param array<int, array<string, mixed>> $rows Katalog satırları. */
	private static function installed_panel( array $rows ): void {
		$any = false;
		echo '<ul class="rbn-cards rbn-kit__list">';
		foreach ( $rows as $row ) {
			$item  = $row['item'];
			$state = $row['state'];
			if ( ! $state['installed'] ) {
				continue;
			}
			$any    = true;
			$update = is_array( $row['rel'] ) && 'ok' === $row['rel']['status'] && is_array( $row['rel']['release'] ) && version_compare( $row['rel']['release']['version'], $state['version'], '>' ) ? $row['rel']['release']['version'] : '';
			$url    = $state['active'] ? self::settings_url( $item ) : '';
			echo '<li class="rbn-card rbn-kit__row"><div class="rbn-kit__id"><span class="dashicons dashicons-' . esc_attr( $item['dashicon'] ) . '" aria-hidden="true"></span><div><h2>' . esc_html( $item['name'] ) . '</h2><p class="rbn-muted">' . esc_html( 'v' . $state['version'] ) . '</p></div></div><div class="rbn-kit__side">';
			echo '<span class="rbn-chip ' . ( $state['active'] ? 'rbn-chip--ok' : '' ) . '">' . esc_html( $state['active'] ? __( 'Etkin', 'adminkit' ) : __( 'Etkin değil', 'adminkit' ) ) . '</span>';
			if ( '' !== $update ) {
				/* translators: %s: yeni sürüm */
				echo '<span class="rbn-chip rbn-kit__upd">' . esc_html( sprintf( __( 'Güncelleme var: %s', 'adminkit' ), $update ) ) . '</span>';
			}
			if ( ! $state['active'] ) {
				self::activate_form( $item );
			} elseif ( '' !== $url ) {
				echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Ayarlara git', 'adminkit' ) . '<span class="rbn-sr"> — ' . esc_html( $item['name'] ) . '</span></a>';
			}
			echo '</div></li>';
		}
		echo '</ul>';
		if ( ! $any ) {
			echo '<p class="rbn-muted">' . esc_html__( 'Kurulu RBN eklentisi yok.', 'adminkit' ) . '</p>';
		}
	}

	/** @param array<int, array<string, mixed>> $rows Katalog satırları. */
	private static function catalog_panel( array $rows ): void {
		$live = Catalog::live();
		echo '<p class="rbn-muted rbn-kit__lead">' . esc_html__( 'RBN Bilişim’in WordPress eklentileri. Kurulum tek tıkla yapılır; paketin SHA-256 özeti ve imzası doğrulanamazsa kurulmaz.', 'adminkit' ) . '</p>';
		if ( ! $live ) {
			echo '<p class="rbn-kit__info">' . esc_html__( 'Katalog çevrimdışı: GitHub sorgusu AdminKit ayarlarından kapatılmış. Bu sayfa hiç ağ çağrısı yapmaz; “Kur” düğmeleri pasiftir.', 'adminkit' ) . ' <a href="' . esc_url( \AdminKit\SettingsPage::url() . '#adminkit-katalog' ) . '">' . esc_html__( 'Ayarı aç', 'adminkit' ) . '</a></p>';
		}
		if ( ! Installer::file_mods_allowed() ) {
			echo '<p class="rbn-kit__info">' . esc_html__( 'Bu sitede eklenti kurma kapalı (DISALLOW_FILE_MODS); “Kur” ve “Etkinleştir” düğmeleri gösterilmez.', 'adminkit' ) . '</p>';
		}
		echo '<ul class="rbn-kit__grid">';
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
		echo '<li class="rbn-card rbn-kit__card" id="rbn-kit-' . esc_attr( $item['id'] ) . '"><div class="rbn-kit__head"><span class="dashicons dashicons-' . esc_attr( $item['dashicon'] ) . '" aria-hidden="true"></span><h2>' . esc_html( $item['name'] ) . '</h2></div><p class="rbn-kit__tag">' . esc_html( $item['tagline'] ) . '</p><div class="rbn-kit__act">';
		if ( $state['installed'] ) {
			echo '<span class="rbn-chip rbn-chip--ok">' . esc_html__( 'Kurulu ✓', 'adminkit' ) . '</span>';
			if ( ! $state['active'] ) {
				if ( Installer::file_mods_allowed() ) {
					self::activate_form( $item );
				}
			} else {
				$url = self::settings_url( $item );
				if ( '' !== $url ) {
					echo '<a class="rbn-kit__link" href="' . esc_url( $url ) . '">' . esc_html__( 'Ayarlara git', 'adminkit' ) . '<span class="rbn-sr"> — ' . esc_html( $item['name'] ) . '</span></a>';
				}
			}
		} elseif ( 'released' !== $item['status'] ) {
			echo '<span class="rbn-chip rbn-kit__soon">' . esc_html__( 'Yakında', 'adminkit' ) . '</span>';
		} elseif ( ! Installer::file_mods_allowed() || ! current_user_can( 'install_plugins' ) ) {
			echo '<span class="rbn-muted">' . esc_html__( 'Bu sitede kurulum yapılamıyor.', 'adminkit' ) . '</span>';
		} else {
			$ready = is_array( $rel ) && 'ok' === $rel['status'] && is_array( $rel['release'] );
			if ( $ready ) {
				$label = sprintf( /* translators: %s: sürüm */ __( 'Kur (v%s)', 'adminkit' ), $rel['release']['version'] );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( Installer::ACT_INSTAL ) . '" /><input type="hidden" name="id" value="' . esc_attr( $item['id'] ) . '" />' . wp_nonce_field( Installer::ACT_INSTAL . '_' . $item['id'], '_wpnonce', true, false ) . '<button type="submit" class="button button-primary">' . esc_html( $label ) . '<span class="rbn-sr"> — ' . esc_html( $item['name'] ) . '</span></button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput -- parçalar kaçışlı.
			} else {
				$why = ! Catalog::live() ? __( 'Çevrimdışı kip', 'adminkit' ) : ( is_array( $rel ) && 'none' === $rel['status'] ? __( 'Yayın bulunamadı', 'adminkit' ) : __( 'Sürüm bilgisi alınamadı', 'adminkit' ) );
				echo '<button type="button" class="button" disabled>' . esc_html__( 'Kur', 'adminkit' ) . '<span class="rbn-sr"> — ' . esc_html( $item['name'] ) . '</span></button><span class="rbn-muted">' . esc_html( $why ) . '</span>';
			}
		}
		echo '</div></li>';
	}

	/** @param array<string, string> $item Katalog öğesi. */
	private static function activate_form( array $item ): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! Installer::file_mods_allowed() ) {
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( Installer::ACT_ACTIVE ) . '" /><input type="hidden" name="id" value="' . esc_attr( $item['id'] ) . '" />' . wp_nonce_field( Installer::ACT_ACTIVE . '_' . $item['id'], '_wpnonce', true, false ) . '<button type="submit" class="button button-primary">' . esc_html__( 'Etkinleştir', 'adminkit' ) . '<span class="rbn-sr"> — ' . esc_html( $item['name'] ) . '</span></button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput -- parçalar kaçışlı.
	}
}
