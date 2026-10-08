/* Fisha contact — topic cards open the form; on send the card folds away, a hand-drawn loop plays, then the thank-you. */
(function () {
	var form = document.querySelector('.fc-form');
	if (!form) return;
	var started = Date.now();
	var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var flight = document.querySelector('.fc-flight');
	var card = document.querySelector('.fc-card');
	var thanks = document.querySelector('.fc-thanks');
	var msg = form.querySelector('.fisha-lead-msg');
	var orderField = form.querySelector('.fc-field--order');
	var loops = [];
	try { loops = JSON.parse(thanks.getAttribute('data-loops') || '[]'); } catch (e) {}
	var pick = loops.length ? loops[Math.floor(Math.random() * loops.length)] : null;
	var art = thanks.querySelector('.fc-thanks__img');

	// Fetch the animated drawing only once someone starts writing.
	var warmed = false;
	form.addEventListener('focusin', function () {
		if (warmed || !pick || still) return;
		warmed = true;
		var im = new Image();
		im.src = pick.src;
	});

	form.querySelector('input[name="source"]').value = location.href.split('#')[0];
	var nojs = form.querySelector('input[name="fisha_nojs"]');
	if (nojs) nojs.remove();

	function topic() { var t = form.querySelector('input[name="topic"]:checked'); return t ? t.value : ''; }
	function syncOrder() { if (orderField) orderField.hidden = topic() !== 'order'; }
	function say(text, ok) { msg.textContent = text; msg.className = 'fisha-lead-msg ' + (ok ? 'is-ok' : 'is-err'); }

	// Closed until a topic is picked (unless one came in the URL or the visitor already typed).
	if (!topic()) form.classList.add('is-closed');
	syncOrder();
	form.addEventListener('change', function (e) {
		if (e.target.name !== 'topic') return;
		syncOrder();
		if (form.classList.contains('is-closed')) {
			form.classList.remove('is-closed');
			form.classList.add('is-opening');
			setTimeout(function () { form.classList.remove('is-opening'); }, 500);
		}
	});

	function firstInvalid() {
		var els = form.querySelectorAll('input, textarea');
		for (var i = 0; i < els.length; i++) {
			var el = els[i];
			if (el.closest('.fisha-hp') || el.closest('[hidden]') || el.type === 'hidden') continue;
			if (!el.checkValidity()) return el;
		}
		return null;
	}
	function nameOf(el) {
		if (el.name === 'topic') return 'what it’s about';
		if (el.type === 'email') return el.value ? '' : 'your email';
		return { name: 'your name', message: 'a message' }[el.name] || 'this field';
	}

	function anim(el, frames, opts) {
		if (!el || !el.animate) return Promise.resolve();
		return el.animate(frames, Object.assign({ fill: 'both' }, opts)).finished.catch(function () {});
	}

	function showThanks(first) {
		thanks.querySelector('.fc-thanks__who').textContent = first ? 'Thanks, ' + first + '!' : 'Thanks!';
		if (pick && art) {
			art.src = still ? pick.still : pick.src;
			art.width = pick.w;
			art.height = pick.h;
		}
		flight.hidden = true;
		thanks.hidden = false;
		thanks.classList.remove('is-done');
		thanks.classList.add('is-in');
		thanks.scrollIntoView({ behavior: still ? 'auto' : 'smooth', block: 'center' });
		var done = function () {
			thanks.classList.remove('is-waiting');
			thanks.classList.add('is-done');
			thanks.focus({ preventScroll: true });
		};
		if (still || !pick) { done(); return; }
		thanks.classList.add('is-waiting');
		setTimeout(done, Math.max(2400, Math.min(pick.ms * 2, 3200)));
	}

	// The paper card folds up and slips away before the drawing appears.
	function carryAway() {
		if (still || !card.animate) return Promise.resolve();
		return anim(form, [{ opacity: 1 }, { opacity: 0 }], { duration: 200, easing: 'ease-out' })
			.then(function () {
				return anim(card, [
					{ transform: 'none', opacity: 1 },
					{ transform: 'scale(0.45) rotate(-5deg)', opacity: 1, offset: 0.6 },
					{ transform: 'translateY(30px) scale(0.12) rotate(8deg)', opacity: 0 }
				], { duration: 650, easing: 'cubic-bezier(.5,0,.3,1)' });
			});
	}

	function resetCard() {
		[form, card].forEach(function (el) { if (el.getAnimations) el.getAnimations().forEach(function (a) { a.cancel(); }); });
		form.reset();
		form.classList.add('is-closed');
		msg.textContent = '';
		syncOrder();
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var bad = firstInvalid();
		if (bad) {
			var n = nameOf(bad);
			say(n ? 'Please add ' + n + '.' : 'Please check the email address.', false);
			if (form.classList.contains('is-closed')) form.classList.remove('is-closed');
			bad.focus();
			return;
		}
		form.querySelector('input[name="fisha_ms"]').value = String(Date.now() - started);
		var btn = form.querySelector('.fc-send');
		var label = btn.textContent;
		btn.disabled = true;
		btn.textContent = 'Sending…';
		say('', true);
		var first = (form.elements.name.value || '').trim().split(/\s+/)[0];

		fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'omit', headers: { Accept: 'application/json' } })
			.then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
			.then(function (j) {
				btn.disabled = false;
				btn.textContent = label;
				if (!j.ok) { say(j.message || 'Something went wrong — please try again.', false); return; }
				return carryAway().then(function () { showThanks(first); });
			})
			.catch(function () {
				btn.disabled = false;
				btn.textContent = label;
				say('Couldn’t reach the river — check your connection and try again.', false);
			});
	});

	var again = document.querySelector('.fc-again');
	if (again) again.addEventListener('click', function () {
		resetCard();
		thanks.hidden = true;
		thanks.classList.remove('is-in', 'is-done', 'is-waiting');
		if (loops.length) {
			if (art && pick) art.src = pick.still; // so the loop starts from the top next time
			pick = loops[Math.floor(Math.random() * loops.length)];
			warmed = false;
		}
		flight.hidden = false;
		var first = form.querySelector('input[name="topic"]');
		if (first) first.focus();
	});
})();
