/*! AdminKit sistem sağlığı — destek raporunu panoya kopyalar (pano yoksa metni seçip execCommand'a düşer). */
(function (w) {
	'use strict';
	var cfg = w.AdminKit && w.AdminKit.systemhealth;
	var d = w.document;
	if (!cfg || !d) { return; }

	function copyText(text) {
		// Tercih: async pano API'si (yalnız güvenli bağlamda; güvenli değilse yok sayılır).
		if (w.navigator && w.navigator.clipboard && typeof w.navigator.clipboard.writeText === 'function' && w.isSecureContext) {
			return w.navigator.clipboard.writeText(text).then(function () { return true; }, function () { return legacy(text); });
		}
		return Promise.resolve(legacy(text));
	}

	/** Eski yol: metin alanını seç, execCommand('copy'). Seçim sonrası eski konuma dönülür. */
	function legacy(text) {
		var area = d.getElementById('adminkit-health-report');
		var host = area;
		var created = false;
		if (!area) {
			area = d.createElement('textarea');
			area.value = text;
			area.setAttribute('readonly', 'readonly');
			area.style.position = 'fixed';
			area.style.top = '-1000px';
			d.body.appendChild(area);
			created = true;
		}
		var prev = d.activeElement;
		var ok = false;
		try {
			area.focus();
			area.select();
			area.setSelectionRange(0, area.value.length);
			ok = d.execCommand && d.execCommand('copy');
		} catch (e) {
			ok = false;
		}
		if (created && host && host.parentNode) {
			host.parentNode.removeChild(host);
		}
		if (prev && typeof prev.focus === 'function') { prev.focus(); }
		return !!ok;
	}

	function say(root, text) {
		var el = root.querySelector('[data-health-status]');
		if (el) { el.textContent = text || ''; }
	}

	function toast(text, type) {
		var UI = w.RbnUi;
		if (UI && typeof UI.toast === 'function') { UI.toast(text, { type: type }); }
	}

	function onClick(root) {
		var area = d.getElementById('adminkit-health-report');
		var text = area ? area.value : '';
		if (!text) { return; }
		copyText(text).then(function (ok) {
			say(root, ok ? cfg.i18n.copied : cfg.i18n.copyFail);
			toast(ok ? cfg.i18n.copied : cfg.i18n.copyFail, ok ? 'success' : 'error');
		});
	}

	/* Kök başına bir kez bağlanır (işaret kökte): ilk yükleme, geç yüklenen betik ve canlı panel değişimi
	 * (`rbn:panel-swapped`, WPRJ-738) aynı yoldan geçer. */
	function boot(scope) {
		var roots = (scope || d).querySelectorAll('[data-health]');
		Array.prototype.forEach.call(roots, function (root) {
			var btn = root.querySelector('[data-health-copy]');
			if (!btn || root.__rbnHealth) { return; }
			root.__rbnHealth = true;
			btn.addEventListener('click', function () { onClick(root); });
		});
	}
	if (d.readyState === 'loading') {
		d.addEventListener('DOMContentLoaded', function () { boot(); });
	} else {
		boot();
	}
	d.addEventListener('rbn:panel-swapped', function (e) { boot(e.target && e.target.nodeType === 1 ? e.target : d); });
}(window));
