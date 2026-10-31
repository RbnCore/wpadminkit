/*! AdminKit — yapışkan kaydet çubuğu. Mevcut gönder düğmesini tıklatır; ikinci form ya da AJAX yoktur. */
( function () {
	'use strict';
	var cfg = ( window.AdminKit || {} ).stickysave;
	if ( ! cfg ) {
		return;
	}
	var root = document.documentElement;
	var forms = document.querySelectorAll( '#wpbody-content form' );
	var form = null;
	var origin = null;
	var i;

	/* Ana kaydet düğmesi: kit formu `.rbn-form-actions` bloğuyla bitirir (ana düğme `.rbn-btn--pri`); WordPress çekirdek formları `.button-primary` / `p.submit` kullanır. Öncelik sırasıyla aranır: ikincil düğmeler (örn. "Back to default") seçilmez. */
	var PRIMARY = [
		'.rbn-form-actions input[type=submit].rbn-btn--pri',
		'.rbn-form-actions button[type=submit].rbn-btn--pri',
		'p.submit input[type=submit]',
		'p.submit button[type=submit]',
		'#submit',
		'input[type=submit].button-primary',
		'button[type=submit].button-primary'
	];
	function primary( f ) {
		var n;
		for ( n = 0; n < PRIMARY.length; n++ ) {
			var el = f.querySelector( PRIMARY[ n ] );
			if ( el ) {
				return el;
			}
		}
		return null;
	}
	function usable( f ) {
		if ( 'post' !== ( f.getAttribute( 'method' ) || 'get' ).toLowerCase() || f.id === 'post' || f.closest( '.rbn-dlg' ) ) {
			return false;
		}
		if ( 'options' === cfg.scope && ! /(^|\/)options\.php(\?|$)/.test( f.getAttribute( 'action' ) || '' ) ) {
			return false;
		}
		return !! primary( f );
	}
	for ( i = 0; i < forms.length; i++ ) {
		if ( usable( forms[ i ] ) ) {
			form = forms[ i ];
			break;
		}
	}
	if ( ! form ) {
		return;
	}
	origin = primary( form );

	var bar = document.createElement( 'div' );
	var status = document.createElement( 'span' );
	var btn = document.createElement( 'button' );
	var baseline = {};
	var visibleDirty = false;
	var dirty = false;
	var submitting = false;
	var live = document.createElement( 'span' );
	var wasDirty = false;

	/** Anında uygulanan anahtarlar kendi ucundan yazılır: "Kaydet" çubuğunu kirli saymazlar (WPRJ-718). */
	function instantNames() {
		var names = {};
		form.querySelectorAll( 'input[data-rbn-instant]' ).forEach( function ( el ) {
			if ( el.name ) {
				names[ el.name ] = true;
			}
		} );
		return names;
	}
	/** Alan adı -> değer birleşimi. Anında anahtarlar, `_wpnonce` ve dosya alanları dışarıda kalır. */
	function snapshot() {
		var map = {};
		var skip = instantNames();
		var data = new FormData( form );
		data.forEach( function ( v, k ) {
			if ( 'string' === typeof v && '_wpnonce' !== k && ! skip[ k ] ) {
				map[ k ] = ( map[ k ] || '' ) + '' + v;
			}
		} );
		return map;
	}
	function tall() {
		return root.scrollHeight >= cfg.minHeight;
	}
	/** Kaydedilmemiş değişiklik var mı (tüm sekmelerde) ve değişen alan şu an görünen bir sekmede mi? */
	function compare() {
		var now = snapshot();
		var seen = {};
		var changed = {};
		var k;
		var any = false;
		for ( k in now ) {
			seen[ k ] = true;
			if ( now[ k ] !== baseline[ k ] ) {
				changed[ k ] = true;
				any = true;
			}
		}
		for ( k in baseline ) {
			if ( ! seen[ k ] ) {
				changed[ k ] = true;
				any = true;
			}
		}
		var visible = false;
		if ( any ) {
			Array.prototype.forEach.call( form.elements, function ( el ) {
				if ( el.name && changed[ el.name ] && ! el.closest( '[hidden]' ) ) {
					visible = true;
				}
			} );
		}
		return { any: any, visible: visible };
	}
	/** Çubuk YALNIZ görünen sekmede kaydedilmemiş değişiklik varken çıkar; yalnız-anahtar sekmelerde dirty hiç oluşmaz. */
	function render() {
		var show = tall() && visibleDirty;
		bar.hidden = ! show;
		document.body.classList.toggle( 'adminkit-has-bar', show );
		root.style.setProperty( '--adminkit-bar-h', show ? bar.offsetHeight + 'px' : '0px' );
		status.textContent = show ? cfg.i18n.dirty : '';
		bar.classList.toggle( 'is-dirty', show );
		/* Çubuk gizliyken içindeki canlı bölge duyurulmaz: duyuru ayrı, hep görünür bir `aria-live` kutusundan yapılır. */
		if ( visibleDirty !== wasDirty ) {
			wasDirty = visibleDirty;
			if ( visibleDirty ) {
				live.textContent = cfg.i18n.dirty;
			} else if ( ! dirty ) {
				live.textContent = cfg.i18n.clean;
			}
		}
	}
	function check() {
		var c = compare();
		dirty = c.any;
		visibleDirty = c.visible;
		render();
	}

	bar.className = 'adminkit-sb rbn-sticky';
	if ( cfg.tone ) {
		bar.setAttribute( 'data-tone', cfg.tone );
	}
	bar.setAttribute( 'role', 'region' );
	bar.setAttribute( 'aria-label', cfg.i18n.bar );
	bar.hidden = true;
	status.className = 'adminkit-sb__st';
	live.className = 'screen-reader-text';
	live.setAttribute( 'role', 'status' );
	live.setAttribute( 'aria-live', 'polite' );
	btn.type = 'button';
	btn.className = 'rbn-btn rbn-btn--pri';
	btn.textContent = cfg.i18n.save;
	bar.appendChild( status );
	bar.appendChild( btn );
	document.body.appendChild( bar );
	document.body.appendChild( live );

	btn.addEventListener( 'click', function () {
		origin.click();
	} );
	/* Alanlar `form="..."` ile forma bağlı olup form öğesinin DIŞINDA durabilir (örn. Genel sekmesi): olay forma kabarmaz, belgeden dinlenir. */
	function mine( e ) {
		if ( e.target && e.target.form === form ) {
			check();
		}
	}
	document.addEventListener( 'input', mine );
	document.addEventListener( 'change', mine );
	/* Canlı panel değişimi (WPRJ-738): değişen bölgenin alanları "kaydedilmemiş" sayılmaz; bölgenin DIŞINDA yazılmış
	 * değişiklikler olduğu gibi kalır. Eski bölgenin alan adları taban çizgisinden çıkar, yeni bölgeninkiler eklenir. */
	function names( root ) {
		var out = [];
		root.querySelectorAll( '[name]' ).forEach( function ( el ) {
			if ( el.name && el.form === form ) {
				out.push( el.name );
			}
		} );
		return out;
	}
	var swapped = [];
	document.addEventListener( 'rbn:panel-swapping', function ( e ) {
		swapped = e.detail && e.detail.root ? names( e.detail.root ) : [];
	} );
	document.addEventListener( 'rbn:panel-swapped', function ( e ) {
		var now = snapshot();
		swapped.concat( e.detail && e.detail.root ? names( e.detail.root ) : [] ).forEach( function ( k ) {
			if ( Object.prototype.hasOwnProperty.call( now, k ) ) {
				baseline[ k ] = now[ k ];
			} else {
				delete baseline[ k ];
			}
		} );
		swapped = [];
		check();
	} );
	form.addEventListener( 'submit', function ( e ) {
		submitting = true;
		window.setTimeout( function () {
			if ( e.defaultPrevented ) {
				submitting = false;
			}
		}, 0 );
	} );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty && ! submitting ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );
	window.addEventListener( 'resize', render );
	/* Sekme değişince (panel `hidden` özniteliği) çubuk o sekmeye göre yeniden hesaplanır. */
	if ( 'MutationObserver' in window ) {
		new MutationObserver( check ).observe( form.closest( '.wrap' ) || form, { subtree: true, attributes: true, attributeFilter: [ 'hidden' ] } );
	}
	window.addEventListener( 'load', function () {
		baseline = snapshot();
		check();
	} );
	baseline = snapshot();
}() );
