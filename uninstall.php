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
delete_option( 'rbnkit_core_group_versions' );
delete_option( 'rbnkit_core_cache_stats' );
wp_clear_scheduled_hook( 'rbnkit_core_prune' );

global $wpdb;
$own = $wpdb->esc_like( 'rbnkit_adminkit.' ) . '%'; // Cache key shape rbnkit_<plugin>.<group>...: only AdminKit's own prefix; every other plugin purges itself (Cache::purge_plugin).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '\_transient\_' . $own, '\_transient\_timeout\_' . $own ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rbnkit_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'adminkit\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
