/*! RBN yönetim arayüzü kiti — paylaşılan sekme bileşeni (TEK kopya, AdminKit). Handle: rbn-admin-ui.
 * İki kullanım:
 *  1) Panel sekmesi: <ul class="rbn-tabs" data-rbn-tabs role="tablist"> içinde <button role="tab" aria-controls="panel-id" data-key="x">.
 *  2) Sunucu gezintisi: aynı kök, içinde <a class="rbn-tabs__tab" href="?page=..&tab=x" aria-current="page"> — JS yoksa düz bağlantı çalışır.
 * Üst sekme `tab`, alt sekme (`rbn-tabs--sub`) `sub` parametresini yazar; URL history.replaceState ile eşitlenir. Ok/Home/End: roving tabindex.
 */
(function () {
	'use strict';

	function param(list) {
		return list.classList.contains('rbn-tabs--sub') ? 'sub' : 'tab';
	}

	function keyOf(tab, name) {
		if (tab.dataset.key) {
			return tab.dataset.key;
		}
		if (tab.href) {
			try {
				var v = new URL(tab.href, location.href).searchParams.get(name);
				if (v) {
					return v;
				}
			} catch (e) { /* geçersiz bağlantı: kimliğe düş */ }
		}
		return tab.id || '';
	}

	function Tabs(list) {
		this.list = list;
		this.name = param(list);
		this.tabs = [].slice.call(list.querySelectorAll('.rbn-tabs__tab'));
		this.panels = this.tabs.some(function (t) { return t.getAttribute('aria-controls'); });
		if (!this.tabs.length) {
			return;
		}
		var self = this;
		this.tabs.forEach(function (tab, i) {
			if (self.panels) {
				tab.addEventListener('click', function (e) {
					if (tab.tagName === 'A') {
						e.preventDefault();
					}
					self.show(tab, true);
				});
			}
			tab.addEventListener('keydown', function (e) { self.key(e, i); });
		});
		this.start();
	}

	Tabs.prototype.start = function () {
		var self = this;
		var want = new URLSearchParams(location.search).get(this.name);
		var current = this.tabs.filter(function (t) { return keyOf(t, self.name) === want; })[0] ||
			this.tabs.filter(function (t) { return t.getAttribute('aria-selected') === 'true' || t.getAttribute('aria-current') === 'page'; })[0] ||
			this.tabs[0];
		if (this.panels) {
			this.show(current, false);
		} else {
			this.mark(current);
		}
	};

	Tabs.prototype.mark = function (active) {
		var panels = this.panels;
		this.tabs.forEach(function (t) {
			var on = t === active;
			t.tabIndex = on ? 0 : -1;
			if (panels) {
				t.setAttribute('aria-selected', on ? 'true' : 'false');
			}
		});
	};

	Tabs.prototype.show = function (active, sync) {
		this.mark(active);
		this.tabs.forEach(function (t) {
			var id = t.getAttribute('aria-controls');
			var p = id ? document.getElementById(id) : null;
			if (p) {
				p.hidden = t !== active;
			}
		});
		if (sync && window.history && history.replaceState) {
			var url = new URL(location.href);
			url.searchParams.set(this.name, keyOf(active, this.name));
			history.replaceState(null, '', url.toString());
		}
	};

	Tabs.prototype.key = function (e, i) {
		var n = -1, len = this.tabs.length;
		if (e.key === 'ArrowRight') { n = (i + 1) % len; }
		else if (e.key === 'ArrowLeft') { n = (i - 1 + len) % len; }
		else if (e.key === 'Home') { n = 0; }
		else if (e.key === 'End') { n = len - 1; }
		if (n < 0) {
			return;
		}
		e.preventDefault();
		var next = this.tabs[n];
		this.tabs.forEach(function (t) { t.tabIndex = t === next ? 0 : -1; });
		next.focus();
		if (this.panels) {
			this.show(next, true);
		}
	};

	function init(root) {
		(root || document).querySelectorAll('[data-rbn-tabs]').forEach(function (list) {
			if (!list.__rbnTabs) {
				list.__rbnTabs = new Tabs(list);
			}
		});
	}

	/* Giriş biçimi: fare/dokunma → <html class="rbn-mouse"> (sekme odak halkası gizlenir); klavye tuşu → sınıf kalkar. */
	var root = document.documentElement;
	['pointerdown', 'mousedown', 'touchstart'].forEach(function (ev) {
		document.addEventListener(ev, function () { root.classList.add('rbn-mouse'); }, true);
	});
	document.addEventListener('keydown', function (e) {
		if (['Tab', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].indexOf(e.key) !== -1) {
			root.classList.remove('rbn-mouse');
		}
	}, true);

	/* Anahtar durum yazısı: <label class="rbn-switch"> içinde data-on / data-off taşıyan öğe (ör. .rbn-switch__st) anahtarla canlı eşlenir.
	 * Kaydedilmeden de görünüm ile yazı tutarlı kalır; tarayıcının geri yüklediği durum da sayfa açılışında eşitlenir. */
	function syncSwitch(input) {
		var label = input.closest ? input.closest('.rbn-switch') : null;
		if (!label) {
			return;
		}
		label.querySelectorAll('[data-on][data-off]').forEach(function (el) {
			var text = input.checked ? el.getAttribute('data-on') : el.getAttribute('data-off');
			if (el.textContent !== text) {
				el.textContent = text;
			}
		});
	}

	function syncSwitches(scope) {
		(scope || document).querySelectorAll('.rbn-switch input[type="checkbox"]').forEach(syncSwitch);
	}

	document.addEventListener('change', function (e) {
		if (e.target && e.target.matches && e.target.matches('.rbn-switch input[type="checkbox"]')) {
			syncSwitch(e.target);
		}
	});
	window.addEventListener('pageshow', function () { syncSwitches(); });

	window.RbnUi = { initTabs: init, syncSwitches: syncSwitches };
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { init(); syncSwitches(); });
	} else {
		init();
		syncSwitches();
	}
}());
