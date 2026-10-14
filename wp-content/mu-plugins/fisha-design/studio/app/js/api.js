/**
 * Fisha Studio API client: sends the REST nonce, asks the owner to sign in again (password, then the 2-step code) when the session ended
 * (then repeats the request), and keeps retrying saves while the connection is down. POSTs (publish, upload)
 * are never repeated automatically.
 */
import { h, modal } from './ui.js';

export const config = JSON.parse( document.getElementById( 'fs-config' ).textContent );
let nonce = config.nonce;
/** After a password change the server starts a new session; its REST nonce replaces the old one. */
export function setNonce( n ) {
	if ( n ) nonce = n;
}
let online = true;
let relogin = null;

export class ApiError extends Error {
	constructor( status, body ) {
		super( body?.message || 'Something went wrong. Please try again.' );
		this.status = status;
		this.code = body?.code || '';
		this.data = body?.data || {};
	}
}

const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );
function setOnline( v ) {
	if ( v === online ) return;
	online = v;
	window.dispatchEvent( new CustomEvent( 'fs:online', { detail: v } ) );
}

async function send( method, path, body, form, { withNonce = true } = {} ) {
	const headers = { Accept: 'application/json' };
	if ( withNonce ) headers[ 'X-WP-Nonce' ] = nonce;
	const init = { method, headers, credentials: 'same-origin' };
	if ( form ) init.body = form;
	else if ( body !== undefined ) {
		headers[ 'Content-Type' ] = 'application/json';
		init.body = JSON.stringify( body );
	}
	const res = await fetch( config.api + path.replace( /^\//, '' ), init );
	const text = await res.text();
	let data = null;
	try {
		data = text ? JSON.parse( text ) : null;
	} catch {
		data = { message: 'The server sent an unexpected answer. Please try again.' };
	}
	if ( ! res.ok ) throw new ApiError( res.status, data );
	return data;
}

const STOP_CODES = [ 'fisha_studio_2fa_expired', 'fisha_studio_locked', 'fisha_studio_2fa_setup', 'fisha_studio_2fa_day_lock' ];

/** Second step for an owner: the app code or a backup code. True when signed in, false to go back to the password. */
async function askCode() {
	let backup = false;
	for ( ;; ) {
		const values = await modal( {
			title: '2-step sign-in',
			body: [
				h( 'p', {}, backup ? 'Enter one of your backup codes. Each code works once.' : 'Enter the 6-digit code from your authenticator app.' ),
				h( 'label.fs-field__label', {}, backup ? 'Backup code' : 'Code', backup
					? h( 'input.fs-input', { name: 'code', autocomplete: 'off', autocapitalize: 'none', spellcheck: 'false', placeholder: 'xxxx-xxxx', maxlength: 12, required: true } )
					: h( 'input.fs-input', { name: 'code', inputmode: 'numeric', autocomplete: 'one-time-code', pattern: '[0-9 ]{6,7}', maxlength: 7, required: true } ) ),
			],
			actions: [
				{ label: backup ? 'Use the code from the app' : 'Use a backup code', value: 'switch' },
				{ label: 'Sign in', kind: 'primary', submit: true, value: ( f ) => ( { code: f.elements.code.value } ) },
			],
		} );
		if ( values === 'switch' ) {
			backup = ! backup;
			continue;
		}
		if ( ! values ) return false;
		try {
			const r = await send( 'POST', 'login/code', { code: values.code }, null, { withNonce: false } );
			nonce = r.nonce;
			if ( r.note ) await modal( { title: 'Backup code used', body: h( 'p', {}, r.note ) } );
			return true;
		} catch ( e ) {
			await modal( { title: 'Could not sign in', body: h( 'p', {}, e.message ) } );
			if ( ! ( e instanceof ApiError ) || STOP_CODES.includes( e.code ) ) return false;
		}
	}
}

function askLogin() {
	relogin ??= ( async () => {
		for ( ;; ) {
			const values = await modal( {
				title: 'Please sign in again',
				body: [
					h( 'p', {}, 'Your session ended. Your changes are kept.' ),
					h( 'label.fs-field__label', {}, 'Email or username', h( 'input.fs-input', { name: 'login', autocomplete: 'username', required: true } ) ),
					h( 'label.fs-field__label', {}, 'Password', h( 'input.fs-input', { name: 'password', type: 'password', autocomplete: 'current-password', required: true } ) ),
				],
				actions: [ { label: 'Sign in', kind: 'primary', submit: true, value: ( f ) => ( { login: f.elements.login.value, password: f.elements.password.value } ) } ],
			} );
			if ( ! values ) continue;
			try {
				const r = await send( 'POST', 'login', values, null, { withNonce: false } );
				if ( r.step === 'code' ) {
					if ( await askCode() ) return;
					continue;
				}
				nonce = r.nonce;
				return;
			} catch ( e ) {
				await modal( { title: 'Could not sign in', body: h( 'p', {}, e.message ) } );
			}
		}
	} )().finally( () => {
		relogin = null;
	} );
	return relogin;
}

export async function api( method, path, body, { form = null, retry = method !== 'POST' } = {} ) {
	for ( let attempt = 0; ; attempt++ ) {
		try {
			const d = await send( method, path, body, form );
			setOnline( true );
			return d;
		} catch ( e ) {
			if ( e instanceof ApiError ) {
				setOnline( true );
				if ( ( e.status === 401 || e.code === 'rest_cookie_invalid_nonce' ) && attempt < 3 ) {
					await askLogin();
					continue;
				}
				if ( e.status >= 500 && retry && attempt < 2 ) {
					await sleep( 800 * ( attempt + 1 ) );
					continue;
				}
				throw e;
			}
			setOnline( false );
			if ( retry ) {
				await sleep( Math.min( 15000, 1000 * 2 ** Math.min( attempt, 4 ) ) );
				continue;
			}
			throw new ApiError( 0, { message: 'No connection, so nothing was changed. Please try again when you are back online.' } );
		}
	}
}
