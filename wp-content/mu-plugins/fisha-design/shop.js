/* Fisha shop — shelf arrows, chip scroll-spy, sticky offset */
(function () {
	var root = document.querySelector('.fisha-shop');
	if (!root) return;

	// chips stick right under the (sticky) site header
	function stickyOffset() {
		var h = document.querySelector('.wp-site-blocks > header, .wp-site-blocks > .wp-block-template-part');
		var pos = h ? getComputedStyle(h).position : '';
		root.style.setProperty('--fs-sticky', (h && pos === 'sticky' ? h.getBoundingClientRect().height : 0) + 'px');
	}
	stickyOffset();
	window.addEventListener('resize', stickyOffset);

	// shelf arrows: only when the row actually overflows
	[].forEach.call(root.querySelectorAll('.fs-shelf'), function (shelf) {
		var row = shelf.querySelector('.fs-row');
		var prev = shelf.querySelector('.fs-arrow--prev');
		var next = shelf.querySelector('.fs-arrow--next');
		if (!row || !prev || !next) return;
		function update() {
			var over = row.scrollWidth > row.clientWidth + 4;
			prev.hidden = next.hidden = !over;
			prev.disabled = row.scrollLeft < 4;
			next.disabled = row.scrollLeft + row.clientWidth >= row.scrollWidth - 4;
		}
		function step(dir) {
			var card = row.querySelector('li');
			var w = card ? card.getBoundingClientRect().width + parseFloat(getComputedStyle(row).columnGap || 16) : row.clientWidth;
			row.scrollBy({ left: dir * w * Math.max(1, Math.floor(row.clientWidth / w)), behavior: 'smooth' });
		}
		prev.addEventListener('click', function () { step(-1); });
		next.addEventListener('click', function () { step(1); });
		row.addEventListener('scroll', update, { passive: true });
		window.addEventListener('resize', update);
		update();
	});

	// chips: highlight the shelf in view (parent pages, where chips are in-page anchors)
	var chips = [].slice.call(root.querySelectorAll('.fs-chip[href^="#"]'));
	if (chips.length && 'IntersectionObserver' in window) {
		var map = {};
		chips.forEach(function (c) { map[c.getAttribute('href').slice(1)] = c; });
		function activate(id) {
			chips.forEach(function (c) { c.classList.toggle('is-active', c === map[id]); });
			var a = map[id];
			if (a && a.parentNode && a.parentNode.parentNode) {
				var ul = a.parentNode.parentNode;
				ul.scrollTo({ left: a.offsetLeft - 24, behavior: 'smooth' });
			}
		}
		var io = new IntersectionObserver(function (es) {
			es.forEach(function (e) { if (e.isIntersecting) activate(e.target.id); });
		}, { rootMargin: '-45% 0px -50% 0px' });
		[].forEach.call(root.querySelectorAll('.fs-shelf[id]'), function (s) { io.observe(s); });
		var top = document.getElementById('fs-top');
		window.addEventListener('scroll', function () {
			if (top && top.getBoundingClientRect().top > window.innerHeight * 0.45) activate('fs-top');
		}, { passive: true });
	}
})();

/* Fisha main shop — client-side filtering (category / type / size / price), mirrored in the URL, + FAQ tabs */
(function () {
	var form = document.querySelector('.fs-filters');
	if (!form) return;
	var root = document.querySelector('.fisha-shop');
	var shelves = [].slice.call(root.querySelectorAll('.fs-shelf[data-cat]'));
	var chips = [].slice.call(form.querySelectorAll('[data-filter="cat"]'));
	var selects = [].slice.call(form.querySelectorAll('select[data-filter]'));
	var countEl = form.querySelector('.fs-filters__count');
	var clears = [].slice.call(root.querySelectorAll('.fs-filters__clear, .fs-filters__clear2'));
	var noRes = root.querySelector('.fs-noresults');
	var state = { cat: '', type: '', size: '', price: '' };

	function sel(name) { return form.querySelector('select[data-filter="' + name + '"]'); }
	function matches(card) {
		if (card.hasAttribute('data-more')) return !state.type && !state.size && !state.price;
		var cats = (card.getAttribute('data-cats') || '').split(' ');
		if (state.type && cats.indexOf(state.type) < 0) return false;
		if (state.size) {
			var sizes = (card.getAttribute('data-sizes') || '').split('|');
			if (sizes.indexOf(state.size) < 0) return false;
		}
		if (state.price) {
			var r = state.price.split('-'), p = parseFloat(card.getAttribute('data-price') || '0');
			if (r[0] && p < +r[0]) return false;
			if (r[1] && p >= +r[1]) return false;
		}
		return true;
	}
	function apply(push) {
		var narrowed = state.type || state.size || state.price, shown = 0;
		shelves.forEach(function (shelf) {
			var cards = [].slice.call(shelf.querySelectorAll('.fs-card')), n = 0;
			cards.forEach(function (c) { var ok = matches(c); c.hidden = !ok; if (ok && !c.hasAttribute('data-more')) n++; });
			var row = shelf.querySelector('.fs-row');
			var hide = (state.cat && shelf.getAttribute('data-cat') !== state.cat) || (narrowed && n === 0) || (!row && narrowed);
			shelf.hidden = !!hide;
			if (!hide) shown += n;
			if (row) row.scrollLeft = 0;
		});
		var any = state.cat || narrowed;
		if (noRes) noRes.hidden = !narrowed || shown > 0;
		if (countEl) countEl.textContent = any ? shown + (shown === 1 ? ' piece' : ' pieces') : '';
		clears.forEach(function (b) { if (b.classList.contains('fs-filters__clear')) b.hidden = !any; });
		chips.forEach(function (c) { var on = c.getAttribute('data-value') === state.cat; c.classList.toggle('is-active', on); c.setAttribute('aria-pressed', on ? 'true' : 'false'); });
		selects.forEach(function (s) { s.value = state[s.getAttribute('data-filter')]; s.classList.toggle('is-set', !!s.value); });
		window.dispatchEvent(new Event('resize')); // refresh shelf arrows
		if (push !== false && window.history.replaceState) {
			var u = new URL(window.location.href);
			Object.keys(state).forEach(function (k) { if (state[k]) u.searchParams.set(k, state[k]); else u.searchParams.delete(k); });
			window.history.replaceState(null, '', u.pathname + u.search + u.hash);
		}
	}
	function scrollToResults() {
		var top = document.getElementById('fs-top');
		if (top && top.getBoundingClientRect().top < 0) top.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}
	chips.forEach(function (c) { c.addEventListener('click', function () { state.cat = c.getAttribute('data-value'); apply(); scrollToResults(); }); });
	selects.forEach(function (s) { s.addEventListener('change', function () { state[s.getAttribute('data-filter')] = s.value; apply(); scrollToResults(); }); });
	clears.forEach(function (b) { b.addEventListener('click', function () { state = { cat: '', type: '', size: '', price: '' }; apply(); }); });

	// initial state from the URL (?cat=&type=&size=&price=), ignoring unknown values
	var q = new URLSearchParams(window.location.search);
	if (chips.some(function (c) { return c.getAttribute('data-value') === q.get('cat'); })) state.cat = q.get('cat');
	['type', 'size', 'price'].forEach(function (k) {
		var s = sel(k), v = q.get(k);
		if (s && v && [].some.call(s.options, function (o) { return o.value === v; })) state[k] = v;
	});
	if (state.cat || state.type || state.size || state.price) apply(false);

	// FAQ topic tabs
	var tabs = [].slice.call(root.querySelectorAll('[data-faq]'));
	var groups = [].slice.call(root.querySelectorAll('[data-faq-group]'));
	tabs.forEach(function (t) {
		t.addEventListener('click', function () {
			var k = t.getAttribute('data-faq');
			tabs.forEach(function (x) { var on = x === t; x.classList.toggle('is-active', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
			groups.forEach(function (g) { g.hidden = !!k && g.getAttribute('data-faq-group') !== k; });
		});
	});
})();
