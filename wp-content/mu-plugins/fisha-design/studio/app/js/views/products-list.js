/**
 * Fisha Studio product list: search and filters (remembered for the browser tab), status badges, and quick
 * price/stock edits that publish straight away for live simple products without pending changes.
 */
import { h, toast, debounce } from '../ui.js';
import { api } from '../api.js';
import { pendingCleanup } from './product-editor.js';
import { stockText, isNoneLeft } from '../fields.js';

export function productsList( main ) {
	const state = { search: '', category: '', state: 'all', stock: 'all', page: 1 };
	try {
		Object.assign( state, JSON.parse( sessionStorage.getItem( 'fs-list' ) || '{}' ) );
	} catch {}
	let alive = true;
	let seq = 0;

	const table = h( 'div.fs-list' );
	const pager = h( 'div.fs-pager' );
	const select = ( key, label, options ) => h( 'select.fs-input', { 'aria-label': label, onchange: ( e ) => update( { [ key ]: e.target.value } ) },
		options.map( ( [ v, t ] ) => h( 'option', { value: v, selected: state[ key ] === v }, t ) ) );
	const catSelect = h( 'select.fs-input', { 'aria-label': 'Category', onchange: ( e ) => update( { category: e.target.value } ) }, h( 'option', { value: '' }, 'All categories' ) );

	main.append(
		h( 'div.fs-page-head', {},
			h( 'h1', {}, 'Products' ),
			h( 'button.fs-btn.fs-btn--primary', { type: 'button', onclick: createProduct }, '+ New product' ) ),
		h( 'div.fs-filters', {},
			h( 'input.fs-input.fs-search', { type: 'search', placeholder: 'Search by name or SKU', value: state.search, 'aria-label': 'Search products', oninput: debounce( ( e ) => update( { search: e.target.value } ), 300 ) } ),
			catSelect,
			select( 'state', 'Status', [ [ 'all', 'Any status' ], [ 'live', 'Live' ], [ 'changed', 'Unpublished changes' ], [ 'new', 'Drafts' ], [ 'hidden', 'Hidden' ] ] ),
			select( 'stock', 'Stock', [ [ 'all', 'Any stock' ], [ 'instock', 'In stock' ], [ 'low', 'Low stock' ], [ 'outofstock', 'Sold out' ], [ 'onbackorder', 'Made to order' ] ] ) ),
		table,
		pager );

	api( 'GET', 'lookups' ).then( ( l ) => {
		for ( const c of l.categories ) catSelect.append( h( 'option', { value: c.id, selected: String( state.category ) === String( c.id ) }, c.path ) );
	} ).catch( () => {} );

	function update( patch ) {
		Object.assign( state, { page: 1 }, patch );
		try {
			sessionStorage.setItem( 'fs-list', JSON.stringify( state ) );
		} catch {}
		load();
	}

	async function load() {
		const my = ++seq;
		table.classList.add( 'is-loading' );
		const q = new URLSearchParams( { search: state.search, category: state.category, state: state.state, stock: state.stock, page: state.page, per_page: 50 } );
		try {
			await pendingCleanup;
			const d = await api( 'GET', 'products?' + q );
			if ( alive && my === seq ) render( d );
		} catch ( e ) {
			if ( alive ) table.replaceChildren( h( 'p.fs-empty', {}, e.message ) );
		} finally {
			table.classList.remove( 'is-loading' );
		}
	}

	function render( d ) {
		if ( ! d.items.length ) {
			table.replaceChildren( h( 'p.fs-empty', {}, 'No products match. Try another search or filter.' ) );
			pager.replaceChildren();
			return;
		}
		table.replaceChildren( h( 'table.fs-table', {},
			h( 'thead', {}, h( 'tr', {},
				h( 'th', { scope: 'col' }, h( 'span.fs-sr', {}, 'Photo' ) ),
				h( 'th', { scope: 'col' }, 'Product' ),
				h( 'th', { scope: 'col' }, 'Price' ),
				h( 'th', { scope: 'col' }, 'Stock' ),
				h( 'th', { scope: 'col' }, h( 'span.fs-sr', {}, 'Actions' ) ) ) ),
			h( 'tbody', {}, d.items.map( row ) ) ) );
		pager.replaceChildren( ...( d.pages > 1 ? [
			h( 'button.fs-btn.fs-btn--small', { type: 'button', disabled: state.page <= 1, onclick: () => update( { page: state.page - 1 } ) }, '← Previous' ),
			h( 'span', {}, `Page ${ state.page } of ${ d.pages } · ${ d.total } products` ),
			h( 'button.fs-btn.fs-btn--small', { type: 'button', disabled: state.page >= d.pages, onclick: () => update( { page: state.page + 1 } ) }, 'Next →' ),
		] : [ h( 'span', {}, `${ d.total } products` ) ] ) );
	}

	function badges( r ) {
		const b = [];
		if ( r.state === 'new' ) b.push( [ 'Draft', 'draft' ] );
		if ( r.state === 'changed' ) b.push( [ 'Unpublished changes', 'changed' ] );
		if ( r.hidden ) b.push( [ 'Hidden', 'hidden' ] );
		if ( r.stock_status === 'outofstock' ) b.push( [ 'Sold out', 'out' ] );
		else if ( r.low ) b.push( [ 'Low stock', 'low' ] );
		if ( r.sizes ) b.push( [ 'Sizes', 'info' ] );
		else if ( ! r.editable ) b.push( [ 'WP admin only', 'info' ] );
		return b.map( ( [ t, k ] ) => h( 'span.fs-badge.fs-badge--' + k, {}, t ) );
	}

	function row( r ) {
		const quick = r.editable && ! r.sizes && r.state === 'live';
		const price = quick
			? h( 'input.fs-input.fs-inline', { inputmode: 'decimal', value: r.regular_price, 'aria-label': `Price of ${ r.name }` } )
			: h( 'span', {}, r.price_text || 'Price on request' );
		const stock = r.sizes
			? h( 'span', {}, r.stock_text )
			: quick && r.counted && ! isNoneLeft( r.stock_quantity )
			? h( 'input.fs-input.fs-inline.fs-inline--short', { inputmode: 'numeric', value: r.stock_quantity ?? '', 'aria-label': `Stock of ${ r.name }` } )
			: h( 'span', {}, stockText( r.counted, r.stock_quantity, r.stock_status ) );
		const save = h( 'button.fs-btn.fs-btn--small.fs-btn--primary', { type: 'button', hidden: true, onclick: () => quickSave() }, 'Publish' );
		const tr = h( 'tr', {},
			h( 'td.fs-table__thumb', {}, r.thumb ? h( 'img', { src: r.thumb, alt: '', width: 48, height: 48, loading: 'lazy' } ) : h( 'span.fs-thumb-empty' ) ),
			h( 'td', {},
				h( 'a.fs-table__name', { href: '#/products/' + r.id }, r.name ),
				h( 'div.fs-table__meta', {}, [ r.categories.join( ', ' ), r.sku ].filter( Boolean ).join( ' · ' ) ),
				h( 'div.fs-badges', {}, badges( r ) ) ),
			h( 'td', { 'data-label': 'Price' }, price ),
			h( 'td', { 'data-label': 'Stock' }, stock ),
			h( 'td.fs-table__actions', {}, save, h( 'a.fs-btn.fs-btn--small', { href: '#/products/' + r.id }, 'Edit' ) ) );
		const changed = () => ( price.tagName === 'INPUT' && price.value !== r.regular_price )
			|| ( stock.tagName === 'INPUT' && stock.value !== String( r.stock_quantity ?? '' ) );
		for ( const input of [ price, stock ] ) {
			if ( input.tagName !== 'INPUT' ) continue;
			input.addEventListener( 'input', () => { save.hidden = ! changed(); } );
			input.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Enter' && changed() ) quickSave(); } );
		}
		async function quickSave() {
			const fields = {};
			const seen = {};
			if ( price.tagName === 'INPUT' && price.value !== r.regular_price ) {
				fields.regular_price = price.value;
				seen.regular_price = r.regular_price;
			}
			if ( stock.tagName === 'INPUT' && stock.value !== String( r.stock_quantity ?? '' ) ) {
				fields.stock_quantity = stock.value;
				seen.stock_quantity = r.stock_quantity;
			}
			save.disabled = true;
			try {
				const next = await api( 'POST', `products/${ r.id }/quick`, { fields, seen } );
				tr.replaceWith( row( next ) );
				toast( `${ r.name } is updated on the site.` );
			} catch ( e ) {
				save.disabled = false;
				if ( e.code === 'fisha_studio_conflict' ) {
					// Something (usually an order) changed it on the site after this list loaded: show the new values, write nothing.
					for ( const c of e.data.conflicts || [] ) {
						const now = c.live === null || c.live === '' ? 'not set' : String( c.live );
						toast( c.field === 'stock_quantity'
							? `Stock changed on the site since this page loaded (probably an order). Now: ${ now }.`
							: `${ c.label } changed on the site since this page loaded. Now: ${ now }.`, 'error' );
					}
					load();
					return;
				}
				toast( e.message, 'error' );
			}
		}
		return tr;
	}

	async function createProduct() {
		try {
			const p = await api( 'POST', 'products', { name: '' } );
			try { sessionStorage.setItem( 'fs-new-' + p.id, '1' ); } catch {}
			location.hash = '#/products/' + p.id;
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	load();
	return { destroy() { alive = false; } };
}
