<?php
/**
 * Sessiz yükseltici kabuğu: hiçbir şey yazdırmaz, hataları toplar. Dosya sistemi bilgisi (FTP vb.) gerekiyorsa
 * form göstermez; kurulum Türkçe bir hata iletisiyle durur.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Kit;

defined( 'ABSPATH' ) || exit;

final class QuietSkin extends \WP_Upgrader_Skin {

	private \WP_Error $errors;

	public function __construct( $args = array() ) {
		parent::__construct( $args );
		$this->errors = new \WP_Error();
	}

	public function get_errors(): \WP_Error {
		return $this->errors;
	}

	public function header() {}

	public function footer() {}

	public function feedback( $feedback, ...$args ) {}

	public function error( $errors ) {
		if ( is_string( $errors ) ) {
			$errors = new \WP_Error( 'adminkit_skin', $errors );
		}
		if ( is_wp_error( $errors ) ) {
			foreach ( $errors->get_error_codes() as $code ) {
				foreach ( $errors->get_error_messages( $code ) as $msg ) {
					$this->errors->add( (string) $code, $msg );
				}
			}
		}
	}

	public function request_filesystem_credentials( $error = false, $context = '', $allow_relaxed_file_ownership = false ) {
		$method = get_filesystem_method( array(), '' !== $context ? $context : WP_PLUGIN_DIR, $allow_relaxed_file_ownership );
		if ( 'direct' === $method ) {
			return true;
		}
		$this->errors->add( 'adminkit_fs', __( 'Sunucu dosya yazma için ek bilgi (ör. FTP) istiyor; kurulum Eklentiler → Yeni ekle üzerinden yapılmalı.', 'adminkit' ) );
		return false;
	}
}
