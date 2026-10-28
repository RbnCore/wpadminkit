<?php
/**
 * Default log storage: the single `{prefix}rbnkit_log` table.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Driver;

use AdminKit\Core\Schema;

defined( 'ABSPATH' ) || exit;

final class TableLogDriver implements LogDriver {

	private const CHUNK = 5000;

	public function insert( array $row ): int {
		global $wpdb;
		$ok = $wpdb->insert( Schema::table(), $row, array( '%s', '%s', '%s', '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	public function query( string $channel, array $filters ): array {
		global $wpdb;
		$table = Schema::table();
		$where = array( 'channel = %s' );
		$args  = array( $channel );
		if ( ! empty( $filters['level'] ) ) {
			$where[] = 'level = %s';
			$args[]  = (string) $filters['level'];
		}
		if ( ! empty( $filters['event'] ) ) {
			$where[] = 'event = %s';
			$args[]  = (string) $filters['event'];
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $filters['from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $filters['to'] . ' 23:59:59';
		}
		$clause   = implode( ' AND ', $where );
		$per_page = max( 1, (int) ( $filters['per_page'] ?? 8 ) );
		$offset   = ( max( 1, (int) ( $filters['page'] ?? 1 ) ) - 1 ) * $per_page;
		$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", $args ) ); // phpcs:ignore WordPress.DB
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT id, level, event, message, context, created_at FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$items    = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$context = json_decode( (string) $row['context'], true );
			$items[] = array(
				'id'      => (int) $row['id'],
				'at'      => gmdate( 'Y-m-d\TH:i:s', (int) strtotime( $row['created_at'] . ' UTC' ) ),
				'level'   => (string) $row['level'],
				'event'   => (string) $row['event'],
				'message' => (string) $row['message'],
				'context' => is_array( $context ) ? $context : array(),
			);
		}
		return array(
			'total' => $total,
			'items' => $items,
		);
	}

	public function count( string $channel ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table() . ' WHERE channel = %s', $channel ) ); // phpcs:ignore WordPress.DB
	}

	public function prune( string $channel, string $cutoff, int $max_rows ): int {
		global $wpdb;
		$table   = Schema::table();
		$deleted = 0;
		for ( $i = 0; $i < 20; $i++ ) {
			$n        = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE channel = %s AND created_at < %s LIMIT %d", $channel, $cutoff, self::CHUNK ) ); // phpcs:ignore WordPress.DB
			$deleted += $n;
			if ( $n < self::CHUNK ) {
				break;
			}
		}
		$edge = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE channel = %s ORDER BY id DESC LIMIT 1 OFFSET %d", $channel, $max_rows ) ); // phpcs:ignore WordPress.DB
		if ( null !== $edge ) {
			for ( $i = 0; $i < 20; $i++ ) {
				$n        = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE channel = %s AND id <= %d LIMIT %d", $channel, (int) $edge, self::CHUNK ) ); // phpcs:ignore WordPress.DB
				$deleted += $n;
				if ( $n < self::CHUNK ) {
					break;
				}
			}
		}
		return $deleted;
	}

	public function purge( string $channel_or_prefix ): int {
		global $wpdb;
		$table = Schema::table();
		if ( '.' === substr( $channel_or_prefix, -1 ) ) {
			return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE channel LIKE %s", $wpdb->esc_like( $channel_or_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB
		}
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE channel = %s", $channel_or_prefix ) ); // phpcs:ignore WordPress.DB
	}
}
