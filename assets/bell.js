/*! AdminKit — bildirim zili: zil (başlık ya da yönetim çubuğu) yerel <dialog> penceresini açar; ESC, dış tıklama ve kapat düğmesi kapatır, odak zile döner. */
( function () {
	'use strict';
	var dlg = document.getElementById( 'rbn-bell-dlg' );
	if ( ! dlg || 'function' !== typeof dlg.showModal ) {
		return;
	}
	var opener = null;
	function open( el, e ) {
		e.preventDefault();
		opener = el;
		if ( ! dlg.open ) {
			dlg.showModal();
		}
	}
	[].forEach.call( document.querySelectorAll( '[data-rbn-bell]' ), function ( b ) {
		b.addEventListener( 'click', function ( e ) {
			open( b, e );
		} );
	} );
	var bar = document.querySelector( '#wp-admin-bar-rbn-notices > a' );
	if ( bar ) {
		bar.addEventListener( 'click', function ( e ) {
			open( bar, e );
		} );
	}
	dlg.addEventListener( 'click', function ( e ) {
		var r = dlg.getBoundingClientRect();
		var out = e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom;
		if ( ( e.target === dlg && out ) || e.target.closest( '[data-rbn-bell-close]' ) ) {
			dlg.close();
		}
	} );
	dlg.addEventListener( 'close', function () {
		if ( opener ) {
			opener.focus();
		}
	} );
}() );
