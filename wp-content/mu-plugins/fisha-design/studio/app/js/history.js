/**
 * Fisha Studio History list: entries newest first ("Published changes · Big Fish Tee", who, when, what changed), with
 * "Restore this version" on full snapshots when an onRestore handler is given, and "Load more".
 */
import { h, toast } from './ui.js';
import { api } from './api.js';
import { ago, when } from './time.js';

export function historyList( { query, onRestore = null } ) {
	let page = 0;
	let pages = 1;
	const list = h( 'ul.fs-hist' );
	const note = h( 'p.fs-field__help', { role: 'status' } );
	const more = h( 'button.fs-btn', { type: 'button', hidden: true, onclick: () => load( false ) }, 'Load more' );
	const el = h( 'div.fs-hist-wrap', {}, list, note, more );

	function row( e ) {
		const item = e.item && ! onRestore ? ( e.item.exists ? h( 'a', { href: `#/${ e.item.type === 'category' ? 'categories' : 'products' }/${ e.item.id }` }, e.item.name ) : h( 'span', {}, e.item.name ) ) : null;
		return h( 'li.fs-hist__row', {},
			h( 'div.fs-hist__head', {},
				h( 'strong', {}, e.title ), item ? ' · ' : null, item,
				h( 'span.fs-hist__when', { title: when( e.when ) }, `${ e.who }, ${ ago( e.when ) }` ) ),
			e.changes.length ? h( 'ul.fs-hist__changes', {}, e.changes.map( ( c ) => h( 'li', {}, c ) ), e.more ? h( 'li', {}, `+${ e.more } more` ) : null ) : null,
			onRestore && e.restorable ? h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: async ( ev ) => {
				ev.target.disabled = true;
				try { await onRestore( e ); } finally { ev.target.disabled = false; }
			} }, 'Restore this version' ) : null );
	}

	async function load( reset ) {
		const next = reset ? 1 : page + 1;
		more.disabled = true;
		note.textContent = 'Loading…';
		try {
			const d = await api( 'GET', 'history?' + new URLSearchParams( { ...query, page: next } ) );
			if ( reset ) list.replaceChildren();
			page = next;
			pages = d.pages;
			list.append( ...d.items.map( row ) );
			note.textContent = list.childElementCount ? '' : 'No history yet.';
			more.hidden = page >= pages;
		} catch ( e ) {
			note.textContent = e.message;
			toast( e.message, 'error' );
		} finally {
			more.disabled = false;
		}
	}
	load( true );
	return { el, reload: () => load( true ) };
}
