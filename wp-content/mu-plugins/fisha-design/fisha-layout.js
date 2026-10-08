/* Fisha layout helpers — keep the "Fisha Story" text column exactly as wide as the hero headline. */
(function () {
	'use strict';

	// Header menu: give each label its text so CSS can reserve the bold width (no jump on hover)
	document.querySelectorAll('.hostinger-ai-menu .wp-block-navigation-item__label').forEach(function (l) {
		l.setAttribute('data-text', l.textContent.trim());
	});

	// Mobile menu: an arrow next to items with a submenu (Shop) to close / open its list
	document.querySelectorAll('.hostinger-ai-menu .wp-block-navigation .has-child').forEach(function (li) {
		var link = li.querySelector(':scope > a');
		if (!link || li.querySelector(':scope > .fisha-subtoggle')) return;
		var name = link.textContent.trim();
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'fisha-subtoggle';
		btn.setAttribute('aria-expanded', 'true');
		btn.setAttribute('aria-label', 'Close ' + name + ' menu');
		btn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>';
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var closed = li.classList.toggle('fisha-closed');
			btn.setAttribute('aria-expanded', String(!closed));
			btn.setAttribute('aria-label', (closed ? 'Open ' : 'Close ') + name + ' menu');
		});
		link.insertAdjacentElement('afterend', btn);
	});

	var hero = document.querySelector('.elementor-element-25adc73 .elementor-heading-title');
	if (!hero) return;
	function sync() {
		var range = document.createRange();
		range.selectNodeContents(hero);
		var w = range.getBoundingClientRect().width;
		if (w) document.documentElement.style.setProperty('--fisha-hero-width', Math.round(w) + 'px');
	}
	sync();
	window.addEventListener('resize', sync, { passive: true });
	if (document.fonts && document.fonts.ready) document.fonts.ready.then(sync);
})();
