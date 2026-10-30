<?php
/**
 * Tek ayar satırı `adminkit_settings`: modül aç/kapat + modül ayarları.
 *
 * Şema AdminKit'in kendi modüllerinden TÜRETİLİR (modül kimliği => alanlar); temizleme, yol ve depolama tek ortak
 * motorda (AdminKit\Core\Settings) yapılır — AdminKit kendi içinde de tek motor kullanır (WPRJ-674 Kademe 4).
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit;

use AdminKit\Core\Settings as Engine;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'adminkit_settings';
	public const GROUP  = 'adminkit';

	/** Motor kayıt kimliği. */
	public const SLUG = 'adminkit';

	/** Ayar sayfasının tek formu; FORM modunda "gönderilmeyen işaret kutusu = kapalı" bununla işler. */
	public const FORM = 'adminkit';

	public static function register(): void {
		self::ensure();
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
			)
		);
		register_setting(
			self::GROUP,
			Core\Network::OPT_GITHUB,
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => array( Core\Network::class, 'sanitize' ),
			)
		);
	}

	/** Motor şemasını bir kez kaydeder (ön yüzde de okunur: `Plugin::init()` modül ayarlarını ister). */
	private static function ensure(): void {
		if ( Engine::registered( self::SLUG ) ) {
			return;
		}
		if ( null === get_option( self::OPTION, null ) ) {
			// Satır yoksa autoload KAPALI açılır: ayarlar yalnız yönetim panelinde okunur.
			add_option( self::OPTION, array(), '', false );
		}
		Engine::register(
			self::SLUG,
			array(
				'option'     => self::OPTION,
				'schema'     => array( self::class, 'schema' ),
				'autoload'   => false,
				// Bilinmeyen modül/alan anahtarları okurken de düşer (modül kaldırıldığında kalıntı kalmaz).
				'read_clean' => true,
				// İzin istemez: kayıt `options.php` üzerinden gelir ve orada manage_options zaten denetlenir.
				'capability' => '',
				'forms'      => array( self::FORM ),
			)
		);
	}

	/**
	 * Şema: `modules.{modül kimliği}.{anahtar}` — modül ayarlarından türetilir.
	 *
	 * `custom` alanlar yalnız arayüzdür (değer saklanmaz), `locked` alanlar forma hiç basılmaz (hep varsayılan).
	 *
	 * @return array<string, array<string, array<string, array<string, mixed>>>>
	 */
	public static function schema(): array {
		$schema = array();
		foreach ( Registry::all() as $id => $module ) {
			$rows = array(
				'enabled' => array(
					'type'    => 'bool',
					'default' => $module->default_enabled(),
					'forms'   => array( self::FORM ),
				),
			);
			foreach ( $module->settings_schema() as $field ) {
				$row = self::row( $field );
				if ( null !== $row ) {
					$rows[ (string) $field['key'] ] = $row;
				}
			}
			$schema['modules'][ (string) $id ] = $rows;
		}
		return $schema;
	}

	/**
	 * Bir modül alanını motor satırına çevirir; saklanmayan alanlarda null.
	 *
	 * @param array<string, mixed> $field Modül alan tanımı.
	 * @return array<string, mixed>|null
	 */
	private static function row( array $field ): ?array {
		$key = (string) ( $field['key'] ?? '' );
		if ( '' === $key || ! empty( $field['locked'] ) || 'custom' === ( $field['type'] ?? '' ) ) {
			return null;
		}
		$default = self::default_of( $field );
		$row     = array( 'forms' => array( self::FORM ) );
		switch ( $field['type'] ?? '' ) {
			case 'checkbox':
				$row += array(
					'type'    => 'bool',
					'default' => ! empty( $default ),
				);
				break;
			case 'number':
				$row += array(
					'type'    => 'int',
					'default' => (int) $default,
					// Eski davranış: sayı olmayan girdi alanın varsayılanına döner (kırpma değil).
					'invalid' => 'default',
				);
				if ( isset( $field['min'] ) ) {
					$row['min'] = (int) $field['min'];
				}
				if ( isset( $field['max'] ) ) {
					$row['max'] = (int) $field['max'];
				}
				break;
			case 'select':
				$row += array(
					'type'    => 'enum',
					'default' => (string) $default,
					'values'  => array_keys( (array) ( $field['options'] ?? array() ) ),
				);
				break;
			case 'color':
				$row += array(
					'type'    => 'color',
					'default' => (string) $default,
					// Eski davranış: `#abc` → `#aabbcc`, küçük harf; geçersiz ya da BOŞ giriş varsayılana döner.
					'clean'   => static function ( $value ) use ( $default ): string {
						return Color::hex( (string) $value ) ?: (string) $default;
					},
				);
				break;
			case 'checklist':
				$row += array(
					'type'        => 'list',
					'key'         => 'raw', // Süzgeç anahtarı korunur (küçük harfe çevrilmez).
					'max_entries' => 1000,
					'default'     => array_values( array_map( 'strval', (array) $default ) ),
					'clean'       => array( self::class, 'clean_checklist' ),
				);
				break;
			default:
				return null;
		}
		return $row;
	}

	/**
	 * Liste alanı: yalnız `[A-Za-z0-9_-]` (1–100 karakter) öğeler kalır, tekilleşir, sıra korunur (eski davranış).
	 *
	 * @param mixed $value Temizlenmiş liste.
	 * @return array<int, string>
	 */
	public static function clean_checklist( $value ): array {
		$out = array();
		foreach ( (array) $value as $item ) {
			if ( is_string( $item ) && 1 === preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $item ) ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Modülün doğrulanmış ayarları (enabled + alanlar); motor varsayılanları ve temizliği uygular.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_module( Module $module ): array {
		self::ensure();
		$all = Engine::all( self::SLUG );
		$row = isset( $all['modules'][ $module->id() ] ) && is_array( $all['modules'][ $module->id() ] ) ? $all['modules'][ $module->id() ] : array();
		$out = array( 'enabled' => ! empty( $row['enabled'] ) );
		foreach ( $module->settings_schema() as $field ) {
			if ( 'custom' === $field['type'] ) {
				continue; // Yalnız arayüz: değer saklanmaz.
			}
			$key         = (string) $field['key'];
			$out[ $key ] = array_key_exists( $key, $row ) ? $row[ $key ] : self::default_of( $field );
		}
		return $out;
	}

	/**
	 * `options.php` temizleme geri çağrısı. Form bütündür: gönderilmeyen modül/alan varsayılana döner
	 * (motor FORM modu), bilinmeyen anahtar yazılmaz.
	 *
	 * @param mixed $input Form verisi.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		self::ensure();
		if ( ! is_array( $input ) || empty( $input['modules'] ) || ! is_array( $input['modules'] ) ) {
			return array(); // Form verisi değil (ör. etkinleştirmede boş satır): varsayılanlar geçerli kalır.
		}
		$res = Engine::preview( self::SLUG, $input, array( 'form' => self::FORM ) );
		return (array) $res['settings'];
	}

	/**
	 * @param array<string, mixed> $field Alan tanımı.
	 * @return mixed
	 */
	public static function default_of( array $field ) {
		return $field['default'] ?? ( 'checkbox' === $field['type'] ? false : ( 'checklist' === $field['type'] ? array() : '' ) );
	}
}
