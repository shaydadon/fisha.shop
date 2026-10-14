/**
 * Fisha Studio Dashboard: counts that open the products list with that filter, what is waiting to be published,
 * recently edited items and the latest activity.
 */
import { h } from '../ui.js';
import { api } from '../api.js';
import { ago } from '../time.js';

const COUNTS = [
	[ 'live', 'Live products', { state: 'published', stock: 'all' } ],
	[ 'sold_out', 'Sold out', { state: 'published', stock: 'outofstock' } ],
	[ 'low', 'Low stock', { state: 'published', stock: 'low' } ],
	[ 'drafts', 'Drafts', { state: 'new', stock: 'all' } ],
];
const STATE = { new: 'Not published yet', changed: 'Unpublished changes' };
const link = ( x ) => `#/${ x.kind === 'category' ? 'categories' : 'products' }/${ x.id }`;

export function dashboardView( main ) {
	let alive = true;
	const body = h( 'div.fs-dash', {}, h( 'p.fs-empty', {}, 'Loading…' ) );
	main.append( h( 'div.fs-page-head', {}, h( 'h1', {}, 'Dashboard' ) ), body );

	function openList( filter ) {
		try { sessionStorage.setItem( 'fs-list', JSON.stringify( { search: '', category: '', state: 'all', stock: 'all', page: 1, ...filter } ) ); } catch {}
		location.hash = '#/products';
	}
	const section = ( title, items, empty, row ) => h( 'section.fs-dash__card', {},
		h( 'h2', {}, title ),
		items.length ? h( 'ul.fs-dash__list', {}, items.map( row ) ) : h( 'p.fs-field__help', {}, empty ) );

	( async () => {
		try {
			const d = await api( 'GET', 'dashboard' );
			if ( ! alive ) return;
			body.replaceChildren(
				h( 'div.fs-dash__counts', {}, COUNTS.map( ( [ key, label, filter ] ) => h( 'button.fs-dash__count', { type: 'button', onclick: () => openList( filter ) },
					h( 'span.fs-dash__num', {}, String( d.counts[ key ] ) ), h( 'span', {}, label ) ) ) ),
				h( 'div.fs-dash__grid', {},
					section( 'Waiting to publish', d.waiting, 'Nothing waiting to publish.', ( x ) => h( 'li', {},
						h( 'a', { href: link( x ) }, x.name ), h( 'span.fs-pill.fs-pill--' + ( x.state === 'new' ? 'draft' : 'changed' ), {}, STATE[ x.state ] ),
						x.updated ? h( 'span.fs-dash__when', {}, ago( x.updated ) ) : null ) ),
					section( 'Recently edited', d.recent, 'Nothing published yet.', ( x ) => h( 'li', {},
						h( 'a', { href: link( x ) }, x.name ), h( 'span.fs-dash__when', {}, ago( x.when ) ) ) ),
					section( 'Latest activity', d.activity, 'No activity yet.', ( e ) => h( 'li', {},
						h( 'span', {}, e.title, e.item ? ' · ' : '', e.item ? ( e.item.exists ? h( 'a', { href: link( { kind: e.item.type, id: e.item.id } ) }, e.item.name ) : e.item.name ) : null ),
						h( 'span.fs-dash__when', {}, `${ e.who }, ${ ago( e.when ) }` ) ) ) ),
				h( 'p.fs-dash__more', {}, h( 'a', { href: '#/history' }, 'See all history →' ) ) );
		} catch ( e ) {
			body.replaceChildren( h( 'p.fs-empty', {}, e.message ) );
		}
	} )();
	return { destroy() { alive = false; } };
}
