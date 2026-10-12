/**
 * Fisha Studio rich text: bold, italic, lists, two heading sizes and links. Pasted text arrives as plain text, so
 * styles copied from Word, Google Docs or WhatsApp never reach the shop (the server cleans again on save).
 */
import { h, modal } from './ui.js';

const BUTTONS = [
	[ 'bold', 'B', 'Bold' ], [ 'italic', 'I', 'Italic' ], [ 'insertUnorderedList', '• List', 'Bulleted list' ],
	[ 'insertOrderedList', '1. List', 'Numbered list' ], [ 'h2', 'H2', 'Heading' ], [ 'h3', 'H3', 'Small heading' ],
	[ 'link', 'Link', 'Add a link' ], [ 'removeFormat', 'Clear', 'Clear formatting' ],
];

function tidy( html ) {
	return html
		.replace( /<div>/g, '<p>' ).replace( /<\/div>/g, '</p>' )
		.replace( /&nbsp;/g, ' ' )
		.replace( /<\/?span[^>]*>/g, '' )
		.replace( /(<p>(<br>)?<\/p>)+$/g, '' )
		.trim();
}

export function richText( label, value, onChange, opts = {} ) {
	const id = 'fs-rt' + Math.random().toString( 36 ).slice( 2, 8 );
	const area = h( 'div.fs-rt__area' + ( opts.small ? '.fs-rt__area--small' : '' ), {
		id, contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-labelledby': id + '-label',
	} );
	area.innerHTML = value || ''; // already cleaned by the server's allow-list (p, br, b, strong, i, em, ul, ol, li, h2, h3, a)
	const emit = () => onChange( tidy( area.innerHTML ) );

	async function run( cmd ) {
		area.focus();
		if ( cmd === 'h2' || cmd === 'h3' ) {
			const now = String( document.queryCommandValue( 'formatBlock' ) ).toLowerCase();
			document.execCommand( 'formatBlock', false, now === cmd ? 'p' : cmd );
		} else if ( cmd === 'link' ) {
			const sel = window.getSelection();
			const range = sel.rangeCount ? sel.getRangeAt( 0 ).cloneRange() : null;
			const url = await modal( {
				title: 'Add a link',
				body: h( 'label.fs-field__label', {}, 'Address', h( 'input.fs-input', { name: 'url', placeholder: 'https://', required: true } ) ),
				actions: [ { label: 'Cancel', value: null }, { label: 'Add link', kind: 'primary', submit: true, value: ( f ) => f.elements.url.value.trim() } ],
			} );
			if ( ! url || ! range ) return;
			area.focus();
			sel.removeAllRanges();
			sel.addRange( range );
			document.execCommand( 'createLink', false, /^(https?:|mailto:|\/)/i.test( url ) ? url : 'https://' + url );
		} else {
			document.execCommand( cmd );
		}
		emit();
	}

	const bar = h( 'div.fs-rt__bar', { role: 'toolbar', 'aria-label': label + ' formatting' }, BUTTONS.map( ( [ cmd, text, title ] ) =>
		h( 'button.fs-rt__btn', { type: 'button', title, 'aria-label': title, onmousedown: ( e ) => e.preventDefault(), onclick: () => run( cmd ) }, text ) ) );
	area.addEventListener( 'focus', () => document.execCommand( 'defaultParagraphSeparator', false, 'p' ) );
	area.addEventListener( 'input', emit );
	area.addEventListener( 'paste', ( e ) => {
		e.preventDefault();
		document.execCommand( 'insertText', false, e.clipboardData.getData( 'text/plain' ) );
	} );
	area.addEventListener( 'drop', ( e ) => e.preventDefault() );

	const err = h( 'p.fs-field__error', { hidden: true } );
	const el = h( 'div.fs-field', {},
		h( 'p.fs-field__label', { id: id + '-label' }, label ),
		h( 'div.fs-rt', {}, bar, area ),
		opts.help ? h( 'p.fs-field__help', {}, opts.help ) : null,
		err );
	return {
		el,
		set( v ) { if ( document.activeElement !== area ) area.innerHTML = v || ''; },
		setError( msg ) {
			err.hidden = ! msg;
			err.textContent = msg || '';
			el.classList.toggle( 'has-error', !! msg );
		},
		focus: () => area.focus(),
	};
}
