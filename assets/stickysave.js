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

	function primary( f ) {
		return f.querySelector( 'p.submit input[type=submit], p.submit button[type=submit], #submit, input[type=submit].button-primary, button[type=submit].button-primary' );
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
	var baseline = '';
	var dirty = false;
	var submitting = false;
	var offscreen = false;

	function snapshot() {
		var parts = [];
		var data = new FormData( form );
		data.forEach( function ( v, k ) {
			if ( 'string' === typeof v && '_wpnonce' !== k ) {
				parts.push( k + '=' + v );
			}
		} );
		return parts.join( '&' );
	}
	function tall() {
		return root.scrollHeight >= cfg.minHeight;
	}
	function render() {
		var show = tall() && ( dirty || offscreen );
		bar.hidden = ! show;
		document.body.classList.toggle( 'adminkit-has-bar', show );
		root.style.setProperty( '--adminkit-bar-h', show ? bar.offsetHeight + 'px' : '0px' );
		status.textContent = dirty ? cfg.i18n.dirty : '';
		bar.classList.toggle( 'is-dirty', dirty );
	}
	function check() {
		dirty = snapshot() !== baseline;
		render();
	}

	bar.className = 'adminkit-sb';
	bar.setAttribute( 'role', 'region' );
	bar.setAttribute( 'aria-label', cfg.i18n.bar );
	bar.hidden = true;
	status.className = 'adminkit-sb__st';
	status.setAttribute( 'role', 'status' );
	btn.type = 'button';
	btn.className = 'button button-primary';
	btn.textContent = cfg.i18n.save;
	bar.appendChild( status );
	bar.appendChild( btn );
	document.body.appendChild( bar );

	btn.addEventListener( 'click', function () {
		origin.click();
	} );
	form.addEventListener( 'input', check );
	form.addEventListener( 'change', check );
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
	if ( 'IntersectionObserver' in window ) {
		new IntersectionObserver( function ( entries ) {
			offscreen = ! entries[ entries.length - 1 ].isIntersecting;
			render();
		} ).observe( origin );
	}
	window.addEventListener( 'load', function () {
		baseline = snapshot();
		render();
	} );
	baseline = snapshot();
}() );
