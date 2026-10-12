/**
 * Fisha Studio preview pane: the real page in an iframe, with the draft laid over it by the server
 * (studio-preview.php). Pick the page (product, its category, Shop, Home), desktop or phone width.
 * Reloads after every autosave and keeps the scroll position.
 */
import { h } from './ui.js';

const DEVICES = [ [ 'desktop', 'Desktop' ], [ 'phone', 'Phone' ] ];

export function previewPane( targets ) {
	let list = targets;
	let current = 'item';
	let device = 'desktop';
	try { device = sessionStorage.getItem( 'fs-device' ) || 'desktop'; } catch {}
	let scrollY = 0;

	const frame = h( 'iframe.fs-preview__frame', { title: 'Live preview of the page' } );
	const loading = h( 'div.fs-preview__loading', { hidden: true, role: 'status' }, 'Updating preview…' );
	const error = h( 'div.fs-preview__error', { hidden: true },
		h( 'p', {}, 'The preview could not load.' ),
		h( 'button.fs-btn', { type: 'button', onclick: () => reload() }, 'Try again' ) );
	const select = h( 'select.fs-input.fs-input--small', { 'aria-label': 'Page to preview', onchange: () => { current = select.value; scrollY = 0; load(); } } );
	const deviceBtns = DEVICES.map( ( [ key, label ] ) => h( 'button.fs-seg', { type: 'button', 'aria-pressed': String( key === device ), onclick: () => {
		device = key;
		try { sessionStorage.setItem( 'fs-device', key ); } catch {}
		paint();
	} }, label ) );
	const open = h( 'a.fs-link', { target: '_blank', rel: 'noopener' }, 'Open in new tab' );
	const el = h( 'section.fs-preview', { 'aria-label': 'Preview' },
		h( 'div.fs-preview__bar', {}, select, h( 'div.fs-seg-group', {}, deviceBtns ), h( 'button.fs-btn.fs-btn--small', { type: 'button', 'aria-label': 'Refresh preview', onclick: () => reload() }, '↻' ), open ),
		h( 'div.fs-preview__stage', {}, h( 'div.fs-preview__device', {}, frame, loading, error ) ) );

	frame.addEventListener( 'load', () => {
		loading.hidden = true;
		try {
			const doc = frame.contentDocument;
			const text = doc?.body?.textContent?.slice( 0, 3000 ) || '';
			const broken = ! doc || doc.querySelector( '.wp-die-message, #error-page' ) || /critical error/i.test( text );
			error.hidden = ! broken;
			if ( ! broken ) frame.contentWindow.scrollTo( { top: scrollY, behavior: 'instant' } );
		} catch {
			error.hidden = true;
		}
	} );

	const url = () => ( list.find( ( t ) => t.key === current ) || list[ 0 ] ).url;
	function paint() {
		el.dataset.device = device;
		deviceBtns.forEach( ( b, i ) => b.setAttribute( 'aria-pressed', String( DEVICES[ i ][ 0 ] === device ) ) );
	}
	function load() {
		loading.hidden = false;
		error.hidden = true;
		open.href = url();
		frame.src = url();
	}
	function reload() {
		try { scrollY = frame.contentWindow.scrollY; } catch { scrollY = 0; }
		load();
	}
	function setTargets( next ) {
		list = next;
		if ( ! list.some( ( t ) => t.key === current ) ) current = 'item';
		select.replaceChildren( ...list.map( ( t ) => h( 'option', { value: t.key, selected: t.key === current }, t.label ) ) );
		open.href = url();
	}

	setTargets( targets );
	paint();
	load();
	return { el, reload, setTargets };
}
