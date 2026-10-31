<?php
/**
 * Module — Settings transfer: download the settings of the RBN kits as ONE JSON file, upload it on another site,
 * read the diff, confirm, apply. There is no automatic link between sites; the admin carries the file by hand.
 *
 * The screen has a form of its own (a file upload cannot live inside the settings form), so it is drawn after
 * `#adminkit-settings-form` through `outside_form()` + `panel_html()` (`SettingsPage`). All the reading, validating
 * and writing is `Core\Settings\Transfer` + the settings engine; this class only carries the screen and the
 * admin-post entry points.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Modules;

use AdminKit\Assets;
use AdminKit\Core\Cache;
use AdminKit\Core\Log;
use AdminKit\Core\Security\Guard;
use AdminKit\Core\Settings as Engine;
use AdminKit\Core\Settings\Transfer;
use AdminKit\Helpers\Toast;
use AdminKit\Module;
use AdminKit\Settings;
use AdminKit\SettingsPage;

defined( 'ABSPATH' ) || exit;

final class SettingsTransfer implements Module {

	public const NONCE   = 'adminkit_transfer';
	public const ACTION  = 'adminkit_transfer';
	public const CHANNEL = 'adminkit.transfer';
	public const GROUP   = 'adminkit.transfer';

	/** How long a checked-but-not-applied import plan stays available, in seconds. */
	private const PLAN_TTL = 900;

	public function id(): string {
		return 'settings_transfer';
	}

	public function title(): string {
		return __( 'Settings transfer', 'adminkit' );
	}

	public function description(): string {
		return __( 'Carries the settings of the RBN kits to another site in one JSON file: export, check the diff, apply. Secrets and site-specific values never leave the site.', 'adminkit' );
	}

	public function default_enabled(): bool {
		return false;
	}

	/** Ayarları kendi formunu ister: dosya yükleme ana ayar formunun içine sığmaz. */
	public function tab_label(): string {
		return __( 'Settings transfer', 'adminkit' );
	}

	public function switch_label(): string {
		return __( 'Use settings transfer', 'adminkit' );
	}

	public function off_hint(): string {
		return __( 'Turn it on and press Save to export or import settings.', 'adminkit' );
	}

	/** Bu modülün alanları ana ayar formunun dışında, kendi admin-post formunda kaydedilir. */
	public function outside_form(): bool {
		return true;
	}

	/** Ayar formu kimliği: ana form ('adminkit') bu modülün alanlarına hiç dokunmaz. */
	public function form_id(): string {
		return 'settings_transfer';
	}

	public function settings_schema(): array {
		return array(
			array(
				'key'        => 'include_personal',
				'type'       => 'checkbox',
				'label'      => __( 'Include e-mail recipients and similar personal data', 'adminkit' ),
				'default'    => false,
				'help'       => __( 'Off: alert recipients and other personal values stay on this site.', 'adminkit' ),
				'hidden'     => true,
				'portable'   => false,
				'personal'   => false,
			),
		);
	}

	/** @param array<string, mixed> $settings Modül ayarları. */
	public function boot( array $settings ): void {
		// Kanca ve ekran kendi formunda; burada yalnız kullanıcının seçimiyle ilgili bir şey yok.
		unset( $settings );
	}

	/**
	 * Her zaman kayıtlı kancalar (modül kapalıyken de): modülün açma anahtarı bu uçtan geçer.
	 */
	public static function hooks(): void {
		Cache::register_group( 'adminkit', 'transfer', self::PLAN_TTL );
		Log::register_channel( 'adminkit', 'transfer', 90, 200 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/* ------------------------------------------------------------------ uç */

	public static function handle(): void {
		Guard::admin_action( self::NONCE, 'manage_options', '_wpnonce', 'die' );

		$do = isset( $_POST['do'] ) ? sanitize_key( (string) wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Guard doğruladı.
		$on = ! empty( $_POST['transfer_enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$personal = ! empty( $_POST['include_personal'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$risky    = ! empty( $_POST['include_risky'] ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! in_array( $do, array( 'save', 'export', 'preview', 'apply' ), true ) ) {
			self::back( 'error', __( 'Unknown action; nothing was done.', 'adminkit' ) );
		}
		if ( 'save' !== $do && ! self::enabled() ) {
			self::back( 'error', __( 'The settings transfer module is off; nothing was done.', 'adminkit' ) );
		}
		if ( in_array( $do, array( 'save', 'export', 'preview' ), true ) ) {
			self::save_options( $on, $personal );
		}

		if ( 'export' === $do ) {
			self::export( $personal );
		}
		if ( 'preview' === $do ) {
			self::preview();
		}
		if ( 'apply' === $do ) {
			self::apply();
		}
		self::back( 'success', __( 'Settings saved.', 'adminkit' ) );
	}

	/** Aç/kapa ve kişisel veri tercihi: ana ayar formunun dışında yaşadıkları için buradan yazılır. */
	private static function save_options( bool $enabled, bool $personal ): void {
		Engine::update(
			Settings::SLUG,
			array(
				'modules' => array(
					'settings_transfer' => array(
						'enabled'          => $enabled ? 1 : 0,
						'include_personal' => $personal ? 1 : 0,
					),
				),
			),
			array()
		);
	}

	/** Dışa aktarma: dosya indirilir, sayfa kapanır (yönlendirme yok). */
	private static function export( bool $personal ): void {
		$slugs = self::requested_kits();
		if ( ! $slugs ) {
			self::back( 'error', __( 'Select at least one kit to export.', 'adminkit' ) );
		}
		$doc  = Transfer::document( $slugs, $personal );
		$json = Transfer::json( $doc );
		$meta = array();
		foreach ( array_keys( $doc['kits'] ) as $slug ) {
			$meta[ $slug ] = self::field_count( (string) $slug, $personal );
		}
		Log::log(
			self::CHANNEL,
			'info',
			'export',
			sprintf( 'Exported %d kit(s): %s.', count( $meta ), implode( ', ', array_keys( $meta ) ) ),
			array(
				'kits'     => $meta,
				'personal' => $personal,
				'user'     => get_current_user_id(),
			)
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . Transfer::filename() . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON gövdesi; indirme cevabı.
		exit;
	}

	/** Yükleme + kuru koşu: plan önbelleğe konur, onay ekranına yönlendirilir. Hiçbir ayar yazılmaz. */
	private static function preview(): void {
		$file = isset( $_FILES['rbnkit_file'] ) && is_array( $_FILES['rbnkit_file'] ) ? $_FILES['rbnkit_file'] : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- Guard doğruladı; değerler aşağıda süzülür.
		$tmp  = (string) ( $file['tmp_name'] ?? '' );
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			self::back( 'error', __( 'No file was uploaded.', 'adminkit' ) );
		}
		$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		if ( 'json' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			self::back( 'error', __( 'The file must be a .json settings file.', 'adminkit' ) );
		}
		$size = (int) filesize( $tmp );
		if ( $size <= 0 || $size > Transfer::MAX_BYTES ) {
			self::back( 'error', __( 'The file is empty or larger than 1 MB.', 'adminkit' ) );
		}
		$type = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $tmp ) : '';
		if ( '' !== $type && ! in_array( $type, array( 'application/json', 'text/plain', 'application/octet-stream', 'text/json' ), true ) ) {
			self::back( 'error', __( 'The file does not look like a JSON settings file.', 'adminkit' ) );
		}
		$raw = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Yüklenen geçici dosya; wp_remote_* kapsam dışı.
		$doc = Transfer::decode( $raw );
		if ( is_wp_error( $doc ) ) {
			self::back( 'error', $doc->get_error_message() );
		}
		$plan  = Transfer::plan( (array) $doc, ! empty( $_POST['include_personal'] ), ! empty( $_POST['include_risky'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$token = strtolower( (string) wp_generate_password( 24, false, false ) );
		Cache::set(
			self::GROUP,
			'plan_' . $token,
			array(
				'plan' => $plan,
				'user' => get_current_user_id(),
			),
			self::PLAN_TTL
		);
		wp_safe_redirect( self::tab_url( array( 'plan' => $token ) ) );
		exit;
	}

	/**
	 * Ayar sayfasının bu modülün sekmesine dönüş adresi. `id()` örnek yöntemidir: statik bağlamdan `self::id()`
	 * çağrısı PHP'de ölümcül hata verir (önizleme/uygulama yönlendirmesi hiç çalışmazdı), bu yüzden tek yerden.
	 *
	 * @param array<string, string> $args Ek sorgu argümanları.
	 */
	public static function tab_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'tab' => ( new self() )->id() ), $args ), SettingsPage::url() );
	}

	/** Onaylanan planı TEK işlemde yazar; hata varsa hiçbir kit yazılmaz (Transfer::apply). */
	private static function apply(): void {
		$token = isset( $_POST['plan'] ) ? sanitize_key( (string) wp_unslash( $_POST['plan'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$row   = self::plan_row( $token );
		if ( null === $row ) {
			self::back( 'error', __( 'The checked file is no longer available. Upload it again.', 'adminkit' ) );
		}
		$counts = Transfer::apply( (array) $row['plan'] );
		Cache::delete( self::GROUP, 'plan_' . $token );
		if ( is_wp_error( $counts ) ) {
			Log::log(
				self::CHANNEL,
				'error',
				'import_failed',
				'Import failed; nothing was changed.',
				array( 'reason' => $counts->get_error_code(), 'user' => get_current_user_id() )
			);
			self::back( 'error', $counts->get_error_message() );
		}
		if ( ! $counts ) {
			self::back( 'info', __( 'Nothing changed: this site already matches the file.', 'adminkit' ) );
		}
		$total = array_sum( $counts );
		Log::log(
			self::CHANNEL,
			'info',
			'import',
			sprintf( 'Imported %1$d field(s) into %2$d kit(s).', $total, count( $counts ) ),
			array(
				'kits'  => $counts,
				'total' => $total,
				'user'  => get_current_user_id(),
			)
		);
		/* translators: 1: field count, 2: kit count */
		self::back( 'success', sprintf( __( '%1$d setting(s) across %2$d kit(s) were applied.', 'adminkit' ), $total, count( $counts ) ) );
	}

	/* ------------------------------------------------------------------ ekran */

	/**
	 * Sekme paneli (ana ayar formunun DIŞINDA çizilir): modül anahtarı, dışa aktarma, içe aktarma ve varsa onay ekranı.
	 */
	public function panel_html(): string {
		$settings = Settings::for_module( $this );
		$on       = ! empty( $settings['enabled'] );
		$personal = ! empty( $settings['include_personal'] );
		$plan     = self::plan_row( isset( $_GET['plan'] ) ? sanitize_key( (string) wp_unslash( $_GET['plan'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification -- Yalnız gösterim; plan sahibi doğrulanır.

		ob_start();
		?>
		<section class="rbn-card ak-transfer" id="adminkit-settings_transfer">
			<form id="adminkit-transfer" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::NONCE ); ?>
				<div class="rbn-card__h ak-card-top">
					<div>
						<h2><?php echo esc_html( $this->title() ); ?></h2>
						<p><?php echo esc_html( $this->description() ); ?></p>
					</div>
					<label class="rbn-switch">
						<input type="checkbox" role="switch" name="transfer_enabled" value="1" <?php checked( $on ); ?> />
						<span class="rbn-switch__x" aria-hidden="true"></span>
						<span class="ak-switch-txt"><?php echo esc_html( $this->switch_label() ); ?></span>
					</label>
				</div>
				<?php if ( ! $on ) : ?>
					<div class="rbn-card__b">
						<?php echo Assets::note( 'info', $this->off_hint() ); // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::note() kaçışlar. ?>
						<div class="rbn-form-actions">
							<button type="submit" name="do" value="save" class="rbn-btn rbn-btn--pri"><?php esc_html_e( 'Save changes', 'adminkit' ); ?></button>
						</div>
					</div>
				<?php else : ?>
					<div class="rbn-card__b">
						<fieldset class="rbn-field">
							<legend><?php esc_html_e( 'Export', 'adminkit' ); ?></legend>
							<p class="rbn-help"><?php esc_html_e( 'The file carries settings only: no passwords, keys, licences, e-mail addresses or site addresses. Redirects, 404 records and page content are not settings and do not travel.', 'adminkit' ); ?></p>
							<?php foreach ( Transfer::kits() as $slug => $label ) : ?>
								<label class="rbn-check">
									<input type="checkbox" name="kits[]" value="<?php echo esc_attr( $slug ); ?>" checked />
									<span><?php echo esc_html( $label ); ?></span>
								</label>
							<?php endforeach; ?>
							<label class="rbn-check">
								<input type="checkbox" name="include_personal" value="1" <?php checked( $personal ); ?> />
								<span><?php esc_html_e( 'Include e-mail recipients and similar personal data', 'adminkit' ); ?></span>
							</label>
							<span class="rbn-help"><?php esc_html_e( 'Off by default. When included, the addresses are written into the file in plain text, so keep the file private.', 'adminkit' ); ?></span>
						</fieldset>
						<div class="rbn-form-actions">
							<button type="submit" name="do" value="export" class="rbn-btn rbn-btn--pri"><?php esc_html_e( 'Download settings file', 'adminkit' ); ?></button>
						</div>

						<hr />

						<fieldset class="rbn-field">
							<legend><?php esc_html_e( 'Import', 'adminkit' ); ?></legend>
							<p class="rbn-help"><?php esc_html_e( 'Check the file first: nothing is written until you confirm on the next screen. Values this site cannot clean are rejected or reset to their default by the settings engine, and the diff shows every change before it happens.', 'adminkit' ); ?></p>
							<label for="adminkit-transfer-file"><?php esc_html_e( 'Settings file (.json, up to 1 MB)', 'adminkit' ); ?></label>
							<input type="file" class="rbn-input" id="adminkit-transfer-file" name="rbnkit_file" accept=".json,application/json" />
							<label class="rbn-check">
								<input type="checkbox" name="include_risky" value="1" />
								<span><?php esc_html_e( 'Also apply lock-out-risk settings (login limit, proxy trust, whitelist)', 'adminkit' ); ?></span>
							</label>
							<span class="rbn-help"><?php esc_html_e( 'Off by default: these settings can lock you out of the admin area if they do not match this server. Add your own address to the whitelist first.', 'adminkit' ); ?></span>
						</fieldset>
						<div class="rbn-form-actions">
							<button type="submit" name="do" value="preview" class="rbn-btn rbn-btn--pri"><?php esc_html_e( 'Check the file', 'adminkit' ); ?></button>
							<button type="submit" name="do" value="save" class="rbn-btn"><?php esc_html_e( 'Save changes', 'adminkit' ); ?></button>
						</div>
					</div>
				<?php endif; ?>
			</form>
			<?php
			if ( null !== $plan && $on ) {
				self::plan_html( (array) $plan['plan'], (string) ( $_GET['plan'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- sanitize_key yukarıda; yalnız token.
			}
			?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Onay ekranı: kit kit değişiklik tablosu, atlananlar, hatalar ve tek "Uygula" düğmesi. */
	private static function plan_html( array $plan, string $token ): void {
		$kits   = (array) ( $plan['kits'] ?? array() );
		$fields = (int) ( $plan['fields'] ?? 0 );
		$errors = false;
		foreach ( $kits as $kit ) {
			$errors = $errors || ! empty( $kit['errors'] );
		}
		?>
		<div class="rbn-card__b ak-transfer__plan" id="adminkit-transfer-plan">
			<h3><?php esc_html_e( 'Checked file', 'adminkit' ); ?></h3>
			<?php echo Assets::note( 'warn', __( 'Applying overwrites the existing settings of this site. Export a copy of this site before you apply.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::note() kaçışlar. ?>
			<?php if ( ! empty( $plan['exported'] ) ) : ?>
				<p class="rbn-help">
					<?php
					/* translators: %s: export date from the file */
					printf( esc_html__( 'File exported: %s', 'adminkit' ), esc_html( (string) $plan['exported'] ) );
					?>
				</p>
			<?php endif; ?>
			<?php foreach ( (array) ( $plan['skipped_kits'] ?? array() ) as $slug => $reason ) : ?>
				<p class="rbn-help">
					<?php
					/* translators: %s: kit name */
					printf( esc_html__( '%s is not active on this site; its settings were left out.', 'adminkit' ), esc_html( Transfer::label( (string) $slug ) ) );
					?>
				</p>
			<?php endforeach; ?>
			<?php if ( ! $kits ) : ?>
				<p class="rbn-help"><?php esc_html_e( 'The file has no settings for a kit that is active here.', 'adminkit' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $kits as $slug => $kit ) : ?>
				<?php
				$label = (string) ( $kit['label'] ?? $slug );
				$rows  = (array) ( $kit['changes'] ?? array() );
				$skip  = (array) ( $kit['skipped'] ?? array() );
				$errs  = (array) ( $kit['errors'] ?? array() );
				?>
				<h4>
					<?php echo esc_html( $label ); ?>
					<span class="rbn-chip"><?php echo esc_html( sprintf( _n( '%d change', '%d changes', count( $rows ), 'adminkit' ), count( $rows ) ) ); ?></span>
				</h4>
				<?php if ( ! empty( $kit['version_note'] ) ) : ?>
					<p class="rbn-help">
						<?php
						/* translators: 1: version in the file, 2: version on this site */
						printf( esc_html__( 'The file came from version %1$s; this site runs %2$s. Settings this version does not know are dropped.', 'adminkit' ), esc_html( (string) $kit['plugin_version'] ), esc_html( (string) $kit['version_note'] ) );
						?>
					</p>
				<?php endif; ?>
				<?php foreach ( $errs as $path => $message ) : ?>
					<p class="rbn-help">
						<?php echo esc_html( $path . ': ' . $message ); ?>
					</p>
				<?php endforeach; ?>
				<?php if ( $rows ) : ?>
					<div class="rbn-tw">
						<table class="rbn-table rbn-table--stack">
							<caption class="rbn-sr"><?php esc_html_e( 'Settings that would change', 'adminkit' ); ?></caption>
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Setting', 'adminkit' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Before', 'adminkit' ); ?></th>
									<th scope="col"><?php esc_html_e( 'After', 'adminkit' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) : ?>
									<tr>
										<th scope="row" data-label="<?php esc_attr_e( 'Setting', 'adminkit' ); ?>"><?php echo esc_html( (string) $row['path'] ); ?></th>
										<td data-label="<?php esc_attr_e( 'Before', 'adminkit' ); ?>"><?php echo esc_html( self::show( $row['old'] ?? null, ! empty( $row['personal'] ) ) ); ?></td>
										<td data-label="<?php esc_attr_e( 'After', 'adminkit' ); ?>"><?php echo esc_html( self::show( $row['new'] ?? null, ! empty( $row['personal'] ) ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				<?php if ( $skip ) : ?>
					<p class="rbn-help"><?php esc_html_e( 'Left out:', 'adminkit' ); ?></p>
					<ul class="rbn-help">
						<?php foreach ( $skip as $row ) : ?>
							<li><?php echo esc_html( (string) $row['path'] . ' — ' . self::reason_label( (string) $row['reason'] ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php if ( $errors ) : ?>
				<?php echo Assets::note( 'err', __( 'This file has a value a kit would reject. Nothing can be applied; fix the value in the file and check it again.', 'adminkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Assets::note() kaçışlar. ?>
			<?php elseif ( $fields > 0 ) : ?>
				<form id="adminkit-transfer-apply" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					data-rbn-confirm="<?php esc_attr_e( 'Existing settings of this site will be overwritten and this cannot be undone. Apply the file?', 'adminkit' ); ?>"
					data-rbn-confirm-title="<?php esc_attr_e( 'Apply the settings file?', 'adminkit' ); ?>"
					data-rbn-confirm-ok="<?php esc_attr_e( 'Apply', 'adminkit' ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
					<input type="hidden" name="do" value="apply" />
					<input type="hidden" name="plan" value="<?php echo esc_attr( sanitize_key( $token ) ); ?>" />
					<?php wp_nonce_field( self::NONCE ); ?>
					<div class="rbn-form-actions">
						<button type="submit" class="rbn-btn rbn-btn--danger">
							<?php
							/* translators: 1: field count, 2: kit count */
							printf( esc_html__( 'Apply %1$d change(s) to %2$d kit(s)…', 'adminkit' ), absint( $fields ), count( $kits ) );
							?>
						</button>
					</div>
				</form>
			<?php else : ?>
				<p class="rbn-help"><?php esc_html_e( 'Nothing would change: this site already matches the file.', 'adminkit' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ yardımcılar */

	/** Modül açık mı (kayıtlı ayardan okunur). */
	public static function enabled(): bool {
		$settings = Settings::for_module( new self() );
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Formdan gelen kit seçimi; yalnız tanımlı kitler geçer.
	 *
	 * @return string[]
	 */
	private static function requested_kits(): array {
		$raw = isset( $_POST['kits'] ) ? (array) wp_unslash( $_POST['kits'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification -- Guard doğruladı; değerler beyaz listeye karşı süzülür.
		$all = Transfer::kits();
		$out = array();
		foreach ( $raw as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( isset( $all[ $slug ] ) ) {
				$out[] = $slug;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Bir kitin dışa aktarılan alan sayısı (günlük için; değer yok). */
	private static function field_count( string $slug, bool $personal ): int {
		$count = 0;
		foreach ( Engine::fields( $slug ) as $row ) {
			$travel = Engine::travel( (array) $row );
			if ( 'portable' === $travel || ( $personal && 'personal' === $travel ) ) {
				$count++;
			}
		}
		return $count;
	}

	/** Önbellekteki plan satırı: token, sahibi bu kullanıcı, süresi dolmamış. @return array<string,mixed>|null */
	private static function plan_row( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}
		$found = null;
		$row   = Cache::get( self::GROUP, 'plan_' . $token, $found );
		if ( ! $found || ! is_array( $row ) || (int) ( $row['user'] ?? 0 ) !== get_current_user_id() || ! is_array( $row['plan'] ?? null ) ) {
			return null;
		}
		return $row;
	}

	/** Bir değerin tablodaki kısa gösterimi; kişisel veri maskelenir. */
	private static function show( $value, bool $personal ): string {
		if ( $personal ) {
			return '•••';
		}
		if ( is_array( $value ) ) {
			$json = (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			return strlen( $json ) > 120 ? substr( $json, 0, 117 ) . '…' : $json;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		$text = (string) $value;
		return strlen( $text ) > 120 ? substr( $text, 0, 117 ) . '…' : $text;
	}

	/** Atlama nedeninin insan dili. */
	private static function reason_label( string $reason ): string {
		switch ( $reason ) {
			case 'site_specific':
				return __( 'site-specific (address, media id): never applied from a file', 'adminkit' );
			case 'secret':
				return __( 'secret: never leaves its site', 'adminkit' );
			case 'personal':
				return __( 'personal data: include it in the export first', 'adminkit' );
			case 'risky':
				return __( 'lock-out risk: tick the box to apply it', 'adminkit' );
			case 'internal':
				return __( 'internal value: not a setting', 'adminkit' );
			case 'prototype':
				return __( 'blocked key, dropped', 'adminkit' );
			case 'key':
				return __( 'invalid key name, dropped', 'adminkit' );
			case 'unknown':
				return __( 'unknown setting, dropped', 'adminkit' );
		}
		return __( 'not portable, dropped', 'adminkit' );
	}

	/** Bildirim + sayfaya dönüş. */
	private static function back( string $type, string $message ): void {
		Toast::flash( $type, $message );
		wp_safe_redirect( self::tab_url() );
		exit;
	}
}
