=== AdminKit — Yönetim Paneli Araçları ===
Contributors: rbnbilisim
Tags: admin, back to top, sticky save, notices, admin color scheme, dashboard
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bağlantı: https://github.com/RbnCore

WordPress yönetim paneline modül modül özellik ekler. Her modül Ayarlar → AdminKit'ten açılıp kapatılır.

== Description ==

* Yukarı çık düğmesi: tüm yönetim sayfalarında; konum, eşik ve kenar boşluğu ayarlanır.
* Yapışkan kaydet çubuğu: uzun ayar sayfalarında mevcut kaydet düğmesini tetikler; kaydedilmemiş değişiklik uyarısı verir.
* Bildirim düzenleyici: bilgi bildirimlerini tek kutuda toplar. Hata bildirimleri hiçbir zaman gizlenmez.
* Yan menü öğelerini gizle (örnek modül, varsayılan kapalı): yalnız görünümü değiştirir, erişimi kapatmaz.
* Renk teması (varsayılan kapalı): 5 hazır tema ya da kendi renkleriniz; kullanıcı bazlı, canlı önizlemeli, WCAG AA (4,5:1) kontrastı denetlenir.
* Ön yüze çıktı vermez, dış servis çağırmaz.

== Installation ==

1. Klasörü `wp-content/plugins/` altına yükleyin ve etkinleştirin.
2. Ayarlar → AdminKit sayfasından modülleri açın ya da kapatın.

== Changelog ==

= 0.2.0 =
* Yeni modül: Renk teması (`color_theme`, varsayılan kapalı). Gece, Okyanus, Orman, Gün batımı, Grafit hazır temaları ve Özel renkler; Kullanıcılar → Panel renkleri'nden kişisel seçim, Ayarlar → AdminKit'ten tüm kullanıcılar için varsayılan.
* Canlı önizleme (kaydetmeden), Kaydet, Vazgeç, Varsayılana dön. Kontrast hesabı WCAG göreli parlaklıkla; 4,5:1 altı renk uyarı + Düzelt ile gösterilir ve uygulanırken otomatik düzeltilir.
* Modül kapalıyken WordPress "Yönetici renk düzeni" aynen çalışır; kişisel seçimde "WordPress varsayılanı" ile modül devre dışı kalır.
* `--adminkit-accent` CSS değişkeni yayınlanır. Ön yüze çıktı yoktur.
* tr_TR çeviri güncellendi.

= 0.1.0 =
* İlk sürüm: 4 modül, modül kayıt arayüzü, tr_TR çeviri.
