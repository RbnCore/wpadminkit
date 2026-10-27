<?php
/**
 * Kaldırma: yalnız `adminkit_settings` ve kullanıcı metası `adminkit_*` silinir.
 *
 * @package AdminKit
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'adminkit_settings' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'adminkit\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
