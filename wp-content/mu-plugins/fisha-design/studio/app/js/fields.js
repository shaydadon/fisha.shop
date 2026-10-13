/**
 * Fisha Studio form widgets. Each returns { el, set(value), setError(message), focus() } and reports edits through
 * onChange. set() never overwrites a field the owner is typing in.
 */
import { h } from './ui.js';

let uid = 0;
const nextId = () => 'fs-f' + ++uid;

function wrap( label, control, id, help ) {
	const err = h( 'p.fs-field__error', { id: id + '-err', hidden: true } );
	const el = h( 'div.fs-field', {},
		label ? h( 'label.fs-field__label', { for: id }, label ) : null,
		control,
		help ? h( 'p.fs-field__help', {}, help ) : null,
		err );
	return {
		el,
		setError( msg ) {
			err.hidden = ! msg;
			err.textContent = msg || '';
			el.classList.toggle( 'has-error', !! msg );
		},
	};
}

export function textField( label, value, onChange, opts = {} ) {
	const id = nextId();
	const attrs = { id, value: value ?? '', maxlength: opts.max, inputmode: opts.inputmode, placeholder: opts.placeholder, list: opts.list, autocomplete: 'off', 'aria-describedby': id + '-err' };
	const input = opts.multiline ? h( 'textarea.fs-input', { ...attrs, rows: 3 } ) : h( 'input.fs-input', { ...attrs, type: opts.type || 'text' } );
	const counter = opts.counter ? h( 'span.fs-counter', { 'aria-live': 'polite' } ) : null;
	const count = () => {
		if ( ! counter ) return;
		counter.textContent = `${ input.value.length } / ${ opts.counter }`;
		counter.classList.toggle( 'is-over', input.value.length > opts.counter );
	};
	input.addEventListener( 'input', () => {
		count();
		onChange( input.value );
	} );
	count();
	const w = wrap( label, h( 'div.fs-control', {}, opts.prefix ? h( 'span.fs-affix', {}, opts.prefix ) : null, input, counter ), id, opts.help );
	return {
		...w,
		set( v ) {
			if ( document.activeElement === input ) return;
			input.value = v ?? '';
			count();
		},
		focus: () => input.focus(),
	};
}

export function selectField( label, value, options, onChange, opts = {} ) {
	const id = nextId();
	const sel = h( 'select.fs-input', { id, 'aria-describedby': id + '-err', onchange: () => onChange( sel.value ) },
		options.map( ( [ v, t ] ) => h( 'option', { value: v }, t ) ) );
	sel.value = String( value ?? '' );
	const w = wrap( label, sel, id, opts.help );
	return { ...w, set( v ) { sel.value = String( v ?? '' ); }, focus: () => sel.focus() };
}

export function toggleField( label, value, onChange, opts = {} ) {
	const id = nextId();
	const box = h( 'input.fs-switch', { id, type: 'checkbox', role: 'switch', checked: !! value, onchange: () => onChange( box.checked ) } );
	const el = h( 'div.fs-field.fs-field--toggle', {}, box, h( 'label', { for: id }, label ), opts.help ? h( 'p.fs-field__help', {}, opts.help ) : null );
	return { el, set( v ) { box.checked = !! v; }, setError() {}, focus: () => box.focus() };
}

export function categoriesField( label, value, categories, onChange ) {
	const id = nextId();
	let selected = new Set( ( value || [] ).map( Number ) );
	const kids = {};
	categories.forEach( ( c ) => ( kids[ c.parent ] ||= [] ).push( c ) );
	const ordered = [];
	const walk = ( pid, depth ) => ( kids[ pid ] || [] ).sort( ( a, b ) => a.name.localeCompare( b.name ) ).forEach( ( c ) => {
		ordered.push( [ c, depth ] );
		walk( c.id, depth + 1 );
	} );
	walk( 0, 0 );
	const boxes = {};
	const list = h( 'div.fs-checks', { role: 'group', 'aria-labelledby': id }, ordered.map( ( [ c, depth ] ) => {
		const b = h( 'input', { type: 'checkbox', value: c.id, checked: selected.has( c.id ), onchange: () => {
			if ( b.checked ) selected.add( c.id );
			else selected.delete( c.id );
			onChange( [ ...selected ].sort( ( x, y ) => x - y ) );
		} } );
		boxes[ c.id ] = b;
		return h( 'label.fs-check', { style: `--depth:${ depth }` }, b, c.name );
	} ) );
	const w = wrap( null, h( 'div', {}, h( 'p.fs-field__label', { id }, label ), list ), id, 'Pick the most specific one. A product in a sub-category also shows on its parent category page.' );
	return {
		...w,
		set( v ) {
			selected = new Set( ( v || [] ).map( Number ) );
			for ( const [ cid, b ] of Object.entries( boxes ) ) b.checked = selected.has( Number( cid ) );
		},
		focus: () => list.querySelector( 'input' )?.focus(),
	};
}

export function tagsField( label, value, tags, onChange, onCreate ) {
	const id = nextId();
	let ids = [ ...( value || [] ) ];
	const names = Object.fromEntries( tags.map( ( t ) => [ t.id, t.name ] ) );
	const chips = h( 'div.fs-chips' );
	const datalist = h( 'datalist', { id: id + '-list' }, tags.map( ( t ) => h( 'option', { value: t.name } ) ) );
	const input = h( 'input.fs-input', { id, list: id + '-list', placeholder: 'Type a tag and press Enter', autocomplete: 'off' } );
	const render = () => chips.replaceChildren( ...ids.map( ( tid ) => h( 'span.fs-chip', {}, names[ tid ] || '#' + tid,
		h( 'button', { type: 'button', 'aria-label': `Remove ${ names[ tid ] || tid }`, onclick: () => {
			ids = ids.filter( ( x ) => x !== tid );
			render();
			onChange( ids );
		} }, '×' ) ) ) );
	input.addEventListener( 'keydown', async ( e ) => {
		if ( e.key !== 'Enter' ) return;
		e.preventDefault();
		const name = input.value.trim();
		if ( ! name ) return;
		let t = tags.find( ( x ) => x.name.toLowerCase() === name.toLowerCase() );
		if ( ! t ) {
			try {
				t = await onCreate( name );
			} catch ( err ) {
				w.setError( err.message );
				return;
			}
			tags.push( t );
			names[ t.id ] = t.name;
			datalist.append( h( 'option', { value: t.name } ) );
		}
		w.setError( '' );
		if ( ! ids.includes( t.id ) ) {
			ids.push( t.id );
			onChange( ids );
		}
		input.value = '';
		render();
	} );
	render();
	const w = wrap( label, h( 'div', {}, chips, input, datalist ), id, 'Tags show on the product page under the description.' );
	return { ...w, set( v ) { ids = [ ...( v || [] ) ]; render(); }, focus: () => input.focus() };
}

export function colorField( label, value, palette, onChange ) {
	const id = nextId();
	let current = value || '';
	const swatches = h( 'div.fs-swatches', { role: 'radiogroup', 'aria-label': label } );
	const picker = h( 'input.fs-color', { type: 'color', 'aria-label': 'Pick any colour' } );
	const hex = h( 'input.fs-input.fs-input--hex', { id, maxlength: 7, 'aria-label': 'Colour code' } );
	const pick = ( c ) => {
		current = c.toLowerCase();
		render();
		onChange( current );
	};
	picker.addEventListener( 'input', () => pick( picker.value ) );
	hex.addEventListener( 'change', () => pick( hex.value.trim() ) );
	function render() {
		swatches.replaceChildren( ...palette.map( ( p ) => h( 'button.fs-swatch' + ( p.color === current ? '.is-on' : '' ), {
			type: 'button', role: 'radio', 'aria-checked': String( p.color === current ), 'aria-label': p.color,
			title: `${ p.color } · used on ${ p.count }`, style: `background:${ p.color }`, onclick: () => pick( p.color ),
		} ) ) );
		if ( /^#[0-9a-f]{6}$/.test( current ) ) picker.value = current;
		if ( document.activeElement !== hex ) hex.value = current;
	}
	render();
	const w = wrap( label, h( 'div.fs-color-row', {}, swatches, picker, hex ), id, 'The background behind the photo on product cards. The colours used most in the shop come first.' );
	return { ...w, set( v ) { current = v || ''; render(); }, focus: () => hex.focus() };
}

/* ---------- stock choice ---------- */

/** The one "Stock" choice every product and size has. "fixed" counts stock and never sells beyond it. */
export const STOCK_CHOICES = [ [ 'fixed', 'Fixed amount' ], [ 'instock', 'In stock' ], [ 'onbackorder', 'Made to order' ], [ 'outofstock', 'Sold out' ] ];
const STOCK_LABEL = Object.fromEntries( STOCK_CHOICES );

/** A number typed or stored that is 0 or less: under "Fixed amount" it means Sold out (a fixed amount is 1 or more). */
export const isNoneLeft = ( qty ) => qty !== null && qty !== undefined && String( qty ).trim() !== '' && Number.isFinite( Number( qty ) ) && Number( qty ) <= 0;

/** The choice an item is on, from its manage_stock, stock_status and stock count (a counted item at 0 shows Sold out). */
export function stockChoiceOf( manage, status, qty = null ) {
	if ( manage ) return isNoneLeft( qty ) ? 'outofstock' : 'fixed';
	return [ 'instock', 'onbackorder', 'outofstock' ].includes( status ) ? status : 'instock';
}

/** What the owner sees for an item's stock: the number for a fixed amount, otherwise the choice. */
export function stockText( manage, qty, status ) {
	if ( manage && ( qty === null || qty === undefined || qty === '' ) ) return 'Fixed amount, no number yet';
	const c = stockChoiceOf( manage, status, qty );
	return c === 'fixed' ? String( qty ) : STOCK_LABEL[ c ];
}

/**
 * The stock choice as a select, plus a number field shown only for "Fixed amount". onChoice( choice ) and
 * onQty( text ) report edits; set( choice, qty ) never overwrites the number while the owner types in it.
 */
export function stockControl( { name, choice, qty, onChoice, onQty } ) {
	let shown = choice; // the choice before the owner's change
	const sel = h( 'select.fs-input.fs-input--small.fs-stock__choice', { 'aria-label': `Stock of ${ name }`, onchange: () => {
		// Coming to Fixed amount from another choice always starts from an empty number (on screen and for the caller).
		if ( sel.value === 'fixed' && shown !== 'fixed' ) {
			input.value = '';
			onQty( '' );
		}
		shown = sel.value;
		paint();
		onChoice( sel.value );
		if ( sel.value === 'fixed' && input.value === '' ) input.focus();
	} }, STOCK_CHOICES.map( ( [ v, t ] ) => h( 'option', { value: v }, t ) ) );
	const input = h( 'input.fs-input.fs-inline.fs-inline--short.fs-stock__qty', { inputmode: 'numeric', placeholder: 'How many', 'aria-label': `How many of ${ name }`, value: isNoneLeft( qty ) ? '' : qty ?? '', oninput: () => {
		// A fixed amount is 1 or more: typing 0 (or less) switches the choice to Sold out.
		if ( isNoneLeft( input.value ) ) {
			// The 0 is not kept: the number is emptied (also for the caller) and the choice becomes Sold out.
			input.value = '';
			onQty( '' );
			sel.value = shown = 'outofstock';
			paint();
			onChoice( 'outofstock' );
			sel.focus();
			return;
		}
		paint();
		onQty( input.value );
	} } );
	const paint = () => {
		input.hidden = sel.value !== 'fixed';
		input.classList.toggle( 'is-empty', sel.value === 'fixed' && input.value.trim() === '' );
	};
	sel.value = choice;
	paint();
	return {
		el: h( 'div.fs-stock', {}, sel, input ),
		select: sel,
		input,
		set( c, q ) {
			sel.value = shown = c;
			if ( document.activeElement !== input ) input.value = isNoneLeft( q ) ? '' : q ?? '';
			paint();
		},
		focus: () => ( sel.value === 'fixed' ? input : sel ).focus(),
	};
}
