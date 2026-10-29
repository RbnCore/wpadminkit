/* AdminKit — RBN Kit Genel bakış, Cache sekmesi. Sekmeler ortak kit betiğindedir (rbn-admin-ui.js). Bağımlılıksız. */
/* Onay penceresi (form[data-rbn-confirm]) ve eklentiye göre satır süzgeci (select[data-rbn-row-filter=<tbody id>]). */
(function () {
	'use strict';
	Array.prototype.forEach.call(document.querySelectorAll('form[data-rbn-confirm]'), function (f) {
		f.addEventListener('submit', function (e) { if (!window.confirm(f.getAttribute('data-rbn-confirm'))) { e.preventDefault(); } });
	});
	Array.prototype.forEach.call(document.querySelectorAll('select[data-rbn-row-filter]'), function (s) {
		var body = document.getElementById(s.getAttribute('data-rbn-row-filter'));
		var none = document.getElementById('rbn-kit-cache-none');
		if (!body) { return; }
		s.addEventListener('change', function () {
			var shown = 0;
			Array.prototype.forEach.call(body.rows, function (r) { var on = !s.value || r.getAttribute('data-plugin') === s.value; r.hidden = !on; shown += on ? 1 : 0; });
			if (none) { none.hidden = shown > 0; }
		});
	});
}());
