/* River — slow drifting carousel, zoom viewer and background music. */
(function () {
	'use strict';
	var root = document.querySelector('.fisha-river');
	if (!root) return;
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	function now() { return performance.now(); }

	/* ------------------------------------------------------------------ carousel */
	var track = root.querySelector('.fisha-river__track');
	var originals = track ? Array.prototype.slice.call(track.querySelectorAll('.fisha-river__slide')) : [];
	var SPEED = 22;          // px per second — slow river drift
	var DIR = root.getAttribute('data-direction') === 'right' ? -1 : 1; // -1: paintings flow left → right
	var FISH_WITH = root.getAttribute('data-fish') === 'with' || DIR < 0; // Fisha moves along with the paintings
	var RESUME_AFTER = 3500; // ms of calm before drifting again
	var loopW = 0, pos = 0, last = null;
	var hover = false, dragging = false, focusIn = false, userPaused = false, viewerOpen = false;
	var holdUntil = 0, anim = null;
	var playBtn = root.querySelector('[data-river="play"]');

	/* Fisha swims along the river line, in step with the carousel */
	var stream = root.querySelector('.fisha-river__stream');
	var swimmer = root.querySelector('.fisha-river__swimmer');
	var waveSvg = stream && stream.querySelector('.fisha-river__water');
	var wavePath = stream && stream.querySelector('.fisha-river__wave');
	var VB_W = 2400, VB_H = 40, lastProg = null, lastMove = 0;
	var facing = (FISH_WITH && DIR > 0) ? -1 : 1; // Fisha Foundation: mirrored, facing left from the first frame
	function updateSwimmer(t) {
		if (!swimmer || !wavePath || !loopW) return;
		var prog = (((track.scrollLeft % loopW) + loopW) % loopW) / loopW;
		if (FISH_WITH) prog = 1 - prog; // Fisha swims in the same direction the paintings flow
		if (lastProg != null) {
			var d = prog - lastProg;
			if (Math.abs(d) > 0.5) { // the loop wrapped: fade out and reappear at the other end
				swimmer.classList.add('is-wrapping');
				setTimeout(function () { swimmer.classList.remove('is-wrapping'); }, 60);
			} else if (Math.abs(d) > 0.000005) { facing = d > 0 ? 1 : -1; lastMove = t; }
		}
		lastProg = prog;
		var sw = stream.clientWidth, fw = swimmer.offsetWidth, fh = swimmer.offsetHeight;
		if (!sw || !fw) return;
		var wr = waveSvg.getBoundingClientRect(), sr = stream.getBoundingClientRect();
		var svgH = wr.height, svgTop = wr.top - sr.top;
		var cx = fw / 2 + prog * (sw - fw);           // fish centre along the stream
		var L = wavePath.getTotalLength();
		var p1 = wavePath.getPointAtLength(L * cx / sw);
		var p2 = wavePath.getPointAtLength(Math.min(L, L * cx / sw + 12));
		var y = svgTop + p1.y / VB_H * svgH;
		var slope = Math.atan2((p2.y - p1.y) / VB_H * svgH, Math.max(0.01, (p2.x - p1.x) / VB_W * sw)) * 180 / Math.PI;
		slope = Math.max(-14, Math.min(14, slope)) * facing;
		var moving = t - lastMove < 400;
		var bob = reduceMotion ? 0 : Math.sin(t / 650) * 2.2;
		var wiggle = reduceMotion ? 0 : Math.sin(t / (moving ? 230 : 900)) * (moving ? 3 : 1.2);
		swimmer.style.transform = 'translate(' + (cx - fw / 2).toFixed(1) + 'px,' + (y - fh * 0.8 + bob).toFixed(1) + 'px) rotate(' + (slope + wiggle).toFixed(2) + 'deg) scaleX(' + facing + ')';
	}

	function addCloneSet() {
		originals.forEach(function (s) {
			var c = s.cloneNode(true);
			c.setAttribute('aria-hidden', 'true');
			c.setAttribute('data-clone', '1');
			c.querySelectorAll('button').forEach(function (b) { b.tabIndex = -1; });
			c.querySelectorAll('img').forEach(function (i) { i.loading = 'lazy'; });
			track.appendChild(c);
		});
	}
	function measure() {
		var first = track.children[0], firstClone = track.children[originals.length];
		loopW = firstClone ? firstClone.offsetLeft - first.offsetLeft : 0;
	}
	function ensureLoop() {
		if (originals.length < 2) return;
		if (track.children.length === originals.length) addCloneSet();
		var sets = track.children.length / originals.length;
		measure();
		while (loopW && track.scrollWidth < loopW * 2 + track.clientWidth && sets < 8) { addCloneSet(); sets++; }
		measure();
	}
	function wrap() {
		if (!loopW) return;
		if (track.scrollLeft >= loopW) { track.scrollLeft -= loopW; pos = track.scrollLeft; if (anim) { anim.from -= loopW; anim.to -= loopW; } }
		else if (track.scrollLeft <= 0 && (dragging || anim || DIR < 0)) { track.scrollLeft += loopW; pos = track.scrollLeft; if (anim) { anim.from += loopW; anim.to += loopW; } }
	}
	function hold(ms) { holdUntil = Math.max(holdUntil, now() + (ms || RESUME_AFTER)); }
	function drifting(t) { return !reduceMotion && !userPaused && !hover && !dragging && !focusIn && !viewerOpen && !anim && !document.hidden && t > holdUntil; }

	function tick(t) {
		var dt = last == null ? 0 : Math.min(64, t - last);
		last = t;
		if (anim) {
			var k = Math.min(1, (t - anim.start) / anim.dur);
			var e = k < 0.5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;
			track.scrollLeft = anim.from + (anim.to - anim.from) * e;
			if (k >= 1) { anim = null; hold(); }
			pos = track.scrollLeft;
		} else if (drifting(t)) {
			if (Math.abs(track.scrollLeft - pos) > 2) pos = track.scrollLeft; // moved by something else (swipe, keyboard…)
			pos += DIR * SPEED * dt / 1000;
			track.scrollLeft = pos;
		} else {
			pos = track.scrollLeft;
		}
		wrap();
		updateSwimmer(t);
		requestAnimationFrame(tick);
	}

	function centerOf(el) { return el.offsetLeft + el.offsetWidth / 2 - track.clientWidth / 2; }
	function step(dir) {
		var slides = Array.prototype.slice.call(track.children);
		var cur = track.scrollLeft, target = null;
		if (dir > 0) {
			for (var i = 0; i < slides.length; i++) { var c = centerOf(slides[i]); if (c > cur + 4) { target = c; break; } }
		} else {
			if (loopW && cur < track.clientWidth) { track.scrollLeft = cur + loopW; cur = track.scrollLeft; }
			for (var j = slides.length - 1; j >= 0; j--) { var d = centerOf(slides[j]); if (d < cur - 4) { target = d; break; } }
		}
		if (target == null) return;
		anim = { from: cur, to: Math.max(0, target), start: now(), dur: reduceMotion ? 1 : 900 };
	}

	if (track && originals.length) {
		ensureLoop();
		if (DIR < 0 && loopW) { track.scrollLeft = loopW; pos = loopW; }
		window.addEventListener('resize', ensureLoop, { passive: true });
		window.addEventListener('load', ensureLoop);

		// Pause while the visitor is looking
		// Hover pauses — but after a drag or the zoom viewer the river keeps flowing until the mouse leaves and comes back
		var hoverArmed = true;
		track.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse' && hoverArmed) hover = true; });
		track.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') { hover = false; hoverArmed = true; hold(1200); } });
		root.addEventListener('fisha:resume', function () { hover = false; hoverArmed = false; });
		// after a drag or the viewer, moving the mouse again over a painting pauses it again

		// (keyboard users: arrow keys step through the paintings and the pause button stops the river)
		track.addEventListener('touchstart', function () { anim = null; hold(); }, { passive: true });
		track.addEventListener('touchmove', function () { hold(); }, { passive: true });
		track.addEventListener('wheel', function (e) { if (Math.abs(e.deltaX) > Math.abs(e.deltaY)) { anim = null; hold(); } }, { passive: true });

		// Drag with the mouse
		var startX = 0, startScroll = 0, moved = false;
		track.addEventListener('pointerdown', function (e) {
			if (e.pointerType !== 'mouse' || e.button !== 0 || e.target.closest('button')) return;
			dragging = true; moved = false; anim = null;
			startX = e.clientX; startScroll = track.scrollLeft;
		});
		track.addEventListener('pointermove', function (e) {
			if (!dragging) return;
			var dx = e.clientX - startX;
			if (!moved && Math.abs(dx) > 4) { moved = true; track.setPointerCapture(e.pointerId); track.classList.add('is-dragging'); }
			if (!moved) return;
			track.scrollLeft = startScroll - dx;
			if (loopW && track.scrollLeft >= loopW) { track.scrollLeft -= loopW; startScroll -= loopW; }
			if (loopW && track.scrollLeft <= 0) { track.scrollLeft += loopW; startScroll += loopW; }
		});
		function endDrag() { if (!dragging) return; dragging = false; hover = false; track.classList.remove('is-dragging'); hold(2500); root.dispatchEvent(new Event('fisha:resume')); }
		track.addEventListener('pointerup', endDrag);
		track.addEventListener('pointercancel', endDrag);
		track.addEventListener('click', function (e) { if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; } }, true);

		// Arrows, keyboard, pause
		root.querySelector('[data-river="prev"]').addEventListener('click', function () { step(-1); });
		root.querySelector('[data-river="next"]').addEventListener('click', function () { step(1); });
		track.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
			if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
		});
		playBtn.addEventListener('click', function () {
			userPaused = !userPaused;
			playBtn.setAttribute('aria-pressed', String(userPaused));
			playBtn.setAttribute('aria-label', userPaused ? 'Let the river flow' : 'Pause the river');
		});
		if (reduceMotion) { userPaused = true; playBtn.setAttribute('aria-pressed', 'true'); playBtn.setAttribute('aria-label', 'Let the river flow'); }

		// Zoom buttons (event delegation covers clones)
		track.addEventListener('click', function (e) {
			var b = e.target.closest('.fisha-river__zoom');
			var img = !b && e.target.closest('.fisha-river__slide img');
			if (!b && !img) return;
			var fig = (b || img).closest('.fisha-river__slide');
			openViewer(parseInt(fig.getAttribute('data-index'), 10) || 0, b || fig.querySelector('.fisha-river__zoom'));
		});

		requestAnimationFrame(tick);
	}

	/* ------------------------------------------------------------------ zoom viewer */
	var items = originals.map(function (s) {
		var img = s.querySelector('img');
		return { view: img.currentSrc || img.src, zoom: img.getAttribute('data-zoom'), dzi: img.getAttribute('data-dzi'), zw: +img.getAttribute('data-zw'), zh: +img.getAttribute('data-zh'), alt: img.alt };
	});
	var box, stage, vimg, countEl, hintEl, returnFocus = null;
	var osdUrl = root.getAttribute('data-osd'), hd = !!osdUrl, osd = null, osdEl = null, osdLoading = null;
	function loadOSD() {
		if (window.OpenSeadragon) return Promise.resolve();
		if (!osdLoading) osdLoading = new Promise(function (res, rej) {
			var sc = document.createElement('script'); sc.src = osdUrl; sc.onload = res; sc.onerror = rej; document.head.appendChild(sc);
		});
		return osdLoading;
	}
	function hideHint() { if (hintEl) hintEl.style.opacity = '0'; }
	var cur = 0, fitW = 0, fitH = 0, s = 1, tx = 0, ty = 0, maxS = 4;
	var pointers = {}, pinchDist = 0, pinchS = 1, panStart = null, tapTime = 0;

	function icon(d, w) { return '<svg viewBox="0 0 24 24" width="' + (w || 22) + '" height="' + (w || 22) + '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + d + '"/></svg>'; }
	function build() {
		box = document.createElement('div');
		box.className = 'fisha-lightbox';
		box.hidden = true;
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-modal', 'true');
		box.setAttribute('aria-label', 'Artwork viewer');
		box.innerHTML =
			'<div class="fisha-lightbox__stage"><img class="fisha-lightbox__img" alt="" draggable="false"><div class="fisha-lightbox__osd"></div></div>' +
			'<div class="fisha-lightbox__hint">' + (window.matchMedia && window.matchMedia('(pointer: coarse)').matches ? 'Pinch or double-tap to zoom · drag to move' : 'Scroll or double-click to zoom · drag to move') + '</div>' +
			'<button type="button" class="fisha-river__btn fisha-lightbox__close" aria-label="Close">' + icon('M6 6l12 12M18 6L6 18') + '</button>' +
			'<div class="fisha-lightbox__bar">' +
				'<button type="button" class="fisha-river__btn" data-lb="prev" aria-label="Previous artwork">' + icon('M15 5l-7 7 7 7') + '</button>' +
				'<button type="button" class="fisha-river__btn" data-lb="out" aria-label="Zoom out">' + icon('M6 12h12') + '</button>' +
				'<span class="fisha-lightbox__count" aria-live="polite"></span>' +
				'<button type="button" class="fisha-river__btn" data-lb="in" aria-label="Zoom in">' + icon('M12 6v12M6 12h12') + '</button>' +
				'<button type="button" class="fisha-river__btn" data-lb="next" aria-label="Next artwork">' + icon('M9 5l7 7-7 7') + '</button>' +
			'</div>';
		document.body.appendChild(box);
		stage = box.querySelector('.fisha-lightbox__stage');
		vimg = box.querySelector('.fisha-lightbox__img');
		countEl = box.querySelector('.fisha-lightbox__count');
		hintEl = box.querySelector('.fisha-lightbox__hint');
		osdEl = box.querySelector('.fisha-lightbox__osd');
		if (hd) box.classList.add('is-hd');

		box.querySelector('.fisha-lightbox__close').addEventListener('click', closeViewer);
		box.querySelector('[data-lb="prev"]').addEventListener('click', function () { show(cur - 1); });
		box.querySelector('[data-lb="next"]').addEventListener('click', function () { show(cur + 1); });
		box.querySelector('[data-lb="in"]').addEventListener('click', function () { zoomBy(1.6); });
		box.querySelector('[data-lb="out"]').addEventListener('click', function () { zoomBy(1 / 1.6); });
		stage.addEventListener('wheel', function (e) {
			if (hd) return;
			e.preventDefault();
			var p = local(e);
			zoomAt(s * Math.exp(-e.deltaY * 0.0018), p.x, p.y, false);
		}, { passive: false });
		stage.addEventListener('dblclick', function (e) { if (hd) return; var p = local(e); zoomAt(s > 1.05 ? 1 : Math.min(maxS, 2.5), p.x, p.y, true); });
		stage.addEventListener('pointerdown', onDown);
		stage.addEventListener('pointermove', onMove);
		stage.addEventListener('pointerup', onUp);
		stage.addEventListener('pointercancel', onUp);
		stage.addEventListener('click', function (e) { if (!hd && e.target === stage && s <= 1.01) closeViewer(); });
		document.addEventListener('keydown', function (e) {
			if (!viewerOpen) return;
			if (e.key === 'Escape') closeViewer();
			else if (e.key === 'ArrowRight') show(cur + 1);
			else if (e.key === 'ArrowLeft') show(cur - 1);
			else if (e.key === '+' || e.key === '=') zoomBy(1.6);
			else if (e.key === '-') zoomBy(1 / 1.6);
			else if (e.key === '0') { if (hd && osd) osd.viewport.goHome(); else zoomAt(1, 0, 0, true); }
			else if (e.key === 'Tab') { // keep focus inside the viewer
				var f = box.querySelectorAll('button'), first = f[0], lastB = f[f.length - 1];
				if (e.shiftKey && document.activeElement === first) { e.preventDefault(); lastB.focus(); }
				else if (!e.shiftKey && document.activeElement === lastB) { e.preventDefault(); first.focus(); }
			}
		});
		window.addEventListener('resize', function () { if (viewerOpen && !hd) layout(true); });
	}
	function zoomBy(f) {
		if (hd) { if (osd) { osd.viewport.zoomBy(f); osd.viewport.applyConstraints(); hideHint(); } return; }
		zoomAt(s * f, 0, 0, true);
	}
	function showHD(it) {
		loadOSD().then(function () {
			if (!osd) {
				osd = window.OpenSeadragon({
					element: osdEl,
					tileSources: it.dzi,
					showNavigationControl: false,
					viewportMargins: { top: 40, bottom: 16, left: 32, right: 32 },
					animationTime: 0.6,
					blendTime: 0.15,
					springStiffness: 8,
					maxZoomPixelRatio: 1.5,
					minZoomImageRatio: 0.9,
					visibilityRatio: 0.6,
					constrainDuringPan: true,
					immediateRender: false,
					keyboardNavEnabled: false,
					gestureSettingsMouse: { clickToZoom: false, dblClickToZoom: true, scrollToZoom: true },
					gestureSettingsTouch: { pinchToZoom: true, dblClickToZoom: true, flickEnabled: true }
				});
				osd.addHandler('canvas-scroll', hideHint);
				osd.addHandler('canvas-pinch', hideHint);
				osd.addHandler('canvas-double-click', hideHint);
			} else {
				osd.open(it.dzi);
			}
		}).catch(function () { box.classList.remove('is-hd'); hd = false; vimg.src = it.view; layout(false); });
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
		tx = Math.max(-mx, Math.min(mx, tx));
		ty = Math.max(-my, Math.min(my, ty));
	}
	function zoomAt(ns, px, py, animate) {
		ns = Math.max(1, Math.min(maxS, ns));
		tx = px - (px - tx) * (ns / s);
		ty = py - (py - ty) * (ns / s);
		s = ns;
		if (s <= 1.001) { tx = 0; ty = 0; }
		clamp();
		apply(animate);
		if (hintEl) hintEl.style.opacity = '0';
	}
	function layout(keep) {
		var r = stage.getBoundingClientRect(), it = items[cur];
		var ratio = it.zw && it.zh ? it.zw / it.zh : (vimg.naturalWidth / vimg.naturalHeight) || 1;
		var pad = 32, aw = r.width - pad * 2, ah = r.height - pad * 2;
		fitW = Math.min(aw, ah * ratio); fitH = fitW / ratio;
		vimg.style.width = fitW + 'px';
		vimg.style.height = fitH + 'px';
		maxS = Math.max(4, ((it.zw || fitW) / fitW) * 1.5);
		if (!keep) { s = 1; tx = 0; ty = 0; }
		clamp();
		apply(false);
	}
	function show(i) {
		var n = items.length;
		cur = (i % n + n) % n;
		var it = items[cur];
		countEl.textContent = (cur + 1) + ' / ' + n;
		if (hd && it.dzi) { showHD(it); return; }
		vimg.alt = it.alt;
		vimg.src = it.view;      // instant (already loaded in the carousel)
		layout(false);
		countEl.textContent = (cur + 1) + ' / ' + n;
		var hi = new Image();
		hi.onload = function () { if (items[cur] === it) vimg.src = it.zoom; };
		hi.src = it.zoom;        // swap to the sharp version when ready
		if (items[(cur + 1) % n].zoom) { var nx = new Image(); nx.src = items[(cur + 1) % n].zoom; } // preload next
	}
	function openViewer(i, from) {
		if (!box) build();
		returnFocus = from || document.activeElement;
		viewerOpen = true;
		box.hidden = false;
		document.documentElement.style.overflow = 'hidden';
		show(i);
		hintEl.style.opacity = '1';
		setTimeout(function () { if (hintEl) hintEl.style.opacity = '0'; }, 3500);
		requestAnimationFrame(function () { box.classList.add('is-open'); });
		box.querySelector('.fisha-lightbox__close').focus();
	}
	function closeViewer() {
		viewerOpen = false;
		box.classList.remove('is-open');
		document.documentElement.style.overflow = '';
		setTimeout(function () { if (!viewerOpen) box.hidden = true; }, 250);
		hover = false; focusIn = false;
		hold(2500);
		root.dispatchEvent(new Event('fisha:resume'));
		if (returnFocus && returnFocus.focus) returnFocus.focus({ preventScroll: true });
	}

	function onDown(e) {
		if (hd) return;
		stage.setPointerCapture(e.pointerId);
		pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
		var ids = Object.keys(pointers);
		if (ids.length === 2) {
			var a = pointers[ids[0]], b = pointers[ids[1]];
			pinchDist = Math.hypot(a.x - b.x, a.y - b.y); pinchS = s; panStart = null;
		} else if (ids.length === 1) {
			if (e.pointerType !== 'mouse') { // double-tap to zoom
				var t = now();
				if (t - tapTime < 300) { var p = local(e); zoomAt(s > 1.05 ? 1 : Math.min(maxS, 2.5), p.x, p.y, true); tapTime = 0; return; }
				tapTime = t;
			}
			panStart = { x: e.clientX, y: e.clientY, tx: tx, ty: ty };
			if (s > 1.01) box.classList.add('is-panning');
		}
	}
	function onMove(e) {
		if (hd || !pointers[e.pointerId]) return;
		pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
		var ids = Object.keys(pointers);
		if (ids.length === 2) {
			var a = pointers[ids[0]], b = pointers[ids[1]];
			var d = Math.hypot(a.x - b.x, a.y - b.y);
			var r = stage.getBoundingClientRect();
			zoomAt(pinchS * d / pinchDist, (a.x + b.x) / 2 - r.left - r.width / 2, (a.y + b.y) / 2 - r.top - r.height / 2, false);
		} else if (panStart && s > 1.01) {
			tx = panStart.tx + (e.clientX - panStart.x);
			ty = panStart.ty + (e.clientY - panStart.y);
			clamp(); apply(false);
		}
	}
	function onUp(e) {
		delete pointers[e.pointerId];
		if (Object.keys(pointers).length < 2) pinchDist = 0;
		if (!Object.keys(pointers).length) { panStart = null; box.classList.remove('is-panning'); }
	}

	/* ------------------------------------------------------------------ music */
	var audio = root.querySelector('.fisha-river__audio');
	var soundBtn = root.querySelector('.fisha-river__sound');
	if (audio && soundBtn) {
		var KEY = 'fishaRiverMuted', VOL = 0.45, playing = false, muted = false, fadeT = null;
		var ALWAYS = root.getAttribute('data-music') === 'always'; // e.g. Fisha Foundation: music on every time the page opens
		try { muted = !ALWAYS && sessionStorage.getItem(KEY) === '1'; } catch (err) {}
		function render() {
			var on = ALWAYS ? !muted : (playing && !muted);
			soundBtn.classList.toggle('is-off', !on);
			soundBtn.setAttribute('aria-pressed', String(on));
			soundBtn.setAttribute('aria-label', on ? 'Mute music' : 'Play music');
		}
		function fadeTo(v, ms, done) {
			clearInterval(fadeT);
			var from = audio.volume, t0 = now();
			fadeT = setInterval(function () {
				var k = Math.min(1, (now() - t0) / ms);
				audio.volume = from + (v - from) * k;
				if (k >= 1) { clearInterval(fadeT); if (done) done(); }
			}, 40);
		}
		function start() {
			if (muted || playing) return;
			audio.volume = 0;
			var p = audio.play();
			if (p && p.then) p.then(function () { playing = true; soundBtn.classList.remove('is-waiting'); render(); fadeTo(VOL, 2500); })
				.catch(function () { playing = false; if (!ALWAYS) soundBtn.classList.add('is-waiting'); render(); });
		}
		function stop() { playing = false; render(); fadeTo(0, 500, function () { audio.pause(); }); }
		function save() { try { sessionStorage.setItem(KEY, muted ? '1' : '0'); } catch (err) {} }

		soundBtn.addEventListener('click', function () {
			var showingOn = ALWAYS ? !muted : (playing && !muted);
			if (showingOn) { muted = true; save(); stop(); }
			else { muted = false; save(); start(); }
		});
		// Browsers only allow sound after the visitor interacts with the page: start on the first gesture.
		function kick(e) {
			if (soundBtn.contains(e.target)) return;
			start();
			if (playing || muted) GESTURES.forEach(function (t) { document.removeEventListener(t, kick, true); });
		}
		var GESTURES = ['pointerdown', 'pointerup', 'mousedown', 'click', 'touchend', 'keydown'];
		GESTURES.forEach(function (t) { document.addEventListener(t, kick, true); });
		document.addEventListener('visibilitychange', function () {
			if (document.hidden && playing) audio.pause();
			else if (!document.hidden && playing && !muted) audio.play().catch(function () {});
		});
		// Coming back with the browser's Back button can restore the page silently — start again
		window.addEventListener('pageshow', function (e) {
			if (!e.persisted) return;
			if (ALWAYS) muted = false;
			playing = !audio.paused;
			if (!playing && !muted) {
				GESTURES.forEach(function (t) { document.addEventListener(t, kick, true); });
				start();
			}
		});
		render();
		start(); // may succeed if the browser already allows autoplay for this site
	}
})();
