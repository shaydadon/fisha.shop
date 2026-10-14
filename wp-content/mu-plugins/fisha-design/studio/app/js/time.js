/**
 * Fisha Studio time words: "2 hours ago", "14 Oct, 10:42" (local time), from ISO dates the server sends in UTC.
 */
export function ago( iso ) {
	if ( ! iso ) return '';
	const s = Math.max( 0, ( Date.now() - new Date( iso ).getTime() ) / 1000 );
	if ( s < 60 ) return 'just now';
	const steps = [ [ 3600, 60, 'minute' ], [ 86400, 3600, 'hour' ], [ 86400 * 30, 86400, 'day' ] ];
	for ( const [ max, unit, word ] of steps ) {
		if ( s < max ) {
			const n = Math.floor( s / unit );
			return `${ n } ${ word }${ n === 1 ? '' : 's' } ago`;
		}
	}
	return when( iso );
}
export function when( iso ) {
	if ( ! iso ) return '';
	const d = new Date( iso );
	return d.toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short' } ) + ', ' + d.toLocaleTimeString( 'en-GB', { hour: '2-digit', minute: '2-digit' } );
}
