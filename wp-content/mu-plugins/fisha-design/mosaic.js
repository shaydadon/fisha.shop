/* River — sketch mosaic: masonry columns, scroll reveal, gentle parallax, zoom viewer. */
(function () {
	'use strict';
	var root = document.querySelector('.fisha-mosaic');
	if (!root) return;
	var grid = root.querySelector('.fisha-mosaic__grid');
	var items = Array.prototype.slice.call(grid.querySelectorAll('.fisha-mosaic__item'));
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	function now() { return performance.now(); }

	/* ---------- masonry: deal sheets into the shortest column ---------- */
	var cols = [], colCount = 0;
	function countFor(w) { return w >= 1180 ? 4 : w >= 760 ? 3 : 2; }
	function ratio(it) { var img = it.querySelector('img'); return (+img.getAttribute('height') || 1) / (+img.getAttribute('width') || 1); }
	function layout() {
		var n = countFor(grid.clientWidth || window.innerWidth);
		if (n === colCount) return;
		colCount = n;
		cols.forEach(function (c) { c.remove(); });
		cols = [];
		var heights = [];
		for (var i = 0; i < n; i++) {
			var c = document.createElement('div');
			c.className = 'fisha-mosaic__col';
			grid.appendChild(c); cols.push(c); heights.push(0);
		}
		items.forEach(function (it) {
			var k = heights.indexOf(Math.min.apply(null, heights));
			cols[k].appendChild(it);
			heights[k] += ratio(it) + 0.12; // + gap/tape allowance
		});
		grid.classList.add('is-masonry');
		parallax();
	}

	/* ---------- gentle parallax: columns drift at slightly different speeds ---------- */
	var SPEEDS = [0, 0.07, -0.035, 0.05];
	var ticking = false;
	function parallax() {
		ticking = false;
		if (reduceMotion) return;
		var r = grid.getBoundingClientRect();
		var progress = (window.innerHeight - r.top) / (window.innerHeight + r.height); // 0 → 1 while the board passes
		progress = Math.max(0, Math.min(1, progress)) - 0.5;
		cols.forEach(function (c, i) {
			c.style.transform = 'translate3d(0,' + (progress * r.height * SPEEDS[i % SPEEDS.length]).toFixed(1) + 'px,0)';
		});
	}
	window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(parallax); } }, { passive: true });
	window.addEventListener('resize', function () { layout(); parallax(); }, { passive: true });

	/* ---------- reveal sheets as they float into view ---------- */
	function reveal() {
		if (!('IntersectionObserver' in window) || reduceMotion) { items.forEach(function (it) { it.classList.add('is-in'); }); return; }
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) {
				if (!e.isIntersecting) return;
				var it = e.target;
				it.style.transitionDelay = (Array.prototype.indexOf.call(it.parentNode.children, it) % 3) * 70 + 'ms';
				it.classList.add('is-in');
				io.unobserve(it);
			});
		}, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
		items.forEach(function (it) { io.observe(it); });
	}

	layout();
	root.classList.add('is-ready');
	reveal();

	/* ---------- zoom viewer ---------- */
	var list = items.map(function (it) {
		var b = it.querySelector('.fisha-mosaic__open'), img = it.querySelector('img');
		return { tile: img.currentSrc || img.src, full: b.getAttribute('data-full'), fw: +b.getAttribute('data-fw'), fh: +b.getAttribute('data-fh') };
	});
	var box, stage, vimg, countEl, hintEl, returnFocus, open = false;
	var cur = 0, fitW = 0, fitH = 0, s = 1, tx = 0, ty = 0, maxS = 4, pointers = {}, pinchDist = 0, pinchS = 1, panStart = null, tapTime = 0;

	function icon(d) { return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + d + '"/></svg>'; }
	function build() {
		box = document.createElement('div');
		box.className = 'fisha-lightbox';
		box.hidden = true;
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-modal', 'true');
		box.setAttribute('aria-label', 'Sketch viewer');
		var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
		box.innerHTML =
			'<div class="fisha-lightbox__stage"><img class="fisha-lightbox__img" alt="" draggable="false"></div>' +
			'<div class="fisha-lightbox__hint">' + (coarse ? 'Pinch or double-tap to zoom · drag to move' : 'Scroll or double-click to zoom · drag to move') + '</div>' +
			'<button type="button" class="fisha-river__btn fisha-lightbox__close" aria-label="Close">' + icon('M6 6l12 12M18 6L6 18') + '</button>' +
			'<div class="fisha-lightbox__bar">' +
				'<button type="button" class="fisha-river__btn" data-lb="prev" aria-label="Previous sketch">' + icon('M15 5l-7 7 7 7') + '</button>' +
				'<button type="button" class="fisha-river__btn" data-lb="out" aria-label="Zoom out">' + icon('M6 12h12') + '</button>' +
				'<span class="fisha-lightbox__count" aria-live="polite"></span>' +
				'<button type="button" class="fisha-river__btn" data-lb="in" aria-label="Zoom in">' + icon('M12 6v12M6 12h12') + '</button>' +
				'<button type="button" class="fisha-river__btn" data-lb="next" aria-label="Next sketch">' + icon('M9 5l7 7-7 7') + '</button>' +
			'</div>';
		document.body.appendChild(box);
		stage = box.querySelector('.fisha-lightbox__stage');
		vimg = box.querySelector('.fisha-lightbox__img');
		countEl = box.querySelector('.fisha-lightbox__count');
		hintEl = box.querySelector('.fisha-lightbox__hint');
		box.querySelector('.fisha-lightbox__close').addEventListener('click', close);
		box.querySelector('[data-lb="prev"]').addEventListener('click', function () { show(cur - 1); });
		box.querySelector('[data-lb="next"]').addEventListener('click', function () { show(cur + 1); });
		box.querySelector('[data-lb="in"]').addEventListener('click', function () { zoomAt(s * 1.6, 0, 0, true); });
		box.querySelector('[data-lb="out"]').addEventListener('click', function () { zoomAt(s / 1.6, 0, 0, true); });
		stage.addEventListener('wheel', function (e) { e.preventDefault(); var p = local(e); zoomAt(s * Math.exp(-e.deltaY * 0.0018), p.x, p.y, false); }, { passive: false });
		stage.addEventListener('dblclick', function (e) { var p = local(e); zoomAt(s > 1.05 ? 1 : Math.min(maxS, 2.5), p.x, p.y, true); });
		stage.addEventListener('pointerdown', onDown);
		stage.addEventListener('pointermove', onMove);
		stage.addEventListener('pointerup', onUp);
		stage.addEventListener('pointercancel', onUp);
		stage.addEventListener('click', function (e) { if (e.target === stage && s <= 1.01) close(); });
		document.addEventListener('keydown', function (e) {
			if (!open) return;
			if (e.key === 'Escape') close();
			else if (e.key === 'ArrowRight') show(cur + 1);
			else if (e.key === 'ArrowLeft') show(cur - 1);
			else if (e.key === '+' || e.key === '=') zoomAt(s * 1.6, 0, 0, true);
			else if (e.key === '-') zoomAt(s / 1.6, 0, 0, true);
			else if (e.key === '0') zoomAt(1, 0, 0, true);
			else if (e.key === 'Tab') {
				var f = box.querySelectorAll('button'), first = f[0], last = f[f.length - 1];
				if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
				else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
			}
		});
		window.addEventListener('resize', function () { if (open) fit(true); });
	}
	function local(e) { var r = stage.getBoundingClientRect(); return { x: e.clientX - r.left - r.width / 2, y: e.clientY - r.top - r.height / 2 }; }
	function apply(animate) {
		vimg.classList.toggle('is-animating', !!animate);
		vimg.style.transform = 'translate(' + (tx - fitW * s / 2) + 'px,' + (ty - fitH * s / 2) + 'px) scale(' + s + ')';
		box.classList.toggle('is-zoomed', s > 1.01);
	}
	function clamp() {
		var r = stage.getBoundingClientRect();
		var mx = Math.max(0, (fitW * s - r.width) / 2), my = Math.max(0, (fitH * s - r.height) / 2);
		tx = Math.max(-mx, Math.min(mx, tx)); ty = Math.max(-my, Math.min(my, ty));
	}
	function zoomAt(ns, px, py, animate) {
		ns = Math.max(1, Math.min(maxS, ns));
		tx = px - (px - tx) * (ns / s); ty = py - (py - ty) * (ns / s); s = ns;
		if (s <= 1.001) { tx = 0; ty = 0; }
		clamp(); apply(animate);
		hintEl.style.opacity = '0';
	}
	function fit(keep) {
		var r = stage.getBoundingClientRect(), it = list[cur], ratio = it.fw / it.fh;
		var aw = r.width - 64, ah = r.height - 64;
		fitW = Math.min(aw, ah * ratio); fitH = fitW / ratio;
		vimg.style.width = fitW + 'px'; vimg.style.height = fitH + 'px';
		maxS = Math.max(3, (it.fw / fitW) * 1.5);
		if (!keep) { s = 1; tx = 0; ty = 0; }
		clamp(); apply(false);
	}
	function show(i) {
		var n = list.length; cur = (i % n + n) % n;
		var it = list[cur];
		vimg.src = it.tile;
		fit(false);
		countEl.textContent = (cur + 1) + ' / ' + n;
		var hi = new Image(); hi.onload = function () { if (list[cur] === it) vimg.src = it.full; }; hi.src = it.full;
		var nx = new Image(); nx.src = list[(cur + 1) % n].full;
	}
	function openAt(i, from) {
		if (!box) build();
		returnFocus = from; open = true; box.hidden = false;
		document.documentElement.style.overflow = 'hidden';
		show(i);
		hintEl.style.opacity = '1';
		setTimeout(function () { hintEl.style.opacity = '0'; }, 3500);
		requestAnimationFrame(function () { box.classList.add('is-open'); });
		box.querySelector('.fisha-lightbox__close').focus();
	}
	function close() {
		open = false; box.classList.remove('is-open');
		document.documentElement.style.overflow = '';
		setTimeout(function () { if (!open) box.hidden = true; }, 250);
		if (returnFocus && returnFocus.focus) returnFocus.focus({ preventScroll: true });
	}
	function onDown(e) {
		stage.setPointerCapture(e.pointerId);
		pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
		var ids = Object.keys(pointers);
		if (ids.length === 2) { var a = pointers[ids[0]], b = pointers[ids[1]]; pinchDist = Math.hypot(a.x - b.x, a.y - b.y); pinchS = s; panStart = null; }
		else if (ids.length === 1) {
			if (e.pointerType !== 'mouse') {
				var t = now();
				if (t - tapTime < 300) { var p = local(e); zoomAt(s > 1.05 ? 1 : Math.min(maxS, 2.5), p.x, p.y, true); tapTime = 0; return; }
				tapTime = t;
			}
			panStart = { x: e.clientX, y: e.clientY, tx: tx, ty: ty };
			if (s > 1.01) box.classList.add('is-panning');
		}
	}
	function onMove(e) {
		if (!pointers[e.pointerId]) return;
		pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
		var ids = Object.keys(pointers);
		if (ids.length === 2) {
			var a = pointers[ids[0]], b = pointers[ids[1]], r = stage.getBoundingClientRect();
			zoomAt(pinchS * Math.hypot(a.x - b.x, a.y - b.y) / pinchDist, (a.x + b.x) / 2 - r.left - r.width / 2, (a.y + b.y) / 2 - r.top - r.height / 2, false);
		} else if (panStart && s > 1.01) {
			tx = panStart.tx + (e.clientX - panStart.x); ty = panStart.ty + (e.clientY - panStart.y);
			clamp(); apply(false);
		}
	}
	function onUp(e) {
		delete pointers[e.pointerId];
		if (Object.keys(pointers).length < 2) pinchDist = 0;
		if (!Object.keys(pointers).length) { panStart = null; box.classList.remove('is-panning'); }
	}

	grid.addEventListener('click', function (e) {
		var b = e.target.closest('.fisha-mosaic__open');
		if (!b) return;
		openAt(parseInt(b.closest('.fisha-mosaic__item').getAttribute('data-index'), 10) || 0, b);
	});
})();
