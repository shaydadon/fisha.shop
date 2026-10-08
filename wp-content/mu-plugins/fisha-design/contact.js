/* Fisha contact — topic cards open the form; on send the card folds and Fisha swims off with it. */
(function () {
	var form = document.querySelector('.fc-form');
	if (!form) return;
	var started = Date.now();
	var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var flight = document.querySelector('.fc-flight');
	var card = document.querySelector('.fc-card');
	var fish = document.querySelector('.fc-carrier');
	var thanks = document.querySelector('.fc-thanks');
	var msg = form.querySelector('.fisha-lead-msg');
	var orderField = form.querySelector('.fc-field--order');

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
		flight.hidden = true;
		thanks.hidden = false;
		thanks.classList.add('is-in');
		thanks.focus({ preventScroll: true });
		thanks.scrollIntoView({ behavior: still ? 'auto' : 'smooth', block: 'center' });
	}

	// The card folds into a note, Fisha swims in, picks it up and swims off with it.
	function carryAway() {
		if (still || !card.animate) return Promise.resolve();
		var w = flight.getBoundingClientRect().width;
		var vw = window.innerWidth;
		fish.style.opacity = '1';
		return anim(form, [{ opacity: 1 }, { opacity: 0 }], { duration: 220, easing: 'ease-out' })
			.then(function () {
				return Promise.all([
					anim(card, [
						{ transform: 'none' },
						{ transform: 'scale(0.42, 0.42) rotate(-4deg)', offset: 0.55 },
						{ transform: 'scale(0.3, 0.16) rotate(-8deg)' }
					], { duration: 650, easing: 'cubic-bezier(.5,0,.3,1)' }),
					anim(fish, [
						{ transform: 'translate(' + (-vw * 0.6) + 'px, -40%) scaleX(-1)' },
						{ transform: 'translate(' + (w * 0.5 - 130) + 'px, -40%) scaleX(-1)' }
					], { duration: 800, delay: 250, easing: 'cubic-bezier(.2,.8,.3,1)' })
				]);
			})
			.then(function () {
				var off = vw;
				return Promise.all([
					anim(card, [
						{ transform: 'scale(0.3, 0.16) rotate(-8deg)' },
						{ transform: 'translate(' + (off * 0.45) + 'px, -60px) scale(0.3, 0.16) rotate(4deg)', offset: 0.5 },
						{ transform: 'translate(' + off + 'px, -20px) scale(0.3, 0.16) rotate(-4deg)' }
					], { duration: 900, easing: 'cubic-bezier(.6,0,.4,1)' }),
					anim(fish, [
						{ transform: 'translate(' + (w * 0.5 - 130) + 'px, -40%) scaleX(-1)' },
						{ transform: 'translate(' + (w * 0.5 - 130 + off * 0.45) + 'px, calc(-40% - 60px)) rotate(-6deg) scaleX(-1)', offset: 0.5 },
						{ transform: 'translate(' + (w * 0.5 - 130 + off) + 'px, calc(-40% - 20px)) rotate(4deg) scaleX(-1)' }
					], { duration: 900, easing: 'cubic-bezier(.6,0,.4,1)' })
				]);
			});
	}

	function resetCard() {
		[form, card, fish].forEach(function (el) { if (el.getAnimations) el.getAnimations().forEach(function (a) { a.cancel(); }); });
		fish.style.opacity = '0';
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
		thanks.classList.remove('is-in');
		flight.hidden = false;
		var first = form.querySelector('input[name="topic"]');
		if (first) first.focus();
	});
})();
