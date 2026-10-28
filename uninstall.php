<?php
/**
 * Kaldırma: yalnız AdminKit'in kendi verisi silinir: ayarlar, çekirdek (Cache + Log) tablosu/seçenekleri/zamanlı görevi, `rbnkit_*` önbellek girdileri, kullanıcı metası `adminkit_*`.
 *
 * @package AdminKit
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'adminkit_settings' );
delete_option( 'adminkit_catalog_live' );
delete_option( 'rbnkit_core_db_version' );
delete_option( 'rbnkit_core_group_versions' );
delete_option( 'rbnkit_core_cache_stats' );
wp_clear_scheduled_hook( 'rbnkit_core_prune' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_rbnkit\_%' OR option_name LIKE '\_transient\_timeout\_rbnkit\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rbnkit_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'adminkit\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
