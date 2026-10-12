/**
 * Fisha Studio DOM helpers: h() builds elements, toast() and modal() talk to the owner.
 */
export function h( tag, attrs = {}, ...children ) {
	const [ name, ...classes ] = tag.split( '.' );
	const el = document.createElement( name || 'div' );
	if ( classes.length ) el.className = classes.join( ' ' );
	for ( const [ k, v ] of Object.entries( attrs || {} ) ) {
		if ( v === undefined || v === null || v === false ) continue;
		if ( k === 'class' ) el.className = [ el.className, v ].filter( Boolean ).join( ' ' );
		else if ( k === 'dataset' ) Object.assign( el.dataset, v );
		else if ( k.startsWith( 'on' ) && typeof v === 'function' ) el.addEventListener( k.slice( 2 ), v );
		else if ( k === 'value' ) el.value = v;
		else if ( k === 'checked' ) el.checked = !! v;
		else if ( v === true ) el.setAttribute( k, '' );
		else el.setAttribute( k, v );
	}
	for ( const c of children.flat( Infinity ) ) {
		if ( c === null || c === undefined || c === false ) continue;
		el.append( c instanceof Node ? c : document.createTextNode( String( c ) ) );
	}
	return el;
}

let toastBox = null;
export function toast( message, kind = 'ok', action = null ) {
	toastBox ??= document.body.appendChild( h( 'div.fs-toasts', { role: 'status', 'aria-live': 'polite' } ) );
	const t = h( 'div.fs-toast.fs-toast--' + kind, {},
		h( 'span', {}, message ),
		action && action.href ? h( 'a', { href: action.href, target: '_blank', rel: 'noopener' }, action.label ) : null );
	toastBox.append( t );
	setTimeout( () => t.classList.add( 'is-leaving' ), kind === 'error' ? 8000 : 4500 );
	setTimeout( () => t.remove(), kind === 'error' ? 8600 : 5100 );
}

export function modal( { title, body, actions = [ { label: 'OK', value: true, kind: 'primary' } ], onOpen } ) {
	return new Promise( ( resolve ) => {
		const dlg = h( 'dialog.fs-modal', { 'aria-labelledby': 'fs-modal-title' } );
		let done = false;
		const close = ( v ) => {
			if ( done ) return;
			done = true;
			dlg.close();
			dlg.remove();
			resolve( v );
		};
		const form = h( 'form.fs-modal__form', {
			onsubmit: ( e ) => {
				e.preventDefault();
				const a = actions.find( ( x ) => x.submit );
				if ( a ) close( typeof a.value === 'function' ? a.value( form ) : a.value );
			},
		},
		h( 'h2.fs-modal__title', { id: 'fs-modal-title' }, title ),
		h( 'div.fs-modal__body', {}, body ),
		h( 'div.fs-modal__actions', {}, actions.map( ( a ) => h( 'button.fs-btn' + ( a.kind ? '.fs-btn--' + a.kind : '' ), {
			type: a.submit ? 'submit' : 'button',
			onclick: a.submit ? null : () => close( a.value ),
		}, a.label ) ) ) );
		dlg.append( form );
		dlg.addEventListener( 'cancel', ( e ) => {
			e.preventDefault();
			close( null );
		} );
		document.body.append( dlg );
		dlg.showModal();
		onOpen?.( dlg, close );
	} );
}

export function confirmDialog( title, text, okLabel = 'OK', kind = 'primary' ) {
	return modal( { title, body: h( 'p', {}, text ), actions: [ { label: 'Cancel', value: false }, { label: okLabel, value: true, kind } ] } ).then( Boolean );
}

export function debounce( fn, ms ) {
	let t;
	const d = ( ...args ) => {
		clearTimeout( t );
		t = setTimeout( () => fn( ...args ), ms );
	};
	d.cancel = () => clearTimeout( t );
	return d;
}
