/* AdminKit — yeniden kullanılabilir günlük listesi (handle: adminkit-core-logs).
   Kullanım: <div data-rbnkit-logs data-channel="eklenti.ad"></div>
   Ayarlar (data-*): channel · channels (JSON: [{id,plugin,name}], verilirse kanal seçici çıkar) · per-page (1-50, varsayılan 8) · filters (1 = süzgeç çubuğu) · root · nonce.
   Betikle: window.RbnKitLogs.init(element, { channel, perPage, filters, channels, root, nonce }).
   Veri: GET rbnkit/v1/logs/<kanal>. Kanal/eklenti adı bilmez. Bağımlılıksız. */
(function () {
	'use strict';
	var cfg = window.rbnkitLogs || { root: '', nonce: '', i18n: {} };
	var t = cfg.i18n || {};
	var seq = 0;

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined) { n.textContent = text; }
		return n;
	}
	function label(text, control, cls) {
		var wrap = el('div', 'rbnkit-logs__field' + (cls ? ' ' + cls : ''));
		var l = el('label', '', text);
		l.htmlFor = control.id;
		wrap.appendChild(l);
		wrap.appendChild(control);
		return wrap;
	}
	function clamp(n, lo, hi, def) {
		n = parseInt(n, 10);
		return isNaN(n) ? def : Math.max(lo, Math.min(hi, n));
	}
	var LEVELS = { debug: ['editor-code', 'debug'], info: ['info-outline', 'info'], warning: ['warning', 'warning'], error: ['dismiss', 'errorLevel'] };

	function fmtTime(iso) {
		var d = new Date(iso + 'Z');
		if (isNaN(d.getTime())) { return iso; }
		return d.toLocaleString(document.documentElement.lang || undefined, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
	}
	function flat(value, out, prefix, depth) {
		if (value !== null && typeof value === 'object' && depth < 3) {
			Object.keys(value).forEach(function (k) { flat(value[k], out, prefix ? prefix + '.' + k : k, depth + 1); });
		} else {
			out.push([prefix, value === null ? 'null' : String(value)]);
		}
		return out;
	}

	function init(root, opts) {
		opts = opts || {};
		var id = 'rbnkit-logs-' + (++seq);
		var data = root.dataset;
		var channels = opts.channels;
		if (!channels && data.channels) { try { channels = JSON.parse(data.channels); } catch (e) { channels = null; } }
		var state = {
			channel: opts.channel || data.channel || (channels && channels[0] && channels[0].id) || '',
			perPage: clamp(opts.perPage || data.perPage, 1, 50, 8),
			filters: (opts.filters !== undefined ? opts.filters : data.filters === '1') ? true : false,
			base: opts.root || data.root || cfg.root,
			nonce: opts.nonce || data.nonce || cfg.nonce,
			page: 1, level: '', type: '', from: '', to: '', total: 0
		};
		var ctrl = null;
		root.classList.add('rbnkit-logs');
		root.textContent = '';

		var bar = el('div', 'rbnkit-logs__bar');
		var chan, level, type, from, to;
		if (channels && channels.length > 1) {
			chan = el('select'); chan.id = id + '-channel';
			var groups = {};
			channels.forEach(function (c) {
				var g = groups[c.plugin];
				if (!g) { g = groups[c.plugin] = el('optgroup'); g.label = c.plugin; chan.appendChild(g); }
				var o = el('option', '', c.id); o.value = c.id; g.appendChild(o);
			});
			chan.value = state.channel;
			bar.appendChild(label(t.channel, chan));
			chan.addEventListener('change', function () { state.channel = chan.value; state.page = 1; resetFilters(true); load(); });
		}
		if (state.filters) {
			level = el('select'); level.id = id + '-level';
			var all = el('option', '', t.allLevels); all.value = ''; level.appendChild(all);
			Object.keys(LEVELS).forEach(function (k) { var o = el('option', '', t[LEVELS[k][1]]); o.value = k; level.appendChild(o); });
			type = el('input'); type.type = 'text'; type.id = id + '-type'; type.maxLength = 60; type.setAttribute('autocomplete', 'off');
			from = el('input'); from.type = 'date'; from.id = id + '-from';
			to = el('input'); to.type = 'date'; to.id = id + '-to';
			bar.appendChild(label(t.level, level));
			bar.appendChild(label(t.eventType, type));
			bar.appendChild(label(t.from, from));
			bar.appendChild(label(t.to, to));
			var reset = el('button', 'button rbnkit-logs__reset', t.reset); reset.type = 'button';
			bar.appendChild(reset);
			var timer = null;
			var apply = function () { state.level = level.value; state.type = type.value.trim(); state.from = from.value; state.to = to.value; state.page = 1; load(); };
			level.addEventListener('change', apply); from.addEventListener('change', apply); to.addEventListener('change', apply);
			type.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(apply, 350); });
			reset.addEventListener('click', function () { resetFilters(true); state.page = 1; load(); });
		}
		function resetFilters(clear) {
			state.level = state.type = state.from = state.to = '';
			if (clear && level) { level.value = ''; type.value = ''; from.value = ''; to.value = ''; }
		}
		if (bar.childNodes.length) { root.appendChild(bar); }
		var live = el('div', 'rbnkit-logs__body'); live.setAttribute('aria-live', 'polite');
		var pager = el('div', 'rbnkit-logs__pager');
		root.appendChild(live);
		root.appendChild(pager);

		function message(kind, text, action) {
			live.textContent = '';
			live.removeAttribute('aria-busy');
			var box = el('div', 'rbnkit-logs__msg is-' + kind);
			if (kind === 'error') { box.setAttribute('role', 'alert'); }
			box.appendChild(el('p', '', text));
			if (action) { box.appendChild(action); }
			live.appendChild(box);
			pager.textContent = '';
		}

		function row(item, n) {
			var ctx = flat(item.context || {}, [], '', 0).filter(function (p) { return p[0] !== ''; });
			var tr = el('tr', 'rbnkit-logs__row');
			var td = el('td', '', ''); td.dataset.label = t.time;
			var time = el('time', '', fmtTime(item.at)); time.dateTime = item.at + 'Z'; td.appendChild(time); tr.appendChild(td);
			var lv = LEVELS[item.level] || ['info-outline', 'info'];
			td = el('td'); td.dataset.label = t.level;
			var badge = el('span', 'rbnkit-logs__level is-' + item.level);
			badge.appendChild(el('span', 'dashicons dashicons-' + lv[0]));
			badge.firstChild.setAttribute('aria-hidden', 'true');
			badge.appendChild(el('span', '', t[lv[1]] || item.level));
			td.appendChild(badge); tr.appendChild(td);
			td = el('td', 'rbnkit-logs__event'); td.dataset.label = t.event; td.appendChild(el('code', '', item.event)); tr.appendChild(td);
			td = el('td', 'rbnkit-logs__msgcell', item.message); td.dataset.label = t.message; tr.appendChild(td);
			td = el('td', 'rbnkit-logs__ctx'); td.dataset.label = t.context;
			if (!ctx.length) {
				td.appendChild(el('span', 'rbnkit-logs__muted', t.noContext));
				tr.appendChild(td);
				return [tr];
			}
			var btn = el('button', 'rbnkit-logs__toggle'); btn.type = 'button';
			var panelId = id + '-ctx-' + n;
			btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-controls', panelId);
			var summary = ctx.slice(0, 3).map(function (p) { return p[0] + '=' + p[1]; }).join(', ');
			btn.appendChild(el('span', 'dashicons dashicons-arrow-down-alt2')); btn.firstChild.setAttribute('aria-hidden', 'true');
			btn.appendChild(el('span', 'rbnkit-logs__sum', summary));
			btn.appendChild(el('span', 'rbnkit-logs__sr', ' — ' + t.show));
			td.appendChild(btn); tr.appendChild(td);
			var dr = el('tr', 'rbnkit-logs__detail'); dr.id = panelId; dr.hidden = true;
			var dc = el('td'); dc.colSpan = 5;
			var dl = el('dl', 'rbnkit-logs__kv');
			ctx.forEach(function (p) { dl.appendChild(el('dt', '', p[0])); dl.appendChild(el('dd', '', p[1])); });
			dc.appendChild(dl); dr.appendChild(dc);
			btn.addEventListener('click', function () {
				var open = btn.getAttribute('aria-expanded') !== 'true';
				btn.setAttribute('aria-expanded', open ? 'true' : 'false');
				btn.lastChild.textContent = ' — ' + (open ? t.hide : t.show);
				dr.hidden = !open;
				tr.classList.toggle('is-open', open);
			});
			return [tr, dr];
		}

		function render(res) {
			var pages = Math.max(1, Math.ceil(res.total / state.perPage));
			live.textContent = '';
			live.removeAttribute('aria-busy');
			var table = el('table', 'rbnkit-logs__table');
			var head = el('tr');
			[t.time, t.level, t.event, t.message, t.context].forEach(function (h) { var th = el('th', '', h); th.scope = 'col'; head.appendChild(th); });
			var thead = el('thead'); thead.appendChild(head); table.appendChild(thead);
			var tbody = el('tbody');
			res.items.forEach(function (item, i) { row(item, i).forEach(function (r) { tbody.appendChild(r); }); });
			table.appendChild(tbody);
			live.appendChild(table);
			pager.textContent = '';
			pager.appendChild(el('span', 'rbnkit-logs__count', t.pageOf.replace('%1$s', state.page).replace('%2$s', pages).replace('%3$s', res.total)));
			var prev = el('button', 'button', t.prev); prev.type = 'button'; prev.disabled = state.page <= 1;
			var next = el('button', 'button', t.next); next.type = 'button'; next.disabled = state.page >= pages;
			prev.addEventListener('click', function () { state.page--; load(); });
			next.addEventListener('click', function () { state.page++; load(); });
			pager.appendChild(prev); pager.appendChild(next);
		}

		function load() {
			if (!state.channel) { return; }
			if (ctrl) { ctrl.abort(); }
			ctrl = window.AbortController ? new AbortController() : null;
			var q = ['page=' + state.page, 'per_page=' + state.perPage];
			if (state.level) { q.push('level=' + encodeURIComponent(state.level)); }
			if (state.type) { q.push('type=' + encodeURIComponent(state.type)); }
			if (state.from) { q.push('from=' + encodeURIComponent(state.from)); }
			if (state.to) { q.push('to=' + encodeURIComponent(state.to)); }
			live.setAttribute('aria-busy', 'true');
			live.textContent = '';
			live.appendChild(el('p', 'rbnkit-logs__loading', t.loading));
			pager.textContent = '';
			var url = state.base + encodeURIComponent(state.channel).replace('%2E', '.') + (state.base.indexOf('?') > -1 ? '&' : '?') + q.join('&');
			fetch(url, { headers: { 'X-WP-Nonce': state.nonce, Accept: 'application/json' }, credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
				.then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
				.then(function (res) {
					state.total = res.total;
					if (!res.items.length) {
						if (state.page > 1 && res.total > 0) { state.page = Math.max(1, Math.ceil(res.total / state.perPage)); load(); return; }
						message('empty', state.level || state.type || state.from || state.to ? t.noMatch : t.empty);
						return;
					}
					render(res);
				})
				.catch(function (e) {
					if (e && e.name === 'AbortError') { return; }
					var retry = el('button', 'button', t.retry); retry.type = 'button';
					retry.addEventListener('click', load);
					message('error', t.error, retry);
				});
		}
		load();
	}

	window.RbnKitLogs = { init: init };
	function boot() { Array.prototype.forEach.call(document.querySelectorAll('[data-rbnkit-logs]'), function (n) { if (!n.classList.contains('rbnkit-logs')) { init(n); } }); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
}());
