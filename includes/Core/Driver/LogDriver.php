<?php
/**
 * Log storage contract. Rows reaching a driver are already scrubbed by Privacy.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Driver;

defined( 'ABSPATH' ) || exit;

interface LogDriver {

	/**
	 * @param array<string, string> $row channel, level, event, message, context (JSON), created_at (UTC Y-m-d H:i:s).
	 * @return int New row id, 0 on failure.
	 */
	public function insert( array $row ): int;

	/**
	 * @param array<string, mixed> $filters level, event, from, to (Y-m-d), page, per_page (already validated).
	 * @return array{total:int,items:array<int,array<string,mixed>>} Newest first; items: id, at (UTC ISO), level, event, message, context.
	 */
	public function query( string $channel, array $filters ): array;

	public function count( string $channel ): int;

	/** Deletes rows older than $cutoff (UTC datetime), then rows beyond $max_rows (oldest first). Returns the deleted count. */
	public function prune( string $channel, string $cutoff, int $max_rows ): int;

	/** Deletes one channel (exact id) or, when the argument ends with a dot, every channel of that plugin. Returns the deleted count. */
	public function purge( string $channel_or_prefix ): int;
}
