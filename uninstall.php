<?php
/**
 * Kaldırma: yalnız `adminkit_settings`, `adminkit_catalog_live`, katalog önbelleği ve kullanıcı metası `adminkit_*` silinir.
 *
 * @package AdminKit
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'adminkit_settings' );
delete_option( 'adminkit_catalog_live' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_adminkit\_kit\_%' OR option_name LIKE '\_transient\_timeout\_adminkit\_kit\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'adminkit\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
