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

	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	function el(tag, cls, txt) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (txt != null) { e.textContent = txt; }
		return e;
	}

	/* ═══ SVG İKON ÜRETİCİ ═══ */
	function icon(name) {
		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('class', 'rbn-ico');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
		use.setAttribute('href', '#i-' + name);
		svg.appendChild(use);
		return svg;
	}

	function i18n(key, fallback) {
		if (window.RbnUiI18n && typeof window.RbnUiI18n[key] === 'string') {
			return window.RbnUiI18n[key];
		}
		return fallback;
	}

	/* ═══ MERKEZİ BİLDİRİM: TOAST (RbnUi.toast) ═══ */
	var toastContainer = null;
	var toastQueue = [];
	var activeToasts = [];
	var MAX_TOASTS = 4;

	function getToastContainer() {
		if (!toastContainer || !document.body.contains(toastContainer)) {
			toastContainer = el('div', 'rbn-toasts');
			var koyu = document.querySelector('.rbn-wrap[data-tone="koyu"]') ||
				document.documentElement.getAttribute('data-tone') === 'koyu' ||
				document.body.getAttribute('data-tone') === 'koyu';
			if (koyu) {
				toastContainer.setAttribute('data-tone', 'koyu');
			}
			document.body.appendChild(toastContainer);
		}
		return toastContainer;
	}

	function spawnToast(opts, type) {
		var container = getToastContainer();
		var role = type === 'error' ? 'alert' : 'status';
		var live = type === 'error' ? 'assertive' : 'polite';

		var toastEl = el('div', 'rbn-toast rbn-toast--' + type);
		toastEl.setAttribute('role', role);
		toastEl.setAttribute('aria-live', live);
		if (opts.id) {
			toastEl.setAttribute('data-toast-id', opts.id);
		}

		// İkon
		var icoMap = { success: 'check', info: 'info', warning: 'alert', error: 'octo' };
		var icoName = icoMap[type] || 'info';
		var icoEl = icon(icoName);
		icoEl.classList.add('rbn-toast__ico');
		toastEl.appendChild(icoEl);

		// Gövde
		var bodyEl = el('div', 'rbn-toast__b');
		if (opts.title) {
			var tEl = el('div', 'rbn-toast__t', opts.title);
			bodyEl.appendChild(tEl);
		}
		var mEl = el('p', 'rbn-toast__m');
		if (typeof opts.message === 'string') {
			mEl.textContent = opts.message;
		} else if (opts.message && opts.message.nodeType) {
			mEl.appendChild(opts.message);
		}
		bodyEl.appendChild(mEl);

		// İsteğe bağlı eylem (action: { label, onClick, url })
		if (opts.action && opts.action.label) {
			var actBox = el('div', 'rbn-toast__act');
			var actBtn;
			if (opts.action.url) {
				actBtn = el('a', 'rbn-btn rbn-btn--sm rbn-btn--pri', opts.action.label);
				actBtn.href = opts.action.url;
			} else {
				actBtn = el('button', 'rbn-btn rbn-btn--sm rbn-btn--pri', opts.action.label);
				actBtn.type = 'button';
				if (typeof opts.action.onClick === 'function') {
					actBtn.addEventListener('click', function (e) {
						opts.action.onClick(e, toastObj);
					});
				}
			}
			actBox.appendChild(actBtn);
			bodyEl.appendChild(actBox);
		}
		toastEl.appendChild(bodyEl);

		// Kapat butonu
		var closeBtn = el('button', 'rbn-toast__close');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', i18n('close', 'Close'));
		closeBtn.appendChild(icon('x'));
		toastEl.appendChild(closeBtn);

		// Otomatik kapanma zamanlayıcısı (error kendiliğinden kapanmaz)
		var duration = typeof opts.duration === 'number' ? opts.duration : (
			type === 'error' ? 0 : (type === 'warning' ? 7000 : 4000)
		);
		var remaining = duration;
		var timer = null;
		var startTime = 0;
		var isLeaving = false;

		function close() {
			if (isLeaving) { return; }
			isLeaving = true;
			if (timer) {
				clearTimeout(timer);
				timer = null;
			}
			toastEl.classList.add('is-leaving');
			var removed = false;
			function removeDom() {
				if (removed) { return; }
				removed = true;
				if (toastEl.parentNode) {
					toastEl.parentNode.removeChild(toastEl);
				}
				var idx = activeToasts.indexOf(toastObj);
				if (idx !== -1) {
					activeToasts.splice(idx, 1);
				}
				// Kuyrukta bekleyen varsa bir sonrakini aç
				if (toastQueue.length > 0) {
					var next = toastQueue.shift();
					spawnToast(next, next.type || 'info');
				}
			}
			toastEl.addEventListener('animationend', removeDom, { once: true });
			setTimeout(removeDom, 300); // animasyonsuz veya reduced-motion güvenlik yedeği
		}

		function startTimer() {
			if (duration > 0 && remaining > 0 && !isLeaving) {
				startTime = Date.now();
				timer = setTimeout(close, remaining);
			}
		}

		function pauseTimer() {
			if (timer) {
				clearTimeout(timer);
				timer = null;
				remaining -= (Date.now() - startTime);
				if (remaining < 500) { remaining = 500; }
			}
		}

		toastEl.addEventListener('pointerenter', pauseTimer);
		toastEl.addEventListener('pointerleave', startTimer);
		toastEl.addEventListener('focusin', pauseTimer);
		toastEl.addEventListener('focusout', startTimer);
		closeBtn.addEventListener('click', close);

		toastEl.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				e.stopPropagation();
				close();
			}
		});

		container.appendChild(toastEl);
		startTimer();

		var toastObj = {
			id: opts.id || null,
			el: toastEl,
			close: close,
			refresh: function (newOpts) {
				if (newOpts.message) {
					mEl.textContent = newOpts.message;
				}
				remaining = typeof newOpts.duration === 'number' ? newOpts.duration : (
					type === 'error' ? 0 : (type === 'warning' ? 7000 : 4000)
				);
				pauseTimer();
				startTimer();
				toastEl.classList.remove('is-leaving');
				toastEl.style.animation = 'none';
				toastEl.offsetHeight; // reflow
				toastEl.style.animation = '';
			}
		};

		activeToasts.push(toastObj);
		return toastObj;
	}

	function toast(opts, maybeOpts) {
		if (typeof opts === 'string') {
			var msg = opts;
			opts = (typeof maybeOpts === 'object' && maybeOpts) ? maybeOpts : {};
			opts.message = msg;
		}
		opts = opts || {};
		var type = opts.type || opts.tone || 'info';
		if (type === 'danger') { type = 'error'; }
		if (type === 'warn') { type = 'warning'; }
		if (['success', 'info', 'warning', 'error'].indexOf(type) === -1) {
			type = 'info';
		}

		// Aynı id varsa yinelenmesin, süresini ve içeriğini yenile
		if (opts.id) {
			for (var i = 0; i < activeToasts.length; i++) {
				if (activeToasts[i].id === opts.id) {
					activeToasts[i].refresh(opts);
					return activeToasts[i];
				}
			}
		}

		if (activeToasts.length >= MAX_TOASTS) {
			toastQueue.push(opts);
			return {
				close: function () {
					var qIdx = toastQueue.indexOf(opts);
					if (qIdx !== -1) { toastQueue.splice(qIdx, 1); }
				}
			};
		}

		return spawnToast(opts, type);
	}

	/* ═══ MERKEZİ ONAY MODALI (RbnUi.confirm) ═══ */
	function confirm(opts) {
		if (typeof opts === 'string') {
			opts = { message: opts };
		}
		opts = opts || {};
		var isDanger = opts.tone === 'danger';
		var title = opts.title || (isDanger ? i18n('confirmDelete', 'Are you sure?') : i18n('confirm', 'Confirm'));
		var confirmLabel = opts.confirmLabel || (isDanger ? i18n('delete', 'Delete') : i18n('ok', 'OK'));
		var cancelLabel = opts.cancelLabel || i18n('cancel', 'Cancel');
		var backdropClose = opts.backdropClose !== false;

		return new Promise(function (resolve) {
			var prevActive = document.activeElement;

			var dlg = el('dialog', 'rbn-dlg rbn-confirm-dlg' + (isDanger ? ' rbn-dlg--danger' : ''));
			var koyu = document.querySelector('.rbn-wrap[data-tone="koyu"]') ||
				document.documentElement.getAttribute('data-tone') === 'koyu' ||
				document.body.getAttribute('data-tone') === 'koyu';
			if (koyu) {
				dlg.setAttribute('data-tone', 'koyu');
			}
			dlg.setAttribute('role', 'dialog');
			dlg.setAttribute('aria-modal', 'true');

			var dlgId = 'rbn-confirm-' + Math.random().toString(36).substring(2, 9);
			var titleId = dlgId + '-title';
			var descId = dlgId + '-desc';
			dlg.setAttribute('aria-labelledby', titleId);
			dlg.setAttribute('aria-describedby', descId);

			// Başlık
			var head = el('div', 'rbn-dlg__h');
			var h2 = el('h2');
			h2.id = titleId;
			var ico = icon(isDanger ? 'alert' : 'info');
			h2.appendChild(ico);
			var titleTxt = el('span', '', title);
			h2.appendChild(titleTxt);
			head.appendChild(h2);

			var closeBtn = el('button', 'rbn-btn rbn-btn--icon');
			closeBtn.type = 'button';
			closeBtn.setAttribute('aria-label', i18n('close', 'Close'));
			closeBtn.appendChild(icon('x'));
			head.appendChild(closeBtn);
			dlg.appendChild(head);

			// Gövde
			var body = el('div', 'rbn-dlg__b');
			var msgP = el('p', '', opts.message || '');
			msgP.id = descId;
			body.appendChild(msgP);
			if (opts.details) {
				var det = el('div', 'rbn-dlg__details', opts.details);
				body.appendChild(det);
			}

			// Çift kontrol metin kutusu (requireText: 'DELETE')
			var requireText = opts.requireText ? String(opts.requireText) : null;
			var requireInput = null;
			if (requireText) {
				var reqWrap = el('div', 'rbn-confirm-require');
				reqWrap.style.marginTop = 'var(--rbn-s4, 1rem)';
				var reqLabelText = opts.requireTextLabel || (i18n('typeToConfirm', 'Type %s to confirm').replace('%s', requireText));
				var reqLabel = el('label', 'rbn-label', reqLabelText);
				requireInput = el('input', 'rbn-input');
				requireInput.type = 'text';
				requireInput.autocomplete = 'off';
				requireInput.setAttribute('aria-label', reqLabelText);
				requireInput.setAttribute('data-confirm-input', '1');
				reqWrap.appendChild(reqLabel);
				reqWrap.appendChild(requireInput);
				body.appendChild(reqWrap);
			}

			dlg.appendChild(body);

			// Altlık
			var foot = el('div', 'rbn-dlg__f');
			var cancelBtn = el('button', 'rbn-btn', cancelLabel);
			cancelBtn.type = 'button';
			cancelBtn.setAttribute('data-confirm-cancel', '1');

			var confirmBtn = el('button', 'rbn-btn ' + (isDanger ? 'rbn-btn--danger' : 'rbn-btn--pri'), confirmLabel);
			confirmBtn.type = 'button';
			confirmBtn.setAttribute('data-confirm-ok', '1');

			if (requireText && requireInput) {
				confirmBtn.disabled = true;
				requireInput.addEventListener('input', function () {
					var val = (requireInput.value || '').trim();
					var match = val.toUpperCase() === requireText.trim().toUpperCase() ||
						val.toLocaleUpperCase('tr') === requireText.trim().toLocaleUpperCase('tr');
					confirmBtn.disabled = !match;
				});
				requireInput.addEventListener('keydown', function (e) {
					if (e.key === 'Enter') {
						e.preventDefault();
						if (!confirmBtn.disabled) {
							cleanup(true);
						}
					}
				});
			}

			foot.appendChild(cancelBtn);
			foot.appendChild(confirmBtn);
			dlg.appendChild(foot);

			document.body.appendChild(dlg);

			var closed = false;
			function cleanup(result) {
				if (closed) { return; }
				closed = true;
				document.documentElement.classList.remove('rbn-locked');
				document.body.classList.remove('rbn-locked');
				if (dlg.open && typeof dlg.close === 'function') {
					dlg.close();
				}
				if (dlg.parentNode) {
					dlg.parentNode.removeChild(dlg);
				}
				if (prevActive && prevActive.focus && document.body.contains(prevActive)) {
					prevActive.focus();
				}
				resolve(result);
			}

			confirmBtn.addEventListener('click', function () { cleanup(true); });
			cancelBtn.addEventListener('click', function () { cleanup(false); });
			closeBtn.addEventListener('click', function () { cleanup(false); });

			dlg.addEventListener('cancel', function (e) {
				e.preventDefault();
				cleanup(false);
			});

			if (backdropClose) {
				dlg.addEventListener('click', function (e) {
					if (e.target === dlg) {
						cleanup(false);
					}
				});
			}

			// Odak tuzağı (Focus trap) & Esc tuşu
			dlg.addEventListener('keydown', function (e) {
				if (e.key === 'Tab') {
					var focusables = dlg.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
					var list = Array.prototype.slice.call(focusables);
					if (!list.length) { return; }
					var first = list[0];
					var last = list[list.length - 1];
					if (e.shiftKey) {
						if (document.activeElement === first) {
							e.preventDefault();
							last.focus();
						}
					} else {
						if (document.activeElement === last) {
							e.preventDefault();
							first.focus();
						}
					}
				} else if (e.key === 'Escape') {
					e.preventDefault();
					cleanup(false);
				}
			});

			// Sayfa kaydırma kilidi (kit scrollbar-gutter:stable sayesinde sayfa kaymaz)
			document.documentElement.classList.add('rbn-locked');
			document.body.classList.add('rbn-locked');

			if (typeof dlg.showModal === 'function') {
				dlg.showModal();
			} else {
				dlg.setAttribute('open', '');
			}

			// İlk odak: requireInput varsa önce giriş kutusuna, danger'da İptal'e, diğerinde onay düğmesine
			if (requireInput) {
				requireInput.focus();
			} else if (isDanger) {
				cancelBtn.focus();
			} else {
				confirmBtn.focus();
			}
		});
	}

	/* ═══ GERİ DÜŞÜŞ: data-rbn-confirm DELEGASYONU ═══ */
	document.addEventListener('submit', function (e) {
		var form = e.target;
		if (!form || typeof form.getAttribute !== 'function') { return; }
		var msg = form.getAttribute('data-rbn-confirm');
		if (msg && !form.__rbnConfirmed) {
			e.preventDefault();
			confirm({
				message: msg,
				tone: 'danger'
			}).then(function (ok) {
				if (ok) {
					form.__rbnConfirmed = true;
					if (typeof form.requestSubmit === 'function') {
						form.requestSubmit();
					} else {
						HTMLFormElement.prototype.submit.call(form);
					}
					form.__rbnConfirmed = false;
				}
			});
		}
	}, true);

	document.addEventListener('click', function (e) {
		var link = e.target.closest ? e.target.closest('a[data-rbn-confirm]') : null;
		if (link && !link.__rbnConfirmed) {
			var msg = link.getAttribute('data-rbn-confirm');
			if (msg) {
				e.preventDefault();
				confirm({
					message: msg,
					tone: 'danger'
				}).then(function (ok) {
					if (ok && link.href) {
						link.__rbnConfirmed = true;
						location.href = link.href;
					}
				});
			}
		}
	}, true);

	window.RbnUi = {
		initTabs: init,
		syncSwitches: syncSwitches,
		$: $,
		$$: $$,
		el: el,
		icon: icon,
		toast: toast,
		confirm: confirm
	};
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { init(); syncSwitches(); });
	} else {
		init();
		syncSwitches();
	}
}());
