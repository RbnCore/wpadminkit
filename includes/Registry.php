<?php
/**
 * Modül kaydı: yerleşik modüller + `adminkit_modules` süzgeciyle eklenenler.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** @var array<string, Module>|null */
	private static ?array $modules = null;

	/**
	 * @return array<string, Module>
	 */
	public static function all(): array {
		if ( null !== self::$modules ) {
			return self::$modules;
		}
		$list = array(
			new Modules\ScrollTop(),
			new Modules\StickySave(),
			new Modules\NoticeTidy(),
			new Modules\MenuTrim(),
			new Modules\ColorTheme(),
		);
		/**
		 * Yeni modül ekleme noktası: `Module` uygulayan nesneleri diziye ekleyin.
		 *
		 * @param Module[] $list Modül listesi.
		 */
		$list = apply_filters( 'adminkit_modules', $list );
		$map  = array();
		foreach ( is_array( $list ) ? $list : array() as $module ) {
			if ( $module instanceof Module && preg_match( '/^[a-z0-9_]+$/', $module->id() ) ) {
				$map[ $module->id() ] = $module;
			}
		}
		self::$modules = $map;
		return $map;
	}
}
