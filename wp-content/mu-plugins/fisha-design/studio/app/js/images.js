/**
 * Fisha Studio photos: the main image plus the gallery (drag or arrow buttons to reorder; the first gallery
 * photo is the one shoppers see when they hover a product card). Uploads go to the media library at once;
 * which photos the product uses is part of the draft. Photo descriptions (alt text) save straight away.
 */
import { h, toast, modal, debounce } from './ui.js';
import { api } from './api.js';

const OK_TYPES = /^image\/(jpeg|png|webp)$/;
const MAX = 15 * 1024 * 1024;

export function imagesField( values, images, onChange, opts = {} ) {
	const known = { ...images };
	let main = values.image_id || 0;
	let gallery = [ ...( values.gallery_image_ids || [] ) ];
	let dragFrom = null;

	const list = h( 'ul.fs-images__list' );
	const err = h( 'p.fs-field__error', { role: 'alert', hidden: true } );
	const fileInput = h( 'input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', multiple: true, hidden: true, onchange: ( e ) => upload( [ ...e.target.files ] ) } );
	const el = h( 'div.fs-field.fs-images', {},
		h( 'p.fs-field__label', {}, opts.label || 'Photos' ),
		list,
		h( 'div.fs-images__actions', {},
			h( 'button.fs-btn', { type: 'button', onclick: () => fileInput.click() }, 'Upload photos' ),
			h( 'button.fs-btn', { type: 'button', onclick: pick }, 'Choose from uploaded' ),
			fileInput ),
		h( 'p.fs-field__help', {}, opts.help || 'JPG, PNG or WebP, up to 15 MB. Square photos look best on cards.' ),
		err );

	const emit = () => {
		onChange( { image_id: main, gallery_image_ids: [ ...gallery ] } );
		render();
	};
	const move = ( i, by ) => {
		const j = i + by;
		if ( j < 0 || j >= gallery.length ) return;
		[ gallery[ i ], gallery[ j ] ] = [ gallery[ j ], gallery[ i ] ];
		emit();
	};

	function tile( id, isMain, index ) {
		const img = known[ id ];
		const li = h( 'li.fs-img' + ( isMain ? '.fs-img--main' : '' ), { draggable: isMain ? null : 'true' },
			img ? h( 'img', { src: img.medium || img.thumb, alt: img.alt || '' } ) : h( 'span.fs-img__missing', {}, 'Photo missing' ),
			h( 'div.fs-img__tools', {},
				isMain
					? h( 'span.fs-img__label', {}, 'Main photo' )
					: h( 'button.fs-btn.fs-btn--tiny', { type: 'button', onclick: () => {
						gallery.splice( index, 1 );
						if ( main ) gallery.unshift( main );
						main = id;
						emit();
					} }, 'Make main' ),
				! isMain && index > 0 ? h( 'button.fs-btn.fs-btn--tiny', { type: 'button', 'aria-label': 'Move earlier', onclick: () => move( index, -1 ) }, '←' ) : null,
				! isMain && index < gallery.length - 1 ? h( 'button.fs-btn.fs-btn--tiny', { type: 'button', 'aria-label': 'Move later', onclick: () => move( index, 1 ) }, '→' ) : null,
				h( 'button.fs-btn.fs-btn--tiny.fs-btn--danger', { type: 'button', 'aria-label': 'Remove photo', onclick: () => {
					if ( isMain ) main = gallery.shift() || 0;
					else gallery.splice( index, 1 );
					emit();
				} }, 'Remove' ) ),
			img ? h( 'input.fs-input', { value: img.alt, placeholder: 'Describe the photo', 'aria-label': 'Photo description', onchange: ( e ) => saveAlt( id, e.target.value ) } ) : null,
			opts.hoverNote !== false && ! isMain && index === 0 ? h( 'p.fs-img__note', {}, 'Shown when shoppers hover the card' ) : null );
		if ( ! isMain ) {
			li.addEventListener( 'dragstart', () => { dragFrom = index; li.classList.add( 'is-dragging' ); } );
			li.addEventListener( 'dragend', () => li.classList.remove( 'is-dragging' ) );
			li.addEventListener( 'dragover', ( e ) => e.preventDefault() );
			li.addEventListener( 'drop', ( e ) => {
				e.preventDefault();
				if ( dragFrom === null || dragFrom === index ) return;
				const [ moved ] = gallery.splice( dragFrom, 1 );
				gallery.splice( index, 0, moved );
				dragFrom = null;
				emit();
			} );
		}
		return li;
	}

	function render() {
		list.replaceChildren(
			...( main ? [ tile( main, true, -1 ) ] : [ h( 'li.fs-img.fs-img--busy', {}, 'No main photo yet' ) ] ),
			...gallery.map( ( id, i ) => tile( id, false, i ) ) );
	}

	function add( img ) {
		const count = ( main ? 1 : 0 ) + gallery.length;
		if ( opts.max && count >= opts.max && img.id !== main && ! gallery.includes( img.id ) ) {
			toast( opts.max === 1 ? 'You can use one photo here.' : `You can use up to ${ opts.max } photos here.`, 'error' );
			return;
		}
		known[ img.id ] = img;
		if ( ! main ) main = img.id;
		else if ( img.id !== main && ! gallery.includes( img.id ) ) gallery.push( img.id );
		emit();
	}

	async function upload( files ) {
		for ( const file of files ) {
			if ( ! OK_TYPES.test( file.type ) ) {
				toast( `${ file.name }: only JPG, PNG or WebP photos can be uploaded. On an iPhone, share the photo as JPG.`, 'error' );
				continue;
			}
			if ( file.size > MAX ) {
				toast( `${ file.name } is larger than 15 MB. Please use a smaller photo.`, 'error' );
				continue;
			}
			const busy = h( 'li.fs-img.fs-img--busy', {}, `Uploading ${ file.name }…` );
			list.append( busy );
			const form = new FormData();
			form.append( 'file', file );
			try {
				add( await api( 'POST', 'media', undefined, { form } ) );
			} catch ( e ) {
				toast( e.message, 'error' );
			} finally {
				busy.remove();
			}
		}
		fileInput.value = '';
	}

	/** The media library, newest first, 40 at a time ("Load more"), with a search by photo name. */
	async function pick() {
		let closeFn = null;
		let page = 0;
		let query = '';
		let seq = 0;
		const items = new Map();
		const grid = h( 'ul.fs-library' );
		const note = h( 'p.fs-field__help', { role: 'status' } );
		const more = h( 'button.fs-btn.fs-library__more', { type: 'button', hidden: true, onclick: () => load( false ) }, 'Load more' );
		const search = h( 'input.fs-input', { type: 'search', placeholder: 'Search by photo name', 'aria-label': 'Search photos', autocomplete: 'off' } );
		search.addEventListener( 'input', debounce( () => {
			query = search.value.trim();
			load( true );
		}, 300 ) );
		grid.addEventListener( 'click', ( e ) => {
			const b = e.target.closest( '.fs-library__item' );
			if ( b && closeFn ) closeFn( items.get( b.dataset.id ) );
		} );

		async function load( reset ) {
			const my = ++seq;
			const next = reset ? 1 : page + 1;
			more.disabled = true;
			note.textContent = 'Loading…';
			try {
				const q = new URLSearchParams( { page: next } );
				if ( query ) q.set( 'search', query );
				const d = await api( 'GET', 'media?' + q );
				if ( my !== seq ) return;
				if ( reset ) {
					grid.replaceChildren();
					items.clear();
				}
				page = next;
				for ( const img of d.items ) {
					items.set( String( img.id ), img );
					grid.append( h( 'li', {}, h( 'button.fs-library__item', { type: 'button', title: img.name, dataset: { id: img.id } }, h( 'img', { src: img.thumb, alt: img.alt || img.name } ) ) ) );
				}
				note.textContent = items.size ? '' : ( query ? 'No photos match this name.' : 'No photos uploaded yet.' );
				more.hidden = page >= d.pages;
			} catch ( e ) {
				if ( my === seq ) note.textContent = e.message;
			} finally {
				if ( my === seq ) more.disabled = false;
			}
		}

		load( true );
		const chosen = await modal( {
			title: 'Choose a photo',
			body: [ search, grid, note, more ],
			actions: [ { label: 'Cancel', value: null } ],
			onOpen: ( dlg, close ) => { closeFn = close; },
		} );
		seq++; // ignore answers that arrive after the window closed
		if ( chosen ) add( chosen );
	}

	async function saveAlt( id, alt ) {
		try {
			known[ id ] = await api( 'PUT', 'media/' + id, { alt } );
			toast( 'Photo description saved.' );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	render();
	return {
		el,
		set( v, imgs ) {
			Object.assign( known, imgs || {} );
			main = v.image_id || 0;
			gallery = [ ...( v.gallery_image_ids || [] ) ];
			render();
		},
		setError( msg ) {
			err.hidden = ! msg;
			err.textContent = msg || '';
		},
		focus: () => fileInput.previousElementSibling?.focus(),
	};
}
