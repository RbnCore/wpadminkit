/*! AdminKit veritabanı bakımı — önizleme → onay → partili silme. Onay (RbnUi.confirm) yoksa düğmeler çalışmaz. */
(function (w) {
	'use strict';
	var cfg = w.AdminKit && w.AdminKit.dbmaint;
	if (!cfg || !w.document) { return; }
	var d = w.document, UI = w.RbnUi || {};
	// Fail-closed: onay bileşeni yüklenmediyse hiçbir silme isteği gönderilmez.
	if (typeof UI.confirm !== 'function') { return; }

	function post(action, data) {
		var body = new URLSearchParams(), k, v, i;
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		for (k in data) {
			if (!Object.prototype.hasOwnProperty.call(data, k)) { continue; }
			v = data[k];
			if (Object.prototype.toString.call(v) === '[object Array]') {
				for (i = 0; i < v.length; i++) { body.append(k + '[]', v[i]); }
			} else {
				body.append(k, v);
			}
		}
		// Kimlik çerezi aynı kaynakta gider; yanıt JSON olmazsa (HTML hata sayfası) anlaşılır hata döner.
		return w.fetch(cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) {
			return r.text().then(function (txt) {
				var j;
				try { j = JSON.parse(txt); } catch (e) { throw new Error(cfg.i18n.failed); }
				if (!r.ok || !j || j.success !== true) {
					throw new Error((j && j.data && j.data.message) || cfg.i18n.failed);
				}
				return j.data;
			});
		});
	}

	function bytes(n) {
		var u = ['B', 'KB', 'MB', 'GB'], i = 0;
		n = Number(n) || 0;
		while (n >= 1024 && i < u.length - 1) { n = n / 1024; i++; }
		return (i ? n.toFixed(1) : Math.round(n)) + ' ' + u[i];
	}

	function label(root, type) {
		var el = root.querySelector('[data-dbmaint-row="' + type + '"] label span');
		return el ? el.textContent : type;
	}

	function Panel(root) {
		this.root = root;
		this.status = root.querySelector('[data-dbmaint-status]');
		this.progress = root.querySelector('[data-dbmaint-progress]');
		this.preview = root.querySelector('[data-dbmaint-preview]');
		this.run = root.querySelector('[data-dbmaint-run]');
		this.busy = false;
		var self = this;
		if (this.preview) { this.preview.addEventListener('click', function () { self.onPreview(); }); }
		if (this.run) { this.run.addEventListener('click', function () { self.onRun(); }); }
	}

	Panel.prototype.say = function (text) {
		if (this.status) { this.status.textContent = text || ''; }
	};

	Panel.prototype.step = function (text) {
		if (!this.progress) { return; }
		this.progress.textContent = text || '';
		this.progress.hidden = !text;
	};

	Panel.prototype.selected = function () {
		return Array.prototype.map.call(this.root.querySelectorAll('[data-dbmaint-type]'), function (el) { return el.checked ? el.value : ''; })
			.filter(function (v) { return v; });
	};

	Panel.prototype.lock = function (on) {
		this.busy = on;
		[this.preview, this.run].forEach(function (b) { if (b) { b.disabled = on; } });
	};

	/** Sunucudaki güncel sayıları tabloya yazar (silme sonrası tazeleme de bunu kullanır). */
	Panel.prototype.refresh = function (types) {
		var self = this;
		return post('adminkit_dbmaint_preview', { types: types }).then(function (data) {
			Object.keys(data.types).forEach(function (t) {
				var row = self.root.querySelector('[data-dbmaint-row="' + t + '"]');
				if (!row) { return; }
				row.querySelector('[data-dbmaint-count]').textContent = String(data.types[t].count);
				row.querySelector('[data-dbmaint-bytes]').textContent = bytes(data.types[t].bytes);
			});
			return data;
		});
	};

	Panel.prototype.onPreview = function () {
		if (this.busy) { return; }
		var types = this.selected(), self = this;
		if (!types.length) { this.say(cfg.i18n.none); return; }
		this.lock(true);
		this.say(cfg.i18n.previewing);
		post('adminkit_dbmaint_preview', { types: types }).then(function (data) {
			self.say(data.total.count > 0
				? cfg.i18n.preview.replace('%1$d', String(data.total.count)).replace('%2$s', bytes(data.total.bytes))
				: cfg.i18n.previewEmpty);
		}).catch(function (e) {
			self.say(e.message || cfg.i18n.failed);
		}).then(function () {
			self.lock(false);
		});
	};

	Panel.prototype.onRun = function () {
		if (this.busy) { return; }
		var types = this.selected(), self = this;
		if (!types.length) { this.say(cfg.i18n.none); return; }
		this.lock(true);
		this.say(cfg.i18n.previewing);
		// Onay metnindeki sayılar önizlemeden gelir: kullanıcı ne silineceğini rakamla görür.
		post('adminkit_dbmaint_preview', { types: types }).then(function (data) {
			if (!data.total.count) {
				self.say(cfg.i18n.previewEmpty);
				return false;
			}
			return UI.confirm({
				title: cfg.i18n.confirmTitle,
				message: cfg.i18n.confirmText,
				details: types.map(function (t) {
					var row = data.types[t] || { count: 0, bytes: 0 };
					return label(self.root, t) + ': ' + row.count + ' (' + bytes(row.bytes) + ')';
				}).join('\n'),
				confirmLabel: cfg.i18n.confirmOk,
				tone: 'danger',
				requireText: cfg.confirm,
				requireTextLabel: cfg.i18n.typeToConfirm.replace('%s', cfg.confirm)
			}).then(function (yes) {
				return yes ? self.runAll(types, data) : false;
			});
		}).catch(function (e) {
			self.say(e.message || cfg.i18n.failed);
			return false;
		}).then(function (deleted) {
			self.lock(false);
			if (deleted) {
				self.step('');
				return self.refresh(allTypes(self)).then(function () {
					self.say(cfg.i18n.done.replace('%d', String(deleted)));
				}).catch(function () {
					self.say(cfg.i18n.done.replace('%d', String(deleted)));
				});
			}
			return null;
		});
	};

	function allTypes(self) {
		return Array.prototype.map.call(self.root.querySelectorAll('[data-dbmaint-type]'), function (el) { return el.value; });
	}

	/** Türleri sırayla siler; sunucu bitmedi derse aynı tür için yeniden çağırır (parti ilerlemesi). */
	Panel.prototype.runAll = function (types, plan) {
		var self = this, total = 0, totals = {}, i = 0, t;
		for (i = 0; i < types.length; i++) { totals[types[i]] = (plan.types[types[i]] || {}).count || 0; }
		i = 0;

		function nextType() {
			if (i >= types.length) { return Promise.resolve(total); }
			return nextBatch(types[i], 0);
		}

		function nextBatch(type, doneInType) {
			t = type;
			return post('adminkit_dbmaint_run', { type: type, confirm: cfg.confirm }).then(function (r) {
				total += r.deleted;
				doneInType += r.deleted;
				self.step(cfg.i18n.progress.replace('%1$d', String(doneInType)).replace('%2$d', String(totals[type])).replace('%3$s', label(self.root, type)));
				if (r.stuck) {
					self.say(cfg.i18n.stuck);
					i++;
					return nextType();
				}
				if (r.done) {
					i++;
					return nextType();
				}
				return nextBatch(type, doneInType);
			});
		}

		self.step(cfg.i18n.working);
		return nextType();
	};

	d.addEventListener('DOMContentLoaded', function () {
		d.querySelectorAll('[data-dbmaint]').forEach(function (el) { new Panel(el); });
	});
}(window));
