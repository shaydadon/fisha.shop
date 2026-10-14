/**
 * Fisha Studio app: top bar, navigation, offline banner and the hash router (#/products, #/products/123).
 */
import { h } from './ui.js';
import { config } from './api.js';
import { productsList } from './views/products-list.js';
import { productEditor } from './views/product-editor.js';
import { categoriesList } from './views/categories-list.js';
import { categoryEditor } from './views/category-editor.js';
import { inventoryView } from './views/inventory.js';
import { optionsView } from './views/options.js';
import { dashboardView } from './views/dashboard.js';
import { historyView } from './views/history.js';
import { accountView } from './views/account.js';

const root = document.getElementById( 'fs-app' );
const main = h( 'main.fs-main', { id: 'fs-main', tabindex: '-1' } );
const offline = h( 'div.fs-offline', { role: 'status', hidden: true }, 'You are offline. Your changes are kept and will be saved when the connection is back.' );
const nav = h( 'nav.fs-nav', { 'aria-label': 'Studio sections' },
	h( 'a.fs-nav__item', { href: '#/dashboard', dataset: { section: 'dashboard' } }, 'Dashboard' ),
	h( 'a.fs-nav__item', { href: '#/products', dataset: { section: 'products' } }, 'Products' ),
	h( 'a.fs-nav__item', { href: '#/categories', dataset: { section: 'categories' } }, 'Categories' ),
	h( 'a.fs-nav__item', { href: '#/inventory', dataset: { section: 'inventory' } }, 'Inventory' ),
	h( 'a.fs-nav__item', { href: '#/options', dataset: { section: 'options' } }, 'Size lists' ),
	h( 'a.fs-nav__item', { href: '#/history', dataset: { section: 'history' } }, 'History' ),
	h( 'a.fs-nav__item', { href: '#/account', dataset: { section: 'account' } }, 'My account' ) );

root.replaceChildren(
	h( 'header.fs-top', {},
		h( 'a.fs-brand', { href: '#/dashboard' }, h( 'img', { src: config.logo, alt: '', width: 44, height: 31 } ), h( 'span', {}, 'Fisha Studio' ) ),
		h( 'div.fs-top__right', {},
			h( 'a.fs-link', { href: config.home, target: '_blank', rel: 'noopener' }, 'View site' ),
			h( 'a.fs-user', { href: '#/account' }, config.user ),
			h( 'a.fs-link', { href: config.logout }, 'Sign out' ) ) ),
	offline,
	h( 'div.fs-shell', {}, nav, main ) );
window.addEventListener( 'fs:online', ( e ) => { offline.hidden = e.detail; } );

const routes = [
	[ /^#\/dashboard$/, () => dashboardView( main ) ],
	[ /^#\/history$/, () => historyView( main ) ],
	[ /^#\/account$/, () => accountView( main ) ],
	[ /^#\/products$/, () => productsList( main ) ],
	[ /^#\/products\/(\d+)$/, ( m ) => productEditor( main, Number( m[ 1 ] ) ) ],
	[ /^#\/categories$/, () => categoriesList( main ) ],
	[ /^#\/categories\/(\d+)$/, ( m ) => categoryEditor( main, Number( m[ 1 ] ) ) ],
	[ /^#\/inventory$/, () => inventoryView( main ) ],
	[ /^#\/options$/, () => optionsView( main ) ],
];

let view = null;
let current = '';
async function route() {
	const hash = location.hash || '#/dashboard';
	if ( hash === current ) return;
	if ( view?.canLeave && ! ( await view.canLeave() ) ) {
		history.replaceState( null, '', current );
		return;
	}
	view?.destroy?.();
	current = hash;
	main.replaceChildren();
	main.className = 'fs-main';
	nav.querySelectorAll( 'a' ).forEach( ( a ) => a.classList.toggle( 'is-active', hash.startsWith( '#/' + a.dataset.section ) ) );
	const match = routes.find( ( [ re ] ) => re.test( hash ) );
	view = match ? match[ 1 ]( hash.match( match[ 0 ] ) ) : dashboardView( main );
	main.focus( { preventScroll: true } );
}
window.addEventListener( 'hashchange', route );
window.addEventListener( 'beforeunload', ( e ) => {
	if ( view?.dirty?.() ) {
		e.preventDefault();
		e.returnValue = '';
	}
} );
if ( ! location.hash ) history.replaceState( null, '', '#/dashboard' );
route();
