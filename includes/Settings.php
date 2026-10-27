<?php
/**
 * Tek ayar satırı `adminkit_settings`: modül aç/kapat + modül ayarları. Bozuk veri güvenli varsayılana düşer.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'adminkit_settings';
	public const GROUP  = 'adminkit';

	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
			)
		);
	}

	/**
	 * Modülün doğrulanmış ayarları (enabled + alanlar).
	 *
	 * @return array<string, mixed>
	 */
	public static function for_module( Module $module ): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$mods   = isset( $stored['modules'] ) && is_array( $stored['modules'] ) ? $stored['modules'] : array();
		$mine   = isset( $mods[ $module->id() ] ) && is_array( $mods[ $module->id() ] ) ? $mods[ $module->id() ] : array();
		$out    = array(
			'enabled' => array_key_exists( 'enabled', $mine ) ? (bool) $mine['enabled'] : $module->default_enabled(),
		);
		foreach ( $module->settings_schema() as $field ) {
			if ( 'custom' === $field['type'] ) {
				continue; // Yalnız arayüz: değer saklanmaz.
			}
			$key         = (string) $field['key'];
			$out[ $key ] = array_key_exists( $key, $mine ) ? self::clean_field( $field, $mine[ $key ] ) : self::default_of( $field );
		}
		return $out;
	}

	/**
	 * @param mixed $input Form verisi.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		static $creating = false;
		if ( ! $creating && false === get_option( self::OPTION ) ) {
			// Satır yoksa önce autoload kapalı açılır; update_option bu bayrağı korur (add_option yeniden buraya düşer: koruma).
			$creating = true;
			add_option( self::OPTION, array(), '', false );
			$creating = false;
		}
		if ( ! is_array( $input ) || empty( $input['modules'] ) || ! is_array( $input['modules'] ) ) {
			return array(); // Form verisi değil (ör. etkinleştirmede boş satır): varsayılanlar geçerli kalır.
		}
		$posted = $input['modules'];
		$out    = array();
		foreach ( Registry::all() as $id => $module ) {
			$mine = isset( $posted[ $id ] ) && is_array( $posted[ $id ] ) ? $posted[ $id ] : array();
			$row  = array( 'enabled' => ! empty( $mine['enabled'] ) );
			foreach ( $module->settings_schema() as $field ) {
				if ( ! empty( $field['locked'] ) || 'custom' === $field['type'] ) {
					continue;
				}
				$key         = (string) $field['key'];
				$row[ $key ] = array_key_exists( $key, $mine ) ? self::clean_field( $field, $mine[ $key ] ) : self::absent( $field );
			}
			$out['modules'][ $id ] = $row;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $field Alan tanımı.
	 * @return mixed
	 */
	public static function default_of( array $field ) {
		return $field['default'] ?? ( 'checkbox' === $field['type'] ? false : ( 'checklist' === $field['type'] ? array() : '' ) );
	}

	/**
	 * Formda gönderilmeyen alan: işaret kutusu = kapalı, liste = boş, öteki = varsayılan.
	 *
	 * @param array<string, mixed> $field Alan tanımı.
	 * @return mixed
	 */
	private static function absent( array $field ) {
		if ( 'checkbox' === $field['type'] ) {
			return false;
		}
		if ( 'checklist' === $field['type'] ) {
			return array();
		}
		return self::default_of( $field );
	}

	/**
	 * @param array<string, mixed> $field Alan tanımı.
	 * @param mixed                $value Ham değer.
	 * @return mixed
	 */
	public static function clean_field( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'checkbox':
				return ! empty( $value );
			case 'number':
				if ( ! is_numeric( $value ) ) {
					return self::default_of( $field );
				}
				return max( (int) $field['min'], min( (int) $field['max'], (int) $value ) );
			case 'select':
				$value = is_scalar( $value ) ? (string) $value : '';
				return array_key_exists( $value, (array) $field['options'] ) ? $value : self::default_of( $field );
			case 'color':
				return Color::hex( $value ) ?: self::default_of( $field );
			case 'checklist':
				$clean = array();
				foreach ( is_array( $value ) ? $value : array() as $item ) {
					if ( is_string( $item ) && preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $item ) ) {
						$clean[] = $item;
					}
				}
				return array_values( array_unique( $clean ) );
		}
		return self::default_of( $field );
	}
}
