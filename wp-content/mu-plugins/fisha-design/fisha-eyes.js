/* Fisha header fish — pupils follow the mouse. */
(function () {
	'use strict';

	// Fallback: if the server couldn't place the fish in the header, put it there now.
	if (!document.querySelector('.fisha-eyes')) {
		var tpl = document.getElementById('fisha-eyes-fallback');
		var target = document.querySelector('.hostinger-ai-site-navigation-wrapper, header .wp-block-navigation, header');
		if (tpl && target) target.appendChild(tpl.content.cloneNode(true));
	}

	var fishes = Array.prototype.slice.call(document.querySelectorAll('.fisha-eyes'));
	if (!fishes.length) return;
	if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

	// Tuning (fractions of the fish width)
	var EYE_CENTER_X = 0.22;  // where the eyes sit horizontally on the fish
	var EYE_CENTER_Y = 0.54;  // ...and vertically
	var MAX_TRAVEL   = 0.028; // pupils reach the inner rim of the eye (like the reference)
	var EASE_DIST    = 40;    // px of mouse distance to reach full travel

	var mx = null, my = null, ticking = false;

	function update() {
		ticking = false;
		fishes.forEach(function (fish) {
			var pupils = fish.querySelector('.fisha-eyes__pupils');
			var r = fish.getBoundingClientRect();
			if (!pupils || !r.width) return;
			var cx = r.left + r.width * EYE_CENTER_X;
			var cy = r.top + r.height * EYE_CENTER_Y;
			var dx = mx - cx, dy = my - cy;
			var dist = Math.sqrt(dx * dx + dy * dy) || 1;
			var travel = r.width * MAX_TRAVEL * Math.min(1, dist / EASE_DIST);
			pupils.style.setProperty('--fx', (dx / dist * travel).toFixed(2) + 'px');
			pupils.style.setProperty('--fy', (dy / dist * travel).toFixed(2) + 'px');
		});
	}

	function onMove(e) {
		var p = e.touches ? e.touches[0] : e;
		mx = p.clientX; my = p.clientY;
		if (!ticking) { ticking = true; requestAnimationFrame(update); }
	}

	function recenter() {
		fishes.forEach(function (fish) {
			var pupils = fish.querySelector('.fisha-eyes__pupils');
			if (pupils) { pupils.style.setProperty('--fx', '0px'); pupils.style.setProperty('--fy', '0px'); }
		});
	}

	window.addEventListener('mousemove', onMove, { passive: true });
	window.addEventListener('touchmove', onMove, { passive: true });
	document.addEventListener('mouseleave', recenter);
	window.addEventListener('scroll', function () { if (mx !== null && !ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
})();
