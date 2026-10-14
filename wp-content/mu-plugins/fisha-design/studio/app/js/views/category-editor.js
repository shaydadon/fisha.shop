/**
 * Fisha Studio category editor: name, address, parent, intro, hero photos and Show on site, next to the real category
 * page in the preview. Edits autosave 0.6 s after typing stops: a live category keeps them as a draft until Publish; a
 * new one takes them at once and stays off the site until its first Publish. The heading, the small line above it and
 * the Google title are filled in by the server.
 */
import { h, toast, modal, confirmDialog, debounce } from '../ui.js';
import { api } from '../api.js';
import { textField, selectField, toggleField } from '../fields.js';
import { imagesField } from '../images.js';
import { previewPane } from '../preview.js';
import { resolveConflicts } from '../conflicts.js';
import { historyList } from '../history.js';

const heroValues = ( ids ) => ( { image_id: ids?.[ 0 ] || 0, gallery_image_ids: ( ids || [] ).slice( 1 ) } );

export function categoryEditor( main, id ) {
	const ed = { data: null, cats: [], pending: {}, saving: null, publishing: false, alive: true, touched: false, changeUrl: false, widgets: {} };
	const status = h( 'span.fs-save-state', { role: 'status', 'aria-live': 'polite' } );
	const head = h( 'div.fs-ed-head' );
	const body = h( 'div.fs-ed-body' );
	const side = h( 'aside.fs-ed-side', { 'aria-label': 'Live preview' } );
	const editor = h( 'div.fs-editor', {}, h( 'div.fs-ed-main', {}, head, body ), side );
	const loading = h( 'p.fs-empty', {}, 'Loading category…' );
	main.classList.add( 'fs-main--editor' );
	main.append( editor, loading );
	let pane = null;
	let urlCode = null;
	const autosave = debounce( () => { if ( ! ed.publishing ) flush(); }, 600 );

	function change( key, value ) {
		ed.touched = true;
		try { sessionStorage.removeItem( 'fs-new-cat-' + id ); } catch {}
		ed.pending[ key ] = value;
		ed.data.values[ key ] = value;
		ed.widgets[ key ]?.setError( '' );
		status.textContent = 'Saving…';
		const pub = head.querySelector( '.fs-publish' );
		if ( pub && ! ed.publishing ) pub.disabled = false;
		paintUrl();
		autosave();
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

	async function saveOnce() {
		const fields = ed.pending;
		ed.pending = {};
		const seen = {};
		for ( const k of Object.keys( fields ) ) if ( ed.data.live && k in ed.data.live ) seen[ k ] = ed.data.live[ k ];
		let ok = true;
		try {
			const d = await api( 'PUT', `categories/${ id }/draft`, { fields, seen } );
			if ( ! ed.alive ) return ok;
			apply( d );
			const errs = d.field_errors || {};
			for ( const [ k, msg ] of Object.entries( errs ) ) ed.widgets[ k ]?.setError( msg );
			if ( Object.keys( errs ).length ) ok = false;
			status.textContent = Object.keys( errs ).length ? 'Some fields need a look' : ( d.state === 'new' ? 'Saved (not on the site yet)' : 'Saved as draft' );
			refreshPreview( d );
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
			if ( key === 'hero_ids' ) w.set( heroValues( ed.data.values.hero_ids ), ed.data.images );
			else if ( key in ed.data.values ) w.set( ed.data.values[ key ] );
		}
		paintUrl();
	}

	function clearErrors() {
		Object.values( ed.widgets ).forEach( ( w ) => w.setError( '' ) );
	}
	function showErrors( e ) {
		const fields = e.data?.fields || {};
		Object.entries( fields ).forEach( ( [ k, m ] ) => ed.widgets[ k ]?.setError( m ) );
		const first = Object.keys( fields )[ 0 ];
		if ( first ) ed.widgets[ first ]?.focus();
		toast( e.message, 'error' );
	}

	/* ---------- header and actions ---------- */

	function renderHead() {
		const d = ed.data;
		const pill = { new: [ 'Not published yet', 'draft' ], changed: [ 'Unpublished changes', 'changed' ], live: [ 'Live', 'live' ] }[ d.state ];
		head.replaceChildren(
			h( 'a.fs-back', { href: '#/categories' }, '← All categories' ),
			h( 'div.fs-ed-title', {}, h( 'h1', {}, d.values.name || 'Untitled' ), h( 'span.fs-pill.fs-pill--' + pill[ 1 ], {}, pill[ 0 ] ), status ),
			h( 'div.fs-ed-actions', {},
				d.state === 'changed' ? h( 'button.fs-btn', { type: 'button', onclick: discard }, 'Discard changes' ) : null,
				d.url ? h( 'a.fs-btn', { href: d.url, target: '_blank', rel: 'noopener' }, 'View on site' ) : null,
				h( 'button.fs-btn.fs-btn--primary.fs-publish', { type: 'button', onclick: () => publish(), disabled: d.state === 'live' && ! Object.keys( ed.pending ).length }, d.state === 'new' ? 'Publish category' : 'Publish changes' ),
				h( 'button.fs-btn.fs-preview-toggle', { type: 'button', onclick: () => editor.classList.toggle( 'is-previewing' ) }, 'Preview' ) ) );
	}

	const newUrl = () => ed.data.base_url + ( ed.data.values.slug || '…' ) + '/';
	function paintUrl() {
		if ( urlCode && ed.data ) urlCode.textContent = newUrl();
	}

	/** A live category whose parent or address changed asks once before it moves; old links will redirect. */
	async function confirmMove() {
		const d = ed.data;
		if ( d.state === 'new' || ( d.values.parent === d.live.parent && d.values.slug === d.live.slug ) || ed.changeUrl === newUrl() ) return true;
		const ok = await confirmDialog( 'Change the address?', `The address changes from ${ d.url } to ${ newUrl() }. Old links, also to its sub-categories, will send visitors to the new address.`, 'Change address' );
		if ( ok ) ed.changeUrl = newUrl();
		return ok;
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
					if ( ! ( await confirmMove() ) ) {
						status.textContent = 'Not published';
						return;
					}
					const d = await api( 'POST', `categories/${ id }/publish`, { resolutions, change_url: !! ed.changeUrl } );
					ed.changeUrl = false;
					clearErrors();
					apply( d );
					status.textContent = 'Published';
					const msg = d.displayed ? 'Published. It is on the site now.' : ( d.values.shown ? 'Published. It appears on the site once it has a product.' : 'Published. It stays out of the menu, Home and Shop.' );
					toast( msg, 'ok', d.url ? { href: d.url, label: 'View on site' } : null );
					refreshPreview( d );
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
					if ( e.code === 'fisha_studio_nothing' && ! Object.keys( ed.pending ).length && ! ed.saving ) {
						try {
							apply( await api( 'DELETE', `categories/${ id }/draft` ) );
							status.textContent = 'Up to date';
							toast( 'Nothing to publish. The site keeps its current values.' );
							return;
						} catch ( e2 ) {
							e = e2;
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

	async function discard() {
		if ( ! ( await confirmDialog( 'Discard changes?', 'Your unpublished changes to this category will be thrown away. The live category stays exactly as it is.', 'Discard', 'danger' ) ) ) return;
		autosave.cancel();
		ed.pending = {};
		ed.changeUrl = false;
		if ( ed.saving ) await ed.saving;
		try {
			const d = await api( 'DELETE', `categories/${ id }/draft` );
			clearErrors();
			apply( d );
			status.textContent = '';
			toast( 'Changes discarded.' );
			refreshPreview( d );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	async function restoreVersion( entry ) {
		if ( ! ( await flush() ) ) return;
		try {
			const d = await api( 'POST', `categories/${ id }/restore`, { log_id: entry.id } );
			clearErrors();
			apply( d );
			for ( const k of Object.keys( ed.widgets ) ) delete ed.widgets[ k ];
			build( d );
			refreshPreview( d );
			toast( 'That version is now in your draft. Check the preview, then publish.' );
			( d.skipped || [] ).forEach( ( s ) => toast( s, 'warn' ) );
		} catch ( e ) {
			toast( e.message, e.code === 'fisha_studio_same' ? 'ok' : 'error' );
		}
	}

	async function editAddress() {
		const d = ed.data;
		const slug = await modal( {
			title: d.state === 'new' ? 'Edit the address' : 'Change the address',
			body: [
				d.state === 'new' ? null : h( 'p', {}, 'This category is live. Old links keep working and send visitors to the new address, also links to its sub-categories.' ),
				h( 'label.fs-field__label', {}, 'Address', h( 'input.fs-input', { name: 'slug', value: d.values.slug, required: true, pattern: '[a-z0-9]+(-[a-z0-9]+)*', maxlength: 60, title: 'Lowercase letters, numbers and single dashes' } ) ),
				h( 'p.fs-field__help', {}, d.base_url + '…' ),
			],
			actions: [ { label: 'Cancel', value: null }, { label: 'Use this address', kind: 'primary', submit: true, value: ( f ) => f.elements.slug.value.trim() } ],
		} );
		if ( ! slug || slug === d.values.slug ) return;
		if ( d.state !== 'new' ) ed.changeUrl = d.base_url + slug + '/';
		change( 'slug', slug );
	}

	/* ---------- delete ---------- */

	const byId = () => new Map( ed.cats.map( ( c ) => [ c.id, c ] ) );
	function pathOf( c ) {
		const m = byId();
		const names = [ c.name ];
		for ( let p = m.get( c.parent ); p; p = m.get( p.parent ) ) names.unshift( p.name );
		return names.join( ' › ' );
	}
	function descendantsOf( root ) {
		const out = new Set();
		for ( let grew = true; grew; ) {
			grew = false;
			for ( const c of ed.cats ) {
				if ( ( c.parent === root || out.has( c.parent ) ) && ! out.has( c.id ) ) {
					out.add( c.id );
					grew = true;
				}
			}
		}
		return out;
	}
	function parentChoices() {
		const below = descendantsOf( id );
		return ed.cats.filter( ( c ) => c.id !== id && ! below.has( c.id ) ).map( ( c ) => [ String( c.id ), pathOf( c ) ] );
	}

	async function remove() {
		const d = ed.data;
		const parent = byId().get( d.live.parent );
		const where = parent ? `the ${ parent.name } page` : 'the Shop page';
		let moveTo = 0;
		if ( d.orphan_products > 0 ) {
			const n = d.orphan_products;
			moveTo = await modal( {
				title: `Delete ${ d.values.name }?`,
				body: [
					h( 'p', {}, `${ n === 1 ? '1 product is' : n + ' products are' } only in this category. Choose where ${ n === 1 ? 'it goes' : 'they go' }:` ),
					h( 'select.fs-input', { name: 'to', required: true, 'aria-label': 'Move products to' }, h( 'option', { value: '' }, 'Choose a category…' ), parentChoices().map( ( [ v, t ] ) => h( 'option', { value: v }, t ) ) ),
					h( 'p.fs-field__help', {}, `Its sub-categories move up one level. Its address sends visitors to ${ where }.` ),
				],
				actions: [ { label: 'Cancel', value: null }, { label: 'Delete category', kind: 'danger', submit: true, value: ( f ) => Number( f.elements.to.value ) } ],
			} );
			if ( ! moveTo ) return;
		} else if ( ! ( await confirmDialog( `Delete ${ d.values.name }?`, d.state === 'new' ? 'This category was never published. It will be removed.' : `Its sub-categories move up one level, and its address sends visitors to ${ where }.`, 'Delete category', 'danger' ) ) ) {
			return;
		}
		autosave.cancel();
		ed.pending = {};
		try {
			await api( 'DELETE', `categories/${ id }`, { move_to: moveTo }, { retry: false } );
			ed.alive = false;
			toast( 'Category deleted.' );
			location.hash = '#/categories';
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	/* ---------- fields ---------- */

	function build( d ) {
		const v = d.values;
		const W = ed.widgets;
		W.name = textField( 'Name', v.name, ( x ) => change( 'name', x ), { max: 60 } );
		urlCode = h( 'code' );
		W.slug = {
			el: h( 'div.fs-field', {}, h( 'p.fs-field__label', {}, 'Address' ),
				h( 'div.fs-url', {}, urlCode, h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: editAddress }, d.state === 'new' ? 'Edit address' : 'Change address' ) ),
				h( 'p.fs-field__error', { hidden: true } ) ),
			set() { paintUrl(); },
			setError( msg ) {
				const e = W.slug.el.querySelector( '.fs-field__error' );
				e.hidden = ! msg;
				e.textContent = msg || '';
			},
			focus() { W.slug.el.querySelector( 'button' ).focus(); },
		};
		W.parent = selectField( 'Parent', String( v.parent ), [ [ '0', 'Top level' ], ...parentChoices() ], ( x ) => change( 'parent', Number( x ) ), { help: 'Top-level categories are in the Shop menu, the footer, the Home page and the Shop page.' } );
		W.lead = textField( 'Intro', v.lead, ( x ) => change( 'lead', x ), { multiline: true, counter: 160, max: 300, help: 'One or two sentences under the heading. Google shows it too.' } );
		W.hero_ids = imagesField( heroValues( v.hero_ids ), d.images, ( x ) => change( 'hero_ids', [ x.image_id, ...x.gallery_image_ids ].filter( Boolean ) ), { label: 'Hero photos', help: 'One or two photos for the top of the page. Leave it empty to use the first product photos.', max: 2, hoverNote: false } );
		W.shown = toggleField( 'Show on site', v.shown, ( x ) => change( 'shown', x ), { help: 'When on, it appears on the site once it has at least one product.' } );
		body.replaceChildren( W.name.el, W.slug.el, W.parent.el, W.lead.el, W.hero_ids.el, W.shown.el,
			h( 'details.fs-cat-history', {},
				h( 'summary', {}, 'History' ),
				h( 'p.fs-field__help', {}, 'Restoring puts that version into your draft. Check it in the preview, then publish.' ),
				historyList( { query: { item_type: 'category', item_id: id, per_page: 20 }, onRestore: restoreVersion } ).el ),
			d.deletable ? h( 'div.fs-danger-zone', {}, h( 'button.fs-btn.fs-btn--danger', { type: 'button', onclick: remove }, 'Delete category' ) ) : null );
		paintUrl();
	}

	function refreshPreview( d ) {
		if ( ! pane ) return;
		pane.setTargets( d.previews );
		pane.reload();
	}

	/* ---------- load ---------- */

	( async () => {
		try {
			const [ d, list ] = await Promise.all( [ api( 'GET', `categories/${ id }` ), api( 'GET', 'categories' ) ] );
			if ( ! ed.alive ) return;
			ed.data = d;
			ed.cats = list.items;
			loading.remove();
			renderHead();
			build( d );
			pane = previewPane( d.previews );
			pane.el.querySelector( '.fs-preview__bar' ).prepend( h( 'button.fs-btn.fs-btn--small.fs-preview-close', { type: 'button', onclick: () => editor.classList.remove( 'is-previewing' ) }, 'Back to editing' ) );
			side.append( pane.el );
		} catch ( e ) {
			loading.replaceChildren( e.message, ' ', h( 'a', { href: '#/categories' }, 'Back to categories' ) );
		}
	} )();

	return {
		destroy() {
			ed.alive = false;
			autosave.cancel();
			// "New category" makes one at once; one made in this session and left untouched is removed when the owner walks away.
			let fresh = false;
			try {
				fresh = sessionStorage.getItem( 'fs-new-cat-' + id ) === '1';
				sessionStorage.removeItem( 'fs-new-cat-' + id );
			} catch {}
			const d = ed.data;
			if ( fresh && ! ed.touched && d?.state === 'new' && ! d.direct_products && ! Object.keys( ed.pending ).length ) {
				api( 'DELETE', `categories/${ id }`, { move_to: 0 }, { retry: false } ).catch( () => {} );
			}
		},
		dirty: () => Object.keys( ed.pending ).length > 0 || !! ed.saving || ed.publishing,
		async canLeave() {
			if ( ed.publishing ) return false;
			if ( ! ed.data || ( ! Object.keys( ed.pending ).length && ! ed.saving ) ) return true;
			if ( await flush() ) return true;
			return confirmDialog( 'Leave without saving?', 'Some changes could not be saved yet.', 'Leave', 'danger' );
		},
	};
}
