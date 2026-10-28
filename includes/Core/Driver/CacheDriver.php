<?php
/**
 * Cache storage contract. Values arrive already wrapped (`array( 'v' => mixed )`), so a stored `false` is a real hit.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Driver;

defined( 'ABSPATH' ) || exit;

interface CacheDriver {

	/** @return array<string, mixed>|null The wrapped value, or null on a miss. */
	public function get( string $key ): ?array;

	/** @param array<string, mixed> $wrapped Wrapped value. */
	public function set( string $key, array $wrapped, int $ttl ): bool;

	/** Stores only when the key is free (used for the stampede lock); best effort where the backend cannot be atomic. */
	public function add( string $key, int $ttl ): bool;

	public function delete( string $key ): bool;

	/** Removes every entry whose key starts with the prefix (uninstall / plugin purge). */
	public function purge( string $prefix ): void;
}
