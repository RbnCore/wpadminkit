/*! AdminKit — WordPress çekirdek bildirimlerini toast'a çevirir. Başka eklentilerin bildirimine, hata/uyarıya ve düğmeli bildirime dokunmaz. */
( function () {
	'use strict';
	var cfg = ( window.AdminKit || {} ).toastconvert;
	var area = document.getElementById( 'wpbody-content' );
	if ( ! cfg || ! area ) {
		return;
	}
	var root = document.documentElement;
	// Kapsayıcı kimliği çekirdeğin işaretidir; genel `.notice-success` seçicisi kullanılmaz (başka eklentilerin kutusunu da yakalar).
	var CANDIDATES = '#message, #setting-error-settings_updated';

	function plain( el ) {
		return ! el.hidden && ! el.querySelector( 'a, button:not(.notice-dismiss), form, input, select, textarea' ) &&
			! el.classList.contains( 'notice-error' ) && ! el.classList.contains( 'error' ) &&
			! el.classList.contains( 'notice-warning' ) && ! el.classList.contains( 'no-toast' ) &&
			( el.classList.contains( 'updated' ) || el.classList.contains( 'notice-success' ) || el.classList.contains( 'notice-info' ) );
	}
	function text( el ) {
		var copy = el.cloneNode( true );
		var dismiss = copy.querySelectorAll( '.notice-dismiss' );
		var i;
		for ( i = 0; i < dismiss.length; i++ ) {
			dismiss[ i ].parentNode.removeChild( dismiss[ i ] );
		}
		return ( copy.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	}
	function isCore( el, msg ) {
		if ( 'setting-error-settings_updated' === el.id ) {
			return cfg.settings && msg === cfg.saved;
		}
		// `#message` kaynağı belirsizse (başka eklenti de basmış olabilir) hiçbirine dokunulmaz.
		return 'message' === el.id && cfg.core && ! document.getElementById( 'adminkit-tc-ambiguous' ) && 1 === area.querySelectorAll( '#message' ).length;
	}
	function run() {
		var list = area.querySelectorAll( CANDIDATES );
		var seen = {};
		var i;
		for ( i = 0; i < list.length; i++ ) {
			var el = list[ i ];
			var msg = text( el );
			if ( '' !== msg && plain( el ) && isCore( el, msg ) && window.RbnUi && window.RbnUi.toast ) {
				if ( ! seen[ msg ] ) {
					seen[ msg ] = true;
					window.RbnUi.toast( { type: el.classList.contains( 'notice-info' ) ? 'info' : 'success', message: msg } );
				}
				el.parentNode.removeChild( el );
			} else {
				el.classList.add( 'rbn-tc-keep' );
			}
		}
		root.classList.remove( 'rbn-tc' );
	}
	// WordPress (common.js) bildirimleri DOM hazır olunca taşır; jQuery.ready sırası bizi ondan sonra çalıştırır.
	if ( window.jQuery ) {
		window.jQuery( run );
	} else if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
}() );
