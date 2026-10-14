/**
 * Fisha Studio History screen: every change made in the Studio, filtered by kind and date, plus recently deleted
 * products with "Bring back" (they return as drafts).
 */
import { h, toast } from '../ui.js';
import { api } from '../api.js';
import { historyList } from '../history.js';
import { ago } from '../time.js';

const KINDS = [ [ 'all', 'Everything' ], [ 'product', 'Products' ], [ 'category', 'Categories' ], [ 'signin', 'Sign-ins' ], [ 'other', 'Size lists and other' ] ];
const DAYS = [ [ '0', 'Any time' ], [ '1', 'Today' ], [ '7', 'Last 7 days' ], [ '30', 'Last 30 days' ] ];

export function historyView( main ) {
	const q = { kind: 'all', days: '0', per_page: 50 };
	const box = h( 'div' );
	const trash = h( 'section.fs-trash' );
	const pick = ( key, options, label ) => h( 'select.fs-input', { 'aria-label': label, onchange: ( e ) => { q[ key ] = e.target.value; render(); } }, options.map( ( [ v, t ] ) => h( 'option', { value: v }, t ) ) );
	main.append( h( 'div.fs-page-head', {}, h( 'h1', {}, 'History' ) ),
		h( 'p.fs-field__help', {}, 'Changes made in the Studio, newest first. Stock that changes because of an order is not listed here.' ),
		h( 'div.fs-filters', {}, pick( 'kind', KINDS, 'What' ), pick( 'days', DAYS, 'When' ) ),
		box, trash );

	function render() {
		box.replaceChildren( historyList( { query: { ...q } } ).el );
	}

	async function loadTrash() {
		try {
			const d = await api( 'GET', 'trash' );
			trash.replaceChildren( h( 'h2', {}, 'Recently deleted products' ),
				d.items.length
					? h( 'ul.fs-trash__list', {}, d.items.map( ( x ) => h( 'li', {},
						x.thumb ? h( 'img', { src: x.thumb, alt: '', width: 44, height: 44 } ) : null,
						h( 'span', {}, x.name, h( 'span.fs-hist__when', {}, ' deleted ' + ago( x.deleted ) ) ),
						h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: () => bringBack( x ) }, 'Bring back' ) ) ) )
					: h( 'p.fs-field__help', {}, 'Nothing deleted in the last 30 days.' ) );
		} catch ( e ) {
			trash.replaceChildren();
		}
	}

	async function bringBack( x ) {
		try {
			const d = await api( 'POST', `products/${ x.id }/untrash` );
			toast( `${ x.name } is back as a draft. Publish it when it is ready.` );
			location.hash = '#/products/' + d.id;
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	render();
	loadTrash();
	return {};
}
