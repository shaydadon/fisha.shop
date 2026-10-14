/**
 * Fisha Studio sign-in: draws the 2-step setup QR code from the otpauth link in #fs-qr (data-otpauth).
 * A plain script (not a module) loaded after the vendored qrcode-generator; the key is also shown as text.
 */
( () => {
	const box = document.getElementById( 'fs-qr' );
	if ( ! box || typeof window.qrcode !== 'function' ) return;
	const qr = window.qrcode( 0, 'M' );
	qr.addData( box.dataset.otpauth || '' );
	qr.make();
	const img = new Image();
	img.src = qr.createDataURL( 5, 20 );
	img.alt = 'QR code for your authenticator app';
	img.width = 220;
	img.height = 220;
	box.replaceChildren( img );
} )();
