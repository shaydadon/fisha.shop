/**
 * Fisha Studio product editor. Every edit autosaves (0.6 s after typing stops) as a server-side draft; Publish
 * first waits for any pending save, then publishes exactly what is on screen. Live products stay untouched until
 * Publish; new products stay hidden until their first Publish.
 */
import { h, toast, modal, confirmDialog, debounce } from '../ui.js';
import { api } from '../api.js';
import { textField, selectField, toggleField, categoriesField, tagsField, STOCK_CHOICES, stockChoiceOf, isNoneLeft } from '../fields.js';
import { variationsPanel } from './product-variations.js';
import { richText } from '../richtext.js';
import { imagesField } from '../images.js';
import { previewPane } from '../preview.js';

// The owner asked for the SEO and Fisha design tabs to be removed; the server keeps filling those fields with defaults.
const TABS = [ [ 'basics', 'Basics' ], [ 'price', 'Price' ], [ 'inventory', 'Inventory' ], [ 'images', 'Photos' ], [ 'shipping', 'Shipping & tax' ] ];
const TABS_SIZES = [ [ 'basics', 'Basics' ], [ 'variations', 'Sizes & options' ], [ 'inventory', 'Inventory' ], [ 'images', 'Photos' ], [ 'shipping', 'Shipping & tax' ] ];
const TAB_OF = {
	name: 'basics', slug: 'basics', status: 'basics', category_ids: 'basics', tag_ids: 'basics', short_description: 'basics', description: 'basics', catalog_visibility: 'basics', featured: 'basics', reviews_allowed: 'basics', menu_order: 'basics',
	regular_price: 'price', sale_price: 'price', date_on_sale_from: 'price', date_on_sale_to: 'price',
	sku: 'inventory', manage_stock: 'inventory', stock_quantity: 'inventory', stock_status: 'inventory', low_stock_amount: 'inventory', backorders: 'inventory', sold_individually: 'inventory',
	image_id: 'images', gallery_image_ids: 'images',
	weight: 'shipping', length: 'shipping', width: 'shipping', height: 'shipping', shipping_class_id: 'shipping', tax_status: 'shipping', tax_class: 'shipping',
};
const tabOf = ( key ) => ( 'attributes' === key || 'variations' === key || String( key ).startsWith( 'var:' ) ) ? 'variations' : ( TAB_OF[ key ] || 'basics' );
let lookups = null;
/** The trash request for an untouched new draft the owner just left; the list waits for it so the draft does not flash. */
export let pendingCleanup = null;

export function productEditor( main, id ) {
	const ed = { data: null, pending: {}, saving: null, publishing: false, alive: true, touched: false, changeUrl: false, widgets: {}, hooks: { loaded: [], saved: [], published: [] } };
	let tab = sessionStorage.getItem( 'fs-tab' ) || 'basics';

	const status = h( 'span.fs-save-state', { role: 'status', 'aria-live': 'polite' } );
	const head = h( 'div.fs-ed-head' );
	const tabBar = h( 'div.fs-tabs', { role: 'tablist', 'aria-label': 'Product sections' } );
	const panels = h( 'div' );
	const side = h( 'aside.fs-ed-side', { 'aria-label': 'Live preview' } );
	const editor = h( 'div.fs-editor', {}, h( 'div.fs-ed-main', {}, head, tabBar, panels ), side );
	main.classList.add( 'fs-main--editor' );
	main.append( editor );
	main.append( h( 'p.fs-empty', { id: 'fs-ed-loading' }, 'Loading product…' ) );

	const autosave = debounce( () => { if ( ! ed.publishing ) flush(); }, 600 );

	/** Marks this product as edited by the owner, so leaving it never removes it. */
	function touch() {
		ed.touched = true;
		try { sessionStorage.removeItem( 'fs-new-' + id ); } catch {}
	}

	function change( key, value ) {
		touch();
		ed.pending[ key ] = value;
		ed.data.values[ key ] = value;
		ed.widgets[ key ]?.setError( '' );
		if ( varsPanel && 'variations' === tabOf( key ) ) varsPanel.setError( key, '' );
		markTab( key, false );
		status.textContent = 'Saving…';
		// A live product's Publish button is disabled until something changes; enable it at once so a quick click is not lost (publish() waits for the save).
		const pub = head.querySelector( '.fs-publish' );
		if ( pub && ed.data.editable && ! ed.publishing ) pub.disabled = false;
		autosave();
		refreshDerived();
		// Size rows show "Stock is kept for the whole product" while the product counts its stock.
		if ( 'manage_stock' === key ) varsPanel?.render();
	}

	/** Saves pending edits, waiting for any save already in flight. Resolves true only when everything on screen is saved. */
	async function flush() {
		autosave.cancel();
		let ok = true;
		for ( ;; ) {
			while ( ed.saving ) {
				if ( ! ( await ed.saving ) ) ok = false;
			}
			if ( ! Object.keys( ed.pending ).length ) return ok;
			if ( ! ok ) return false;
			ed.saving = saveOnce();
			if ( ! ( await ed.saving ) ) ok = false;
		}
	}

	/** One draft save of everything pending. Resolves to whether it fully succeeded. */
	async function saveOnce() {
		const fields = ed.pending;
		ed.pending = {};
		// The live values the owner had on screen (from the last payload): the server uses them as the base of a field's
		// first edit, so an order that lands before that edit reaches the server still shows up as a conflict on Publish.
		const seen = {};
		for ( const k of Object.keys( fields ) ) if ( ed.data.live && k in ed.data.live ) seen[ k ] = ed.data.live[ k ];
		let ok = true;
		try {
			const d = await api( 'PUT', `products/${ id }/draft`, { fields, seen } );
			if ( ! ed.alive ) return ok;
			apply( d );
			const errs = d.field_errors || {};
			for ( const [ k, msg ] of Object.entries( errs ) ) setError( k, msg );
			if ( Object.keys( errs ).length ) ok = false;
			status.textContent = Object.keys( errs ).length ? 'Some fields need a look' : ( d.state === 'new' ? 'Saved (not on the site yet)' : 'Saved as draft' );
			ed.hooks.saved.forEach( ( f ) => f( d ) );
		} catch ( e ) {
			ok = false;
			ed.pending = { ...fields, ...ed.pending };
			status.textContent = 'Not saved yet';
			toast( e.message, 'error' );
		} finally {
			ed.saving = null;
		}
		return ok;
	}

	/** Takes a payload from the server, keeping edits typed since the request left. */
	function apply( d ) {
		const local = { ...ed.pending };
		ed.data = d;
		Object.assign( ed.data.values, local );
		renderHead();
		for ( const [ key, w ] of Object.entries( ed.widgets ) ) {
			if ( key === 'images' ) w.set( ed.data.values, ed.data.images );
			else if ( key in ed.data.values ) w.set( ed.data.values[ key ] );
		}
		varsPanel?.render();
		refreshDerived();
	}

	function setError( key, msg ) {
		if ( varsPanel && 'variations' === tabOf( key ) ) varsPanel.setError( key, msg );
		else ed.widgets[ key === 'image_id' || key === 'gallery_image_ids' ? 'images' : key ]?.setError( msg );
		markTab( key, !! msg );
	}
	function clearErrors() {
		varsPanel?.clearErrors();
		Object.values( ed.widgets ).forEach( ( w ) => w.setError( '' ) );
		tabBar.querySelectorAll( '.has-error' ).forEach( ( t ) => t.classList.remove( 'has-error' ) );
	}
	function markTab( key, on ) {
		const t = tabBar.querySelector( `[data-tab="${ tabOf( key ) }"]` );
		if ( t && on ) t.classList.add( 'has-error' );
	}
	function showErrors( e ) {
		const fields = e.data?.fields || {};
		Object.entries( fields ).forEach( ( [ k, m ] ) => setError( k, m ) );
		const first = Object.keys( fields )[ 0 ];
		if ( first ) {
			openTab( tabOf( first ) );
			if ( 'variations' === tabOf( first ) ) varsPanel?.focus( first );
			else ed.widgets[ first === 'image_id' || first === 'gallery_image_ids' ? 'images' : first ]?.focus();
		}
		toast( e.message, 'error' );
	}

	/* ---------- header and actions ---------- */

	function renderHead() {
		const d = ed.data;
		const pill = { new: [ 'Draft · not on the site yet', 'draft' ], changed: [ 'Unpublished changes', 'changed' ], live: [ 'Live', 'live' ] }[ d.state ];
		const actions = [];
		if ( ! d.editable ) {
			actions.push( h( 'p.fs-note', {}, 'This kind of product can only be edited in WP admin.' ) );
		} else {
			if ( d.state === 'changed' ) actions.push( h( 'button.fs-btn', { type: 'button', onclick: discard }, 'Discard changes' ) );
			if ( d.url ) actions.push( h( 'a.fs-btn', { href: d.url, target: '_blank', rel: 'noopener' }, 'View on site' ) );
			actions.push( h( 'button.fs-btn.fs-btn--primary.fs-publish', { type: 'button', onclick: () => publish(), disabled: d.state === 'live' }, d.state === 'new' ? 'Publish to the shop' : 'Publish changes' ) );
		}
		actions.push( h( 'button.fs-btn.fs-preview-toggle', { type: 'button', onclick: () => editor.classList.toggle( 'is-previewing' ) }, 'Preview' ) );
		head.replaceChildren(
			h( 'a.fs-back', { href: '#/products' }, '← All products' ),
			h( 'div.fs-ed-title', {}, h( 'h1', {}, d.values.name || 'Untitled' ), h( 'span.fs-pill.fs-pill--' + pill[ 1 ], {}, pill[ 0 ] ), status ),
			h( 'div.fs-ed-actions', {}, actions ) );
	}

	async function publish( resolutions = {} ) {
		if ( ed.publishing ) return;
		ed.publishing = true;
		const btn = head.querySelector( '.fs-publish' );
		if ( btn ) btn.disabled = true;
		status.textContent = 'Publishing…';
		try {
			for ( ;; ) {
				try {
					if ( ! ( await flush() ) ) {
						status.textContent = 'Fix the highlighted fields, then publish';
						return;
					}
					const d = await api( 'POST', `products/${ id }/publish`, { resolutions, change_url: ed.changeUrl } );
					ed.changeUrl = false;
					clearErrors();
					apply( d );
					status.textContent = 'Published';
					toast( d.values.status === 'private' ? 'Saved. It is hidden from the shop.' : 'Published. It is on the site now.', 'ok', { href: d.published_url, label: 'View on site' } );
					ed.hooks.published.forEach( ( f ) => f( d ) );
					return;
				} catch ( e ) {
					if ( e.code === 'fisha_studio_conflict' ) {
						const chosen = await resolveConflicts( e.data.conflicts );
						if ( chosen ) {
							resolutions = { ...resolutions, ...chosen };
							continue;
						}
						status.textContent = 'Not published';
						return;
					}
					if ( e.code === 'fisha_studio_nothing' ) {
						// Everything left was resolved as "keep the site's value". Drop the draft only if nothing newer is waiting.
						if ( ! Object.keys( ed.pending ).length && ! ed.saving ) {
							try {
								const d = await api( 'DELETE', `products/${ id }/draft` );
								clearErrors();
								apply( d );
								status.textContent = 'Up to date';
								toast( 'Nothing to publish. The site keeps its current values.' );
								ed.hooks.saved.forEach( ( f ) => f( d ) );
								return;
							} catch ( e2 ) {
								e = e2;
							}
						} else {
							status.textContent = 'Not published';
							toast( 'Nothing to publish. The site keeps its current values.' );
							return;
						}
					}
					status.textContent = 'Not published';
					showErrors( e );
					return;
				}
			}
		} finally {
			ed.publishing = false;
			const b2 = head.querySelector( '.fs-publish' );
			if ( b2 ) b2.disabled = ed.data.state === 'live' && ! Object.keys( ed.pending ).length;
			if ( Object.keys( ed.pending ).length ) autosave();
		}
	}

	function show( c, v ) {
		if ( Array.isArray( v ) ) return v.length ? v.join( ', ' ) : 'none';
		if ( v === '' || v === null || v === undefined ) return /(^|:)stock_quantity$/.test( c.field ) ? 'no fixed amount' : '(empty)';
		if ( v === true ) return 'on';
		if ( v === false ) return 'off';
		return String( v );
	}
	function resolveConflicts( conflicts ) {
		return modal( {
			title: 'Something changed on the site',
			body: conflicts.map( ( c ) => h( 'fieldset.fs-conflict', {},
				h( 'legend', {}, c.label ),
				h( 'p', {}, `When you started editing it was ${ show( c, c.base ) }. The site now has ${ show( c, c.live ) }${ /(^|:)stock_quantity$/.test( c.field ) ? ', probably because an order came in' : '' }.` ),
				h( 'label.fs-radio', {}, h( 'input', { type: 'radio', name: c.field, value: 'live', checked: true } ), `Keep ${ show( c, c.live ) }` ),
				h( 'label.fs-radio', {}, h( 'input', { type: 'radio', name: c.field, value: 'mine' } ), `Use mine: ${ show( c, c.mine ) }` ) ) ),
			// Each answer carries the site value shown, so "Use mine" is refused if an order changes it again meanwhile.
			actions: [ { label: 'Cancel', value: null }, { label: 'Publish', kind: 'primary', submit: true, value: ( f ) => Object.fromEntries( conflicts.map( ( c ) => [ c.field, { choice: f.elements[ c.field ].value, live: c.live } ] ) ) } ],
		} );
	}

	async function discard() {
		if ( ! ( await confirmDialog( 'Discard changes?', 'Your unpublished changes to this product will be thrown away. The live product stays exactly as it is.', 'Discard', 'danger' ) ) ) return;
		autosave.cancel();
		ed.pending = {};
		if ( ed.saving ) await ed.saving;
		try {
			const d = await api( 'DELETE', `products/${ id }/draft` );
			clearErrors();
			apply( d );
			status.textContent = '';
			toast( 'Changes discarded.' );
			ed.hooks.saved.forEach( ( f ) => f( d ) );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	async function trash() {
		const isNew = ed.data.state === 'new';
		if ( ! ( await confirmDialog( isNew ? 'Delete this draft?' : 'Move to trash?', isNew ? 'This product was never published. It will be moved to the trash.' : 'The product disappears from the shop. It stays in the trash for 30 days, and the site admin can restore it.', isNew ? 'Delete draft' : 'Move to trash', 'danger' ) ) ) return;
		autosave.cancel();
		ed.pending = {};
		try {
			await api( 'POST', `products/${ id }/trash` );
			toast( isNew ? 'Draft deleted.' : 'Moved to the trash.' );
			ed.alive = false;
			location.hash = '#/products';
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	async function changeAddress() {
		const v = ed.data.values;
		const slug = await modal( {
			title: 'Change the address',
			body: [
				h( 'p', {}, 'This product is live. Links people already shared, and search engines, use the current address. The old address will keep working and send visitors to the new one.' ),
				h( 'label.fs-field__label', {}, 'New address', h( 'input.fs-input', { name: 'slug', value: v.slug, required: true, pattern: '[a-z0-9]+(-[a-z0-9]+)*', maxlength: 60, title: 'Lowercase letters, numbers and single dashes' } ) ),
				h( 'p.fs-field__help', {}, ed.data.product_base + '…' ),
			],
			actions: [ { label: 'Cancel', value: null }, { label: 'Use this address', kind: 'primary', submit: true, value: ( f ) => f.elements.slug.value.trim() } ],
		} );
		if ( ! slug || slug === v.slug ) return;
		ed.changeUrl = true;
		change( 'slug', slug );
		urlBox.set( slug );
	}

	/* ---------- tabs and fields ---------- */

	function openTab( key ) {
		tab = key;
		try { sessionStorage.setItem( 'fs-tab', key ); } catch {}
		tabBar.querySelectorAll( '[role="tab"]' ).forEach( ( t ) => t.setAttribute( 'aria-selected', String( t.dataset.tab === key ) ) );
		panels.querySelectorAll( '[role="tabpanel"]' ).forEach( ( p ) => { p.hidden = p.dataset.tab !== key; } );
	}

	let urlBox = null;
	let varsPanel = null;
	function urlField( v ) {
		const code = h( 'code' );
		const isNew = () => ed.data.state === 'new';
		const input = h( 'input.fs-input', { placeholder: 'made from the name', maxlength: 60, 'aria-label': 'Address' } );
		input.addEventListener( 'input', () => change( 'slug', input.value.trim().toLowerCase() ) );
		const err = h( 'p.fs-field__error', { hidden: true } );
		const box = h( 'div.fs-field', {}, h( 'p.fs-field__label', {}, 'Address' ), h( 'div.fs-url' ), err );
		const paint = ( slug ) => {
			code.textContent = ed.data.product_base + ( slug || '…' ) + '/';
			box.querySelector( '.fs-url' ).replaceChildren( code, isNew()
				? h( 'span.fs-field__help', {}, 'Made from the name until you type your own:' )
				: h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: changeAddress }, 'Change address' ) );
			if ( isNew() ) {
				if ( document.activeElement !== input ) input.value = slug || '';
				box.querySelector( '.fs-url' ).append( input );
			}
		};
		paint( v );
		return { el: box, set: paint, setError( m ) { err.hidden = ! m; err.textContent = m || ''; }, focus: () => ( isNew() ? input : box.querySelector( 'button' ) )?.focus() };
	}

	const derived = [];
	function refreshDerived() { derived.forEach( ( f ) => f( ed.data.values ) ); }
	const showIf = ( widget, test ) => derived.push( ( v ) => { widget.el.hidden = ! test( v ); } );

	function build( d ) {
		const v = d.values;
		const L = lookups;
		const W = ed.widgets;
		const sizes = 'variable' === d.type;
		const tabs = sizes ? TABS_SIZES : TABS;
		if ( ! tabs.some( ( [ k ] ) => k === tab ) ) tab = 'basics';
		const on = ( key ) => ( value ) => change( key, value );
		const money = { inputmode: 'decimal', prefix: L.currency };

		W.name = textField( 'Name', v.name, on( 'name' ), { max: 120, placeholder: 'Product name' } );
		W.slug = urlBox = urlField( v.slug );
		W.status = selectField( 'Visibility', v.status, [ [ 'publish', 'Live in the shop' ], [ 'private', 'Hidden (only you can see it)' ] ], on( 'status' ) );
		W.category_ids = categoriesField( 'Categories', v.category_ids, L.categories, on( 'category_ids' ) );
		W.tag_ids = tagsField( 'Tags', v.tag_ids, L.tags, on( 'tag_ids' ), ( name ) => api( 'POST', 'tags', { name } ) );
		W.short_description = richText( 'Short description', v.short_description, on( 'short_description' ), { small: true, help: 'Shown next to the photos and used by Google.' } );
		W.description = richText( 'Full description', v.description, on( 'description' ), { help: 'Shown in the Description tab under the product.' } );
		W.catalog_visibility = selectField( 'Where it shows', v.catalog_visibility, [ [ 'visible', 'Shop and search' ], [ 'catalog', 'Shop only' ], [ 'search', 'Search only' ], [ 'hidden', 'Only by direct link' ] ], on( 'catalog_visibility' ) );
		W.featured = toggleField( 'Featured product', v.featured, on( 'featured' ) );
		W.reviews_allowed = toggleField( 'Allow reviews', v.reviews_allowed, on( 'reviews_allowed' ) );
		// Only a product that was never live (and never sold) can switch type; switching to one item deletes its sizes.
		const typeField = d.restructurable
			? toggleField( 'Comes in sizes or options', sizes, ( yes ) => switchType( yes ? 'variable' : 'simple' ), { help: 'For example a T-shirt in S, M and L. Each size gets its own price and stock.' } )
			: null;

		// "Price on request" = no price at all. A new product starts with the price fields open.
		let onRequest = d.state !== 'new' && v.regular_price === '';
		const onRequestField = toggleField( 'Price on request', onRequest, ( yes ) => {
			onRequest = yes;
			if ( yes ) {
				change( 'regular_price', '' );
				change( 'sale_price', '' );
				W.regular_price.set( '' );
				W.sale_price.set( '' );
			} else {
				refreshDerived();
				W.regular_price.focus();
			}
		}, { help: 'Cards show "Price on request" and the product cannot be added to the bag.' } );
		W.regular_price = textField( 'Price', v.regular_price, on( 'regular_price' ), money );
		W.sale_price = textField( 'Sale price', v.sale_price, on( 'sale_price' ), { ...money, help: 'Leave empty for no sale. Cards show a "Sale" badge while it runs.' } );
		W.date_on_sale_from = textField( 'Sale starts', v.date_on_sale_from, on( 'date_on_sale_from' ), { type: 'date', help: 'Optional' } );
		W.date_on_sale_to = textField( 'Sale ends', v.date_on_sale_to, on( 'date_on_sale_to' ), { type: 'date', help: 'Optional' } );
		showIf( W.regular_price, () => ! onRequest );
		showIf( W.sale_price, ( x ) => ! onRequest && x.regular_price !== '' );
		showIf( W.date_on_sale_from, ( x ) => ! onRequest && x.sale_price !== '' );
		showIf( W.date_on_sale_to, ( x ) => ! onRequest && x.sale_price !== '' );

		W.sku = textField( 'SKU', v.sku, on( 'sku' ), { max: 64, help: 'Your own stock code. Optional.' } );
		// One "Stock" choice: Fixed amount (1 or more; the shop never sells more than that), In stock, Made to order, Sold out.
		// A counted item at 0 shows Sold out; picking Fixed amount for it waits for a number of 1 or more.
		let wantFixed = false;
		const choiceNow = ( x ) => ( wantFixed && x.manage_stock ? 'fixed' : stockChoiceOf( x.manage_stock, x.stock_status, x.stock_quantity ) );
		const setChoice = ( c ) => {
			wantFixed = c === 'fixed';
			if ( c === 'fixed' ) {
				change( 'manage_stock', true );
				change( 'backorders', 'no' );
			} else {
				change( 'manage_stock', false );
				change( 'stock_status', c );
			}
			W.stock_choice.set( c );
			if ( c === 'fixed' ) {
				if ( isNoneLeft( ed.data.values.stock_quantity ) ) W.stock_quantity.set( '' );
				if ( ( ed.data.values.stock_quantity ?? '' ) === '' || isNoneLeft( ed.data.values.stock_quantity ) ) W.stock_quantity.focus();
			}
		};
		W.stock_choice = selectField( 'Stock', choiceNow( v ), STOCK_CHOICES, setChoice, { help: 'Fixed amount: the shop sells at most this many (1 or more), then shows it as sold out. In stock: no limit.' } );
		derived.push( ( x ) => W.stock_choice.set( choiceNow( x ) ) );
		// A product with sizes can count its stock as a whole; otherwise each size has its own stock choice.
		W.manage_stock = toggleField( 'Count stock for the whole product', v.manage_stock, ( yes ) => {
			change( 'manage_stock', yes );
			if ( yes ) change( 'backorders', 'no' );
		}, { help: 'Switch it off to set the stock of each size in Sizes & options.' } );
		// One item: 0 (or less) under Fixed amount means Sold out. A product counted as a whole keeps its number as typed.
		W.stock_quantity = textField( sizes ? 'In stock' : 'How many', sizes || ! isNoneLeft( v.stock_quantity ) ? v.stock_quantity ?? '' : '', ( x ) => {
			if ( ! sizes && isNoneLeft( x ) ) {
				setChoice( 'outofstock' );
				W.stock_choice.focus();
				return;
			}
			change( 'stock_quantity', x );
		}, { inputmode: 'numeric', placeholder: 'Enter a number' } );
		const notCounted = h( 'p.fs-field__help.fs-stock-note', {}, 'Not counted yet. Enter how many you have.' );
		const fixedShown = ( x ) => ( sizes ? x.manage_stock : choiceNow( x ) === 'fixed' );
		derived.push( ( x ) => { notCounted.hidden = ! fixedShown( x ) || ( ( x.stock_quantity ?? '' ) !== '' && ( sizes || ! isNoneLeft( x.stock_quantity ) ) ); } );
		W.low_stock_amount = textField( 'Low stock alert at', v.low_stock_amount ?? '', on( 'low_stock_amount' ), { inputmode: 'numeric', help: 'The Studio flags it as low stock at this count (2 when empty).' } );
		W.sold_individually = toggleField( 'One per order', v.sold_individually, on( 'sold_individually' ), { help: 'For one-of-a-kind pieces.' } );
		showIf( W.stock_quantity, fixedShown );
		showIf( W.low_stock_amount, fixedShown );
		const soldOut = h( 'button.fs-btn', { type: 'button', onclick: () => setChoice( 'outofstock' ) }, 'Mark sold out' );
		// A counted item goes back to a fixed amount (the owner types how many); otherwise back to In stock.
		const backIn = h( 'button.fs-btn', { type: 'button', onclick: () => {
			if ( ed.data.values.manage_stock ) { openTab( 'inventory' ); setChoice( 'fixed' ); W.stock_quantity.focus(); }
			else setChoice( 'instock' );
		} }, 'Back in stock' );

		W.images = imagesField( v, d.images, ( x ) => {
			change( 'image_id', x.image_id );
			change( 'gallery_image_ids', x.gallery_image_ids );
		} );

		W.weight = textField( 'Weight (kg)', v.weight, on( 'weight' ), { inputmode: 'decimal' } );
		W.length = textField( 'Length (cm)', v.length, on( 'length' ), { inputmode: 'decimal' } );
		W.width = textField( 'Width (cm)', v.width, on( 'width' ), { inputmode: 'decimal' } );
		W.height = textField( 'Height (cm)', v.height, on( 'height' ), { inputmode: 'decimal' } );
		W.shipping_class_id = selectField( 'Shipping class', v.shipping_class_id, [ [ '0', 'Standard' ], ...L.shipping_classes.map( ( s ) => [ String( s.id ), s.name ] ) ], on( 'shipping_class_id' ) );
		W.tax_status = selectField( 'Tax', v.tax_status, [ [ 'taxable', 'Taxable' ], [ 'shipping', 'Shipping only' ], [ 'none', 'No tax' ] ], on( 'tax_status' ) );
		W.tax_class = selectField( 'Tax class', v.tax_class, L.tax_classes.map( ( t ) => [ t.slug, t.name ] ), on( 'tax_class' ) );

		varsPanel = sizes ? variationsPanel( {
			productId: id,
			values: () => ed.data.values,
			payload: () => ed.data,
			change,
			flush,
			apply: ( x ) => {
				touch();
				apply( x );
			},
			saved: ( x ) => ed.hooks.saved.forEach( ( f ) => f( x ) ),
			catalog: L.attributes || [],
			currency: L.currency,
			images: () => [ ed.data.values.image_id, ...( ed.data.values.gallery_image_ids || [] ) ].filter( Boolean ).map( ( x ) => ( { id: x } ) ),
		} ) : null;

		const panel = ( key, ...children ) => h( 'section.fs-panel', { role: 'tabpanel', id: 'fs-panel-' + key, 'aria-labelledby': 'fs-tab-' + key, dataset: { tab: key }, hidden: key !== tab }, children );
		tabBar.replaceChildren( ...tabs.map( ( [ key, label ] ) => h( 'button.fs-tab', { type: 'button', role: 'tab', id: 'fs-tab-' + key, 'aria-controls': 'fs-panel-' + key, 'aria-selected': String( key === tab ), dataset: { tab: key }, onclick: () => openTab( key ) }, label ) ) );
		const readOnly = ! d.editable;
		panels.replaceChildren(
			panel( 'basics', W.name.el, typeField?.el, W.slug.el, W.status.el, W.category_ids.el, W.short_description.el, W.description.el, W.tag_ids.el,
				h( 'div.fs-grid-2', {}, W.catalog_visibility.el, h( 'div', {}, W.featured.el, W.reviews_allowed.el ) ),
				readOnly ? null : h( 'div.fs-danger-zone', {}, h( 'button.fs-btn.fs-btn--danger', { type: 'button', onclick: trash }, d.state === 'new' ? 'Delete this draft' : 'Move to trash' ) ) ),
			sizes
				? panel( 'variations', varsPanel.el )
				: panel( 'price', onRequestField.el, h( 'div.fs-grid-2', {}, W.regular_price.el, W.sale_price.el ), h( 'div.fs-grid-2', {}, W.date_on_sale_from.el, W.date_on_sale_to.el ) ),
			sizes
				? panel( 'inventory', W.manage_stock.el, h( 'div.fs-grid-2', {}, W.stock_quantity.el, W.low_stock_amount.el ), notCounted, W.sold_individually.el, W.sku.el )
				: panel( 'inventory', h( 'div.fs-stock-actions', {}, soldOut, backIn ), h( 'div.fs-grid-2', {}, W.stock_choice.el, W.stock_quantity.el ), notCounted, W.low_stock_amount.el, W.sold_individually.el, W.sku.el ),
			panel( 'images', W.images.el ),
			panel( 'shipping', h( 'div.fs-grid-4', {}, W.weight.el, W.length.el, W.width.el, W.height.el ), W.shipping_class_id.el, h( 'div.fs-grid-2', {}, W.tax_status.el, W.tax_class.el ) ) );
		if ( readOnly ) panels.querySelectorAll( 'input, select, textarea, button, [contenteditable]' ).forEach( ( x ) => {
			if ( x.hasAttribute( 'contenteditable' ) ) x.setAttribute( 'contenteditable', 'false' );
			else x.disabled = true;
		} );
		refreshDerived();
	}

	/** Rebuilds the whole editor from a payload (after the product type changed). */
	function reload( d ) {
		ed.data = d;
		derived.length = 0;
		for ( const k of Object.keys( ed.widgets ) ) delete ed.widgets[ k ];
		renderHead();
		build( d );
		ed.hooks.saved.forEach( ( f ) => f( d ) );
	}

	async function switchType( type ) {
		touch();
		if ( ! ( await flush() ) ) {
			reload( ed.data );
			return;
		}
		if ( 'simple' === type && ( ed.data.variations || [] ).length && ! ( await confirmDialog( 'Remove the sizes?', 'This product will be one item again, and the sizes made for it are deleted.', 'Remove sizes', 'danger' ) ) ) {
			reload( ed.data );
			return;
		}
		try {
			reload( await api( 'POST', `products/${ id }/type`, { type } ) );
		} catch ( e ) {
			toast( e.message, 'error' );
			reload( ed.data );
		}
	}

	/* ---------- load ---------- */

	( async () => {
		try {
			const [ d, l ] = await Promise.all( [ api( 'GET', `products/${ id }` ), lookups ? Promise.resolve( lookups ) : api( 'GET', 'lookups' ) ] );
			if ( ! ed.alive ) return;
			lookups = l;
			ed.data = d;
			main.querySelector( '#fs-ed-loading' )?.remove();
			renderHead();
			build( d );
			if ( d.conflicts?.length ) status.textContent = 'Something changed on the site since you started. You will be asked when you publish.';
			ed.hooks.loaded.forEach( ( f ) => f( d ) );
		} catch ( e ) {
			main.querySelector( '#fs-ed-loading' )?.replaceWith( h( 'p.fs-empty', {}, e.message, ' ', h( 'a', { href: '#/products' }, 'Back to products' ) ) );
		}
	} )();

	let pane = null;
	ed.hooks.loaded.push( ( d ) => {
		pane = previewPane( d.previews );
		pane.el.querySelector( '.fs-preview__bar' ).prepend( h( 'button.fs-btn.fs-btn--small.fs-preview-close', { type: 'button', onclick: () => editor.classList.remove( 'is-previewing' ) }, 'Back to editing' ) );
		side.append( pane.el );
	} );
	const refreshPreview = ( d ) => {
		if ( ! pane ) return;
		pane.setTargets( d.previews );
		pane.reload();
	};
	ed.hooks.saved.push( refreshPreview );
	ed.hooks.published.push( refreshPreview );

	return {
		destroy() {
			ed.alive = false;
			autosave.cancel();
			// "New product" makes a draft at once; one made in this session and left untouched is removed when the owner walks away.
			let fresh = false;
			try {
				fresh = sessionStorage.getItem( 'fs-new-' + id ) === '1';
				sessionStorage.removeItem( 'fs-new-' + id );
			} catch {}
			const d = ed.data;
			if ( fresh && ! ed.touched && d?.state === 'new' && ! d.values.name && ! ( d.variations || [] ).length && ! Object.keys( ed.pending ).length ) {
				pendingCleanup = api( 'POST', `products/${ id }/trash` ).catch( () => {} ).finally( () => { pendingCleanup = null; } );
			}
		},
		dirty: () => Object.keys( ed.pending ).length > 0 || !! ed.saving || ed.publishing,
		async canLeave() {
			if ( ed.publishing ) return false;
			if ( ! ed.data || ( ! Object.keys( ed.pending ).length && ! ed.saving ) ) return true;
			if ( await flush() ) return true;
			return confirmDialog( 'Leave without saving?', 'Some changes could not be saved yet.', 'Leave', 'danger' );
		},
		ed,
	};
}
