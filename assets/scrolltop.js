/*! AdminKit — yukarı çık düğmesi (bağımlılıksız). */
( function () {
	'use strict';
	var cfg = ( window.AdminKit || {} ).scrolltop;
	if ( ! cfg || document.getElementById( 'adminkit-top' ) ) {
		return;
	}
	var btn = document.createElement( 'button' );
	var ns = 'http://www.w3.org/2000/svg';
	var svg = document.createElementNS( ns, 'svg' );
	var path = document.createElementNS( ns, 'path' );
	var shown = false;
	var ticking = false;

	btn.type = 'button';
	btn.id = 'adminkit-top';
	btn.className = 'adminkit-top adminkit-top--' + ( 'left' === cfg.position ? 'left' : 'right' );
	btn.setAttribute( 'aria-label', cfg.label );
	btn.title = cfg.label;
	btn.hidden = true;
	btn.style.setProperty( '--adminkit-edge', cfg.margin + 'px' );
	svg.setAttribute( 'viewBox', '0 0 24 24' );
	svg.setAttribute( 'aria-hidden', 'true' );
	path.setAttribute( 'd', 'M6 15l6-6 6 6' );
	svg.appendChild( path );
	btn.appendChild( svg );
	document.body.appendChild( btn );

	function update() {
		ticking = false;
		var want = ( window.pageYOffset || document.documentElement.scrollTop ) > cfg.threshold;
		if ( want !== shown ) {
			shown = want;
			btn.hidden = ! want;
		}
	}
	window.addEventListener( 'scroll', function () {
		if ( ! ticking ) {
			ticking = true;
			window.requestAnimationFrame( update );
		}
	}, { passive: true } );
	btn.addEventListener( 'click', function () {
		var calm = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		window.scrollTo( { top: 0, behavior: calm ? 'auto' : 'smooth' } );
	} );
	update();
}() );
