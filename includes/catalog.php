<?php
/**
 * RBN eklenti kataloğu (statik liste). Bir eklenti yayınlanınca yalnız `status` satırı `released` olur; sürüm ve paket bilgisi sayfa açılınca GitHub sürümlerinden okunur (ayardan kapatılabilir).
 *
 * Alanlar: id (= eklenti klasörü = depo adı), name, tagline (tek cümle), dashicon, plugin_file, repo (RbnCore/<id>),
 * status (released | soon), settings_slug (RBN Kit alt öğesi ya da Ayarlar altı sayfa kısa adı).
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'            => 'adminkit',
		'name'          => 'AdminKit',
		'tagline'       => 'Yönetim paneline yukarı çık düğmesi, yapışkan kaydet çubuğu ve renk teması gibi küçük araçlar ekler.',
		'dashicon'      => 'admin-generic',
		'plugin_file'   => 'adminkit/adminkit.php',
		'repo'          => 'RbnCore/adminkit',
		'status'        => 'soon',
		'settings_slug' => 'adminkit',
	),
	array(
		'id'            => 'contenthealth',
		'name'          => 'ContentHealth',
		'tagline'       => 'Yazılardaki, etiketlerdeki ve görsellerdeki eksikleri bulur; alt metin gibi sorunları düzeltmenize yardım eder.',
		'dashicon'      => 'heart',
		'plugin_file'   => 'contenthealth/contenthealth.php',
		'repo'          => 'RbnCore/contenthealth',
		'status'        => 'soon',
		'settings_slug' => 'contenthealth',
	),
	array(
		'id'            => 'cookiekit',
		'name'          => 'CookieKit',
		'tagline'       => 'KVKK uyumlu çerez bandı ve tercih paneli kurar; Google Consent Mode v2 ile çalışır.',
		'dashicon'      => 'shield',
		'plugin_file'   => 'cookiekit/cookiekit.php',
		'repo'          => 'RbnCore/cookiekit',
		'status'        => 'soon',
		'settings_slug' => 'cookiekit',
	),
);
