/*! AdminKit ayar sayfası: kayıttan sonra aynı sekmeye dönülsün (kit sekme betiği adresi ?tab= ile eşitler; dönüş adresi gönderimde ona çekilir). */
(function () {
	'use strict';
	var form = document.querySelector('form[action="options.php"]');
	var ref = form && form.querySelector('input[name="_wp_http_referer"]');
	if (!ref) {
		return;
	}
	form.addEventListener('submit', function () {
		ref.value = location.pathname + location.search;
	});
})();
