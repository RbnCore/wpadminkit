<?php
/**
 * RBN eklenti kataloğu (statik liste). Bir eklenti yayınlanınca yalnız `status` satırı `released` olur; sürüm ve paket bilgisi sayfa açılınca GitHub sürümlerinden okunur (ayardan kapatılabilir).
 *
 * Alanlar: id (= eklenti klasörü), name, tagline (tek cümle), dashicon, plugin_file, repo (açık alan: RbnCore/wp<id>),
 * status (released | soon), settings_slug (RBN Kit alt öğesi ya da Ayarlar altı sayfa kısa adı).
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'            => 'adminkit',
		'name'          => 'AdminKit',
		'tagline'       => __( 'Adds small tools to the admin area, such as a back-to-top button, a sticky save bar and a color theme.', 'adminkit' ),
		'dashicon'      => 'admin-generic',
		'plugin_file'   => 'adminkit/adminkit.php',
		'repo'          => 'RbnCore/wpadminkit',
		'status'        => 'soon',
		'settings_slug' => 'adminkit',
	),
	array(
		'id'            => 'contenthealth',
		'name'          => 'ContentHealth',
		'tagline'       => __( 'Finds gaps in posts, tags and images, and helps you fix problems such as missing alt text.', 'adminkit' ),
		'dashicon'      => 'heart',
		'plugin_file'   => 'contenthealth/contenthealth.php',
		'repo'          => 'RbnCore/wpcontenthealth',
		'status'        => 'soon',
		'settings_slug' => 'contenthealth',
	),
	array(
		'id'            => 'cookiekit',
		'name'          => 'CookieKit',
		'tagline'       => __( 'Adds a cookie banner and a preference panel that follow the Turkish data protection law (KVKK); works with Google Consent Mode v2.', 'adminkit' ),
		'dashicon'      => 'shield',
		'plugin_file'   => 'cookiekit/cookiekit.php',
		'repo'          => 'RbnCore/wpcookiekit',
		'status'        => 'soon',
		'settings_slug' => 'cookiekit',
	),
	array(
		'id'            => 'wpseokit',
		'name'          => 'WPSeoKit',
		'tagline'       => __( 'Optimizes how your site appears in search engines: titles, descriptions, social cards, sitemap, schema, redirects and a 404 log.', 'adminkit' ),
		'dashicon'      => 'search',
		'plugin_file'   => 'wpseokit/wpseokit.php',
		'repo'          => 'RbnCore/wpseokit',
		'status'        => 'soon',
		'settings_slug' => 'wpseokit',
	),
);
