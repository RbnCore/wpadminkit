/* AdminKit — RBN Kit Genel bakış sekmeleri: ok tuşları (Sol/Sağ/Home/End) odağı sekmeler arasında taşır, Enter/Boşluk ya da tıklama sekmeyi açar.
   Betik yoksa sekmeler düz bağlantıdır (?tab=). Bağımlılıksız. */
(function () {
	'use strict';
	var list = document.querySelector('.rbn-kit__tabs');
	if (!list) { return; }
	var tabs = Array.prototype.slice.call(list.querySelectorAll('[role="tab"]'));
	function show(tab, push) {
		tabs.forEach(function (t) {
			var on = t === tab;
			t.setAttribute('aria-selected', on ? 'true' : 'false');
			t.tabIndex = on ? 0 : -1;
			var panel = document.getElementById(t.getAttribute('aria-controls'));
			if (panel) { panel.hidden = !on; }
		});
		if (push && window.history && history.replaceState) { history.replaceState(null, '', tab.getAttribute('href')); }
	}
	tabs.forEach(function (tab, i) {
		tab.addEventListener('click', function (e) { e.preventDefault(); show(tab, true); });
		tab.addEventListener('keydown', function (e) {
			var next = -1;
			if (e.key === 'ArrowRight') { next = (i + 1) % tabs.length; }
			else if (e.key === 'ArrowLeft') { next = (i - 1 + tabs.length) % tabs.length; }
			else if (e.key === 'Home') { next = 0; }
			else if (e.key === 'End') { next = tabs.length - 1; }
			else if (e.key === ' ') { e.preventDefault(); show(tab, true); return; }
			if (next < 0) { return; }
			e.preventDefault();
			tabs[next].focus();
		});
	});
}());
