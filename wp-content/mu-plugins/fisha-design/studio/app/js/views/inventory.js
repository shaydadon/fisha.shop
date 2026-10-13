/**
 * Fisha Studio Inventory: every product and every size in one table, for a stock count or price update. A change
 * goes live as soon as it is saved, and is refused if it changed on the site since the list loaded (an order).
 */
import { h, toast, debounce } from '../ui.js';
import { api } from '../api.js';
import { stockControl, stockChoiceOf, stockText } from '../fields.js';

export function inventoryView( main ) {
	const state = { search: '', category: '', stock: 'all', sort: 'name', page: 1 };
	try { Object.assign( state, JSON.parse( sessionStorage.getItem( 'fs-inv' ) || '{}' ) ); } catch {}
	let alive = true;
	let seq = 0;
	const list = h( 'div.fs-list' );
	const pager = h( 'div.fs-pager' );
	const select = ( key, label, options ) => h( 'select.fs-input', { 'aria-label': label, onchange: ( e ) => update( { [ key ]: e.target.value } ) },
		options.map( ( [ v, t ] ) => h( 'option', { value: v, selected: state[ key ] === v }, t ) ) );
	const catSelect = h( 'select.fs-input', { 'aria-label': 'Category', onchange: ( e ) => update( { category: e.target.value } ) }, h( 'option', { value: '' }, 'All categories' ) );
	const search = debounce( ( value ) => update( { search: value } ), 300 );

	main.append(
		h( 'div.fs-page-head', {}, h( 'h1', {}, 'Inventory' ) ),
		h( 'p.fs-field__help', {}, 'Change a number and press Enter or Save. It goes live straight away.' ),
		h( 'div.fs-filters', {},
			h( 'input.fs-input.fs-search', { type: 'search', placeholder: 'Search by name, size or SKU', value: state.search, 'aria-label': 'Search', oninput: ( e ) => search( e.target.value ) } ),
			catSelect,
			select( 'stock', 'Stock', [ [ 'all', 'Any stock' ], [ 'low', 'Low stock' ], [ 'outofstock', 'Sold out' ], [ 'instock', 'In stock' ], [ 'onbackorder', 'Made to order' ], [ 'uncounted', 'Not counted (no limit)' ] ] ),
			select( 'sort', 'Sort by', [ [ 'name', 'Name' ], [ 'stock', 'Lowest stock first' ], [ 'sku', 'SKU' ], [ 'price', 'Price' ] ] ) ),
		list,
		pager );

	api( 'GET', 'lookups' ).then( ( l ) => {
		for ( const c of l.categories ) catSelect.append( h( 'option', { value: c.id, selected: String( state.category ) === String( c.id ) }, c.path ) );
	} ).catch( () => {} );

	function update( patch ) {
		Object.assign( state, { page: 1 }, patch );
		try { sessionStorage.setItem( 'fs-inv', JSON.stringify( state ) ); } catch {}
		load();
	}

	async function load() {
		const my = ++seq;
		list.classList.add( 'is-loading' );
		const q = new URLSearchParams( { search: state.search, category: state.category, stock: state.stock, sort: state.sort, page: state.page, per_page: 100 } );
		try {
			const d = await api( 'GET', 'inventory?' + q );
			if ( alive && my === seq ) render( d );
		} catch ( e ) {
			if ( alive && my === seq ) list.replaceChildren( h( 'p.fs-empty', {}, e.message ) );
		} finally {
			if ( my === seq ) list.classList.remove( 'is-loading' );
		}
	}

	function render( d ) {
		if ( ! d.items.length ) {
			list.replaceChildren( h( 'p.fs-empty', {}, 'Nothing matches. Try another search or filter.' ) );
			pager.replaceChildren();
			return;
		}
		list.replaceChildren( h( 'table.fs-table.fs-inv', {},
			h( 'thead', {}, h( 'tr', {},
				h( 'th', { scope: 'col' }, h( 'span.fs-sr', {}, 'Photo' ) ),
				h( 'th', { scope: 'col' }, 'Product' ),
				h( 'th', { scope: 'col' }, 'Stock' ),
				h( 'th', { scope: 'col' }, 'Price' ),
				h( 'th', { scope: 'col' }, h( 'span.fs-sr', {}, 'Actions' ) ) ) ),
			h( 'tbody', {}, d.items.map( row ) ) ) );
		pager.replaceChildren( ...( d.pages > 1 ? [
			h( 'button.fs-btn.fs-btn--small', { type: 'button', disabled: state.page <= 1, onclick: () => update( { page: state.page - 1 } ) }, '← Previous' ),
			h( 'span', {}, `Page ${ state.page } of ${ d.pages } · ${ d.total } rows` ),
			h( 'button.fs-btn.fs-btn--small', { type: 'button', disabled: state.page >= d.pages, onclick: () => update( { page: state.page + 1 } ) }, 'Next →' ),
		] : [ h( 'span', {}, `${ d.total } rows` ) ] ) );
	}

	function row( r ) {
		const title = r.option ? `${ r.name } · ${ r.option }` : r.name;
		const open = h( 'a.fs-btn.fs-btn--small', { href: '#/products/' + r.product_id }, 'Open' );
		const product = h( 'td', {},
			h( 'a.fs-table__name', { href: '#/products/' + r.product_id }, r.name ),
			r.option ? h( 'span.fs-inv__option', {}, r.option ) : null,
			h( 'div.fs-table__meta', {}, [ r.sku, r.enabled ? '' : 'Switched off' ].filter( Boolean ).join( ' · ' ) ),
			h( 'div.fs-badges', {},
				'outofstock' === r.stock_status ? h( 'span.fs-badge.fs-badge--out', {}, 'Sold out' ) : r.low ? h( 'span.fs-badge.fs-badge--low', {}, 'Low stock' ) : null,
				// Sells without a limit: a hint for items the owner may want to cap with a fixed amount.
				! r.counted && 'outofstock' !== r.stock_status && ! r.locked ? h( 'span.fs-badge.fs-badge--info', {}, 'No limit' ) : null,
				r.locked ? h( 'span.fs-badge.fs-badge--changed', {}, r.locked ) : null ) );
		const thumb = h( 'td.fs-table__thumb', {}, r.thumb ? h( 'img', { src: r.thumb, alt: '', width: 48, height: 48, loading: 'lazy' } ) : h( 'span.fs-thumb-empty' ) );
		if ( r.locked ) {
			return h( 'tr', {}, thumb, product,
				h( 'td', { 'data-label': 'Stock' }, stockText( r.counted, r.stock_quantity, r.stock_status ) ),
				h( 'td', { 'data-label': 'Price' }, r.regular_price ),
				h( 'td.fs-table__actions', {}, open ) );
		}
		// The stock choice and, for "Fixed amount", the number. A product with sizes counted as a whole only has its number.
		const refresh = () => { save.hidden = ! Object.keys( fieldsNow() ).length; };
		const stock = r.stock_only
			? { el: h( 'input.fs-input.fs-inline.fs-inline--short', { inputmode: 'numeric', value: r.stock_quantity ?? '', 'aria-label': `Stock of ${ title }` } ) }
			: stockControl( { name: title, choice: stockChoiceOf( r.counted, r.stock_status ), qty: r.stock_quantity, onChoice: refresh, onQty: refresh } );
		if ( r.stock_only ) Object.assign( stock, { select: { value: 'fixed' }, input: stock.el } );
		// A product with sizes whose stock is counted as a whole: only its count is edited here, prices are per size.
		const price = r.stock_only
			? h( 'span.fs-field__help', {}, 'Set per size' )
			: h( 'input.fs-input.fs-inline', { inputmode: 'decimal', value: r.regular_price, 'aria-label': `Price of ${ title }` } );
		const save = h( 'button.fs-btn.fs-btn--small.fs-btn--primary', { type: 'button', hidden: true, onclick: () => commit() }, 'Save' );
		// What the owner saw; an uncounted item was seen with no number (null).
		const was = { stock_quantity: r.counted ? r.stock_quantity : null, stock_status: r.stock_status, regular_price: r.regular_price };
		const fieldsNow = () => {
			const f = {};
			const choice = stock.select.value;
			const qty = stock.input.value.trim();
			if ( choice === 'fixed' ) {
				// A number turns counting on (the server then never sells beyond it); no number yet means nothing to save.
				if ( qty !== '' && ( ! r.counted || qty !== String( r.stock_quantity ?? '' ) ) ) f.stock_quantity = qty;
			} else if ( r.counted || choice !== r.stock_status ) {
				f.stock_status = choice;
			}
			if ( ! r.stock_only && price.value !== r.regular_price ) f.regular_price = price.value;
			return f;
		};
		for ( const input of r.stock_only ? [ stock.input ] : [ stock.input, stock.select, price ] ) {
			input.addEventListener( 'input', refresh );
			input.addEventListener( 'change', refresh );
			input.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Enter' && ! save.hidden && ! save.disabled ) commit(); } );
		}
		async function commit() {
			const fields = fieldsNow();
			if ( ! Object.keys( fields ).length ) return;
			const seen = {};
			for ( const k of Object.keys( fields ) ) seen[ k ] = was[ k ];
			save.disabled = true;
			try {
				const res = await api( 'POST', r.kind === 'variation' ? `variations/${ r.id }/quick` : `products/${ r.id }/quick`, { fields, seen } );
				toast( `${ title } is updated on the site.` );
				// Only this row is redrawn, so numbers typed in other rows stay. A size comes back as its inventory row;
				// a product comes back as its products-list row, whose stock and price are merged in.
				const next = r.kind === 'variation' ? res : { ...r, stock_quantity: res.stock_quantity, stock_status: res.stock_status, regular_price: res.regular_price, low: res.low, counted: res.counted, manage_stock: res.counted };
				if ( alive ) tr.replaceWith( row( next ) );
			} catch ( e ) {
				save.disabled = false;
				if ( e.code === 'fisha_studio_conflict' ) {
					toast( `${ title } changed on the site since this page loaded (probably an order). The list now shows the current values.`, 'error' );
					load();
					return;
				}
				toast( e.message, 'error' );
			}
		}
		const tr = h( 'tr', {}, thumb, product,
			h( 'td', { 'data-label': 'Stock' }, stock.el ),
			h( 'td', { 'data-label': 'Price' }, price ),
			h( 'td.fs-table__actions', {}, save, open ) );
		return tr;
	}

	load();
	return { destroy() { alive = false; search.cancel(); } };
}
