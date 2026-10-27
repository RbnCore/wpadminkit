<?php
/**
 * Modül sözleşmesi: yeni modül bu arayüzü uygular ve `adminkit_modules` süzgeciyle kaydolur.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit;

defined( 'ABSPATH' ) || exit;

interface Module {

	/** Kararlı kimlik (küçük harf, rakam, alt çizgi). Ayar anahtarı olarak saklanır. */
	public function id(): string;

	public function title(): string;

	/** Tek cümlelik açıklama. */
	public function description(): string;

	public function default_enabled(): bool;

	/**
	 * Modülün ayar alanları. Her alan: key, type (number|select|checkbox|checklist), label, default;
	 * ayrıca min/max/step/unit (number), options (select/checklist; dizi ya da çağrılabilir), help, locked.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function settings_schema(): array;

	/**
	 * Modül açıkken, yalnız yönetim panelinde çağrılır.
	 *
	 * @param array<string, mixed> $settings Varsayılanlarla birleşmiş, doğrulanmış modül ayarları.
	 */
	public function boot( array $settings ): void;
}
