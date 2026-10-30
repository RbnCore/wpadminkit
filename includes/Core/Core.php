<?php
/**
 * Core bootstrap: loads in every context (front end, REST, cron, admin) but outputs nothing; it only registers hooks.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core;

defined( 'ABSPATH' ) || exit;

final class Core {

	/** Core API level. Consumers check `ADMINKIT_CORE_API >= N`; raised when the public contract grows. */
	public const API = 4;

	public static function boot(): void {
		Schema::maybe_upgrade();
		add_action( 'rest_api_init', array( LogRest::class, 'register' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( Log::PRUNE_HOOK, array( Log::class, 'prune_all' ) );
		add_action( 'shutdown', array( Cache::class, 'save_stats' ) );
		Cache::register_group( 'adminkit', 'catalog', 12 * HOUR_IN_SECONDS );
		Cache::register_group( 'adminkit', 'updates', 12 * HOUR_IN_SECONDS );
		Log::register_channel( 'adminkit', 'updates', 90, 200 );
		// WPRJ-691: a prepare() call with a broken argument shape would otherwise fail silently (empty result set).
		Log::register_channel( 'adminkit', 'db', 90, 200, array( 'error', 'warning' ) );
		// Central updater (API level 3): one hook set for every registered RBN plugin; admin, cron and WP-CLI only, never the front end.
		if ( is_admin() || wp_doing_cron() || defined( 'WP_CLI' ) ) {
			Updater::init();
		}
		Updater::register( 'adminkit', ADMINKIT_FILE, ADMINKIT_VERSION );
		/**
		 * Core is ready: register cache groups and log channels here. If this already fired, call directly.
		 */
		do_action( 'rbnkit_core_ready' );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( Log::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Log::PRUNE_HOOK );
		}
	}

	public static function activate(): void {
		Schema::install();
		self::schedule();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Log::PRUNE_HOOK );
	}
}
