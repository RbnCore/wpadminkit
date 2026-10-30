# Tek ayar motoru (`AdminKit\Core\Settings`) — tasarım (WPRJ-674)

Karar (patron kuralı: **"tekrar eden kod olmasın, her şey merkezi olsun"**): ayar okuma, temizleme, doğrulama,
kaydetme, varsayılana dönme ve değişiklik günlüğü **tek yerde** — AdminKit çekirdeğinde — yazılır. WPSeoKit,
ContentHealth ve CookieKit kendi ayar motorlarını **silip** yalnız ŞEMA bildirir. Geriye dönük uyum köprüsü,
migrasyon kodu ve `.bak` kopya YOKTUR (AGENTS.md §4; `adminkit/docs/cekirdek.md` §15 kuralı).

**Kök neden (Lena/Okan kanıtı):** aynı iş üç yerde farklı yazılmıştı. WPSeoKit tipli şema ama iç içe yolu
çözemiyordu (WPRJ-658) ve tek satır `text` textarea ile basılıyordu (WPRJ-672); ContentHealth gönderilmeyen işaret
kutusunu "değişmedi" sayıp kapalı kuralı kaydetmiyordu (WPRJ-670); CookieKit `sanitize_text_field` ile satır sonu
kaybediyordu (WPRJ-670). Her hata üç yerde ayrı ayrı bulunuyordu. Motor tek olduğunda hata bir yerde bulunur ve
bir yerde düzelir.

---

## 1. Dosyalar ve ad alanı

| Dosya | Sınıf | İş |
|---|---|---|
| `wordpress/Plugins/adminkit/src/includes/Core/Settings.php` | `AdminKit\Core\Settings` | **motor**: şema kaydı, depolama, okuma, temizleme, doğrulama, kaydetme, sıfırlama, günlük |
| `.../includes/Core/Settings/Form.php` | `AdminKit\Core\Settings\Form` | **ortak form alanı yardımcıları** (kit bileşenleri: switch/text/number/textarea/select/color/check + gizli `0` ikizi + ad üretimi) |
| `.../includes/Core/Settings/Handler.php` | `AdminKit\Core\Settings\Handler` | **kayıt işleyicisi**: `Guard::admin_action` + temizle + kaydet + `Toast::flash` + yönlendirme |
| `.../includes/Core/Settings/Store*.php` | — | yok. Depolama `get_option/update_option` üstünde ince katmandır; yeni depolama motoru açılmaz (`cekirdek.md` §3 ile aynı çizgi). |

Yükleme: `adminkit.php` autoloader'ı zaten `AdminKit\Core\Settings` → `includes/Core/Settings.php`,
`AdminKit\Core\Settings\Form` → `includes/Core/Settings/Form.php` eşler. Sınıf adı ile alt ad alanı aynı kökü
paylaşır; PHP'de ikisi ayrı sembol tablosundadır, çakışmaz (`adminkit/tests/ayar-motoru-test.php` bunu sınar).

Motor **ön yüzde de** çalışır (CookieKit ön yüzde `Settings::get('enabled')` okur); çıktı vermez, yalnız
`get_option` + saf fonksiyonlar kullanır.

## 2. Kayıt (eklenti yalnız şema bildirir)

```php
\AdminKit\Core\Settings::register( 'cookiekit', array(
    'option'     => 'cookiekit_settings',          // ZORUNLU: MEVCUT seçenek adı, DEĞİŞMEZ
    'schema'     => <dizi|callable>,               // ZORUNLU: alan yolu => alan tanımı (iç içe olabilir)
    'forms'      => array( 'genel', 'gorunum', … ), // isteğe bağlı: form kimlikleri (belge + doğrulama)
    'form_field' => '_tab',                         // isteğe bağlı: gönderimde form kimliğini taşıyan alan
    'capability' => 'manage_options',               // isteğe bağlı: yazma izni; yoksa yönetici varsayılır
    'autoload'   => null,                           // null: mevcut bayrak korunur; true/false: yalnız OLuştururken
    'read_clean' => false,                          // true: okurken de temizle (WPSeoKit'in bugünkü davranışı)
    'log'        => null,                           // callable(before, after): değişiklik günlüğü
    'on_save'    => null,                           // callable(before, after, input, form): array (ek yol güncellemesi)
    'messages'   => <dizi|callable>,                // bilinmeyen form iletisi (callable: çeviri `init`'te yüklensin)
) );
```

**İzin tek yerde:** `capability` alanı şemanın izin bildirimidir; motor `update()` başında bir kez
`current_user_can()` bakar, izin yoksa **hiçbir şey yazmaz** ve mevcut ayarları döndürür. `options.php` yolu
(WordPress'in kendi kontrolü), admin-post (`Guard::admin_action`) ve REST (kendi `permission_callback`) bu kontrolün
üstüne biner; eklentiler ayrıca izin kodu yazmaz.

**Çeviri zamanı:** `messages` callable olabilir. Eklentinin `__()` çağrıları `plugins_loaded` sırasında değil, ilk
okuma/yazmada çalışır — WP 6.7+ "metin alanı çok erken yüklendi" uyarısı debug.log'a düşmez.

**Callable biçimi:** `schema`/`messages` için hem `'fonksiyon_adı'`, hem `fn()`/`Closure`, hem de
`array( Sınıf::class, 'statik_metot' )` geçerlidir; dizi-callable **şema haritası sanılmaz** (WPRJ-674).

Kayıt kimliği (`slug`) eklentinin metin alanı adıyla aynıdır (`adminkit`, `wpseokit`, `contenthealth`,
`cookiekit`). Aynı slug iki kez kaydedilirse ikinci kayıt **ilkini ezmez**, `_doing_it_wrong` verir (tek şema ilkesi).

## 3. Şema biçimi

Şema **iç içe** olabilir; içinde `type` anahtarı olan düğüm **alan**, olmayan düğüm **bölüm/öbek**tir. Yol, iç içe
anahtarların nokta ile birleşimidir: `templates.sep`, `social.profiles`, `rules.ai_chat_residue.max_len`,
`frequency` (düz eklentilerde tek parça).

```php
'templates' => array(
    'sep' => array( 'type' => 'text', 'default' => '-' ),
),
'robots' => array(
    'noindex_date' => array( 'type' => 'bool', 'default' => true ),
),
```

### 3.1 Alan anahtarları

| Anahtar | Anlamı |
|---|---|
| `type` | **ZORUNLU** tür (§3.2) |
| `default` | **ZORUNLU** varsayılan |
| `min` / `max` | `int` sınırları (dışına çıkan değer kırpılır) |
| `max_len` | `text`/`text_multi`/`list` öğesi en çok karakter (varsayılan 300) |
| `values` | `enum` beyaz listesi (`value => etiket` ya da düz liste) |
| `keys` | `list`: yalnız bu değerler kabul edilir (boşsa serbest); `map`: anahtar beyaz listesi |
| `key` | `list`: öğe biçimi `slug` (varsayılan) \| `text` \| `raw` (**ham metin**: etiket sıyrılmaz, yalnız `trim` + `max_len` — regex/desen listeleri, WPRJ-674); `map`: anahtar biçimi `slug` \| `int` \| `raw` |
| `max_entries` | `list`/`map` üst sınırı (varsayılan 50) |
| `value` | `map`: değer alan tanımı (tek alan) — ör. `array( 'type' => 'url' )` |
| `fields` | `map`: değer satırı alanları (ör. `title`, `description`) — satırın kendisi dizi değilse satır **düşer** (boş satır uydurulmaz; WPSeoKit `tpl_map` davranışı) |
| `filter` | `map`: `callable( $key, $deger ): bool` — satır kabul edilsin mi (ör. `term_exists`) |
| `forms` | alanı çizen form kimlikleri (FORM modunda yetki kümesi) |
| `internal` | `true`: yalnız kod yazar (`set()`/`on_save`); gönderimden **hiç** okunmaz |
| `invalid` | `clamp` (varsayılan) \| `keep` (geçersiz değerde eski değer korunur) \| `default` (geçersiz değerde alanın varsayılanı — AdminKit modül sayıları) + hata kaydı |
| `empty` | `text`/`text_multi`: `keep` (varsayılan) \| `default` (boş gönderim varsayılana döner) \| `clear` |
| `clean` | `callable( $raw, $old ): mixed` — tür temizliğinin üstüne eklenti kuralı (ör. desen doğrulama) |
| `validate` | `callable( $value ): string` — boş değilse hata iletisi |

### 3.2 Tür listesi (üç eklentinin birleşimi)

| Tür | Temizleme | Depolanan | Kim kullanır |
|---|---|---|---|
| `bool` | `filter_var(…, FILTER_VALIDATE_BOOLEAN)` | `true/false` | WPSeoKit, ContentHealth |
| `flag` | `!empty` → `1/0` | `1/0` (mevcut şekil **korunur**) | CookieKit, AdminKit |
| `int` | `(int)`, `min…max` kırpma | `int` | hepsi |
| `text` | `wp_strip_all_tags` + geçersiz UTF-8 + satır sonu→boşluk + `trim` + `max_len`; **`sanitize_text_field` DEĞİL**: `%date%` gibi yüzde-çiftlerini bozmaz (WPRJ-672) | `string` tek satır | hepsi |
| `text_multi` | `wp_strip_all_tags` + `\r\n|\r`→`\n` + `trim` + `max_len`; **satır sonu korunur** (WPRJ-670) | `string` çok satır | CookieKit |
| `color` | `sanitize_hex_color` ya da `''` | `#rrggbb` \| `''` | CookieKit, AdminKit |
| `url` | yalnız `http`/`https` şeması; **protokol-bağıl (`//alan/yol`) reddedilir** (WPRJ-685); geçerliyse `esc_url_raw` | tam URL \| `''` | WPSeoKit |
| `link` | site içi `/yol` ya da **aynı alan adlı** http(s) adres; başka alan adı/`//`/ters bölü/denetim karakteri reddedilir | `string` \| `''` | CookieKit |
| `host` | küçük harf, katı alan adı (IP ve tek etiket reddedilir) | `string` \| `''` | WPSeoKit |
| `host_port` | küçük harf + `trim`; en çok bir `:` ile `alan`/`port` ayrılır, port `1…65535` (baştaki sıfırlar atılır); alan kısmı `host` sözleşmesine uymalı — **IP, e-posta, şema ve yol reddedilir** (atılmaz, değer boş döner; WPRJ-685) | `alan` \| `alan:port` \| `''` | ContentHealth |
| `enum` | `values` içindeyse değer, değilse varsayılan | `string` | hepsi |
| `list` | satır/virgül ayrılmış metin ya da dizi → normalize → `slug`/`text`/`raw` öğe → `keys` süzgeci → tekilleştir → `max_entries` | düz dizi (`array_values`) | hepsi |
| `map` | anahtar biçimi + beyaz liste + değer alanı/temizliği + `filter` + `max_entries` | ilişkisel dizi | WPSeoKit |

`raw` öğe biçimi **regex/desen** listeleri içindir: `wp_strip_all_tags` uygulanmaz (desen içindeki `<div>` gibi
etiketler bozulmaz), yalnız `trim` + `max_len` + `max_entries`. Doğrulama `clean` kancasına bırakılır
(ör. ContentHealth `Patterns::validate_list`).

`checklist` türü ayrı değildir: AdminKit modül alanlarındaki `checklist` = `list` (`key => 'slug'`).

## 4. Depolama ve okuma

- Depolama **eklenti başına tek seçenek**: `wpseokit_settings`, `contenthealth_settings`, `cookiekit_settings`,
  `adminkit_settings`. **Adlar ve şekiller DEĞİŞMEZ** (canlı `filmmakineser.net.tr` verisi bu şekille okunmaya
  devam eder). Migrasyon/köprü kodu yoktur: şema **mevcut veriyi okuyacak biçimde** yazılır.
- `all($slug)`: `get_option` + varsayılanlarla birleştirme. `read_clean` açıksa (WPSeoKit) her alan okurken de
  temizlenir — bugünkü davranış aynen korunur; kapalıysa (CookieKit, ContentHealth) ham değer döner (bugünkü gibi).
- İstek başına bellek önbelleği; `reset_cache()` yazımdan sonra temizler.
- Yazma: satır **yoksa** `add_option( $option, $deger, '', $autoload )`, **varsa** `update_option( $option, $deger )`
  (autoload bayrağına dokunulmaz — wpseokit `on`, contenthealth `off`, cookiekit `auto` bugünkü gibi kalır).
  `autoload` üç durumludur: `true`/`false` açıkça istenir, `null` (varsayılan) WordPress'in kararına bırakılır —
  CookieKit'in bugünkü `auto` satırı böyle korunur. Yazma sırasında motor `sanitize_option_<seçenek>` filtresine
  **yeniden girmez** (kendi yazdığı değeri ikinci kez temizlemez); `options.php` yolu bu filtreyle zaten çalışır.
- `get( $slug, 'a.b.c', $varsayilan )` her derinlikte çalışır; skalerin altına inilmez, eksik yolda varsayılan döner.
- `set( $slug, 'a.b.c', $deger )`: tek yolu temizleyip yazar (sihirbaz, `wizard_skip` gibi kod yazımları).
- `reset( $slug, '' | 'bolum' | 'bolum.alan' )`: varsayılana döner (mevcut UI'lar bugün kullanmıyor; yeni ekranlar için).
- `preview( $slug, $girdi, array( 'form' => …, 'base' => … ) ): array( 'settings' => …, 'errors' => … )`:
  **yazmadan** hesaplar (“bu gövde kaydedilirse ne olur”). `update()` de aynı saf `compute()` yolunu kullanır — tek kod,
  iki giriş; arada davranış farkı oluşamaz. `base` verilirse girdi **o değer kümesi** üzerine uygulanır (WPSeoKit
  `Settings::sanitize( $girdi, $taban )` içe aktarma probu böyle çalışır); verilmezse depolanan değerler kullanılır.

## 5. Kısmi güncelleme semantiği — **formdaki her alan gönderilir**

İki mod vardır ve hangisinin kullanıldığını **çağıran** söyler:

| Mod | Kural | Kim kullanır |
|---|---|---|
| `patch` (varsayılan) | Gönderilmeyen yol **eski değerinde kalır**; gönderilen yol temizlenip yazılır | WPSeoKit `POST /wpseokit/v1/settings` (JS yalnız değişen formu gönderir), ContentHealth REST `onboarding` |
| `form` (`'form' => 'genel'`) | Yalnız o formun alanları yetkilidir: **gönderilmeyen işaret kutusu = kapalı** (`false`/`0`), gönderilmeyen liste = boş dizi, `empty=>default` metin = varsayılan; **başka formun alanına dokunulmaz** | CookieKit (sekme formları), ContentHealth ayar formu, AdminKit modül formu |

- **Her form, çizdiği her alanı gönderir.** İşaret kutuları için iki yol birlikte vardır: (a) `Form::switch()`
  gerektiğinde gizli `0` ikizini kendisi basar (`'twin' => true`), (b) motor FORM modunda hiç gönderilmeyen
  işaret kutusunu kapalı sayar. İkisi de aynı sonucu verir; bir form ikizi unutsa da değer yanlış kaydedilmez —
  WPRJ-670'in kök nedeni bu iki kuralın motorda olmasıyla kapanır.
- Gönderimde **bilinmeyen yol** sessizce atılır (bugünkü davranış).
- `internal` alanlar hiçbir modda gönderimden okunmaz.
- Hata siyaseti alan bazlıdır: `int` kırpılır (uyarı kaydı), `enum` varsayılana düşer, `invalid => keep` olan
  alanlarda (CookieKit renk/adres, WPSeoKit `host`/`url`) **eski değer korunur** ve hata kaydı üretilir.

## 6. Doğrulama, hata kaydı ve kullanıcıya gösterim

- `validate( $slug, $partial ): array` — yalnız **tür** denetimi (yol => ileti). WPSeoKit REST'i bunu 400 ile
  kullanır; iletiler bugünkü metinlerle aynıdır (`Expected a boolean.` …). Bölümün kendisi nesne değilse **tek** hata
  bölüm yolunda döner (`general => A section must be an object.`), alan alan hata üretilmez; bilinmeyen yol hata değildir.
- `sanitize()/update()` sonrası `Settings::last_errors(): array` — yol => ileti (kırpma/ret nedeni).
- CookieKit `add_settings_error` ile alan kimliğine bağlar (bugünkü satır içi hata kutusu), ContentHealth kayıt
  sonrası toast'ta ilk 3 hatayı gösterir (bugünkü gibi), WPSeoKit REST 400 gövdesinde `errors` döner.

## 7. Kayıt işleyicisi ve iki kayıt yolu

Motor **iki** mevcut akışı da destekler; üçüncü bir yol açılmaz:

1. **`options.php` (WordPress `register_setting`)** — `Settings::register_option( $slug, $group )`:
   `sanitize_callback` motordur; form kimliği `form_field` taşıyıcısından okunur; hata `add_settings_error`'a düşer.
   *CookieKit* ve *AdminKit* bunu kullanır (kaydetti bildirimi `settings-updated` ile aynen kalır).
2. **`admin-post.php`** — `Handler::post( $slug, array( nonce, cap, field, form, redirect, ok, error ) )`:
   `Guard::admin_action()` → `Settings::update( …, array( 'form' => … ) )` → `Toast::flash()` → `wp_safe_redirect()`.
   *ContentHealth* bunu kullanır (bugünkü `contenthealth_save` akışı).
3. **REST** — WPSeoKit'in mevcut `POST /wpseokit/v1/settings` rotası **korunur** (JS sözleşmesi değişmez); gövdesi
   motora delege edilir (`validate` + `update` PATCH modu). Yeni REST rotası açılmaz.

## 8. Değişiklik günlüğü

Motor, yazımdan sonra kayıtlı `log` geri çağrısını `( $before, $after )` ile çağırır — `Logs::settings_changed`
kalıbı: yalnız **değişen yol adları** yazılır, **değer yazılmaz** (sır sızıntısı yok). WPSeoKit kendi
`Logs::settings_changed`'ını bağlar (bugünkü kanal/olaylar aynı). ContentHealth ve CookieKit bugün günlük
tutmuyor; boş bırakırlar (yeni kanal açmak bu kartın işi değil).

## 9. Ortak form alanı yardımcıları (`Settings\Form`)

WPSeoKit `Screens\Ui`, ContentHealth `Screens` ve CookieKit `AdminPage` bugün aynı kit bileşenlerini üç kez
yazıyor. Ortak hâl `Settings\Form`'dadır; her eklentinin kendi sınıfı **ince bir adaptör** olarak kalır (kendi
sınıf adları/i18n'i oradan geçer, HTML **birebir** korunur):

| Metot | Ne basar |
|---|---|
| `Form::name( $option, $path, $style )` | `option[a][b]` (bracket, varsayılan) \| `a.b` (dotted) \| `prefix[a][b]` (nested) |
| `Form::switch( $o )` | `role="switch"` onay kutusu; `twin` açıksa önce gizli `0`; `$o['label_class']`, `$o['text_class']` ile eklenti sınıfları |
| `Form::check( $o )` | düz onay kutusu (+ isteğe bağlı ikiz) |
| `Form::number( $o )`, `Form::text( $o )`, `Form::textarea( $o )`, `Form::select( $o )`, `Form::color( $o )` | kit girdileri; `mono`, `maxlength`, `rows`, `data-count`, `data-type` gibi mevcut öznitelikler |
| `Form::twin( $name, $value = '0' )` | gizli ikiz |
| `Form::errors( $o )` | alan hatası `<p class="rbn-field__err">` + `aria-invalid` bağlacı (CookieKit satır içi hata) |

`Form` **kaçışlar**: dönen parçalar güvenlidir; çağıran yalnız birleştirir. `data-*` öznitelikleri için hazır
anahtar sözlüğü (`$o['data']`) — elle HTML yazılmaz.

## 10. Eklenti başına eşleme (şema bildirimi)

### 10.1 CookieKit (`cookiekit_settings`, düz)

| Alan | Tür / kural | Not |
|---|---|---|
| `enabled`, `show_analytics`, `show_ads`, `log`, `delete_on_uninstall` | `flag`, form `genel` | mevcut `1/0` şekli korunur |
| `position` | `enum` `Settings::POSITIONS`, form `gorunum`, `invalid => keep` | |
| `days` | `int` 30…395, form `genel` | kırpma + uyarı (bugünkü gibi) |
| `privacy_url`, `cookie_url` | `link`, form `metinler` + `kurulum`, `invalid => keep` | site yolu ya da aynı alan adlı URL |
| `color_accent`, `color_bg`, `color_text` | `color`, form `gorunum`, `invalid => keep` | |
| `title`(80), `accept`(30), `reject`(30), `prefs`(30), `save`(40) | `text`, `max_len`, `empty => default`, form `metinler` | |
| `desc` | `text_multi`, `max_len 500`, `empty => default`, form `metinler` | **satır sonu korunur** |
| `revision` (`int`), `wizard_done` (`flag`) | `internal` | yalnız `set()`/`on_save` (formdan okunmaz) |
| `bump_revision`, `_tur` | form yardımcı alanları (saklanmaz) | `on_save` reçetesi |

`on_save` (eklentiye özel, tek yerde — CookieKit `Settings::on_save`): `genel` formu `revision`'ı
`bump_revision` geldiyse 1 artırır; her sekme formu `wizard_done = 1` yazar; `kurulum` formunda `_tur` (`reklam`
/`analiz`) → `enabled=1`, `show_analytics=1`, `show_ads = _tur==='reklam' ? 1 : 0`.

`Admin::sanitize()` **silinir**: `register_setting( … 'sanitize_callback' => Settings::register_option … )`.
`Admin::url_clean()` motorun `link` türüne taşınır (tek kopya; `Cookies`/`Rest` aynı davranışı görür).

### 10.2 ContentHealth (`contenthealth_settings`)

| Alan | Tür |
|---|---|
| `frequency` | `enum` off/hourly/daily/weekly |
| `time_budget` / `batch_size` | `int` 1…20 / 10…500 |
| `statuses`, `exclude_post_types`, `term_taxonomies` | `list` `key => slug`, satır+virgül, `max_entries 100` |
| `keep_modified`, `audit_on_save`, `delete_on_uninstall` | `bool` |
| `live_host` | `host_port` |
| `alt_template` | `text`, `max_len 200` |
| `rules.<kural>.*` | kuralın `defaults()` türlerinden türetilen satırlar (§10.2.1) |

**`rules.<kural>` şeması tek yerde türetilir:** `Rule::settings_schema()` (taban sınıf) — `defaults()` bir
*sözleşmedir*: `bool` → `bool`, `int` → `int` (0…100000), dizi → `list` (öğe `raw`, `split => lines`,
`max_entries 100`, `max_len Patterns::MAX_LEN`; adı `patterns`/`pat_` ile başlayan alanlarda
`clean` = `Patterns::validate_list` + hataları `$errors[$path]`'e yazar), metin → `text`. `Rule::sanitize()` **silinir**.

**Modül alanları** (`term_taxonomies`, `alt_template`) `$module->settings()` varsayılanlarından türetilir
(`bool`→`bool`, `int`→`int`, dizi→`list key=>slug`, diğer→`text max_len 200`); sırası eski
`array_merge(defaults, module->settings())` sırasıyla aynıdır — depolanan şekil ve anahtar sırası değişmez.

### 10.3 WPSeoKit (`wpseokit_settings`, bölümlü — model buradan alındı)

Bugünkü `Settings::schema()` **biçim değiştirir** (konumsal dizi → adlandırılmış satır) ama **yollar, türler,
varsayılanlar ve sınırlar birebir aynı** kalır:

| Bugünkü | Yeni |
|---|---|
| `array( 'bool', true )` | `array( 'type' => 'bool', 'default' => true )` |
| `array( 'int', 1000, 100, 2000 )` | `array( 'type' => 'int', 'default' => 1000, 'min' => 100, 'max' => 2000 )` |
| `array( 'enum', 'summary', array( … ) )` | `array( 'type' => 'enum', 'default' => 'summary', 'values' => array( … ) )` |
| `array( 'list', array() )` | `array( 'type' => 'list', 'default' => array() )` |
| `tpl_map` | `map`, `key => slug`, `fields => title/description (text)`, `max_entries 50` |
| `url_map` | `map`, `keys => facebook/instagram/…`, `value => url` |
| `type_map` | `map`, `key => int`, `value => enum Movie/TVSeries`, `filter => term_exists` |

`Settings::clean/check_type/sanitize/validate/text/host/url` **silinir**; `Settings::schema()` yeni biçimde
kalır (tek bildirim yeri) ve `defaults()` motordan okunur. `SettingsRepository` motora delege eden **ince cephe**
olarak kalır (112 çağrı yeri değişmesin; davranışı motordur). `Settings::TEXT_MAX` motor sabitini gösterir;
`text()/host()/url()` motorun `clean_text/clean_host/clean_url` fonksiyonlarını çağırır (üç eklentide tek kopya).

**Kayıt (Kademe 3'te uygulandı):** `Plugin::boot()` içinde `Settings::register()` — `option => wpseokit_settings`,
`autoload => true`, `read_clean => true` (her okumada temizleme bugünkü davranış), `capability => ''` (eski kod izin
istemiyordu; kapı REST `permission_callback` + admin formu `Guard`), `log => array( Logs::class, 'settings_changed' )`
(değişiklik günlüğü yalnız gerçekten değişince; motor no-op'ta çağırmaz). `register()` kendini korur: şema zaten
kayıtlıysa sessizce döner (test/WP-CLI'de `boot()` iki kez koşabilir; `_doing_it_wrong` debug.log'a düşmez).

**Bu kademede YAPILMAYAN (bilerek):** `Screens\Ui` form yardımcılarının `Settings\Form`'a taşınması. Kademe 3'ün
kapsamı şema/yol/temizleme + depolamadır; `Ui` çizim kodu **hiç değişmedi** → önce/sonra HTML birebir aynı (20
ekran/282 alan, bayt ve md5 dahil). Ortak form yardımcıları ayrı bir iş olarak duruyor.

### 10.4 AdminKit kendi modül ayarları (`adminkit_settings`, iç içe — Kademe 4)

`AdminKit\Settings` artık yalnız **şema bildirimi + ince adaptör**; `clean_field()` ve `absent()` silindi. Şema
elle yazılmaz, **kayıtlı modüllerden türetilir** (`Registry::all()` + `Module::settings_schema()`):

| Eski modül alanı | Motor satırı |
|---|---|
| `enabled` (her modül için) | `array( 'type' => 'bool', 'default' => $module->default_enabled() )` |
| `checkbox` | `bool` (varsayılan `! empty(...)`) |
| `number` | `int` + `min`/`max` + **`invalid => 'default'`** (eski `clean_field`: sayı değilse alanın varsayılanı) |
| `select` | `enum`, `values` = `array_keys( options )` (geçersiz değer varsayılana düşer) |
| `color` | `color` + `clean` → `Color::hex()` (küçük harf, `#abc` → `#aabbcc`) `?: default` (geçersiz/boş → varsayılan) |
| `checklist` | `list`, `key => 'raw'` (büyük harf korunur), `max_entries 1000` + `clean` → `/^[A-Za-z0-9_\-]{1,100}$/`, tekilleştir |
| `custom` / `locked` | **satır yok** — `custom` yalnız arayüz, `locked` forma hiç basılmaz (hep varsayılan) |

Yol: `modules.{modül kimliği}.{anahtar}` (motor `modules.veri` gibi iç içe dizileri noktalı yola açar — ek düzleştirme
gerekmedi). Kayıt `Settings::ensure()` içinde, `register()` de onu çağırır: `option => adminkit_settings`,
`autoload => false` (satır yoksa `add_option(..., '', false)`), `read_clean => true` (kaldırılan modülün kalıntısı
okurken de düşer), `capability => ''` (kayıt `options.php`'den gelir; orada `manage_options` denetlenir),
`forms => array( 'adminkit' )`. `ensure()` ön yüzde de koşar çünkü `Plugin::init()` her modül için `for_module()` çağırır.

Okuma `for_module( Module )`: `Engine::all( 'adminkit' )[modules][id]` — depolanan değer, yoksa `default_of()`.
Yazma `sanitize( $input )`: gövde `modules` dizisi taşımıyorsa `array()` (etkinleştirmedeki boş satır varsayılanı
bozmaz), aksi hâlde `Engine::preview( 'adminkit', $input, array( 'form' => 'adminkit' ) )` — **saf hesap**; tek yazmayı
`options.php` yapar. FORM modu eski "toptan yeniden kur" davranışını birebir verir: gönderilmeyen modül/işaret kutusu
kapalı, gönderilmeyen liste boş, bilinmeyen modül/alan yazılmaz.

**Bu kademede YAPILMAYAN (bilerek):** `SettingsPage` çizimi (form adları `adminkit_settings[modules][id][key]`,
kilitli kutunun `name`'siz `checked disabled` basılması, `select`'in callable `options`'ı çözmemesi) **hiç
değişmedi** → ayar sayfası önce/sonra **21291 bayt, md5 aynı, 46 alan (ad/sıra/tür) birebir**.

## 11. HTML değişmezliği (kabul)

Ayar ekranlarının **alan adı / alan sırası / tür listesi birebir aynı** kalır; önce/sonra HTML özeti her eklenti
için alınır ve karşılaştırılır (`E:\tmp\_gecici\AYAR-MOTORU\html-ozet\`). Görsel doğrulamayı Lena/Zeki yapar;
Baran DOM/HTML çıktısı ve sayısal ölçümle kanıtlar.

## 12. Kademe planı (her kademe AYRI commit, testler yeşil)

| Kademe | İçerik |
|---|---|
| **1** | motor (`Settings` + `Form` + `Handler`) + motor birim testi + **CookieKit** geçişi (en küçük) |
| **2** | **ContentHealth** geçişi (kural şeması türetimi, ayar formu, REST `onboarding` PATCH) |
| **3** | **WPSeoKit** geçişi (en büyük, en olgun; şema biçim değişimi + `Ui` delege) |
| **4** | **AdminKit kendi modül ayarları** geçişi (uygulandı) — böylece AdminKit içinde de tek motor kalır (`Settings::clean_field/absent` silindi) |

Kademe 4 kartta adı geçmeyen dördüncü motordu; "AdminKit içinde tek motor" ilkesi gereği aynı karta dâhil edildi.
Kademe 1–3 bitmeden 4'e geçilmez.

## 13. Koruma ve geri alma

- **Canlı veri:** seçenek adları/şekilleri değişmez; yazma öncesi `Settings::all()` mevcut veriyi okur. Yerel
  gerçek veriden türetilmiş fixture testi (§14) her kademede koşar.
- **Yedek:** `E:\tmp\_yedekler\2026-10-30-baran-ayar-motoru\ayarlar-*.json` (+ `NEDEN.txt`); her koşu öncesi/sonrası
  birebir karşılaştırılır (dört seçenek + `seokit_redirects/404_logs/snapshots` sayımları).
- **Geri alma:** kademe başına tek commit; `git revert <sha>` yeterlidir (migrasyon kodu olmadığı için veri geri
  almayı gerektirmez).
- **Yıkıcı işlem yok:** motor hiçbir seçeneği silmez; `reset` yalnız açık çağrıda çalışır ve mevcut ekranlar onu
  çağırmaz.
- Testler gerçek tabloları/seçenekleri düşürmez: motor testleri **geçici seçenek adı** (`adminkit_test_*`) kullanır
  ve sonunda siler; `uninstall` testleri bugünkü geçici önek kalıbıyla (`adminkit\tests\_uninstall-kur.php`) çalışır.

## 14. Testler ve kapılar

| Test | Ne kanıtlar |
|---|---|
| `adminkit/tests/ayar-motoru-test.php` (yeni) | 11 tür + iç içe yol + FORM/PATCH + ikiz + `invalid=>keep` + `empty=>default` + `map` + `reset` + günlük geri çağrısı + autoload korunur + sınıf/alt ad alanı birlikte yüklenir |
| `cookiekit/tests/ayar-motoru-test.php` (yeni) | sekme izolasyonu, işaretsiz kutu = 0, renk/adres geçersizse eski korunur, `days` kırpılır, **textarea satır sonu**, boş metin → varsayılan, `1/0` şekli korunur, **gerçek yerel veriden fixture** ile okuma aynı |
| `contenthealth/tests/ayar-motoru-test.php` (yeni) | kural şeması, desen doğrulama/ret, **gönderilmeyen işaret kutusu = kapalı** (WPRJ-670 regresyonu), REST PATCH, fixture aynı |
| `wpseokit/tests/ayar-motoru-test.php` (yeni) | **gerçek `wpseokit_settings`** ile `all()` birebir (SQL + kademe yedeği), bölüm/anahtar sırası, PATCH, on türün temizliği (tpl/url/type haritaları dahil), `validate` iletileri, gerçek yazma + SQL'den geri alma |
| `adminkit/tests/modul-ayar-test.php` (yeni) | şemanın modüllerden türemesi (`custom`/`locked` saklanmaz), `for_module()` depolanan-değer-yoksa-varsayılan, **gerçek `adminkit_settings`** ile `all()` 1:1, FORM modu (gönderilmeyen kutu/modül kapalı, bilinmeyen anahtar yazılmaz), tür temizliği (sayı `invalid=>default`/kırpma, select, renk `Color::hex`, checklist), gerçek yazma + SQL'den geri alma |
| mevcut paket | `schema`, `a-paketi`, `report`, `redirect`, `meta`, `llms`, `score`, `ayar-yol`, `metin-tek-satir`, `settings-rules`, `wpseokit-entegrasyon`, `metinler`, `uninstall`, `core-*`, `guard`, `toast`, `menu-sirasi`, `yukleme`, `uninstall-kapi`, `db-imza` — kırmızı artmaz |
| kapılar | `kopya-tara.py --calisma-agaci --sinir 0` = 0 izinsiz · `guvenlik-kapisi.py … = 0 kırmızı` · `ceviri-eksik.py = 0` · `debug.log` yeni satır 0 |

Kabul kapısı (sonraki adım, **Okan'ın işi**): `E:\tmp\_gecici\QA-E2E\e2e-kaydet-yenile.cjs` tüm formlarda 0 hata.
Baran yalnız PHP birim/entegrasyon testleriyle kanıtlar.

## 15. Kapsam dışı

- “Varsayılana dön” düğmesi **UI'ya eklenmez** (motor yeteneği hazır; ekran değişikliği bu kartın işi değil).
- Sürüm artmaz, push/canlı yayın yok, `wpseokit`/`contenthealth`/`cookiekit` kaynak depolarına ayrı commit.
