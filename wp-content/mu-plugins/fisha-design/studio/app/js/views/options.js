/**
 * Fisha Studio "Size lists": the shop-wide options (Size, Shoe size…) and their values. Changes apply straight away
 * to every product that uses them; a value can only be deleted when no product uses it.
 */
import { h, toast, modal, confirmDialog } from '../ui.js';
import { api } from '../api.js';

export function optionsView( main ) {
	let alive = true;
	const box = h( 'div.fs-opts' );
	main.append(
		h( 'div.fs-page-head', {}, h( 'h1', {}, 'Size lists' ), h( 'button.fs-btn.fs-btn--primary', { type: 'button', onclick: addOption }, '+ New list' ) ),
		h( 'p.fs-field__help', {}, 'The values shoppers choose from, shared by all products. Renaming a value updates every product that uses it.' ),
		box );

	async function load() {
		try {
			const list = await api( 'GET', 'attributes' );
			if ( alive ) box.replaceChildren( ...list.map( card ) );
		} catch ( e ) {
			if ( alive ) box.replaceChildren( h( 'p.fs-empty', {}, e.message ) );
		}
	}

	async function ask( title, label, value = '', max = 40 ) {
		return modal( {
			title,
			body: h( 'label.fs-field__label', {}, label, h( 'input.fs-input', { name: 'v', value, required: true, maxlength: max } ) ),
			actions: [ { label: 'Cancel', value: null }, { label: 'Save', kind: 'primary', submit: true, value: ( f ) => f.elements.v.value.trim() } ],
		} );
	}

	async function addOption() {
		const label = await ask( 'New list', 'Name, for example Ring size', '', 28 );
		if ( ! label ) return;
		try {
			await api( 'POST', 'attributes', { label } );
			load();
		} catch ( e ) { toast( e.message, 'error' ); }
	}

	function card( a ) {
		const add = h( 'input.fs-input.fs-input--small', { placeholder: 'New value', 'aria-label': `New value for ${ a.label }` } );
		add.addEventListener( 'keydown', async ( e ) => {
			if ( e.key !== 'Enter' ) return;
			e.preventDefault();
			const name = add.value.trim();
			if ( ! name ) return;
			try {
				await api( 'POST', `attributes/${ a.id }/terms`, { name } );
				load();
			} catch ( err ) { toast( err.message, 'error' ); }
		} );
		const move = async ( i, by ) => {
			const ids = a.terms.map( ( t ) => t.id );
			const j = i + by;
			if ( j < 0 || j >= ids.length ) return;
			[ ids[ i ], ids[ j ] ] = [ ids[ j ], ids[ i ] ];
			try {
				await api( 'POST', `attributes/${ a.id }/order`, { term_ids: ids } );
				load();
			} catch ( err ) { toast( err.message, 'error' ); }
		};
		const rows = a.terms.map( ( t, i ) => h( 'li.fs-opts__value', {},
			h( 'strong', {}, t.name ),
			h( 'span.fs-table__meta', {}, t.sizes ? `${ t.sizes } size${ t.sizes > 1 ? 's' : '' }` : 'Not used' ),
			h( 'div.fs-opts__tools', {},
				h( 'button.fs-btn.fs-btn--tiny', { type: 'button', 'aria-label': `Move ${ t.name } up`, disabled: i === 0, onclick: () => move( i, -1 ) }, '↑' ),
				h( 'button.fs-btn.fs-btn--tiny', { type: 'button', 'aria-label': `Move ${ t.name } down`, disabled: i === a.terms.length - 1, onclick: () => move( i, 1 ) }, '↓' ),
				h( 'button.fs-btn.fs-btn--tiny', { type: 'button', onclick: async () => {
					const name = await ask( `Rename ${ t.name }`, 'Name', t.name );
					if ( ! name || name === t.name ) return;
					try { await api( 'PUT', `attributes/terms/${ t.id }`, { name } ); load(); } catch ( err ) { toast( err.message, 'error' ); }
				} }, 'Rename' ),
				h( 'button.fs-btn.fs-btn--tiny.fs-btn--danger', { type: 'button', onclick: async () => {
					if ( ! ( await confirmDialog( `Delete ${ t.name }?`, 'It is removed from this list. Values that products use cannot be deleted.', 'Delete', 'danger' ) ) ) return;
					try { await api( 'DELETE', `attributes/terms/${ t.id }` ); load(); } catch ( err ) { toast( err.message, 'error' ); }
				} }, 'Delete' ) ) ) );
		return h( 'section.fs-opts__card', {},
			h( 'div.fs-vars__attr-head', {},
				h( 'h2', {}, a.label ),
				h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: async () => {
					const label = await ask( `Rename ${ a.label }`, 'Name', a.label, 28 );
					if ( ! label || label === a.label ) return;
					try { await api( 'PUT', `attributes/${ a.id }`, { label } ); load(); } catch ( err ) { toast( err.message, 'error' ); }
				} }, 'Rename list' ) ),
			h( 'ul.fs-opts__values', {}, rows ),
			add );
	}

	load();
	return { destroy() { alive = false; } };
}
