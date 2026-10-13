/**
 * Fisha Studio categories: the shop's categories as a tree with their product counts, whether each is on the site
 * (and why not), a Show on site switch, arrows or drag to change the order among siblings, and "New category".
 */
import { h, toast, confirmDialog } from '../ui.js';
import { api } from '../api.js';

const STATE = { new: [ 'Not published yet', 'draft' ], changed: [ 'Unpublished changes', 'changed' ], live: [ 'Live', 'live' ] };
const WHY = { empty: 'Not on the site yet: no products', parent: 'Not on the site: its parent is hidden' };

export function categoriesList( main ) {
	let items = [];
	let busy = false;
	let alive = true;
	let dragId = null;
	const tree = h( 'div.fs-cats', { 'aria-live': 'polite' } );
	const note = h( 'p.fs-empty', {}, 'Loading categories…' );
	main.append(
		h( 'div.fs-page-head', {},
			h( 'h1', {}, 'Categories' ),
			h( 'button.fs-btn.fs-btn--primary', { type: 'button', onclick: create }, '+ New category' ) ),
		h( 'p.fs-field__help', {}, 'A shown category appears on the site once it has a product. Top-level ones are in the Shop menu, the footer, the Home page and the Shop page, in this order.' ),
		note,
		tree );

	async function load() {
		try {
			const d = await api( 'GET', 'categories' );
			if ( ! alive ) return;
			items = d.items;
			note.hidden = true;
			tree.replaceChildren( branch( 0 ) || h( 'p.fs-empty', {}, 'No categories yet.' ) );
		} catch ( e ) {
			note.hidden = false;
			note.textContent = e.message;
		}
	}

	const kids = ( pid ) => items.filter( ( c ) => c.parent === pid );

	function branch( pid ) {
		const group = kids( pid );
		if ( ! group.length ) return null;
		return h( 'ul.fs-cats__group', {}, group.map( ( c, i ) => row( c, i, group ) ) );
	}

	function row( c, i, group ) {
		const pill = STATE[ c.state ];
		const sw = h( 'input.fs-switch', { type: 'checkbox', role: 'switch', checked: c.shown, 'aria-label': `Show ${ c.name } on the site`, onchange: () => setShown( c, sw ) } );
		const li = h( 'li.fs-cat', { draggable: 'true', dataset: { id: c.id } },
			h( 'div.fs-cat__row', {},
				h( 'span.fs-cat__grip', { 'aria-hidden': 'true' }, '⋮⋮' ),
				h( 'a.fs-cat__name', { href: '#/categories/' + c.id }, c.name || 'Untitled' ),
				h( 'span.fs-pill.fs-pill--' + pill[ 1 ], {}, pill[ 0 ] ),
				h( 'span.fs-cat__count', {}, c.count === 1 ? '1 product' : `${ c.count } products` ),
				WHY[ c.off_reason ] ? h( 'span.fs-badge.fs-badge--info', {}, WHY[ c.off_reason ] ) : null,
				h( 'label.fs-cat__shown', {}, sw, h( 'span', {}, 'Show on site' ) ),
				h( 'span.fs-cat__move', {},
					h( 'button.fs-btn.fs-btn--tiny', { type: 'button', 'aria-label': `Move ${ c.name } up`, disabled: i === 0, onclick: () => reorder( group, i, i - 1 ) }, '↑' ),
					h( 'button.fs-btn.fs-btn--tiny', { type: 'button', 'aria-label': `Move ${ c.name } down`, disabled: i === group.length - 1, onclick: () => reorder( group, i, i + 1 ) }, '↓' ) ) ),
			branch( c.id ) );
		li.addEventListener( 'dragstart', ( e ) => {
			e.stopPropagation();
			dragId = c.id;
			li.classList.add( 'is-dragging' );
		} );
		li.addEventListener( 'dragend', () => li.classList.remove( 'is-dragging' ) );
		li.addEventListener( 'dragover', ( e ) => {
			const d = items.find( ( x ) => x.id === dragId );
			if ( d && d.parent === c.parent ) {
				e.preventDefault();
				e.stopPropagation();
			}
		} );
		li.addEventListener( 'drop', ( e ) => {
			const from = group.findIndex( ( x ) => x.id === dragId );
			if ( from < 0 ) return;
			e.preventDefault();
			e.stopPropagation();
			dragId = null;
			reorder( group, from, i );
		} );
		return li;
	}

	async function setShown( c, sw ) {
		if ( busy ) {
			sw.checked = c.shown;
			return;
		}
		const on = sw.checked;
		if ( ! on && c.state !== 'new' && ! ( await confirmDialog( `Hide ${ c.name }?`, 'It disappears from the menu, the Home page, the Shop page and its parent category. Its own page keeps working for people who have the link.', 'Hide', 'danger' ) ) ) {
			sw.checked = true;
			return;
		}
		busy = true;
		try {
			await api( 'POST', `categories/${ c.id }/shown`, { shown: on } );
			toast( c.state === 'new' ? 'Saved. It applies when you publish the category.' : ( on ? `${ c.name } is shown on the site.` : `${ c.name } is hidden.` ) );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
		busy = false;
		await load();
	}

	async function reorder( group, from, to ) {
		if ( busy || from === to || to < 0 || to >= group.length ) return;
		const ids = group.map( ( c ) => c.id );
		const [ moved ] = ids.splice( from, 1 );
		ids.splice( to, 0, moved );
		busy = true;
		try {
			const d = await api( 'POST', 'categories/order', { parent: group[ 0 ].parent, ids } );
			items = d.items;
			tree.replaceChildren( branch( 0 ) );
			tree.querySelector( `[data-id="${ moved }"] .fs-cat__name` )?.focus();
		} catch ( e ) {
			toast( e.message, 'error' );
			await load();
		}
		busy = false;
	}

	async function create() {
		if ( busy ) return;
		busy = true;
		try {
			const d = await api( 'POST', 'categories', {} );
			try { sessionStorage.setItem( 'fs-new-cat-' + d.id, '1' ); } catch {}
			location.hash = '#/categories/' + d.id;
		} catch ( e ) {
			toast( e.message, 'error' );
		}
		busy = false;
	}

	load();
	return { destroy() { alive = false; } };
}
