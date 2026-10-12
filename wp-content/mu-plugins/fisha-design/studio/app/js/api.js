/**
 * Fisha Studio API client: sends the REST nonce, asks the owner to sign in again when the session ended
 * (then repeats the request), and keeps retrying saves while the connection is down. POSTs (publish, upload)
 * are never repeated automatically.
 */
import { h, modal } from './ui.js';

export const config = JSON.parse( document.getElementById( 'fs-config' ).textContent );
let nonce = config.nonce;
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
