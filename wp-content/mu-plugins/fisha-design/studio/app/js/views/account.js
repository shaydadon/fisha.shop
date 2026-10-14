/**
 * Fisha Studio My account: who is signed in, change the password (this browser stays signed in, other devices are
 * signed out), 2-step sign-in status, new backup codes (needs a code from the app), sign out of other devices.
 */
import { h, toast, modal, confirmDialog } from '../ui.js';
import { api, setNonce } from '../api.js';
import { textField } from '../fields.js';
import { when } from '../time.js';

export function accountView( main ) {
	const body = h( 'div.fs-account', {}, h( 'p.fs-empty', {}, 'Loading…' ) );
	main.append( h( 'div.fs-page-head', {}, h( 'h1', {}, 'My account' ) ), body );

	async function load() {
		try {
			render( await api( 'GET', 'account' ) );
		} catch ( e ) {
			body.replaceChildren( h( 'p.fs-empty', {}, e.message ) );
		}
	}

	function render( a ) {
		const f = {};
		const pw = ( key, label, auto ) => {
			f[ key ] = textField( label, '', () => f[ key ].setError( '' ), { type: 'password' } );
			f[ key ].el.querySelector( 'input' ).setAttribute( 'autocomplete', auto );
			return f[ key ].el;
		};
		const save = h( 'button.fs-btn.fs-btn--primary', { type: 'submit' }, 'Change password' );
		const form = h( 'form.fs-account__card', { onsubmit: async ( e ) => {
			e.preventDefault();
			const val = ( k ) => f[ k ].el.querySelector( 'input' ).value;
			save.disabled = true;
			try {
				const r = await api( 'POST', 'account/password', { current: val( 'current' ), new: val( 'new' ), confirm: val( 'confirm' ) } );
				setNonce( r.nonce );
				form.reset();
				toast( 'Password changed. Other devices were signed out.' );
			} catch ( err ) {
				Object.entries( err.data?.fields || {} ).forEach( ( [ k, m ] ) => f[ k ]?.setError( m ) );
				toast( err.message, 'error' );
			} finally {
				save.disabled = false;
			}
		} },
		h( 'h2', {}, 'Password' ),
		h( 'input', { type: 'text', name: 'username', value: a.login, autocomplete: 'username', hidden: true } ),
		pw( 'current', 'Current password', 'current-password' ),
		pw( 'new', 'New password (at least 12 characters)', 'new-password' ),
		pw( 'confirm', 'New password again', 'new-password' ),
		save );

		const twofa = a.owner ? h( 'section.fs-account__card', {},
			h( 'h2', {}, '2-step sign-in' ),
			h( 'p', {}, a.twofa.enabled ? `On${ a.twofa.since ? ' since ' + when( a.twofa.since ) : '' }. You have ${ a.twofa.backup_left } backup code${ a.twofa.backup_left === 1 ? '' : 's' } left.` : 'Not set up yet. Sign out and sign in again to set it up.' ),
			a.twofa.enabled ? h( 'button.fs-btn', { type: 'button', onclick: newCodes }, 'Make new backup codes' ) : null ) : null;

		body.replaceChildren(
			h( 'section.fs-account__card', {}, h( 'h2', {}, 'Signed in as' ), h( 'p', {}, `${ a.name } (${ a.email })` ),
				h( 'button.fs-btn', { type: 'button', onclick: signOutOthers }, 'Sign out of all other devices' ) ),
			form, twofa );
	}

	async function newCodes() {
		const code = await modal( {
			title: 'Make new backup codes',
			body: [
				h( 'p', {}, 'Your old backup codes will stop working. Type the 6-digit code from your authenticator app.' ),
				h( 'label.fs-field__label', {}, 'Code from the app', h( 'input.fs-input', { name: 'code', inputmode: 'numeric', autocomplete: 'one-time-code', pattern: '[0-9 ]{6,7}', maxlength: 7, required: true } ) ),
			],
			actions: [ { label: 'Cancel', value: null }, { label: 'Make new codes', kind: 'primary', submit: true, value: ( f ) => f.elements.code.value } ],
		} );
		if ( ! code ) return;
		try {
			const r = await api( 'POST', 'account/backup-codes', { code } );
			await modal( {
				title: 'Your new backup codes',
				body: [ h( 'p', {}, 'Save them somewhere safe that is not your phone. Each works once. They are shown only now.' ),
					h( 'ol.fs-codes', {}, r.codes.map( ( c ) => h( 'li', {}, h( 'code', {}, c ) ) ) ) ],
				actions: [ { label: 'I have saved these codes', kind: 'primary', value: true } ],
			} );
			load();
		} catch ( e ) {
			toast( e.data?.fields?.code || e.message, 'error' );
		}
	}

	async function signOutOthers() {
		if ( ! ( await confirmDialog( 'Sign out of all other devices?', 'Any other browser or phone signed in to the Studio will need to sign in again. This one stays signed in.', 'Sign out others' ) ) ) return;
		try {
			await api( 'POST', 'account/sign-out-others' );
			toast( 'Other devices were signed out.' );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	load();
	return {};
}
