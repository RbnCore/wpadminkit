/*! AdminKit — bildirim düzenleyici. Hata ve uyarı bildirimleri, düğmeli bildirimler yerinde kalır; yalnız düz başarı ve bilgi bildirimleri toplanır; hiçbir bildirim silinmez. */
( function () {
	'use strict';
	var cfg = ( window.AdminKit || {} ).notices;
	var area = document.getElementById( 'wpbody-content' );
	if ( ! cfg || ! area ) {
		return;
	}
	var SEL = '.notice, div.updated, div.error, .update-nag';
	var box = null;
	var panel = null;
	var count = null;
	var open = !! cfg.open;

	function isError( el ) {
		return el.classList.contains( 'notice-error' ) || el.classList.contains( 'error' );
	}
	function isWarning( el ) {
		return el.classList.contains( 'notice-warning' ) || el.classList.contains( 'warning' );
	}
	function hasActions( el ) {
		return !! el.querySelector( 'a:not(.notice-dismiss), button:not(.notice-dismiss), form, input, select, textarea' );
	}
	function isToastCandidate( el ) {
		return el.classList.contains( 'rbn-toast' ) || !! el.closest( '.rbn-toast-wrap' ) ||
			el.classList.contains( 'no-toast' ) || el.classList.contains( 'rbn-tc-keep' ) ||
			( document.documentElement.classList.contains( 'rbn-tc' ) && ( 'message' === el.id || 'setting-error-settings_updated' === el.id ) );
	}
	// Kullanıcının kendi eyleminin geri bildirimi (ayar kaydedildi), uyarılar, eylem/düğme içerenler ve satır içi bildirimler yerinde kalır.
	function collectable( el ) {
		return ! isError( el ) && ! isWarning( el ) && ! hasActions( el ) && ! isToastCandidate( el ) &&
			! el.hidden && 'none' !== window.getComputedStyle( el ).display &&
			! el.classList.contains( 'inline' ) && ! el.classList.contains( 'settings-error' ) &&
			! el.closest( '.adminkit-nt, .rbn-dlg, form, .postbox, #screen-meta' );
	}
	function save() {
		var body = new URLSearchParams( { action: 'adminkit_notices', nonce: cfg.nonce, open: open ? '1' : '0' } );
		window.fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).catch( function () {} );
	}
	function build( before ) {
		box = document.createElement( 'div' );
		var head = document.createElement( 'button' );
		var errs = document.createElement( 'span' );
		count = document.createElement( 'span' );
		panel = document.createElement( 'div' );
		box.className = 'adminkit-nt';
		head.type = 'button';
		head.className = 'adminkit-nt__btn';
		head.setAttribute( 'aria-controls', 'adminkit-nt-panel' );
		head.title = cfg.i18n.toggle;
		count.className = 'adminkit-nt__n';
		errs.className = 'adminkit-nt__e';
		errs.hidden = true;
		head.appendChild( count );
		head.appendChild( errs );
		panel.id = 'adminkit-nt-panel';
		panel.className = 'adminkit-nt__p';
		panel.setAttribute( 'role', 'region' );
		panel.setAttribute( 'aria-label', cfg.i18n.title );
		box.appendChild( head );
		box.appendChild( panel );
		before.parentNode.insertBefore( box, before );
		// WordPress kapatılan bildirimi kaldırır: sayı güncel kalsın.
		new MutationObserver( sync ).observe( panel, { childList: true } );
		head.addEventListener( 'click', function () {
			open = ! open;
			sync();
			save();
		} );
	}
	function sync() {
		var n = panel.children.length;
		var e = 0;
		var w = 0;
		var list = area.querySelectorAll( SEL );
		var i;
		for ( i = 0; i < list.length; i++ ) {
			var item = list[ i ];
			if ( ! item.closest( '.adminkit-nt' ) && item.offsetParent !== null ) {
				if ( isError( item ) ) {
					e++;
				} else if ( isWarning( item ) ) {
					w++;
				}
			}
		}
		box.hidden = 0 === n;
		count.textContent = cfg.i18n.title + ' (' + n + ')';
		var errs = box.querySelector( '.adminkit-nt__e' );
		var totalOutside = e + w;
		errs.hidden = 0 === totalOutside;
		if ( e > 0 && w > 0 ) {
			errs.textContent = totalOutside + ' ' + ( cfg.i18n.important || cfg.i18n.errors );
		} else if ( e > 0 ) {
			errs.textContent = e + ' ' + cfg.i18n.errors;
		} else if ( w > 0 ) {
			errs.textContent = w + ' ' + ( cfg.i18n.warnings || cfg.i18n.errors );
		}
		panel.hidden = ! open;
		box.firstChild.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	}
	function run() {
		var list = area.querySelectorAll( SEL );
		var i;
		for ( i = 0; i < list.length; i++ ) {
			if ( collectable( list[ i ] ) ) {
				if ( ! box ) {
					build( list[ i ] );
				}
				panel.appendChild( list[ i ] );
			}
		}
		if ( box ) {
			sync();
		}
	}
	// WordPress (common.js) bildirimleri DOM hazır olunca başlığın altına taşır; jQuery.ready sırası bizi ondan sonra çalıştırır.
	if ( window.jQuery ) {
		window.jQuery( run );
	} else if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
	window.addEventListener( 'load', function () {
		if ( ! box || ! area.querySelector( SEL ) ) {
			return;
		}
		run();
	} );
}() );
