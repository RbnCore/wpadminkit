/*! AdminKit renk teması — canlı önizleme. Algoritma includes/Color.php ile birebir aynıdır (testle karşılaştırılır). */
(function (w) {
	'use strict';
	var W = '#ffffff', INK = '#1d2327', B = '#000000';
	function rgb(h) { return [parseInt(h.substr(1, 2), 16), parseInt(h.substr(3, 2), 16), parseInt(h.substr(5, 2), 16)]; }
	function lum(h) {
		var l = rgb(h).map(function (c) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); });
		return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2];
	}
	function ratio(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
	function mix(a, b, t) {
		var x = rgb(a), y = rgb(b), o = '#', i, n;
		for (i = 0; i < 3; i++) { n = Math.round(x[i] + (y[i] - x[i]) * t); o += ('0' + n.toString(16)).slice(-2); }
		return o;
	}
	function hex(v) {
		var m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(v || '').trim());
		if (!m) { return ''; }
		m = m[1].toLowerCase();
		return '#' + (m.length === 3 ? m.replace(/./g, '$&$&') : m);
	}
	function fix(bg, against, toward, min) {
		var i, c;
		for (i = 0; i <= 50; i++) {
			c = mix(bg, toward, i / 50);
			if (against.every(function (f) { return ratio(c, f) >= min; })) { return c; }
		}
		return toward;
	}
	function chrome(bg, shell, min) { return fix(bg, [shell === 'koyu' ? W : INK], shell === 'koyu' ? B : W, min); }
	function accent(a, bg, min) { return fix(a, [W, bg], B, min); }
	function trip(h) { return rgb(h).join(', '); }
	function palette(s, min) {
		var koyu = s.shell === 'koyu', to = koyu ? B : W, top = chrome(s.top, s.shell, min), side = chrome(s.side, s.shell, min),
			acc = accent(s.accent, s.bg, min), d10 = mix(acc, B, 0.1), d20 = mix(acc, B, 0.2);
		return {
			acc: acc, acc_rgb: trip(acc), d10: d10, d10_rgb: trip(d10), d20: d20, d20_rgb: trip(d20), bg: s.bg, side: side, top: top,
			sub: mix(side, to, 0.25), bh: mix(top, to, 0.2), bs: mix(top, to, 0.35), t: koyu ? W : INK, w: W
		};
	}
	function css(tpl, s, min) { var p = palette(s, min); return tpl.replace(/%(\w+)%/g, function (m, k) { return p[k]; }); }
	function report(s, min) {
		var t = s.shell === 'koyu' ? W : INK, fx = accent(s.accent, s.bg, min);
		return [
			['menu', 'side', ratio(s.side, t), chrome(s.side, s.shell, min)],
			['bar', 'top', ratio(s.top, t), chrome(s.top, s.shell, min)],
			['link', 'accent', ratio(s.accent, W), fx],
			['page', 'accent', ratio(s.accent, s.bg), fx]
		];
	}
	w.AdminKitColor = { hex: hex, ratio: ratio, mix: mix, palette: palette, css: css, report: report, fix: fix };

	var cfg = w.AdminKit && w.AdminKit.colortheme;
	if (!cfg || !w.document) { return; }
	var d = w.document, ST = 'adminkit-color-preview';

	function Box(root) {
		var self = this, inp = {}, form;
		this.root = root;
		root.querySelectorAll('[data-ck]').forEach(function (el) {
			var k = el.getAttribute('data-ck');
			(inp[k] = inp[k] || []).push(el);
		});
		this.inp = inp;
		this.rep = root.querySelector('[data-ck-report]');
		this.st = root.querySelector('[data-ck-status]');
		this.init = this.read();
		root.addEventListener('input', function (e) { self.on(e); });
		root.addEventListener('change', function (e) { self.on(e); });
		root.addEventListener('click', function (e) {
			var b = e.target.closest && e.target.closest('[data-ck-fix]');
			if (b) { self.set(b.getAttribute('data-ck-fix'), b.getAttribute('data-to')); self.update(); }
		});
		form = root.closest('form') || d;
		form.querySelectorAll('[data-ck-cancel]').forEach(function (b) { b.addEventListener('click', function () { self.cancel(); }); });
		this.update();
	}
	Box.prototype.read = function () {
		var o = { scheme: 'wp', shell: this.inp.shell[0].value }, i = this.inp;
		i.scheme.forEach(function (e) { if (e.checked) { o.scheme = e.value; } });
		['top', 'side', 'accent'].forEach(function (k) { o[k] = i[k][0].value; });
		return o;
	};
	Box.prototype.set = function (k, v) {
		(this.inp[k] || []).forEach(function (e) { e.value = v; });
		(this.inp[k + '-pick'] || []).forEach(function (e) { e.value = v; });
	};
	Box.prototype.on = function (e) {
		var k = e.target.getAttribute && e.target.getAttribute('data-ck'), t, self = this;
		if (!k) { return; }
		if (k.slice(-5) === '-pick') { this.set(k.slice(0, -5), e.target.value); }
		else if (k === 'scheme') {
			t = cfg.themes[e.target.value];
			if (t) { ['top', 'side', 'accent', 'shell'].forEach(function (f) { self.set(f, t[f]); }); }
		} else if (['top', 'side', 'accent'].indexOf(k) > -1 && hex(e.target.value)) { this.set(k + '-pick', hex(e.target.value)); }
		this.update();
	};
	Box.prototype.resolve = function (v) {
		var t = cfg.themes[v.scheme], s;
		if (v.scheme === 'wp') { return null; }
		if (t) { return { top: t.top, side: t.side, accent: t.accent, bg: t.bg, shell: t.shell }; }
		s = { top: hex(v.top), side: hex(v.side), accent: hex(v.accent), bg: cfg.bg, shell: v.shell === 'acik' ? 'acik' : 'koyu' };
		return s.top && s.side && s.accent ? s : null;
	};
	Box.prototype.update = function () {
		var v = this.read(), s = this.resolve(v), real = d.getElementById('adminkit-color'), pv = d.getElementById(ST),
			same = JSON.stringify(v) === JSON.stringify(this.init), rows = '',
			// Tablo her seçim için dolu gelir: WordPress düzeni de kendi renkleriyle ölçülür (panele CSS uygulanmaz).
			base = s || (v.scheme === 'wp' ? cfg.themes.wp : null);
		if (pv) { pv.parentNode.removeChild(pv); }
		if (base) {
			report(base, cfg.min).forEach(function (r) {
				var ok = r[2] >= cfg.min;
				rows += '<li><span class="ak-ct__lbl">' + cfg.i18n[r[0]] + '</span> <b>' + r[2].toFixed(2) + ':1</b> ' +
					'<span class="rbn-badge rbn-badge--' + (ok ? 'ok' : 'err') + '">' + (ok ? cfg.i18n.pass : cfg.i18n.fail) + '</span>' +
					(ok ? '' : ' <span class="rbn-help ak-ct__note">' + cfg.i18n.low.replace('%s', r[3]) + '</span> <button type="button" class="rbn-btn rbn-btn--sm" data-ck-fix="' + r[1] + '" data-to="' + r[3] + '">' + cfg.i18n.fix + '</button>') + '</li>';
			});
		} else {
			rows = '<li><span class="ak-ct__lbl">' + cfg.i18n.invalid + '</span></li>';
		}
		// Aynı içerik yeniden çizilmez: metin kutusundan çıkışta (change) düğme DOM'dan koparsa "Düzelt" tıklaması kaybolur.
		if (rows !== this.rows) { this.rep.innerHTML = rows; this.rows = rows; }
		this.swatch(v);
		if (real) { real.disabled = !same; }
		if (!same) {
			pv = d.createElement('style');
			pv.id = ST;
			pv.textContent = s ? css(cfg.tpl, s, cfg.min) : '';
			d.head.appendChild(pv);
		}
		if (this.st) { this.st.textContent = same ? cfg.i18n.saved : cfg.i18n.preview; }
	};
	// "Özel" kartının şeridi, yazılan özel renkleri gösterir (geçersiz hex'te önceki renk kalır).
	Box.prototype.swatch = function (v) {
		var card = this.root.querySelector('[data-ck-card="custom"] .ak-ct__sw');
		if (!card) { return; }
		['top', 'side', 'accent'].forEach(function (k, i) { if (hex(v[k])) { card.children[i].style.background = hex(v[k]); } });
	};
	Box.prototype.cancel = function () {
		var o = this.init, self = this, r;
		this.inp.scheme.forEach(function (e) { e.checked = e.value === o.scheme; });
		['top', 'side', 'accent', 'shell'].forEach(function (k) { self.set(k, o[k]); });
		r = d.getElementById('adminkit-color');
		if (r) { r.disabled = false; }
		this.update();
	};

	d.addEventListener('DOMContentLoaded', function () {
		d.querySelectorAll('.ak-ct').forEach(function (el) { new Box(el); });
	});
}(window));
