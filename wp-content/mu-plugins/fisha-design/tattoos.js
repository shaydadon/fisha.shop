/* Fisha Tattood — reveal, inline videos, hero sound, lightbox */
(function () {
	var root = document.querySelector('.fisha-tattoos');
	if (!root) return;
	var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
	var cards = [].slice.call(root.querySelectorAll('.ft-card'));
	var gridVideos = [].slice.call(root.querySelectorAll('.ft-card video'));
	var hero = root.querySelector('.ft-hero__video');

	// Reveal cards on scroll + play grid videos only while visible
	if ('IntersectionObserver' in window && !reduce) {
		var io = new IntersectionObserver(function (es) {
			es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } });
		}, { rootMargin: '0px 0px -8% 0px' });
		cards.forEach(function (c) { io.observe(c); });
	} else {
		root.classList.add('ft-noanim');
	}
	function safePlay(v) { var p = v.play(); if (p && p.catch) p.catch(function () {}); }
	if ('IntersectionObserver' in window) {
		var vo = new IntersectionObserver(function (es) {
			es.forEach(function (e) { if (e.isIntersecting && !reduce) safePlay(e.target); else e.target.pause(); });
		}, { threshold: 0.35 });
		gridVideos.forEach(function (v) { vo.observe(v); });
		if (hero) vo.observe(hero);
	}

	// Hero sound toggle
	var sound = root.querySelector('.ft-sound');
	if (sound && hero) {
		sound.addEventListener('click', function () {
			var on = hero.muted;
			hero.muted = !on;
			if (on) safePlay(hero);
			sound.setAttribute('aria-pressed', on ? 'true' : 'false');
			sound.setAttribute('aria-label', on ? 'Turn sound off' : 'Turn sound on');
		});
	}

	// Lightbox
	var lb = root.querySelector('.ft-lightbox');
	var stage = lb.querySelector('.ft-lb__stage');
	var count = lb.querySelector('.ft-lb__count');
	var altEl = lb.querySelector('.ft-lb__alt');
	var buttons = [].slice.call(root.querySelectorAll('.ft-card__open'));
	var cur = -1, lastFocus = null;
	document.body.appendChild(lb); // escape any transformed/overflow ancestors

	function show(i) {
		cur = (i + buttons.length) % buttons.length;
		var b = buttons[cur], el;
		stage.innerHTML = '';
		if (b.dataset.type === 'video') {
			el = document.createElement('video');
			el.src = b.dataset.src; el.poster = b.dataset.poster || '';
			el.controls = true; el.playsInline = true; el.autoplay = true; el.loop = true;
			el.setAttribute('aria-label', b.dataset.alt);
		} else {
			el = document.createElement('img');
			el.src = b.dataset.src; el.alt = b.dataset.alt; el.decoding = 'async';
		}
		stage.appendChild(el);
		count.textContent = (cur + 1) + ' / ' + buttons.length;
		altEl.textContent = b.dataset.alt;
		// preload neighbours (images only)
		[cur + 1, cur - 1].forEach(function (k) {
			var n = buttons[(k + buttons.length) % buttons.length];
			if (n.dataset.type !== 'video') { var im = new Image(); im.src = n.dataset.src; }
		});
	}
	function open(i) {
		lastFocus = document.activeElement;
		if (hero) hero.pause();
		gridVideos.forEach(function (v) { v.pause(); });
		lb.hidden = false;
		document.documentElement.style.overflow = 'hidden';
		show(i);
		lb.querySelector('.ft-lb__close').focus();
	}
	function close() {
		lb.hidden = true;
		stage.innerHTML = '';
		document.documentElement.style.overflow = '';
		if (hero && !reduce) safePlay(hero);
		if (lastFocus) lastFocus.focus();
	}
	buttons.forEach(function (b, i) { b.addEventListener('click', function () { open(i); }); });
	lb.querySelector('.ft-lb__close').addEventListener('click', close);
	lb.querySelector('.ft-lb__prev').addEventListener('click', function () { show(cur - 1); });
	lb.querySelector('.ft-lb__next').addEventListener('click', function () { show(cur + 1); });
	lb.addEventListener('click', function (e) { if (e.target === lb || e.target === stage) close(); });
	document.addEventListener('keydown', function (e) {
		if (lb.hidden) return;
		if (e.key === 'Escape') close();
		else if (e.key === 'ArrowRight') show(cur + 1);
		else if (e.key === 'ArrowLeft') show(cur - 1);
		else if (e.key === 'Tab') { // keep focus inside
			var f = [].slice.call(lb.querySelectorAll('button, video[controls]'));
			var first = f[0], last = f[f.length - 1];
			if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
		}
	});
	// swipe
	var sx = null, sy = null;
	stage.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
	stage.addEventListener('touchend', function (e) {
		if (sx === null) return;
		var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
		if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) show(cur + (dx < 0 ? 1 : -1));
		sx = null;
	});
})();
