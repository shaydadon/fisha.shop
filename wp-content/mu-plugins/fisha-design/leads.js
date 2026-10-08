/* Fisha leads — sends the drop sign-up and request forms with fetch, shows the answer inline. */
(function () {
	var started = Date.now();

	function msgEl(form) { return form.querySelector('.fisha-lead-msg'); }
	function say(form, text, ok) {
		var m = msgEl(form);
		if (!m) return;
		m.textContent = text;
		m.className = 'fisha-lead-msg ' + (ok ? 'is-ok' : 'is-err');
	}

	// Show "placement" only for tattoo requests (the field is still sent when hidden).
	function syncPlacement(form) {
		var pl = form.querySelector('.fr-field--placement');
		if (!pl) return;
		var t = form.querySelector('input[name="type"]:checked');
		pl.hidden = !(t && t.value === 'tattoo');
	}

	function firstInvalid(form) {
		var els = form.querySelectorAll('input, textarea, select');
		for (var i = 0; i < els.length; i++) {
			var el = els[i];
			if (el.closest('.fisha-hp') || el.closest('[hidden]')) continue;
			if (!el.checkValidity()) return el;
		}
		return null;
	}

	function labelFor(el) {
		if (el.type === 'radio') return 'what you’d like';
		if (el.type === 'checkbox') return 'the tick box';
		var l = el.closest('label');
		var s = l && l.querySelector('span');
		return s ? s.textContent.replace(/\*|\(.*?\)/g, '').trim().toLowerCase() : 'this field';
	}

	document.querySelectorAll('.fisha-lead-form').forEach(function (form) {
		var src = form.querySelector('input[name="source"]');
		if (src) src.value = location.href.split('#')[0];
		var nojs = form.querySelector('input[name="fisha_nojs"]');
		if (nojs) nojs.remove();

		syncPlacement(form);
		form.addEventListener('change', function (e) {
			if (e.target.name === 'type') syncPlacement(form);
			if (e.target.type === 'file' && e.target.files.length > 3) {
				say(form, 'Please choose up to 3 images.', false);
				e.target.value = '';
			}
		});

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var bad = firstInvalid(form);
			if (bad) {
				say(form, bad.type === 'email' && bad.value ? 'Please check the email address.' : 'Please add ' + labelFor(bad) + '.', false);
				bad.focus();
				return;
			}
			var btn = form.querySelector('[type="submit"]');
			var ms = form.querySelector('input[name="fisha_ms"]');
			if (ms) ms.value = String(Date.now() - started);
			btn.disabled = true;
			var label = btn.textContent;
			btn.textContent = 'Sending…';
			say(form, '', true);

			fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'omit', headers: { Accept: 'application/json' } })
				.then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Something went wrong — please try again.' }; }); })
				.then(function (j) {
					say(form, j.message || (j.ok ? 'Thanks!' : 'Something went wrong — please try again.'), !!j.ok);
					if (j.ok) {
						form.classList.add('is-sent');
						form.reset();
						syncPlacement(form);
					}
				})
				.catch(function () { say(form, 'Couldn’t reach the server — check your connection and try again.', false); })
				.then(function () { btn.disabled = false; btn.textContent = label; });
		});
	});
})();
