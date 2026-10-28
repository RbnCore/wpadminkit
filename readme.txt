=== AdminKit — Yönetim Paneli Araçları ===
Contributors: rbnbilisim
Tags: admin, back to top, sticky save, notices, admin color scheme, dashboard
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bağlantı: https://github.com/RbnCore

WordPress yönetim paneline modül modül özellik ekler. Her modül RBN Kit → AdminKit'ten açılıp kapatılır.

== Description ==

* Yukarı çık düğmesi: tüm yönetim sayfalarında; konum, eşik ve kenar boşluğu ayarlanır.
* Yapışkan kaydet çubuğu: uzun ayar sayfalarında mevcut kaydet düğmesini tetikler; kaydedilmemiş değişiklik uyarısı verir.
* Bildirim düzenleyici: bilgi bildirimlerini tek kutuda toplar. Hata bildirimleri hiçbir zaman gizlenmez.
* Yan menü öğelerini gizle (örnek modül, varsayılan kapalı): yalnız görünümü değiştirir, erişimi kapatmaz.
* Renk teması (varsayılan kapalı): 5 hazır tema ya da kendi renkleriniz; kullanıcı bazlı, canlı önizlemeli, WCAG AA (4,5:1) kontrastı denetlenir.
* Ön yüze çıktı vermez. Dış bağlantı yalnız RBN Kit → Genel bakış sayfası açılınca ve yalnız GitHub'a yapılır (eklenti kataloğu); AdminKit ayarlarındaki "Eklenti kataloğunu GitHub'dan canlı sorgula" kapatılırsa hiç ağ çağrısı olmaz.
* RBN Kit → Genel bakış: **Kurulu** ve **Eklenti kataloğu** sekmeleri. Katalogdan tek tıkla kurulum: imzalı GitHub sürümü, SHA-256 + Ed25519 doğrulaması zorunlu; kurulum sonrası eklentiyi ayrıca "Etkinleştir" dersiniz.

== Installation ==

1. Klasörü `wp-content/plugins/` altına yükleyin ve etkinleştirin.
2. Yan menüde Başlangıç'ın altındaki RBN Kit → AdminKit sayfasından modülleri açın ya da kapatın.

== Changelog ==

= 0.1.0 =
* İlk sürüm: 5 modül (yukarı çık, yapışkan kaydet çubuğu, bildirim düzenleyici, yan menü gizleme, renk teması), modül kayıt arayüzü, tr_TR çeviri.
* Renk teması (`color_theme`, varsayılan kapalı): Gece, Okyanus, Orman, Gün batımı, Grafit hazır temaları ve Özel renkler; Kullanıcılar → Panel renkleri'nden kişisel seçim, RBN Kit → AdminKit'ten tüm kullanıcılar için varsayılan. Canlı önizleme, Kaydet, Vazgeç, Varsayılana dön; kontrast WCAG göreli parlaklıkla denetlenir (4,5:1 altı uyarı + Düzelt).
* Renk uyumu: yukarı çık düğmesi, yapışkan kaydet çubuğu, bildirim kutusu ve ayar kartları seçilen vurgu rengine uyar (`--adminkit-accent`, `-contrast`, `-hover`, `-active`); renk teması kapalıyken görünüm değişmez.
* Menü: AdminKit, yan menüde Başlangıç'ın hemen altındaki ortak **RBN Kit** menüsünde (Genel bakış + AdminKit); Ayarlar altında kaydı yoktur.
* Ön yüze çıktı yoktur. GitHub'a yalnız eklenti kataloğu sayfası açılınca (düz GET, 12 saat önbellek) bağlanılır; ayardan kapatılabilir. `DISALLOW_FILE_MODS` açıksa Kur/Etkinleştir gizlenir.
