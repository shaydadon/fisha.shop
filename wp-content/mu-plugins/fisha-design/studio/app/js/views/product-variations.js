/**
 * Fisha Studio "Sizes & options" tab for products with sizes: the options shoppers choose (Size: S, M, L…) and one
 * row per size with its own on/off, price, sale price, stock, SKU and photo. Every change is a normal draft edit
 * ("attributes" or "var:{id}:{field}"), so autosave, preview, conflicts and Publish work as for any other field.
 */
import { h, toast, modal, confirmDialog } from '../ui.js';
import { api } from '../api.js';
import { stockControl, stockChoiceOf } from '../fields.js';

export function variationsPanel( ctx ) {
	const el = h( 'div.fs-vars' );
	const errBox = h( 'p.fs-field__error', { role: 'alert', hidden: true } );
	const inputs = {};
	let signature = '';
	const key = ( vid, f ) => `var:${ vid }:${ f }`;

	function sig() {
		const v = ctx.values();
		// When the product counts stock as a whole, a size switching its own count on or off changes how its row looks.
		const whole = ( s ) => ( v.manage_stock ? !! v[ key( s.id, 'manage_stock' ) ] : null );
		return JSON.stringify( [ ctx.payload().variations.map( ( s ) => [ s.id, s.pending, s.deletable, whole( s ) ] ), v.attributes, !! v.manage_stock ] );
	}

	/** Rebuilds when sizes or options changed; otherwise only refreshes values of inputs not being typed in. */
	function render() {
		const s = sig();
		if ( s === signature && el.childElementCount ) {
			const v = ctx.values();
			for ( const [ k, w ] of Object.entries( inputs ) ) if ( k in v ) w.set( v[ k ] );
			return;
		}
		signature = s;
		for ( const k of Object.keys( inputs ) ) delete inputs[ k ];
		el.replaceChildren( errBox, optionsSection(), sizesSection() );
	}

	/* ---------- options ---------- */

	function setAttributes( list ) {
		ctx.change( 'attributes', list );
		render();
	}

	function optionsSection() {
		const list = ctx.values().attributes || [];
		const used = new Set( list.map( ( a ) => a.name ) );
		const add = h( 'select.fs-input', { 'aria-label': 'Add an option', onchange: () => {
			const v = add.value;
			add.value = '';
			if ( v ) addAttribute( v );
		} },
		h( 'option', { value: '' }, '+ Add an option…' ),
		ctx.catalog.filter( ( c ) => ! used.has( c.name ) ).map( ( c ) => h( 'option', { value: c.name }, c.label ) ),
		h( 'option', { value: '__custom' }, 'Something else, only for this product…' ) );
		return h( 'section.fs-vars__options', {},
			h( 'h2', {}, 'Options shoppers choose' ),
			h( 'p.fs-field__help', {}, 'For example Size. Pick the values this product comes in, then create the sizes below.' ),
			list.map( ( a, i ) => attributeCard( a, i, list ) ),
			add );
	}

	async function addAttribute( name ) {
		const list = [ ...( ctx.values().attributes || [] ) ];
		if ( name === '__custom' ) {
			const label = await modal( {
				title: 'New option for this product',
				body: h( 'label.fs-field__label', {}, 'Name', h( 'input.fs-input', { name: 'n', required: true, maxlength: 40, placeholder: 'For example Colour' } ) ),
				actions: [ { label: 'Cancel', value: null }, { label: 'Add', kind: 'primary', submit: true, value: ( f ) => f.elements.n.value.trim() } ],
			} );
			if ( ! label ) return;
			list.push( { name: label, label, taxonomy: false, options: [], variation: true, visible: true } );
		} else {
			const c = ctx.catalog.find( ( x ) => x.name === name );
			list.push( { name, label: c ? c.label : name, taxonomy: true, options: [], variation: true, visible: true } );
		}
		setAttributes( list );
	}

	function checkbox( label, on, onChange ) {
		const b = h( 'input', { type: 'checkbox', checked: !! on, onchange: () => onChange( b.checked ) } );
		return h( 'label.fs-check', {}, b, label );
	}

	function attributeCard( a, i, list ) {
		const update = ( patch ) => setAttributes( list.map( ( x, j ) => ( j === i ? { ...x, ...patch } : x ) ) );
		let values;
		if ( a.taxonomy ) {
			const c = ctx.catalog.find( ( x ) => x.name === a.name ) || { id: 0, terms: [] };
			const chosen = new Set( a.options.map( Number ) );
			const newValue = h( 'input.fs-input.fs-input--small', { placeholder: 'New value', 'aria-label': `New value for ${ a.label }` } );
			newValue.addEventListener( 'keydown', async ( e ) => {
				if ( e.key !== 'Enter' ) return;
				e.preventDefault();
				const name = newValue.value.trim();
				if ( ! name || ! c.id ) return;
				try {
					const t = await api( 'POST', `attributes/${ c.id }/terms`, { name } );
					if ( ! c.terms.some( ( x ) => x.id === t.id ) ) c.terms.push( t );
					update( { options: [ ...new Set( [ ...a.options.map( Number ), t.id ] ) ] } );
					toast( `${ t.name } added to ${ a.label }.` );
				} catch ( err ) {
					toast( err.message, 'error' );
				}
			} );
			values = h( 'div.fs-chips', { role: 'group', 'aria-label': `${ a.label } values` },
				c.terms.map( ( t ) => h( 'button.fs-chip.fs-chip--toggle', { type: 'button', 'aria-pressed': String( chosen.has( t.id ) ), onclick: () => update( {
					options: chosen.has( t.id ) ? a.options.filter( ( o ) => Number( o ) !== t.id ) : [ ...a.options, t.id ],
				} ) }, t.name ) ),
				newValue );
		} else {
			const add = h( 'input.fs-input.fs-input--small', { placeholder: 'Add a value and press Enter', 'aria-label': `Add a value to ${ a.label }` } );
			add.addEventListener( 'keydown', ( e ) => {
				if ( e.key !== 'Enter' ) return;
				e.preventDefault();
				const v = add.value.trim();
				if ( v && ! a.options.includes( v ) ) update( { options: [ ...a.options, v ] } );
			} );
			values = h( 'div.fs-chips', {},
				a.options.map( ( o ) => h( 'span.fs-chip', {}, o, h( 'button', { type: 'button', 'aria-label': `Remove ${ o }`, onclick: () => update( { options: a.options.filter( ( x ) => x !== o ) } ) }, '×' ) ) ),
				add );
		}
		return h( 'div.fs-vars__attr', {},
			h( 'div.fs-vars__attr-head', {},
				h( 'strong', {}, a.label ),
				h( 'button.fs-btn.fs-btn--tiny.fs-btn--danger', { type: 'button', onclick: () => setAttributes( list.filter( ( _, j ) => j !== i ) ) }, 'Remove option' ) ),
			values,
			h( 'div.fs-vars__flags', {},
				checkbox( 'Used for sizes', a.variation, ( on ) => update( { variation: on } ) ),
				checkbox( 'Show on the product page', a.visible, ( on ) => update( { visible: on } ) ) ) );
	}

	/* ---------- sizes ---------- */

	function setField( vid, f, value ) {
		ctx.change( key( vid, f ), value );
		inputs[ key( vid, f ) ]?.set( value );
	}

	function sizesSection() {
		const d = ctx.payload();
		const make = h( 'button.fs-btn', { type: 'button', onclick: generate }, 'Create missing sizes' );
		if ( ! d.variations.length ) {
			return h( 'section.fs-vars__sizes', {}, h( 'h2', {}, 'Sizes' ), h( 'p.fs-empty', {}, 'No sizes yet. Choose the values above, then create the sizes.' ), make );
		}
		const bulkPrice = h( 'input.fs-input.fs-input--small', { inputmode: 'decimal', placeholder: 'Price', 'aria-label': 'Price for every size' } );
		const bulkStock = h( 'input.fs-input.fs-input--small', { inputmode: 'numeric', placeholder: '+ stock', 'aria-label': 'Add to the stock of every counted size' } );
		const bulk = h( 'div.fs-vars__bulk', {},
			bulkPrice,
			h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: () => {
				const x = bulkPrice.value.trim();
				if ( ! x ) return;
				d.variations.forEach( ( s ) => setField( s.id, 'regular_price', x ) );
				bulkPrice.value = '';
			} }, 'Set every price' ),
			bulkStock,
			h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: () => {
				const n = parseInt( bulkStock.value, 10 );
				if ( ! n ) return;
				const v = ctx.values();
				d.variations.forEach( ( s ) => {
					if ( v[ key( s.id, 'manage_stock' ) ] ) setField( s.id, 'stock_quantity', Math.max( 0, ( Number( v[ key( s.id, 'stock_quantity' ) ] ) || 0 ) + n ) );
				} );
				bulkStock.value = '';
			} }, 'Add to stock' ),
			h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: () => d.variations.forEach( ( s ) => setField( s.id, 'enabled', true ) ) }, 'All on' ),
			h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: () => d.variations.forEach( ( s ) => setField( s.id, 'enabled', false ) ) }, 'All off' ) );
		return h( 'section.fs-vars__sizes', {}, h( 'h2', {}, 'Sizes' ), bulk, h( 'div.fs-vars__rows', {}, d.variations.map( row ) ), make );
	}

	function row( s ) {
		const v = ctx.values();
		const k = ( f ) => key( s.id, f );
		const keep = ( input, fn ) => ( x ) => { if ( document.activeElement !== input ) fn( x ); };
		const reg = ( f, input, set ) => {
			inputs[ k( f ) ] = { set, focus: () => input.focus() };
			return input;
		};
		const on = h( 'input.fs-switch', { type: 'checkbox', role: 'switch', checked: !! v[ k( 'enabled' ) ], 'aria-label': `${ s.label } on sale`, onchange: () => ctx.change( k( 'enabled' ), on.checked ) } );
		const price = h( 'input.fs-input.fs-inline', { inputmode: 'decimal', value: v[ k( 'regular_price' ) ] ?? '', 'aria-label': `${ s.label } price`, oninput: () => ctx.change( k( 'regular_price' ), price.value ) } );
		const sale = h( 'input.fs-input.fs-inline', { inputmode: 'decimal', value: v[ k( 'sale_price' ) ] ?? '', placeholder: 'Sale', 'aria-label': `${ s.label } sale price`, oninput: () => ctx.change( k( 'sale_price' ), sale.value ) } );
		// The size's stock choice; "Fixed amount" shows its number (the server then never sells beyond it).
		// A counted size at 0 shows Sold out; picking Fixed amount for it shows an empty number to fill in.
		let wantFixed = false;
		const choiceNow = () => {
			const x = ctx.values();
			return wantFixed && x[ k( 'manage_stock' ) ] ? 'fixed' : stockChoiceOf( x[ k( 'manage_stock' ) ], x[ k( 'stock_status' ) ], x[ k( 'stock_quantity' ) ] );
		};
		const stock = stockControl( {
			name: s.label,
			choice: choiceNow(),
			qty: v[ k( 'stock_quantity' ) ],
			onChoice: ( c ) => {
				wantFixed = c === 'fixed';
				if ( c === 'fixed' ) {
					ctx.change( k( 'manage_stock' ), true );
				} else {
					ctx.change( k( 'manage_stock' ), false );
					ctx.change( k( 'stock_status' ), c );
				}
			},
			onQty: ( x ) => ctx.change( k( 'stock_quantity' ), x ),
		} );
		const paintStock = () => stock.set( choiceNow(), ctx.values()[ k( 'stock_quantity' ) ] );
		const sku = h( 'input.fs-input.fs-inline', { value: v[ k( 'sku' ) ] ?? '', placeholder: 'SKU', 'aria-label': `${ s.label } SKU`, oninput: () => ctx.change( k( 'sku' ), sku.value ) } );
		const photo = h( 'select.fs-input.fs-input--small', { 'aria-label': `${ s.label } photo`, onchange: () => ctx.change( k( 'image_id' ), Number( photo.value ) ) },
			h( 'option', { value: '0' }, 'Product photo' ),
			ctx.images().map( ( img, i ) => h( 'option', { value: String( img.id ) }, i ? `Photo ${ i + 1 }` : 'Main photo' ) ) );
		photo.value = String( v[ k( 'image_id' ) ] || 0 );
		reg( 'enabled', on, ( x ) => { on.checked = !! x; } );
		reg( 'regular_price', price, keep( price, ( x ) => { price.value = x ?? ''; } ) );
		reg( 'sale_price', sale, keep( sale, ( x ) => { sale.value = x ?? ''; } ) );
		reg( 'manage_stock', stock.select, paintStock );
		reg( 'stock_quantity', stock.input, paintStock );
		reg( 'stock_status', stock.select, paintStock );
		reg( 'sku', sku, keep( sku, ( x ) => { sku.value = x ?? ''; } ) );
		reg( 'image_id', photo, ( x ) => { photo.value = String( x || 0 ); } );

		// The product counts its stock as a whole and this size has no count of its own: nothing to set here.
		const whole = !! v.manage_stock && ! v[ k( 'manage_stock' ) ];
		return h( 'div.fs-vars__row', { dataset: { vid: s.id } },
			h( 'div.fs-vars__name', {}, on, h( 'strong', {}, s.label ), s.pending ? h( 'span.fs-badge.fs-badge--draft', {}, 'New' ) : null ),
			h( 'label.fs-vars__cell.fs-vars__price', {}, h( 'span.fs-vars__cap', {}, ctx.currency ), price ),
			h( 'label.fs-vars__cell.fs-vars__sale', {}, sale ),
			whole
				? h( 'div.fs-vars__cell.fs-vars__stock.fs-field__help', {}, 'Stock is kept for the whole product' )
				: h( 'div.fs-vars__cell.fs-vars__stock', {}, stock.el ),
			h( 'div.fs-vars__cell.fs-vars__sku', {}, sku ),
			h( 'div.fs-vars__cell.fs-vars__photo', {}, photo ),
			s.deletable ? h( 'button.fs-btn.fs-btn--tiny.fs-btn--danger.fs-vars__del', { type: 'button', 'aria-label': `Delete ${ s.label }`, onclick: () => removeSize( s ) }, 'Delete' ) : null,
			h( 'p.fs-field__error.fs-vars__err', { hidden: true } ) );
	}

	/* ---------- actions ---------- */

	async function generate() {
		if ( ! ( await ctx.flush() ) ) {
			toast( 'Fix the highlighted fields first.', 'error' );
			return;
		}
		try {
			const d = await api( 'POST', `products/${ ctx.productId }/variations/generate` );
			ctx.apply( d );
			render();
			ctx.saved( d );
			toast( d.created ? `${ d.created } size${ d.created > 1 ? 's' : '' } added. Set their prices, then publish.` : 'Every combination already exists.' );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	async function removeSize( s ) {
		if ( ! ( await confirmDialog( 'Delete ' + s.label + '?', 'This size is removed from the product.', 'Delete', 'danger' ) ) ) return;
		if ( ! ( await ctx.flush() ) ) return;
		try {
			const d = await api( 'DELETE', `products/${ ctx.productId }/variations/${ s.id }` );
			ctx.apply( d );
			render();
			ctx.saved( d );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	/* ---------- errors ---------- */

	function setError( k, msg ) {
		const m = /^var:(\d+):/.exec( k );
		const rowEl = m ? el.querySelector( `[data-vid="${ m[ 1 ] }"]` ) : null;
		const box = rowEl ? rowEl.querySelector( '.fs-vars__err' ) : errBox;
		box.hidden = ! msg;
		box.textContent = msg || '';
		rowEl?.classList.toggle( 'has-error', !! msg );
	}

	render();
	return {
		el,
		render,
		setError,
		focus: ( k ) => inputs[ k ]?.focus(),
		clearErrors() {
			el.querySelectorAll( '.fs-vars__err' ).forEach( ( b ) => { b.hidden = true; b.textContent = ''; } );
			el.querySelectorAll( '.fs-vars__row.has-error' ).forEach( ( r ) => r.classList.remove( 'has-error' ) );
			errBox.hidden = true;
		},
	};
}
