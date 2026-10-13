/**
 * Fisha Studio "Something changed on the site" dialog: for each field that changed on the site since the owner started
 * editing, keep the site's value or use theirs. Each answer carries the site value shown, so "Use mine" is refused if
 * the site changes it again meanwhile.
 */
import { h, modal } from './ui.js';

function show( c, v ) {
	if ( Array.isArray( v ) ) return v.length ? v.join( ', ' ) : 'none';
	if ( v === '' || v === null || v === undefined ) return /(^|:)stock_quantity$/.test( c.field ) ? 'no fixed amount' : '(empty)';
	if ( v === true ) return 'on';
	if ( v === false ) return 'off';
	return String( v );
}

export function resolveConflicts( conflicts ) {
	return modal( {
		title: 'Something changed on the site',
		body: conflicts.map( ( c ) => h( 'fieldset.fs-conflict', {},
			h( 'legend', {}, c.label ),
			h( 'p', {}, `When you started editing it was ${ show( c, c.base ) }. The site now has ${ show( c, c.live ) }${ /(^|:)stock_quantity$/.test( c.field ) ? ', probably because an order came in' : '' }.` ),
			h( 'label.fs-radio', {}, h( 'input', { type: 'radio', name: c.field, value: 'live', checked: true } ), `Keep ${ show( c, c.live ) }` ),
			h( 'label.fs-radio', {}, h( 'input', { type: 'radio', name: c.field, value: 'mine' } ), `Use mine: ${ show( c, c.mine ) }` ) ) ),
		actions: [ { label: 'Cancel', value: null }, { label: 'Publish', kind: 'primary', submit: true, value: ( f ) => Object.fromEntries( conflicts.map( ( c ) => [ c.field, { choice: f.elements[ c.field ].value, live: c.live } ] ) ) } ],
	} );
}
