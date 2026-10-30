<?php
/**
 * Kaldırma: yalnız AdminKit'in kendi verisi silinir: ayarlar, çekirdek (Cache + Log) tablosu/seçenekleri/zamanlı görevi, yalnız `rbnkit_adminkit.*` önbellek girdileri (başka eklentinin önbelleğine dokunulmaz), kullanıcı metası `adminkit_*`.
 *
 * @package AdminKit
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'adminkit_settings' );
delete_option( 'adminkit_network_github' );
delete_option( 'rbnkit_core_db_version' );
delete_option( 'rbnkit_core_cache_stats' );
wp_clear_scheduled_hook( 'rbnkit_core_prune' );

// The group counters live in one row per group (`rbnkit_core_gv_<plugin>.<name>`). Only AdminKit's own rows are
// deleted here: the counters of the other plugins must survive, otherwise their old cache entries would become
// reachable again (a counter that falls back to 1 revives every entry of that version).
global $wpdb;
$counters = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'rbnkit_core_gv_adminkit.' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( (array) $counters as $name ) {
	if ( is_string( $name ) && 0 === strpos( $name, 'rbnkit_core_gv_adminkit.' ) ) {
		delete_option( $name );
	}
}

$own = $wpdb->esc_like( 'rbnkit_adminkit.' ) . '%'; // Cache key shape rbnkit_<plugin>.<group>...: only AdminKit's own prefix; every other plugin purges itself (Cache::purge_plugin).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '\_transient\_' . $own, '\_transient\_timeout\_' . $own ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Rate limit counters (WPRJ-685): their rows carry the transient shape, so they are named here by the same prefix the
// class uses (`RateLimit::PREFIX`); the pair is deleted together with its timeout marker.
$rate = $wpdb->esc_like( 'rbnkit_rate_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '\_transient\_' . $rate, '\_transient\_timeout\_' . $rate ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rbnkit_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'adminkit\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
