# Fisha Studio — Phase 1 (Foundation + Simple Products) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `/studio` — a secure, owner-only panel where the shop owner edits simple products (all fields incl. Fisha design + SEO), sees changes in a live preview of the real page, and publishes them — to dev.fisha.shop.

**Architecture:** New module `wp-content/mu-plugins/fisha-design/studio/` inside the existing mu-plugin. PHP: a field schema (single source of truth) drives REST endpoints (`fisha-studio/v1`), server-side drafts, a signed-token preview overlay (filters on the real templates) and a transactional publish via WooCommerce APIs. UI: no-build ES-module app served at `/studio` with a strict CSP.

**Tech Stack:** WordPress 7.1, WooCommerce 11.1, PHP 8.3, MariaDB 11 (InnoDB), vanilla JS (ES modules, no build), WP-CLI 2.12 in Docker (`wpcli` service) for tests.

**Spec:** `docs/superpowers/specs/2026-10-12-fisha-studio-design.md`

**Scope of this plan:** spec §10 phase 1 only. Phases 2 (variations, attributes, inventory), 3 (categories + settings migration) and 4 (dashboard, history/restore, duplicate, mobile polish) get their own plans after this ships to dev. Variable products are listed and openable but read-only in this phase (Publish disabled with a clear note).

## Global Constraints

- All code lives in `wp-content/mu-plugins/fisha-design/studio/` (deployed) and `tests/studio/` (never deployed). Loaded from `fisha-design.php` with `require_once`.
- Every PHP file starts with `<?php` + a one-paragraph doc comment + `if ( ! defined( 'ABSPATH' ) ) exit;` — same as the existing modules. WordPress coding style (tabs, spaces inside parens), function prefix `fisha_studio_`.
- REST namespace exactly `fisha-studio/v1`. Role slug exactly `fisha_owner`. Custom capability exactly `fisha_studio`.
- Writes only through WooCommerce/WordPress APIs — no direct SQL writes except the audit-log table.
- URL patterns never change: products `/product/{slug}/`, categories `/product-category/{parent}/{slug}/`. The Studio never touches `permalink_structure` or `woocommerce_permalinks`. Existing slugs are never regenerated; renaming does not change a slug.
- New-item slugs: lowercase ASCII `[a-z0-9-]`, ≤ 60 chars, unique, not reserved.
- Owner-facing copy: plain English, no em/en dashes (site rule from `remove-dashes.php`), no stack traces.
- Brand: Nunito, orange `#E48734` (text-safe orange `#B5651D` for text on light backgrounds), paper `#FAF7F2`, ink `#2B2622`.
- Deploy: `develop` → dev.fisha.shop, `main` → live (GitHub Actions rsyncs `wp-content/mu-plugins/fisha-design/` except `tools/`). Do not merge to `main` in this plan.
- Tests: run from the repo root `C:\xampp\htdocs\Fisha` in Git Bash with `bash tests/studio/run.sh [filter]`. Docker must be running (`docker compose up -d`).
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Text pasted from Word / Google Docs / WhatsApp into descriptions** → expected: clean text with only bold/italic/links/lists/headings kept, no styles or scripts. Pinned by the `fields: html is cleaned` test (Task 5) and the plain-paste handler in `richtext.js` (Task 14).
2. **Prices typed like a person types them** (`12,50`, `₪120`, ` 99 `) → expected: accepted as 12.5 / 120 / 99, never silently "Price on request". Pinned by `fields: money accepts human input` (Task 5).
3. **The same product edited in two tabs or on phone + laptop** → expected: each autosave merges per field; nothing the other tab typed is lost. Pinned by `drafts: two saves merge` (Task 7).
4. **iPhone photos (HEIC) or huge files** → expected: a clear message naming JPG/PNG/WebP, nothing half-uploaded. Pinned by `media: heic rejected with a clear message` (Task 10).
5. **Clicking Publish while an autosave is still in flight or failed** → expected: Publish waits for the pending save and publishes exactly what is on screen. Pinned by `flush()` inside `publish()` and its browser check, step 6 item 8 (Task 14).

---

## File map

| Path | Responsibility |
|---|---|
| `wp-content/mu-plugins/fisha-design.php` | + one `require_once` for `studio/studio.php` |
| `studio/studio.php` | constants, file loading, `/studio` routing, page security headers, app/login page rendering |
| `studio/studio-role.php` | `fisha_owner` role, caps, `fisha_studio_create_owner()`, WP-CLI `wp fisha-studio create-owner` |
| `studio/studio-gates.php` | wp-admin redirect, admin bar, REST allow-list, login redirect, cookie lifetime |
| `studio/studio-login.php` | credential check, lockout, sign-in |
| `studio/studio-fields.php` | product field schema, `fisha_studio_clean()`, read/sanitize/apply/validate |
| `studio/studio-urls.php` | slug generation, reserved slugs, unique slug |
| `studio/studio-audit.php` | audit table, log write/read, prune cron, error log file |
| `studio/studio-drafts.php` | draft storage, edit entry point, conflicts, defaults |
| `studio/studio-publish.php` | validate → conflicts → snapshot → transaction → purge; trash |
| `studio/studio-preview.php` | token, overlay filters, preview targets, no-cache |
| `studio/api/api.php` | REST helpers (permission, errors, item loader) + route registration include |
| `studio/api/api-session.php` | `GET /session`, `POST /login` |
| `studio/api/api-lookups.php` | `GET /lookups`, `POST /tags` |
| `studio/api/api-media.php` | `GET/POST /media`, `PUT /media/{id}` |
| `studio/api/api-products.php` | products list/create/get/draft/discard/publish/quick/trash |
| `seo.php` | + per-product SEO title/description |
| `studio/app/index.php`, `studio/app/login.php` | HTML shells |
| `studio/app/studio.css` | all Studio styles |
| `studio/app/js/ui.js` | DOM helper, toast, modal |
| `studio/app/js/api.js` | fetch wrapper, nonce, re-login, offline |
| `studio/app/js/app.js` | layout, router |
| `studio/app/js/fields.js` | form widgets |
| `studio/app/js/richtext.js` | minimal rich-text editor |
| `studio/app/js/images.js` | main image + gallery manager |
| `studio/app/js/preview.js` | preview pane |
| `studio/app/js/views/products-list.js` | product list + quick edit |
| `studio/app/js/views/product-editor.js` | editor, autosave, publish, conflicts |
| `tests/studio/run.sh`, `run.php`, `helpers.php`, `test-*.php` | integration tests |

(`studio/…` = `wp-content/mu-plugins/fisha-design/studio/…`)

---

### Task 1: Test harness + Studio module bootstrap

**Files:**
- Create: `tests/studio/run.sh`, `tests/studio/run.php`, `tests/studio/helpers.php`, `tests/studio/test-boot.php`, `tests/studio/baseline.sh`, `tests/studio/baseline-before.txt`
- Create: `wp-content/mu-plugins/fisha-design/studio/studio.php`
- Modify: `wp-content/mu-plugins/fisha-design.php` (after `require_once FISHA_DESIGN_DIR . 'home.php';`)
- Modify: `.gitignore` (append one line)

**Interfaces:**
- Produces: constants `FISHA_STUDIO_DIR`, `FISHA_STUDIO_URL`, `FISHA_STUDIO_NS` (`'fisha-studio/v1'`), `FISHA_STUDIO_ROLE` (`'fisha_owner'`); `fisha_studio_route_for_path( string $path ): ?string` → `'app' | 'login' | 'logout' | null`.
- Produces (tests): `fs_test( string $name, callable $fn )`, `fs_eq( $expected, $actual, string $msg = '' )`, `fs_true( $v, string $msg )`, `fs_false( $v, string $msg )`, `fs_on_cleanup( callable )`, `fs_user( string $role ): WP_User`, `fs_as( string $role ): WP_User`, `fs_product( array $props = [], string $status = 'publish' ): WC_Product_Simple`, `fs_term( string $name, int $parent = 0 ): WP_Term`, `fs_image(): int`, `fs_rest( string $method, string $route, array $params = [] ): array{0:int,1:mixed}`, `fs_api( string $method, string $path, array $params = [] ): array{0:int,1:mixed}`.

- [ ] **Step 1: Write the test runner**

`tests/studio/run.sh`:
```bash
#!/usr/bin/env bash
# Fisha Studio integration tests. Run from anywhere in Git Bash:  bash tests/studio/run.sh [name-filter]
set -o pipefail
cd "$(dirname "$0")/../.." || exit 1
MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp eval-file tests/studio/run.php "$@" 2>&1 \
	| grep -v -e 'Container fisha' -e 'level=warning'
```

`tests/studio/run.php`:
```php
<?php
/**
 * Fisha Studio integration tests, run inside the Docker wpcli container by tests/studio/run.sh.
 * Each test-*.php file registers tests with fs_test(). Fixtures are named "zz-test …" and removed after every test.
 */
if ( ! defined( 'ABSPATH' ) ) exit( "Run through tests/studio/run.sh\n" );

$GLOBALS['fs_filter'] = isset( $args[0] ) ? (string) $args[0] : '';
require __DIR__ . '/helpers.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $fs_file ) {
	echo basename( $fs_file ) . "\n";
	require $fs_file;
}
$fs_r = $GLOBALS['fs_results'];
echo "\n{$fs_r['pass']} passed, {$fs_r['fail']} failed\n";
if ( $fs_r['fail'] ) WP_CLI::halt( 1 );
```

`tests/studio/helpers.php`:
```php
<?php
/**
 * Tiny test framework + fixtures for the Fisha Studio integration tests.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Fs_Fail extends Exception {}
$GLOBALS['fs_results'] = array( 'pass' => 0, 'fail' => 0 );
$GLOBALS['fs_cleanup'] = array();

function fs_test( string $name, callable $fn ): void {
	if ( $GLOBALS['fs_filter'] && false === stripos( $name, $GLOBALS['fs_filter'] ) ) return;
	try {
		$fn();
		$GLOBALS['fs_results']['pass']++;
		echo "  PASS  $name\n";
	} catch ( Throwable $e ) {
		$GLOBALS['fs_results']['fail']++;
		echo "  FAIL  $name\n        " . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n";
	} finally {
		fs_cleanup();
	}
}
function fs_eq( $expected, $actual, string $msg = '' ): void {
	if ( $expected !== $actual ) {
		throw new Fs_Fail( ( $msg ? "$msg: " : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}
function fs_true( $v, string $msg = 'expected true' ): void { if ( ! $v ) throw new Fs_Fail( $msg ); }
function fs_false( $v, string $msg = 'expected false' ): void { if ( $v ) throw new Fs_Fail( $msg ); }
function fs_on_cleanup( callable $fn ): void { $GLOBALS['fs_cleanup'][] = $fn; }
function fs_cleanup(): void {
	while ( null !== ( $fn = array_pop( $GLOBALS['fs_cleanup'] ) ) ) {
		try { $fn(); } catch ( Throwable $e ) { echo '        cleanup error: ' . $e->getMessage() . "\n"; }
	}
	if ( function_exists( 'fisha_studio_preview_uninstall' ) ) fisha_studio_preview_uninstall();
	wp_set_current_user( 0 );
}

/* ---------- fixtures ---------- */

function fs_user( string $role ): WP_User {
	$login = 'zz-test-' . $role . '-' . strtolower( wp_generate_password( 6, false ) );
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
	if ( is_wp_error( $id ) ) throw new Fs_Fail( $id->get_error_message() );
	fs_on_cleanup( function () use ( $id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $id );
	} );
	return get_user_by( 'id', $id );
}
function fs_as( string $role ): WP_User {
	$u = fs_user( $role );
	wp_set_current_user( $u->ID );
	return $u;
}
function fs_product( array $props = array(), string $status = 'publish' ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( 'zz-test product ' . strtolower( wp_generate_password( 5, false ) ) );
	$p->set_status( $status );
	$p->set_regular_price( '100' );
	$p->set_short_description( 'Test short description.' );
	foreach ( $props as $k => $v ) $p->{"set_$k"}( $v );
	$id = $p->save();
	fs_on_cleanup( function () use ( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'fisha_studio_log';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) ) $wpdb->delete( $table, array( 'item_type' => 'product', 'item_id' => $id ) );
		wp_delete_post( $id, true );
	} );
	return wc_get_product( $id );
}
function fs_term( string $name, int $parent = 0 ): WP_Term {
	$r = wp_insert_term( 'zz-test ' . $name, 'product_cat', array( 'parent' => $parent, 'slug' => 'zz-test-' . sanitize_title( $name ) ) );
	if ( is_wp_error( $r ) ) throw new Fs_Fail( $r->get_error_message() );
	fs_on_cleanup( function () use ( $r ) { wp_delete_term( $r['term_id'], 'product_cat' ); } );
	return get_term( $r['term_id'], 'product_cat' );
}
/** A real image attachment (copy of the Fisha logo). */
function fs_image(): int {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$tmp = wp_tempnam( 'zz-test.png' );
	copy( FISHA_DESIGN_DIR . 'img/fisha-logo.png', $tmp );
	$id = media_handle_sideload( array( 'name' => 'zz-test-' . wp_generate_password( 5, false ) . '.png', 'tmp_name' => $tmp ), 0 );
	if ( is_wp_error( $id ) ) throw new Fs_Fail( $id->get_error_message() );
	fs_on_cleanup( function () use ( $id ) { wp_delete_attachment( $id, true ); } );
	return $id;
}

/* ---------- REST ---------- */

/** Runs a REST request in-process. Returns [status, data]. */
function fs_rest( string $method, string $route, array $params = array() ): array {
	$req = new WP_REST_Request( $method, $route );
	if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $params ) );
	} else {
		foreach ( $params as $k => $v ) $req->set_param( $k, $v );
	}
	$res = rest_do_request( $req );
	// JSON round trip, so tests see exactly what the browser gets (objects become arrays).
	return array( $res->get_status(), json_decode( wp_json_encode( rest_get_server()->response_to_data( $res, false ) ), true ) );
}
function fs_api( string $method, string $path, array $params = array() ): array {
	return fs_rest( $method, '/' . FISHA_STUDIO_NS . $path, $params );
}
```

`tests/studio/test-boot.php`:
```php
<?php
fs_test( 'boot: studio module is loaded', function () {
	fs_true( defined( 'FISHA_STUDIO_DIR' ), 'FISHA_STUDIO_DIR is defined' );
	fs_eq( 'fisha-studio/v1', FISHA_STUDIO_NS );
	fs_eq( 'fisha_owner', FISHA_STUDIO_ROLE );
} );

fs_test( 'boot: studio routes are recognised', function () {
	fs_eq( 'app', fisha_studio_route_for_path( 'studio' ) );
	fs_eq( 'app', fisha_studio_route_for_path( '/studio/' ) );
	fs_eq( 'app', fisha_studio_route_for_path( 'studio/products' ) );
	fs_eq( 'login', fisha_studio_route_for_path( 'studio/login' ) );
	fs_eq( 'logout', fisha_studio_route_for_path( 'studio/logout/' ) );
	fs_eq( null, fisha_studio_route_for_path( 'studios' ) );
	fs_eq( null, fisha_studio_route_for_path( 'product/studio' ) );
	fs_eq( null, fisha_studio_route_for_path( '' ) );
} );
```

- [ ] **Step 2: Capture the visible-site baseline (before any Studio code exists)**

`tests/studio/baseline.sh`:
```bash
#!/usr/bin/env bash
# Prints what visitors and search engines see on key pages (title, description, H1, card count),
# so a before/after diff proves the Studio changed nothing visible. Usage: bash tests/studio/baseline.sh > file
for u in / /shop-2/ /product-category/originals/ /product-category/collectibles/clippers/ /product-category/wearables/ /product/out-to-the-river/ /product/upside-down/; do
	html=$(curl -s "http://localhost:8484$u")
	echo "== $u"
	echo "$html" | grep -o -e '<title>[^<]*' -e '<meta name="description" content="[^"]*' | head -2
	echo "$html" | grep -o '<h1[^>]*>.*</h1>' | sed 's/<[^>]*>//g' | head -1
	echo "cards: $(echo "$html" | grep -o 'class="fs-card' | wc -l)"
done
```
Run: `bash tests/studio/baseline.sh > tests/studio/baseline-before.txt` and check the file has a title, H1 and a non-zero card count for the category pages. Task 16 compares against it.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `bash tests/studio/run.sh boot`
Expected: `FAIL  boot: studio module is loaded` ("FISHA_STUDIO_DIR is defined") and a fatal/FAIL for `fisha_studio_route_for_path` undefined.

- [ ] **Step 4: Create the module**

`wp-content/mu-plugins/fisha-design/studio/studio.php`:
```php
<?php
/**
 * Fisha Studio: the shop owner's catalog panel at /studio
 * (spec: docs/superpowers/specs/2026-10-12-fisha-studio-design.md).
 * This file defines the constants, loads the Studio parts and serves the /studio pages.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_DIR', FISHA_DESIGN_DIR . 'studio/' );
define( 'FISHA_STUDIO_URL', FISHA_DESIGN_URL . 'studio/' );
define( 'FISHA_STUDIO_NS', 'fisha-studio/v1' );
define( 'FISHA_STUDIO_ROLE', 'fisha_owner' );

/** 'app' | 'login' | 'logout' for a request path (relative to the site root), or null when it is not a Studio URL. */
function fisha_studio_route_for_path( string $path ): ?string {
	$path = trim( $path, '/' );
	if ( 'studio' === $path ) return 'app';
	if ( 0 !== strpos( $path, 'studio/' ) ) return null;
	$rest = trim( substr( $path, 7 ), '/' );
	return in_array( $rest, array( 'login', 'logout' ), true ) ? $rest : 'app';
}
```

In `wp-content/mu-plugins/fisha-design.php`, directly after `require_once FISHA_DESIGN_DIR . 'home.php';` add:
```php
require_once FISHA_DESIGN_DIR . 'studio/studio.php';
```

Append to `.gitignore`:
```
# local-only Studio test owner (see tests/studio/README.md)
/tests/studio/local-owner.txt
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `bash tests/studio/run.sh boot`
Expected: `2 passed, 0 failed`. Also open http://localhost:8484/ — the site still renders (no fatal).

- [ ] **Step 6: Commit**

```bash
git add .gitignore tests/studio wp-content/mu-plugins/fisha-design.php wp-content/mu-plugins/fisha-design/studio/studio.php
git commit -m "Studio: module bootstrap and integration test harness

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Owner role, capabilities and create-owner command

**Files:**
- Create: `wp-content/mu-plugins/fisha-design/studio/studio-role.php`
- Modify: `studio/studio.php` (add require at the end)
- Test: `tests/studio/test-role.php`
- Create: `tests/studio/README.md`

**Interfaces:**
- Consumes: `FISHA_STUDIO_ROLE`.
- Produces: `fisha_studio_caps(): array<string,bool>`; `fisha_studio_install_role(): void` (runs on `init` priority 5, re-installs when `FISHA_STUDIO_ROLE_VERSION` changes); `fisha_studio_create_owner( string $email, string $login = '', string $password = '' ): int|WP_Error`; WP-CLI `wp fisha-studio create-owner <email> [--login=<login>] [--password=<password>]`.

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-role.php`:
```php
<?php
fs_test( 'role: owner has catalog caps only', function () {
	$role = get_role( FISHA_STUDIO_ROLE );
	fs_true( $role, 'role exists' );
	foreach ( array( 'read', 'upload_files', 'edit_products', 'edit_others_products', 'edit_published_products', 'publish_products', 'delete_products', 'delete_published_products', 'manage_product_terms', 'assign_product_terms', 'fisha_studio' ) as $cap ) {
		fs_true( $role->has_cap( $cap ), "has $cap" );
	}
	foreach ( array( 'manage_options', 'edit_posts', 'edit_pages', 'manage_woocommerce', 'edit_users', 'list_users', 'edit_shop_orders', 'install_plugins', 'activate_plugins', 'edit_theme_options', 'unfiltered_html', 'view_admin_dashboard' ) as $cap ) {
		fs_false( $role->has_cap( $cap ), "must not have $cap" );
	}
} );

fs_test( 'role: administrators can open the studio', function () {
	fs_true( get_role( 'administrator' )->has_cap( 'fisha_studio' ) );
} );

fs_test( 'role: owner can edit and trash products made by someone else', function () {
	$p    = fs_product();
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'zz-test page', 'post_status' => 'publish' ) );
	fs_on_cleanup( function () use ( $page ) { wp_delete_post( $page, true ); } );
	fs_as( FISHA_STUDIO_ROLE );
	fs_true( current_user_can( 'edit_post', $p->get_id() ), 'edit_post' );
	fs_true( current_user_can( 'delete_post', $p->get_id() ), 'delete_post' );
	fs_false( current_user_can( 'edit_post', $page ), 'cannot edit a normal page' );
} );

fs_test( 'role: create owner makes a fisha_owner user and refuses duplicates', function () {
	$email = 'zz-test-owner-' . strtolower( wp_generate_password( 5, false ) ) . '@example.test';
	$id = fisha_studio_create_owner( $email, '', wp_generate_password( 20 ) );
	fs_true( is_int( $id ), 'returns a user id' );
	fs_on_cleanup( function () use ( $id ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $id ); } );
	fs_eq( array( FISHA_STUDIO_ROLE ), array_values( get_user_by( 'id', $id )->roles ) );
	fs_true( is_wp_error( fisha_studio_create_owner( $email ) ), 'duplicate email refused' );
	fs_true( is_wp_error( fisha_studio_create_owner( 'not-an-email' ) ), 'bad email refused' );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh role`
Expected: FAIL "role exists" and undefined `fisha_studio_create_owner`.

- [ ] **Step 3: Implement**

`studio/studio-role.php`:
```php
<?php
/**
 * Fisha Studio: the "Shop owner (Studio)" role. It can manage the catalog (products, product categories, tags,
 * media uploads) and nothing else. Accounts are created with:  wp fisha-studio create-owner <email>
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_ROLE_VERSION', '1' );

function fisha_studio_caps(): array {
	$caps = array( 'read', 'upload_files', 'fisha_studio',
		'edit_products', 'edit_others_products', 'edit_published_products', 'edit_private_products',
		'publish_products', 'read_private_products',
		'delete_products', 'delete_others_products', 'delete_published_products', 'delete_private_products',
		'manage_product_terms', 'edit_product_terms', 'delete_product_terms', 'assign_product_terms' );
	return array_fill_keys( $caps, true );
}

function fisha_studio_install_role(): void {
	if ( get_option( 'fisha_studio_role_version' ) === FISHA_STUDIO_ROLE_VERSION && get_role( FISHA_STUDIO_ROLE ) ) return;
	remove_role( FISHA_STUDIO_ROLE );
	add_role( FISHA_STUDIO_ROLE, 'Shop owner (Studio)', fisha_studio_caps() );
	$admin = get_role( 'administrator' );
	if ( $admin ) $admin->add_cap( 'fisha_studio' );
	update_option( 'fisha_studio_role_version', FISHA_STUDIO_ROLE_VERSION );
}
add_action( 'init', 'fisha_studio_install_role', 5 );

/**
 * Creates a Studio owner. Without a password, WordPress emails a "set your password" link.
 * @return int|WP_Error user id
 */
function fisha_studio_create_owner( string $email, string $login = '', string $password = '' ) {
	$email = sanitize_email( $email );
	if ( ! is_email( $email ) ) return new WP_Error( 'fisha_studio_email', 'That is not a valid email address.' );
	if ( email_exists( $email ) ) return new WP_Error( 'fisha_studio_email', 'A user with this email already exists.' );
	fisha_studio_install_role();
	$login = sanitize_user( $login ?: strstr( $email, '@', true ), true );
	if ( username_exists( $login ) ) $login .= '-' . strtolower( wp_generate_password( 4, false ) );
	$id = wp_insert_user( array(
		'user_login'   => $login,
		'user_email'   => $email,
		'user_pass'    => $password ?: wp_generate_password( 32 ),
		'role'         => FISHA_STUDIO_ROLE,
		'display_name' => $login,
	) );
	if ( is_wp_error( $id ) ) return $id;
	if ( ! $password ) wp_new_user_notification( $id, null, 'user' );
	return (int) $id;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'fisha-studio create-owner', function ( $args, $assoc ) {
		if ( empty( $args[0] ) ) WP_CLI::error( 'Usage: wp fisha-studio create-owner <email> [--login=<login>] [--password=<password>]' );
		$id = fisha_studio_create_owner( $args[0], $assoc['login'] ?? '', $assoc['password'] ?? '' );
		if ( is_wp_error( $id ) ) WP_CLI::error( $id->get_error_message() );
		$u = get_user_by( 'id', $id );
		WP_CLI::success( "Created Studio owner '{$u->user_login}' (#$id)." . ( empty( $assoc['password'] ) ? ' A set-password email was sent.' : '' ) );
	} );
}
```

Append to `studio/studio.php`:
```php
require_once FISHA_STUDIO_DIR . 'studio-role.php';
```

`tests/studio/README.md`:
```markdown
# Fisha Studio tests

Run from the repo root in Git Bash (Docker must be running):

    bash tests/studio/run.sh            # all tests
    bash tests/studio/run.sh publish    # only tests whose name contains "publish"

## Local test owner (browser checks)

Create once on the local Docker site (never on dev/live):

    PW=$(openssl rand -hex 12)
    MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp fisha-studio create-owner owner@fisha.test --login=studio-owner --password="$PW"
    printf 'login: studio-owner\npassword: %s\n' "$PW" > tests/studio/local-owner.txt

`local-owner.txt` is git-ignored. Sign in at http://localhost:8484/studio/login/
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh role`
Expected: `4 passed, 0 failed`

- [ ] **Step 5: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio: shop owner role and create-owner command

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Access gates

**Files:**
- Create: `studio/studio-gates.php`
- Modify: `studio/studio.php` (require)
- Test: `tests/studio/test-gates.php`

**Interfaces:**
- Consumes: `FISHA_STUDIO_ROLE`, `FISHA_STUDIO_NS`.
- Produces: `fisha_studio_is_owner( ?WP_User $u = null ): bool`; `fisha_studio_admin_gate_target( WP_User $u, string $script ): ?string`; `fisha_studio_rest_allowed( WP_User $u, string $route ): bool`. Hooks: `admin_init`@0, `show_admin_bar`, `rest_pre_dispatch`, `login_redirect`, `woocommerce_login_redirect`, `auth_cookie_expiration`.

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-gates.php`:
```php
<?php
fs_test( 'gates: owner is sent from wp-admin to the studio', function () {
	$owner = fs_user( FISHA_STUDIO_ROLE );
	fs_eq( home_url( '/studio/' ), fisha_studio_admin_gate_target( $owner, 'index.php' ) );
	fs_eq( home_url( '/studio/' ), fisha_studio_admin_gate_target( $owner, 'profile.php' ) );
	fs_eq( null, fisha_studio_admin_gate_target( $owner, 'admin-ajax.php' ) );
	fs_eq( null, fisha_studio_admin_gate_target( $owner, 'admin-post.php' ) );
	fs_eq( null, fisha_studio_admin_gate_target( $owner, 'async-upload.php' ) );
	fs_eq( null, fisha_studio_admin_gate_target( fs_user( 'administrator' ), 'index.php' ) );
} );

fs_test( 'gates: owner may only call studio, store and public site REST routes', function () {
	$owner = fs_user( FISHA_STUDIO_ROLE );
	fs_true( fisha_studio_rest_allowed( $owner, '/fisha-studio/v1/products' ) );
	fs_true( fisha_studio_rest_allowed( $owner, '/wc/store/v1/cart' ) );
	fs_true( fisha_studio_rest_allowed( $owner, '/fisha/v1/drop' ) );
	fs_false( fisha_studio_rest_allowed( $owner, '/wp/v2/users' ) );
	fs_false( fisha_studio_rest_allowed( $owner, '/wc/v3/products' ) );
	fs_false( fisha_studio_rest_allowed( $owner, '/fisha-studio/v1evil' ) );
	fs_false( fisha_studio_rest_allowed( $owner, '/' ) );
	fs_true( fisha_studio_rest_allowed( fs_user( 'administrator' ), '/wp/v2/users' ) );
} );

fs_test( 'gates: core REST is refused for the owner and allowed for admins', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( $status, $data ) = fs_rest( 'GET', '/wp/v2/users/me' );
	fs_eq( 403, $status );
	fs_eq( 'fisha_studio_forbidden', $data['code'] );
	fs_as( 'administrator' );
	list( $status ) = fs_rest( 'GET', '/wp/v2/users/me' );
	fs_eq( 200, $status );
} );

fs_test( 'gates: no admin bar, short sessions and studio landing for the owner', function () {
	$owner = fs_as( FISHA_STUDIO_ROLE );
	fs_false( apply_filters( 'show_admin_bar', true ), 'admin bar hidden' );
	fs_eq( 12 * HOUR_IN_SECONDS, apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $owner->ID, false ) );
	fs_eq( home_url( '/studio/' ), apply_filters( 'login_redirect', admin_url(), '', $owner ) );
	$admin = fs_as( 'administrator' );
	fs_eq( 2 * DAY_IN_SECONDS, apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $admin->ID, false ) );
	fs_eq( admin_url(), apply_filters( 'login_redirect', admin_url(), '', $admin ) );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh gates`
Expected: FAIL, undefined `fisha_studio_admin_gate_target`.

- [ ] **Step 3: Implement**

`studio/studio-gates.php`:
```php
<?php
/**
 * Fisha Studio access gates: a shop owner never reaches wp-admin, the admin bar or any REST route outside the
 * Studio's own (plus the public store/form routes the site itself uses), and their sessions last 12 hours.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_is_owner( ?WP_User $u = null ): bool {
	$u = $u ?: wp_get_current_user();
	return $u && $u->exists() && in_array( FISHA_STUDIO_ROLE, (array) $u->roles, true );
}

/** Where to send this user when they open a wp-admin script, or null to let them in. */
function fisha_studio_admin_gate_target( WP_User $u, string $script ): ?string {
	if ( ! fisha_studio_is_owner( $u ) ) return null;
	if ( in_array( $script, array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php' ), true ) ) return null;
	return home_url( '/studio/' );
}
// Priority 0: before WooCommerce's own admin redirect (which would send the owner to My Account).
add_action( 'admin_init', function () {
	$script = basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) );
	$target = fisha_studio_admin_gate_target( wp_get_current_user(), $script );
	if ( $target ) {
		wp_safe_redirect( $target );
		exit;
	}
}, 0 );

add_filter( 'show_admin_bar', function ( $show ) {
	return fisha_studio_is_owner() ? false : $show;
} );

function fisha_studio_rest_allowed( WP_User $u, string $route ): bool {
	if ( ! fisha_studio_is_owner( $u ) ) return true;
	foreach ( array( '/' . FISHA_STUDIO_NS . '/', '/wc/store/', '/fisha/v1/' ) as $prefix ) {
		if ( 0 === strpos( $route, $prefix ) ) return true;
	}
	return false;
}
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
	if ( null !== $result ) return $result;
	if ( fisha_studio_rest_allowed( wp_get_current_user(), $request->get_route() ) ) return $result;
	return new WP_Error( 'fisha_studio_forbidden', 'This is not available from the Studio.', array( 'status' => 403 ) );
}, 10, 3 );

$fisha_studio_landing = function ( $to, $requested = '', $user = null ) {
	return ( $user instanceof WP_User && fisha_studio_is_owner( $user ) ) ? home_url( '/studio/' ) : $to;
};
add_filter( 'login_redirect', $fisha_studio_landing, 20, 3 );
add_filter( 'woocommerce_login_redirect', function ( $to, $user = null ) use ( $fisha_studio_landing ) {
	return $fisha_studio_landing( $to, '', $user );
}, 20, 2 );

add_filter( 'auth_cookie_expiration', function ( $length, $user_id ) {
	$u = get_user_by( 'id', (int) $user_id );
	return ( $u && fisha_studio_is_owner( $u ) ) ? 12 * HOUR_IN_SECONDS : $length;
}, 20, 2 );
```

Append to `studio/studio.php`:
```php
require_once FISHA_STUDIO_DIR . 'studio-gates.php';
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh gates`
Expected: `4 passed, 0 failed`

- [ ] **Step 5: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio: keep the owner out of wp-admin, admin bar and core REST

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Sign-in, lockout and the /studio pages

**Files:**
- Create: `studio/studio-login.php`, `studio/app/login.php`, `studio/app/index.php` (shell only; the JS arrives in Task 12)
- Modify: `studio/studio.php` (require + routing + headers + page renderers)
- Test: `tests/studio/test-login.php`

**Interfaces:**
- Consumes: `fisha_client_ip()` (from `leads.php`), `fisha_studio_route_for_path()`.
- Produces: `fisha_studio_login_locked( string $login, string $ip ): bool`; `fisha_studio_login_failed( string $login, string $ip ): void`; `fisha_studio_login_clear( string $login, string $ip ): void`; `fisha_studio_check_credentials( string $login, string $password, string $ip ): WP_User|WP_Error` (codes `fisha_studio_locked`, `fisha_studio_invalid`); `fisha_studio_sign_in( string $login, string $password, string $ip ): WP_User|WP_Error` (sets auth cookie); `fisha_studio_csp_nonce(): string`; `fisha_studio_page_headers(): void`; `fisha_studio_import_map(): array`.

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-login.php`:
```php
<?php
function fs_ip(): string {
	$ip = '203.0.113.' . wp_rand( 1, 254 );
	fs_on_cleanup( function () use ( $ip ) { fisha_studio_login_clear( '', $ip ); } );
	return $ip;
}
function fs_owner_with_password( string $pw ): WP_User {
	$u = fs_user( FISHA_STUDIO_ROLE );
	wp_set_password( $pw, $u->ID );
	fs_on_cleanup( function () use ( $u ) { fisha_studio_login_clear( $u->user_login, '' ); } );
	return get_user_by( 'id', $u->ID );
}

fs_test( 'login: owner with the right password gets in', function () {
	$u = fs_owner_with_password( 'right-password-123' );
	$r = fisha_studio_check_credentials( $u->user_login, 'right-password-123', fs_ip() );
	fs_true( $r instanceof WP_User, 'returns the user' );
	fs_eq( $u->ID, $r->ID );
} );

fs_test( 'login: wrong password and non-studio users get the same generic error', function () {
	$u  = fs_owner_with_password( 'right-password-123' );
	$ip = fs_ip();
	$r  = fisha_studio_check_credentials( $u->user_login, 'nope', $ip );
	fs_eq( 'fisha_studio_invalid', $r->get_error_code() );
	$c = fs_user( 'customer' );
	wp_set_password( 'customer-pass-123', $c->ID );
	$r2 = fisha_studio_check_credentials( $c->user_login, 'customer-pass-123', $ip );
	fs_eq( 'fisha_studio_invalid', $r2->get_error_code() );
	fs_eq( $r->get_error_message(), $r2->get_error_message(), 'same message, no hint which part was wrong' );
} );

fs_test( 'login: five failures lock the account even with the right password', function () {
	$u  = fs_owner_with_password( 'right-password-123' );
	$ip = fs_ip();
	for ( $i = 0; $i < 5; $i++ ) fisha_studio_check_credentials( $u->user_login, 'wrong', $ip );
	$r = fisha_studio_check_credentials( $u->user_login, 'right-password-123', fs_ip() );
	fs_eq( 'fisha_studio_locked', $r->get_error_code(), 'locked by login from another IP' );
	fs_true( fisha_studio_login_locked( 'someone-else', $ip ), 'the IP is locked too' );
} );

fs_test( 'login: success clears earlier failures', function () {
	$u  = fs_owner_with_password( 'right-password-123' );
	$ip = fs_ip();
	for ( $i = 0; $i < 4; $i++ ) fisha_studio_check_credentials( $u->user_login, 'wrong', $ip );
	fisha_studio_check_credentials( $u->user_login, 'right-password-123', $ip );
	fisha_studio_check_credentials( $u->user_login, 'wrong', $ip );
	fs_false( fisha_studio_login_locked( $u->user_login, $ip ), 'counter was reset' );
} );

fs_test( 'login: import map versions every studio module', function () {
	$map = fisha_studio_import_map();
	fs_true( isset( $map['imports'] ), 'has imports' );
	foreach ( $map['imports'] as $from => $to ) {
		fs_true( 0 === strpos( $to, $from . '?v=' ), "$from is versioned" );
	}
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh login`
Expected: FAIL, undefined `fisha_studio_login_clear`.

- [ ] **Step 3: Implement sign-in and lockout**

`studio/studio-login.php`:
```php
<?php
/**
 * Fisha Studio sign-in: checks a login against WordPress, only lets Studio users in, and locks an account or IP for
 * 15 minutes after 5 failed tries (also for wp-login.php, so the lock cannot be side-stepped).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_LOGIN_TRIES', 5 );
define( 'FISHA_STUDIO_LOGIN_LOCK', 15 * MINUTE_IN_SECONDS );

function fisha_studio_login_key( string $kind, string $value ): string {
	return 'fs_login_' . $kind . '_' . md5( strtolower( trim( $value ) ) );
}
function fisha_studio_login_locked( string $login, string $ip ): bool {
	return ( '' !== $login && (int) get_transient( fisha_studio_login_key( 'u', $login ) ) >= FISHA_STUDIO_LOGIN_TRIES )
		|| ( '' !== $ip && (int) get_transient( fisha_studio_login_key( 'ip', $ip ) ) >= FISHA_STUDIO_LOGIN_TRIES );
}
function fisha_studio_login_failed( string $login, string $ip ): void {
	foreach ( array( 'u' => $login, 'ip' => $ip ) as $kind => $value ) {
		if ( '' === $value ) continue;
		$key = fisha_studio_login_key( $kind, $value );
		set_transient( $key, (int) get_transient( $key ) + 1, FISHA_STUDIO_LOGIN_LOCK );
	}
}
function fisha_studio_login_clear( string $login, string $ip ): void {
	if ( '' !== $login ) delete_transient( fisha_studio_login_key( 'u', $login ) );
	if ( '' !== $ip ) delete_transient( fisha_studio_login_key( 'ip', $ip ) );
}

/** @return WP_User|WP_Error */
function fisha_studio_check_credentials( string $login, string $password, string $ip ) {
	$login = trim( $login );
	if ( fisha_studio_login_locked( $login, $ip ) ) {
		return new WP_Error( 'fisha_studio_locked', 'Too many tries. Please wait 15 minutes and try again.' );
	}
	$GLOBALS['fisha_studio_in_check'] = true;
	$user = wp_authenticate( $login, $password );
	unset( $GLOBALS['fisha_studio_in_check'] );
	if ( is_wp_error( $user ) || ! user_can( $user, 'fisha_studio' ) ) {
		fisha_studio_login_failed( $login, $ip );
		return new WP_Error( 'fisha_studio_invalid', 'That email/username and password do not match.' );
	}
	fisha_studio_login_clear( $login, $ip );
	return $user;
}

/** Checks credentials and starts a session (sets the auth cookie). @return WP_User|WP_Error */
function fisha_studio_sign_in( string $login, string $password, string $ip ) {
	$user = fisha_studio_check_credentials( $login, $password, $ip );
	if ( is_wp_error( $user ) ) return $user;
	wp_set_auth_cookie( $user->ID, false, is_ssl() );
	wp_set_current_user( $user->ID );
	do_action( 'wp_login', $user->user_login, $user );
	return $user;
}

// The same lock on wp-login.php and the My Account form.
add_filter( 'authenticate', function ( $user, $login ) {
	if ( ! empty( $GLOBALS['fisha_studio_in_check'] ) || ! $login ) return $user;
	return fisha_studio_login_locked( (string) $login, fisha_client_ip() )
		? new WP_Error( 'fisha_studio_locked', 'Too many tries. Please wait 15 minutes and try again.' )
		: $user;
}, 99, 2 );
add_action( 'wp_login_failed', function ( $login ) {
	if ( empty( $GLOBALS['fisha_studio_in_check'] ) ) fisha_studio_login_failed( (string) $login, fisha_client_ip() );
} );
// A fresh REST nonce right after signing in needs the new cookie in this request too.
add_action( 'set_logged_in_cookie', function ( $cookie ) { $_COOKIE[ LOGGED_IN_COOKIE ] = $cookie; } );
```

- [ ] **Step 4: Add routing, headers and pages to `studio/studio.php`**

Append to `studio/studio.php`:
```php
require_once FISHA_STUDIO_DIR . 'studio-login.php';

/** Per-request nonce for the one inline <script type="importmap">. */
function fisha_studio_csp_nonce(): string {
	static $nonce = null;
	return $nonce ?? ( $nonce = base64_encode( random_bytes( 16 ) ) );
}

function fisha_studio_page_headers(): void {
	nocache_headers();
	do_action( 'litespeed_control_set_nocache', 'fisha studio' );
	header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . fisha_studio_csp_nonce() . "'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'" );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Referrer-Policy: same-origin' );
}

/** Maps every Studio JS module URL to the same URL + ?v=<mtime>, so a deploy never leaves a stale module in a browser cache. */
function fisha_studio_import_map(): array {
	$imports = array();
	$base    = FISHA_STUDIO_DIR . 'app/js/';
	$files   = array_merge( glob( $base . '*.js' ) ?: array(), glob( $base . '*/*.js' ) ?: array() );
	foreach ( $files as $file ) {
		$rel = substr( $file, strlen( FISHA_STUDIO_DIR ) );
		$url = FISHA_STUDIO_URL . $rel;
		$imports[ $url ] = $url . '?v=' . filemtime( $file );
	}
	return array( 'imports' => $imports );
}

function fisha_studio_login_page(): void {
	if ( current_user_can( 'fisha_studio' ) ) {
		wp_safe_redirect( home_url( '/studio/' ) );
		exit;
	}
	$error = '';
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_fs_nonce'] ?? '' ) ), 'fisha_studio_login' ) ) {
			$error = 'This page was open for a long time. Please try again.';
		} else {
			$r = fisha_studio_sign_in( (string) wp_unslash( $_POST['log'] ?? '' ), (string) wp_unslash( $_POST['pwd'] ?? '' ), fisha_client_ip() );
			if ( ! is_wp_error( $r ) ) {
				wp_safe_redirect( home_url( '/studio/' ) );
				exit;
			}
			$error = $r->get_error_message();
		}
	}
	status_header( 200 );
	require FISHA_STUDIO_DIR . 'app/login.php';
}

add_action( 'parse_request', function ( $wp ) {
	$route = fisha_studio_route_for_path( (string) $wp->request );
	if ( ! $route ) return;
	fisha_studio_page_headers();
	if ( 'logout' === $route ) {
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'fisha_studio_logout' ) ) wp_logout();
		wp_safe_redirect( home_url( '/studio/login/' ) );
		exit;
	}
	if ( 'login' === $route ) {
		fisha_studio_login_page();
		exit;
	}
	if ( ! current_user_can( 'fisha_studio' ) ) {
		wp_safe_redirect( home_url( '/studio/login/' ) );
		exit;
	}
	status_header( 200 );
	require FISHA_STUDIO_DIR . 'app/index.php';
	exit;
}, 0 );
```

`studio/app/login.php`:
```php
<?php
/**
 * Fisha Studio sign-in page (no JavaScript needed). Variables: $error (string).
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Sign in | Fisha Studio</title>
	<link rel="icon" href="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-logo.png' ); ?>">
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400..800&display=swap">
	<link rel="stylesheet" href="<?php echo esc_url( FISHA_STUDIO_URL . 'app/studio.css?v=' . fisha_asset_ver( 'studio/app/studio.css' ) ); ?>">
</head>
<body class="fs-login-page">
	<main class="fs-login">
		<img class="fs-login__logo" src="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-logo.png' ); ?>" alt="" width="120" height="85">
		<h1>Fisha Studio</h1>
		<p class="fs-login__sub">Sign in to manage the shop.</p>
		<?php if ( $error ) : ?><p class="fs-login__error" role="alert"><?php echo esc_html( $error ); ?></p><?php endif; ?>
		<form method="post" action="<?php echo esc_url( home_url( '/studio/login/' ) ); ?>">
			<?php wp_nonce_field( 'fisha_studio_login', '_fs_nonce', false ); ?>
			<label>Email or username<input name="log" type="text" autocomplete="username" required autofocus value="<?php echo esc_attr( wp_unslash( $_POST['log'] ?? '' ) ); ?>"></label>
			<label>Password<input name="pwd" type="password" autocomplete="current-password" required></label>
			<button class="fs-btn fs-btn--primary" type="submit">Sign in</button>
		</form>
		<a class="fs-login__forgot" href="<?php echo esc_url( wp_lostpassword_url( home_url( '/studio/login/' ) ) ); ?>">Forgot your password?</a>
	</main>
</body>
</html>
```

`studio/app/index.php`:
```php
<?php
/**
 * Fisha Studio app shell. The UI is plain ES modules (studio/app/js), versioned through an import map.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$fs_user   = wp_get_current_user();
$fs_config = array(
	'api'      => esc_url_raw( rest_url( FISHA_STUDIO_NS . '/' ) ),
	'nonce'    => wp_create_nonce( 'wp_rest' ),
	'home'     => home_url( '/' ),
	'logout'   => wp_nonce_url( home_url( '/studio/logout/' ), 'fisha_studio_logout' ),
	'user'     => $fs_user->display_name,
	'currency' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
	'logo'     => FISHA_DESIGN_URL . 'img/fisha-logo.png',
);
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Fisha Studio</title>
	<link rel="icon" href="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-logo.png' ); ?>">
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400..800&display=swap">
	<link rel="stylesheet" href="<?php echo esc_url( FISHA_STUDIO_URL . 'app/studio.css?v=' . fisha_asset_ver( 'studio/app/studio.css' ) ); ?>">
	<script type="importmap" nonce="<?php echo esc_attr( fisha_studio_csp_nonce() ); ?>"><?php echo wp_json_encode( fisha_studio_import_map(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ); ?></script>
	<script type="application/json" id="fs-config"><?php echo wp_json_encode( $fs_config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
	<?php $fs_map = fisha_studio_import_map(); $fs_app = FISHA_STUDIO_URL . 'app/js/app.js'; ?>
	<script type="module" src="<?php echo esc_url( $fs_map['imports'][ $fs_app ] ?? $fs_app ); ?>"></script>
</head>
<body>
	<div id="fs-app" class="fs-app"><p class="fs-boot">Loading Fisha Studio…</p></div>
</body>
</html>
```

Note: import maps do not apply to a `<script src>`, so `app.js` is loaded with its versioned URL taken from the same map. Its relative imports (`./ui.js`) resolve to plain URLs, which the map rewrites to their versioned URLs, so every module loads exactly once and is cache-busted on deploy.

Create an empty placeholder so `fisha_asset_ver()` and the import map have files until Task 12: `studio/app/studio.css` containing `/* Fisha Studio styles: see Task 12 */` and `studio/app/js/app.js` containing `document.getElementById( 'fs-app' ).textContent = 'Fisha Studio is being built.';`.

- [ ] **Step 5: Run the tests**

Run: `bash tests/studio/run.sh login`
Expected: `5 passed, 0 failed`

- [ ] **Step 6: Check the pages over HTTP**

Run:
```bash
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://localhost:8484/studio/
curl -s -D - -o /dev/null http://localhost:8484/studio/login/ | grep -i -e '^HTTP' -e content-security -e x-frame -e x-robots
```
Expected: `302 http://localhost:8484/studio/login/`; then `HTTP/1.1 200`, a `Content-Security-Policy` header with `frame-ancestors 'self'`, `X-Frame-Options: SAMEORIGIN`, `X-Robots-Tag: noindex, nofollow`.

- [ ] **Step 7: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio: /studio routing, sign-in page with lockout, security headers

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 5: Product field schema and URL rules

**Files:**
- Create: `studio/studio-urls.php`, `studio/studio-fields.php`
- Modify: `studio/studio.php` (requires)
- Test: `tests/studio/test-fields.php`, `tests/studio/test-urls.php`

**Interfaces:**
- Produces (urls): `fisha_studio_reserved_slugs(): string[]`; `fisha_studio_slug_error( string $slug ): ?string`; `fisha_studio_slug_from_name( string $name ): string`; `fisha_studio_unique_product_slug( string $slug, int $id ): string`.
- Produces (fields): `fisha_studio_product_fields(): array<string,array>` — each definition has `type` (`text|html|slug|money|decimal|int|bool|enum|color|date|attachment|attachments|ids`), `get( WC_Product ): mixed`, `set( WC_Product, mixed ): void`, optional `label`, `max`, `min`, `nullable`, `required`, `options` (array or callable), `taxonomy`, `meta` (post meta key). `fisha_studio_clean( array $def, mixed $v ): array{0:mixed,1:?string}`; `fisha_studio_product_read( WC_Product $p ): array`; `fisha_studio_sanitize( array $schema, array $input ): array{0:array,1:array}` (values, errors); `fisha_studio_product_apply( WC_Product $p, array $values ): void`; `fisha_studio_product_validate( array $values, WC_Product $p ): array<string,string>`; `fisha_studio_same( mixed $a, mixed $b ): bool`.
- Field keys (used by every later task and the UI): `name, slug, status, catalog_visibility, featured, category_ids, tag_ids, short_description, description, reviews_allowed, menu_order, regular_price, sale_price, date_on_sale_from, date_on_sale_to, sku, manage_stock, stock_quantity, stock_status, low_stock_amount, backorders, sold_individually, image_id, gallery_image_ids, weight, length, width, height, shipping_class_id, tax_status, tax_class, card_bg, meta_line, series, hero_skip, seo_title, seo_desc`.

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-urls.php`:
```php
<?php
fs_test( 'urls: slugs from names are short, lowercase ascii', function () {
	fs_eq( 'single-fisha-no-4', fisha_studio_slug_from_name( 'Single Fisha No. 4' ) );
	fs_eq( 'cafe-fisha', fisha_studio_slug_from_name( 'Café Fisha!' ) );
	fs_true( strlen( fisha_studio_slug_from_name( str_repeat( 'very long words ', 10 ) ) ) <= 60, 'max 60 chars' );
	fs_false( '-' === substr( fisha_studio_slug_from_name( str_repeat( 'abc ', 30 ) ), -1 ), 'no trailing dash' );
	fs_eq( 'item', fisha_studio_slug_from_name( 'שלום' ), 'non-latin falls back' );
} );

fs_test( 'urls: bad and reserved slugs are refused', function () {
	fs_eq( null, fisha_studio_slug_error( 'out-to-the-river' ) );
	fs_eq( null, fisha_studio_slug_error( '' ), 'empty means generate' );
	fs_true( fisha_studio_slug_error( 'Out To River' ), 'spaces/capitals' );
	fs_true( fisha_studio_slug_error( 'a--b' ), 'double dash' );
	fs_true( fisha_studio_slug_error( str_repeat( 'a', 61 ) ), 'too long' );
	fs_true( fisha_studio_slug_error( 'studio' ), 'reserved' );
	fs_true( fisha_studio_slug_error( 'shop-2' ), 'reserved' );
} );

fs_test( 'urls: unique product slug skips taken ones', function () {
	$p = fs_product( array( 'slug' => 'zz-test-unique' ) );
	fs_eq( 'zz-test-unique', fisha_studio_unique_product_slug( 'zz-test-unique', $p->get_id() ), 'own slug stays' );
	fs_eq( 'zz-test-unique-2', fisha_studio_unique_product_slug( 'zz-test-unique', 0 ) );
} );
```

`tests/studio/test-fields.php`:
```php
<?php
function fs_field( string $key ): array { return fisha_studio_product_fields()[ $key ]; }

fs_test( 'fields: money accepts human input', function () {
	$def = fs_field( 'regular_price' );
	fs_eq( array( '12.5', null ), fisha_studio_clean( $def, '12,50' ) );
	fs_eq( array( '120', null ), fisha_studio_clean( $def, '₪120' ) );
	fs_eq( array( '99', null ), fisha_studio_clean( $def, ' 99 ' ) );
	fs_eq( array( '2200', null ), fisha_studio_clean( $def, '2,200' ), 'thousands comma' );
	fs_eq( array( '', null ), fisha_studio_clean( $def, '' ) );
	fs_true( fisha_studio_clean( $def, '-5' )[1], 'negative refused' );
	fs_true( fisha_studio_clean( $def, 'abc' )[1], 'letters refused' );
} );

fs_test( 'fields: html is cleaned', function () {
	list( $v ) = fisha_studio_clean( fs_field( 'description' ), '<p style="color:red" class="MsoNormal">Hi <b>there</b><script>alert(1)</script><img src=x onerror=alert(1)></p>' );
	fs_false( strpos( $v, '<script' ), 'no script' );
	fs_false( strpos( $v, 'onerror' ), 'no handlers' );
	fs_false( strpos( $v, 'style=' ), 'no inline styles' );
	fs_false( strpos( $v, 'MsoNormal' ), 'no Word classes' );
	fs_true( false !== strpos( $v, '<b>there</b>' ), 'bold kept' );
} );

fs_test( 'fields: other types', function () {
	fs_eq( array( 3, null ), fisha_studio_clean( fs_field( 'stock_quantity' ), '3' ) );
	fs_eq( array( null, null ), fisha_studio_clean( fs_field( 'stock_quantity' ), '' ) );
	fs_true( fisha_studio_clean( fs_field( 'stock_quantity' ), '2.5' )[1], 'whole numbers only' );
	fs_true( fisha_studio_clean( fs_field( 'stock_quantity' ), '-1' )[1], 'no negative stock input' );
	fs_eq( array( true, null ), fisha_studio_clean( fs_field( 'featured' ), 'true' ) );
	fs_eq( array( false, null ), fisha_studio_clean( fs_field( 'featured' ), '0' ) );
	fs_true( fisha_studio_clean( fs_field( 'stock_status' ), 'maybe' )[1], 'unknown enum' );
	fs_eq( array( '#efe8dc', null ), fisha_studio_clean( fs_field( 'card_bg' ), '#EFE8DC' ) );
	fs_true( fisha_studio_clean( fs_field( 'card_bg' ), 'red' )[1], 'colour must be hex' );
	fs_eq( array( '2026-11-01', null ), fisha_studio_clean( fs_field( 'date_on_sale_from' ), '2026-11-01' ) );
	fs_true( fisha_studio_clean( fs_field( 'date_on_sale_from' ), '2026-02-30' )[1], 'impossible date' );
	fs_true( fisha_studio_clean( fs_field( 'name' ), str_repeat( 'x', 121 ) )[1], 'name max 120' );
	fs_true( fisha_studio_clean( fs_field( 'category_ids' ), array( 99999999 ) )[1], 'unknown category' );
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'zz-test not an image', 'post_status' => 'draft' ) );
	fs_on_cleanup( function () use ( $page ) { wp_delete_post( $page, true ); } );
	fs_true( fisha_studio_clean( fs_field( 'image_id' ), $page )[1], 'non-image refused' );
	$img = fs_image();
	fs_eq( array( $img, null ), fisha_studio_clean( fs_field( 'image_id' ), (string) $img ), 'image accepted as int' );
} );

fs_test( 'fields: sanitize ignores unknown keys and reports errors per field', function () {
	list( $values, $errors ) = fisha_studio_sanitize( fisha_studio_product_fields(), array( 'name' => ' Hello ', 'evil' => 'x', 'regular_price' => 'abc' ) );
	fs_eq( 'Hello', $values['name'] );
	fs_false( isset( $values['evil'] ), 'unknown key dropped' );
	fs_true( isset( $errors['regular_price'] ), 'price error reported' );
} );

fs_test( 'fields: every field round-trips through apply, save and read', function () {
	$cat  = fs_term( 'roundtrip' );
	$img  = fs_image();
	$img2 = fs_image();
	$tag  = wp_insert_term( 'zz-test-tag', 'product_tag' );
	fs_on_cleanup( function () use ( $tag ) { wp_delete_term( $tag['term_id'], 'product_tag' ); } );
	$p = fs_product();
	$want = array(
		'name' => 'zz-test Round Trip', 'slug' => 'zz-test-round-trip', 'status' => 'private', 'catalog_visibility' => 'search',
		'featured' => true, 'category_ids' => array( $cat->term_id ), 'tag_ids' => array( $tag['term_id'] ),
		'short_description' => '<p>Short <b>bold</b></p>', 'description' => '<h2>Long</h2><p>Text</p>', 'reviews_allowed' => false,
		'menu_order' => -3, 'regular_price' => '650', 'sale_price' => '500', 'date_on_sale_from' => '2026-11-01', 'date_on_sale_to' => '2026-11-30',
		'sku' => 'ZZ-TEST-RT-' . wp_rand( 1000, 9999 ), 'manage_stock' => true, 'stock_quantity' => 4, 'stock_status' => 'instock',
		'low_stock_amount' => 1, 'backorders' => 'no', 'sold_individually' => true, 'image_id' => $img, 'gallery_image_ids' => array( $img2 ),
		'weight' => '1.2', 'length' => '33', 'width' => '33', 'height' => '2', 'shipping_class_id' => 0, 'tax_status' => 'taxable', 'tax_class' => '',
		'card_bg' => '#e3ecef', 'meta_line' => 'Ink on cotton paper · 33 × 33 cm', 'series' => 'zz-test-series', 'hero_skip' => true,
		'seo_title' => 'Round Trip | Fisha', 'seo_desc' => 'A test product for the round trip.',
	);
	fs_eq( array_keys( fisha_studio_product_fields() ), array_keys( $want ), 'the test covers every field' );
	fisha_studio_product_apply( $p, $want );
	$p->save();
	$got = fisha_studio_product_read( wc_get_product( $p->get_id() ) );
	foreach ( $want as $k => $v ) fs_eq( $v, $got[ $k ], $k );
} );

fs_test( 'fields: validation of a complete product', function () {
	$p   = fs_product();
	$ok  = fisha_studio_product_read( $p );
	$ok['image_id']     = fs_image();
	$ok['category_ids'] = array( fs_term( 'validate' )->term_id );
	fs_eq( array(), fisha_studio_product_validate( $ok, $p ), 'complete product is valid' );

	$bad = array_merge( $ok, array( 'name' => '', 'short_description' => '', 'image_id' => 0, 'category_ids' => array() ) );
	$e   = fisha_studio_product_validate( $bad, $p );
	foreach ( array( 'name', 'short_description', 'image_id', 'category_ids' ) as $k ) fs_true( isset( $e[ $k ] ), "$k required" );

	fs_true( isset( fisha_studio_product_validate( array_merge( $ok, array( 'sale_price' => '100' ) ), $p )['sale_price'] ), 'sale must be lower' );
	fs_true( isset( fisha_studio_product_validate( array_merge( $ok, array( 'regular_price' => '', 'sale_price' => '50' ) ), $p )['sale_price'] ), 'sale needs a price' );
	fs_true( isset( fisha_studio_product_validate( array_merge( $ok, array( 'date_on_sale_from' => '2026-12-02', 'date_on_sale_to' => '2026-12-01' ) ), $p )['date_on_sale_to'] ), 'dates in order' );
	fs_true( isset( fisha_studio_product_validate( array_merge( $ok, array( 'manage_stock' => true, 'stock_quantity' => null ) ), $p )['stock_quantity'] ), 'stock needed' );
	fs_true( isset( fisha_studio_product_validate( array_merge( $ok, array( 'slug' => 'studio' ) ), $p )['slug'] ), 'reserved slug' );
	$other = fs_product( array( 'sku' => 'ZZ-TEST-DUP-' . $p->get_id() ) );
	fs_true( isset( fisha_studio_product_validate( array_merge( $ok, array( 'sku' => $other->get_sku() ) ), $p )['sku'] ), 'duplicate sku' );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh urls` and `bash tests/studio/run.sh fields`
Expected: FAIL with undefined functions.

- [ ] **Step 3: Implement URL rules**

`studio/studio-urls.php`:
```php
<?php
/**
 * Fisha Studio URL rules. The Studio only ever sets slugs: products stay at /product/{slug}/ and categories at
 * /product-category/{parent}/{slug}/. New slugs are short lowercase ASCII; existing slugs are never regenerated.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_reserved_slugs(): array {
	return array( 'page', 'feed', 'embed', 'product', 'product-category', 'product-tag', 'shop', 'shop-2', 'studio',
		'cart', 'checkout', 'my-account', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login' );
}

/** Error message for a slug the owner typed, or null when it is fine. Empty = "make one from the name". */
function fisha_studio_slug_error( string $slug ): ?string {
	if ( '' === $slug ) return null;
	if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) return 'Use only lowercase letters, numbers and single dashes.';
	if ( strlen( $slug ) > 60 ) return 'Keep the address to 60 characters or fewer.';
	if ( in_array( $slug, fisha_studio_reserved_slugs(), true ) ) return 'This address is used by the site. Please pick another one.';
	return null;
}

function fisha_studio_slug_from_name( string $name ): string {
	$slug = sanitize_title( remove_accents( $name ) );
	$slug = preg_replace( '/%[0-9a-f]{2}/i', '', $slug );           // drop letters that have no ASCII form
	$slug = trim( preg_replace( '/-+/', '-', $slug ), '-' );
	if ( strlen( $slug ) > 60 ) {
		$slug = substr( $slug, 0, 60 );
		$cut  = strrpos( $slug, '-' );
		if ( false !== $cut && $cut > 20 ) $slug = substr( $slug, 0, $cut );
		$slug = trim( $slug, '-' );
	}
	return '' !== $slug ? $slug : 'item';
}

function fisha_studio_unique_product_slug( string $slug, int $id ): string {
	if ( in_array( $slug, fisha_studio_reserved_slugs(), true ) ) $slug .= '-2';
	return wp_unique_post_slug( $slug, $id, 'publish', 'product', 0 );
}
```

- [ ] **Step 4: Implement the field schema**

`studio/studio-fields.php`:
```php
<?php
/**
 * Fisha Studio field schema: every product field the site reads (spec §2.3), with how to read it, write it,
 * clean it and check it. The REST API, drafts, preview and publish all work from this one list.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_product_fields(): array {
	static $fields = null;
	if ( null !== $fields ) return $fields;

	$prop = function ( string $prop, string $type, array $extra = array() ): array {
		return array_merge( array(
			'type' => $type,
			'get'  => function ( WC_Product $p ) use ( $prop ) { return $p->{"get_$prop"}( 'edit' ); },
			'set'  => function ( WC_Product $p, $v ) use ( $prop ) { $p->{"set_$prop"}( $v ); },
		), $extra );
	};
	$meta = function ( string $key, string $type, array $extra = array() ): array {
		return array_merge( array(
			'type' => $type,
			'meta' => $key,
			'get'  => function ( WC_Product $p ) use ( $key ) { return $p->get_meta( $key, true, 'edit' ); },
			'set'  => function ( WC_Product $p, $v ) use ( $key ) {
				if ( '' === $v || false === $v || null === $v ) $p->delete_meta_data( $key );
				else $p->update_meta_data( $key, true === $v ? '1' : $v );
			},
		), $extra );
	};

	$fields = array(
		// Basics
		'name'               => $prop( 'name', 'text', array( 'label' => 'Name', 'max' => 120, 'required' => true ) ),
		'slug'               => $prop( 'slug', 'slug', array( 'label' => 'Address' ) ),
		'status'             => $prop( 'status', 'enum', array( 'label' => 'Visibility', 'options' => array( 'publish', 'private', 'draft' ) ) ),
		'catalog_visibility' => $prop( 'catalog_visibility', 'enum', array( 'label' => 'Where it shows', 'options' => array( 'visible', 'catalog', 'search', 'hidden' ) ) ),
		'featured'           => $prop( 'featured', 'bool', array( 'label' => 'Featured' ) ),
		'category_ids'       => $prop( 'category_ids', 'ids', array( 'label' => 'Categories', 'taxonomy' => 'product_cat', 'required' => true ) ),
		'tag_ids'            => $prop( 'tag_ids', 'ids', array( 'label' => 'Tags', 'taxonomy' => 'product_tag' ) ),
		'short_description'  => $prop( 'short_description', 'html', array( 'label' => 'Short description', 'required' => true ) ),
		'description'        => $prop( 'description', 'html', array( 'label' => 'Full description' ) ),
		'reviews_allowed'    => $prop( 'reviews_allowed', 'bool', array( 'label' => 'Reviews' ) ),
		'menu_order'         => $prop( 'menu_order', 'int', array( 'label' => 'Order' ) ),
		// Price
		'regular_price'      => $prop( 'regular_price', 'money', array( 'label' => 'Price' ) ),
		'sale_price'         => $prop( 'sale_price', 'money', array( 'label' => 'Sale price' ) ),
		'date_on_sale_from'  => $prop( 'date_on_sale_from', 'date', array( 'label' => 'Sale starts',
			'set' => function ( WC_Product $p, $v ) { $p->set_date_on_sale_from( $v ? $v . ' 00:00:00' : null ); } ) ),
		'date_on_sale_to'    => $prop( 'date_on_sale_to', 'date', array( 'label' => 'Sale ends',
			'set' => function ( WC_Product $p, $v ) { $p->set_date_on_sale_to( $v ? $v . ' 23:59:59' : null ); } ) ),
		// Inventory
		'sku'                => $prop( 'sku', 'text', array( 'label' => 'SKU', 'max' => 64 ) ),
		'manage_stock'       => $prop( 'manage_stock', 'bool', array( 'label' => 'Track stock' ) ),
		'stock_quantity'     => $prop( 'stock_quantity', 'int', array( 'label' => 'Stock', 'nullable' => true, 'min' => 0 ) ),
		'stock_status'       => $prop( 'stock_status', 'enum', array( 'label' => 'Stock status', 'options' => array( 'instock', 'outofstock', 'onbackorder' ) ) ),
		'low_stock_amount'   => $prop( 'low_stock_amount', 'int', array( 'label' => 'Low stock alert at', 'nullable' => true, 'min' => 0 ) ),
		'backorders'         => $prop( 'backorders', 'enum', array( 'label' => 'Orders when sold out', 'options' => array( 'no', 'notify', 'yes' ) ) ),
		'sold_individually'  => $prop( 'sold_individually', 'bool', array( 'label' => 'One per order' ) ),
		// Images
		'image_id'           => $prop( 'image_id', 'attachment', array( 'label' => 'Main image', 'required' => true ) ),
		'gallery_image_ids'  => $prop( 'gallery_image_ids', 'attachments', array( 'label' => 'Gallery' ) ),
		// Shipping & tax
		'weight'             => $prop( 'weight', 'decimal', array( 'label' => 'Weight' ) ),
		'length'             => $prop( 'length', 'decimal', array( 'label' => 'Length' ) ),
		'width'              => $prop( 'width', 'decimal', array( 'label' => 'Width' ) ),
		'height'             => $prop( 'height', 'decimal', array( 'label' => 'Height' ) ),
		'shipping_class_id'  => $prop( 'shipping_class_id', 'int', array( 'label' => 'Shipping class', 'min' => 0 ) ),
		'tax_status'         => $prop( 'tax_status', 'enum', array( 'label' => 'Tax', 'options' => array( 'taxable', 'shipping', 'none' ) ) ),
		'tax_class'          => $prop( 'tax_class', 'enum', array( 'label' => 'Tax class', 'options' => function () { return array_merge( array( '' ), WC_Tax::get_tax_class_slugs() ); } ) ),
		// Fisha design (read by fisha_product_card(), fisha_series_picker() and shop-home.php)
		'card_bg'            => $meta( '_fisha_card_bg', 'color', array( 'label' => 'Card background' ) ),
		'meta_line'          => $meta( '_fisha_colour', 'text', array( 'label' => 'Line under the name', 'max' => 80 ) ),
		'series'             => $meta( '_fisha_series', 'slug', array( 'label' => 'Design series' ) ),
		'hero_skip'          => $meta( '_fisha_hero_skip', 'bool', array( 'label' => 'Keep out of the Shop hero' ) ),
		// SEO (read by seo.php)
		'seo_title'          => $meta( 'fisha_seo_title', 'text', array( 'label' => 'SEO title', 'max' => 70 ) ),
		'seo_desc'           => $meta( 'fisha_seo_desc', 'text', array( 'label' => 'SEO description', 'max' => 170 ) ),
	);
	return $fields;
}

/** Cleans one value for a field definition. Returns [clean value, error message or null]. */
function fisha_studio_clean( array $def, $v ): array {
	$label = $def['label'] ?? 'This field';
	switch ( $def['type'] ) {
		case 'text':
			$v = trim( sanitize_text_field( (string) $v ) );
			if ( isset( $def['max'] ) && mb_strlen( $v ) > $def['max'] ) return array( $v, sprintf( '%s can be at most %d characters.', $label, $def['max'] ) );
			return array( $v, null );

		case 'html':
			$allowed = array(
				'p' => array(), 'br' => array(), 'b' => array(), 'strong' => array(), 'i' => array(), 'em' => array(),
				'ul' => array(), 'ol' => array(), 'li' => array(), 'h2' => array(), 'h3' => array(),
				'a' => array( 'href' => true, 'target' => true, 'rel' => true ),
			);
			return array( trim( wp_kses( (string) $v, $allowed ) ), null );

		case 'slug':
			return array( sanitize_title( (string) $v ), null );

		case 'money':
		case 'decimal':
			$raw = trim( (string) $v );
			$v   = preg_replace( '/[^\d.,\-]/u', '', $raw );                // drop ₪, spaces and other symbols
			if ( '' === $v ) return '' === $raw ? array( '', null ) : array( $raw, sprintf( '%s must be a number of 0 or more.', $label ) );
			if ( preg_match( '/^\d{1,3}(,\d{3})+(\.\d+)?$/', $v ) ) $v = str_replace( ',', '', $v );  // 2,200 → 2200
			$v = str_replace( ',', '.', $v );                                  // 12,50 → 12.50
			if ( ! is_numeric( $v ) || (float) $v < 0 ) return array( $v, sprintf( '%s must be a number of 0 or more.', $label ) );
			return array( wc_format_decimal( $v, 'money' === $def['type'] ? wc_get_price_decimals() : false, true ), null );

		case 'int':
			if ( ( '' === $v || null === $v ) && ! empty( $def['nullable'] ) ) return array( null, null );
			if ( ! is_numeric( $v ) || (float) $v !== (float) (int) $v ) return array( $v, sprintf( '%s must be a whole number.', $label ) );
			$v = (int) $v;
			if ( isset( $def['min'] ) && $v < $def['min'] ) return array( $v, sprintf( '%s must be %d or more.', $label, $def['min'] ) );
			return array( $v, null );

		case 'bool':
			return array( in_array( $v, array( true, 1, '1', 'yes', 'true', 'on' ), true ), null );

		case 'enum':
			$options = is_callable( $def['options'] ) ? call_user_func( $def['options'] ) : $def['options'];
			$v       = (string) $v;
			return in_array( $v, $options, true ) ? array( $v, null ) : array( $v, sprintf( '%s has a value the shop does not know.', $label ) );

		case 'color':
			if ( '' === (string) $v ) return array( '', null );
			$c = sanitize_hex_color( (string) $v );
			return $c ? array( strtolower( $c ), null ) : array( $v, sprintf( '%s must be a colour like #efe8dc.', $label ) );

		case 'date':
			if ( $v instanceof WC_DateTime ) return array( $v->date( 'Y-m-d' ), null );
			$v = trim( (string) $v );
			if ( '' === $v ) return array( '', null );
			$d = DateTime::createFromFormat( '!Y-m-d', $v );
			return ( $d && $d->format( 'Y-m-d' ) === $v ) ? array( $v, null ) : array( $v, sprintf( '%s must be a real date.', $label ) );

		case 'attachment':
			$id = absint( $v );
			if ( $id && ! wp_attachment_is_image( $id ) ) return array( $id, sprintf( '%s must be an uploaded image.', $label ) );
			return array( $id, null );

		case 'attachments':
		case 'ids':
			if ( is_string( $v ) ) $v = '' === $v ? array() : explode( ',', $v );
			$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $v ) ) ) );
			if ( 'attachments' === $def['type'] ) {
				foreach ( $ids as $id ) if ( ! wp_attachment_is_image( $id ) ) return array( $ids, sprintf( '%s can only contain uploaded images.', $label ) );
				return array( $ids, null );
			}
			sort( $ids );
			foreach ( $ids as $id ) {
				$t = get_term( $id, $def['taxonomy'] );
				if ( ! $t || is_wp_error( $t ) ) return array( $ids, sprintf( '%s contains something that no longer exists.', $label ) );
			}
			return array( $ids, null );
	}
	return array( $v, 'Unknown field type.' );
}

/** Live values of every field, cleaned to the same shapes the Studio sends. */
function fisha_studio_product_read( WC_Product $p ): array {
	$out = array();
	foreach ( fisha_studio_product_fields() as $key => $def ) {
		$out[ $key ] = fisha_studio_clean( $def, call_user_func( $def['get'], $p ) )[0];
	}
	return $out;
}

/** Cleans the known keys of $input. Returns [values, errors] (errors keyed by field). */
function fisha_studio_sanitize( array $schema, array $input ): array {
	$values = array();
	$errors = array();
	foreach ( $input as $key => $v ) {
		if ( ! isset( $schema[ $key ] ) ) continue;
		list( $clean, $err ) = fisha_studio_clean( $schema[ $key ], $v );
		$values[ $key ] = $clean;
		if ( $err ) $errors[ $key ] = $err;
	}
	return array( $values, $errors );
}

/** Sets values on the product object (does not save). */
function fisha_studio_product_apply( WC_Product $p, array $values ): void {
	$schema = fisha_studio_product_fields();
	foreach ( $values as $key => $v ) {
		if ( isset( $schema[ $key ] ) ) call_user_func( $schema[ $key ]['set'], $p, $v );
	}
}

/** Checks a complete set of values (live merged with draft). Returns errors keyed by field. */
function fisha_studio_product_validate( array $v, WC_Product $p ): array {
	$e = array();
	foreach ( fisha_studio_product_fields() as $key => $def ) {
		if ( empty( $def['required'] ) ) continue;
		$val = $v[ $key ] ?? null;
		if ( '' === $val || null === $val || 0 === $val || array() === $val ) $e[ $key ] = sprintf( '%s is needed before publishing.', $def['label'] );
	}
	$sale    = (string) ( $v['sale_price'] ?? '' );
	$regular = (string) ( $v['regular_price'] ?? '' );
	if ( '' !== $sale ) {
		if ( '' === $regular ) $e['sale_price'] = 'Set the normal price before a sale price.';
		elseif ( (float) $sale >= (float) $regular ) $e['sale_price'] = 'The sale price must be lower than the normal price.';
	}
	$from = (string) ( $v['date_on_sale_from'] ?? '' );
	$to   = (string) ( $v['date_on_sale_to'] ?? '' );
	if ( $from && $to && $from > $to ) $e['date_on_sale_to'] = 'The sale must end after it starts.';
	$sku = (string) ( $v['sku'] ?? '' );
	if ( '' !== $sku && ! wc_product_has_unique_sku( $p->get_id(), $sku ) ) $e['sku'] = 'Another product already uses this SKU.';
	if ( ! empty( $v['manage_stock'] ) && null === ( $v['stock_quantity'] ?? null ) ) $e['stock_quantity'] = 'Enter how many are in stock.';
	// Only a new or changed address is checked: some existing slugs predate these rules and must keep working.
	$slug   = (string) ( $v['slug'] ?? '' );
	$is_new = in_array( get_post_status( $p->get_id() ), array( 'draft', 'auto-draft', 'pending' ), true );
	if ( $is_new || $slug !== $p->get_slug( 'edit' ) ) {
		$slug_error = fisha_studio_slug_error( $slug );
		if ( $slug_error ) $e['slug'] = $slug_error;
	}
	return $e;
}

function fisha_studio_same( $a, $b ): bool {
	return wp_json_encode( $a ) === wp_json_encode( $b );
}
```

Append to `studio/studio.php`:
```php
require_once FISHA_STUDIO_DIR . 'studio-urls.php';
require_once FISHA_STUDIO_DIR . 'studio-fields.php';
```

- [ ] **Step 5: Run to verify pass**

Run: `bash tests/studio/run.sh urls` then `bash tests/studio/run.sh fields`
Expected: `3 passed` and `6 passed`, 0 failed. If the round-trip test fails on one key, the message names it (e.g. `menu_order: expected -3, got 0`): fix that field's `get`/`set`, not the test.

- [ ] **Step 6: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio: product field schema, cleaning, validation and URL rules

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Audit log and error log

**Files:**
- Create: `studio/studio-audit.php`
- Modify: `studio/studio.php` (require)
- Test: `tests/studio/test-audit.php`

**Interfaces:**
- Produces: `fisha_studio_log_table(): string`; `fisha_studio_install_log(): void` (on `init`@6, versioned by option `fisha_studio_log_db`); `fisha_studio_log( string $type, int $id, string $action, ?array $before, ?array $after ): int` (insert id, 0 on failure); `fisha_studio_log_for( string $type, int $id, int $limit = 20 ): array` (newest first; rows with decoded `before`/`after`); `fisha_studio_log_error( string $context, Throwable $e ): void` (writes `wp-content/fisha-studio-logs/studio.log`, folder denied to the web). Cron hook `fisha_studio_prune` (daily, deletes rows older than 365 days).

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-audit.php`:
```php
<?php
fs_test( 'audit: table exists and rows round-trip', function () {
	global $wpdb;
	fs_eq( fisha_studio_log_table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', fisha_studio_log_table() ) ) );
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', fisha_studio_log_table() ) );
	fs_eq( 'InnoDB', $engine, 'needed for publish transactions' );
	$p = fs_product();
	fs_as( FISHA_STUDIO_ROLE );
	$id = fisha_studio_log( 'product', $p->get_id(), 'publish', array( 'name' => 'Old' ), array( 'name' => 'New' ) );
	fs_true( $id > 0, 'insert id' );
	$rows = fisha_studio_log_for( 'product', $p->get_id() );
	fs_eq( 1, count( $rows ) );
	fs_eq( 'Old', $rows[0]['before']['name'] );
	fs_eq( 'New', $rows[0]['after']['name'] );
	fs_eq( get_current_user_id(), (int) $rows[0]['user_id'] );
} );

fs_test( 'audit: prune removes rows older than a year', function () {
	global $wpdb;
	$p = fs_product();
	$old = fisha_studio_log( 'product', $p->get_id(), 'publish', null, array() );
	$wpdb->update( fisha_studio_log_table(), array( 'created' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ), array( 'id' => $old ) );
	$new = fisha_studio_log( 'product', $p->get_id(), 'publish', null, array() );
	do_action( 'fisha_studio_prune' );
	fs_eq( array( $new ), array_map( 'intval', wp_list_pluck( fisha_studio_log_for( 'product', $p->get_id() ), 'id' ) ) );
} );

fs_test( 'audit: error log folder is closed to the web', function () {
	fisha_studio_log_error( 'test', new RuntimeException( 'zz-test error' ) );
	$dir = WP_CONTENT_DIR . '/fisha-studio-logs';
	fs_true( file_exists( "$dir/.htaccess" ) && false !== strpos( file_get_contents( "$dir/.htaccess" ), 'Require all denied' ), '.htaccess denies' );
	fs_true( false !== strpos( file_get_contents( "$dir/studio.log" ), 'zz-test error' ), 'message written' );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh audit`
Expected: FAIL, undefined `fisha_studio_log_table`.

- [ ] **Step 3: Implement**

`studio/studio-audit.php`:
```php
<?php
/**
 * Fisha Studio audit log: who changed what and when, with the values before and after (kept 365 days).
 * Also the Studio's error log, in a folder the web server refuses to serve.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_LOG_DB', 1 );

function fisha_studio_log_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'fisha_studio_log';
}

function fisha_studio_install_log(): void {
	if ( (int) get_option( 'fisha_studio_log_db', 0 ) >= FISHA_STUDIO_LOG_DB ) return;
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . fisha_studio_log_table() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  item_type varchar(20) NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  action varchar(20) NOT NULL,
  before_json longtext NULL,
  after_json longtext NULL,
  PRIMARY KEY  (id),
  KEY item (item_type,item_id),
  KEY created (created)
) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';' );
	update_option( 'fisha_studio_log_db', FISHA_STUDIO_LOG_DB );
}
add_action( 'init', 'fisha_studio_install_log', 6 );

function fisha_studio_log( string $type, int $id, string $action, ?array $before, ?array $after ): int {
	global $wpdb;
	$ok = $wpdb->insert( fisha_studio_log_table(), array(
		'created'     => current_time( 'mysql', true ),
		'user_id'     => get_current_user_id(),
		'item_type'   => $type,
		'item_id'     => $id,
		'action'      => $action,
		'before_json' => null === $before ? null : wp_json_encode( $before ),
		'after_json'  => null === $after ? null : wp_json_encode( $after ),
	) );
	return $ok ? (int) $wpdb->insert_id : 0;
}

function fisha_studio_log_for( string $type, int $id, int $limit = 20 ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . fisha_studio_log_table() . ' WHERE item_type = %s AND item_id = %d ORDER BY id DESC LIMIT %d', $type, $id, $limit
	), ARRAY_A );
	foreach ( $rows as &$r ) {
		$r['before'] = null === $r['before_json'] ? null : json_decode( $r['before_json'], true );
		$r['after']  = null === $r['after_json'] ? null : json_decode( $r['after_json'], true );
		unset( $r['before_json'], $r['after_json'] );
	}
	return $rows;
}

add_action( 'fisha_studio_prune', function () {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . fisha_studio_log_table() . ' WHERE created < %s', gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS ) ) );
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'fisha_studio_prune' ) ) wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fisha_studio_prune' );
} );

function fisha_studio_log_error( string $context, Throwable $e ): void {
	$dir = WP_CONTENT_DIR . '/fisha-studio-logs';
	if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
	if ( ! file_exists( "$dir/.htaccess" ) ) file_put_contents( "$dir/.htaccess", "Require all denied\nDeny from all\n" );
	if ( ! file_exists( "$dir/index.php" ) ) file_put_contents( "$dir/index.php", "<?php // Silence.\n" );
	error_log( gmdate( 'c' ) . " [$context] " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", 3, "$dir/studio.log" );
}
```

Append to `studio/studio.php`:
```php
require_once FISHA_STUDIO_DIR . 'studio-audit.php';
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh audit`
Expected: `3 passed, 0 failed`

- [ ] **Step 5: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio: audit log table and protected error log

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Drafts, defaults and conflicts

**Files:**
- Create: `studio/studio-drafts.php`
- Modify: `studio/studio.php` (require)
- Test: `tests/studio/test-drafts.php`

**Interfaces:**
- Consumes: Task 5 (`fisha_studio_product_read`, `fisha_studio_product_apply`, `fisha_studio_same`, `fisha_studio_slug_from_name`, `fisha_studio_product_fields`).
- Produces: constant `FISHA_STUDIO_DRAFT_META` (`'_fisha_studio_draft'`); `fisha_studio_is_new( WC_Product $p ): bool`; `fisha_studio_draft_get( int $id ): array{fields:array,base:array,user:int,updated:int}`; `fisha_studio_draft_save( WC_Product $p, array $values ): array`; `fisha_studio_draft_discard( int $id ): void`; `fisha_studio_conflicts( WC_Product $p, array $draft ): array<array{field:string,label:string,base:mixed,live:mixed,mine:mixed}>`; `fisha_studio_product_create( string $name ): WC_Product_Simple` (draft status); `fisha_studio_product_edit( WC_Product $p, array $values ): void` (new item → applied directly; live item → draft); `fisha_studio_target_status( WC_Product $p ): string` (`publish|private`); `fisha_studio_default_card_bg( array $cat_ids ): string`.
- Meta used on new items: `_fisha_studio_target_status`, `_fisha_studio_slug_manual`.

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-drafts.php`:
```php
<?php
fs_test( 'drafts: editing a live product leaves it untouched', function () {
	$p = fs_product( array( 'regular_price' => '100' ) );
	fs_as( FISHA_STUDIO_ROLE );
	fisha_studio_product_edit( $p, array( 'regular_price' => '120', 'name' => 'zz-test Changed' ) );
	$live = wc_get_product( $p->get_id() );
	fs_eq( '100', $live->get_regular_price() );
	$d = fisha_studio_draft_get( $p->get_id() );
	fs_eq( array( 'regular_price' => '120', 'name' => 'zz-test Changed' ), $d['fields'] );
	fs_eq( '100', $d['base']['regular_price'], 'base remembers the live value' );
	fs_eq( get_current_user_id(), $d['user'] );
} );

fs_test( 'drafts: two saves merge', function () {
	$p = fs_product();
	fisha_studio_product_edit( $p, array( 'regular_price' => '120' ) );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'sku' => 'ZZ-TEST-MERGE' ) );
	$d = fisha_studio_draft_get( $p->get_id() );
	fs_eq( '120', $d['fields']['regular_price'], 'first tab change kept' );
	fs_eq( 'ZZ-TEST-MERGE', $d['fields']['sku'], 'second tab change kept' );
} );

fs_test( 'drafts: changing a value back removes it from the draft', function () {
	$p = fs_product( array( 'regular_price' => '100' ) );
	fisha_studio_product_edit( $p, array( 'regular_price' => '120' ) );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'regular_price' => '100' ) );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	fs_eq( '', get_post_meta( $p->get_id(), FISHA_STUDIO_DRAFT_META, true ), 'meta removed' );
} );

fs_test( 'drafts: quotes and backslashes survive', function () {
	$p = fs_product();
	fisha_studio_product_edit( $p, array( 'short_description' => '<p>Fisha\'s "best" \\ fish</p>' ) );
	fs_eq( '<p>Fisha\'s "best" \\ fish</p>', fisha_studio_draft_get( $p->get_id() )['fields']['short_description'] );
} );

fs_test( 'drafts: discard', function () {
	$p = fs_product();
	fisha_studio_product_edit( $p, array( 'regular_price' => '1' ) );
	fisha_studio_draft_discard( $p->get_id() );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'] );
} );

fs_test( 'drafts: new product is a WooCommerce draft and edits apply directly', function () {
	$cat = fs_term( 'drafts-new' );
	$p   = fisha_studio_product_create( 'zz-test Blue Reed Fisha' );
	fs_on_cleanup( function () use ( $p ) { wp_delete_post( $p->get_id(), true ); } );
	fs_eq( 'draft', get_post_status( $p->get_id() ) );
	fs_true( fisha_studio_is_new( $p ) );
	fs_eq( 'zz-test-blue-reed-fisha', $p->get_slug(), 'slug from the name' );
	fisha_studio_product_edit( $p, array( 'name' => 'zz-test Green Reed Fisha', 'category_ids' => array( $cat->term_id ), 'status' => 'private' ) );
	$p = wc_get_product( $p->get_id() );
	fs_eq( 'zz-test Green Reed Fisha', $p->get_name(), 'applied directly' );
	fs_eq( 'zz-test-green-reed-fisha', $p->get_slug(), 'slug follows the name until edited' );
	fs_eq( 'draft', get_post_status( $p->get_id() ), 'still a draft' );
	fs_eq( 'private', fisha_studio_target_status( $p ), 'chosen visibility remembered' );
	fs_eq( '#efe8dc', $p->get_meta( '_fisha_card_bg' ), 'card colour default for an empty category' );
	fisha_studio_product_edit( $p, array( 'slug' => 'zz-test-my-own-url' ) );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'name' => 'zz-test Renamed Again' ) );
	fs_eq( 'zz-test-my-own-url', wc_get_product( $p->get_id() )->get_slug(), 'typed slug is kept' );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'], 'no draft meta for new items' );
} );

fs_test( 'drafts: card colour default follows the category', function () {
	$cat = fs_term( 'drafts-colour' );
	foreach ( array( '#E3ECEF', '#e3ecef', '#ffffff' ) as $colour ) {
		$q = fs_product( array( 'category_ids' => array( $cat->term_id ) ) );
		$q->update_meta_data( '_fisha_card_bg', $colour );
		$q->save();
	}
	fs_eq( '#e3ecef', fisha_studio_default_card_bg( array( $cat->term_id ) ) );
} );

fs_test( 'drafts: conflicts only when the live value moved under an edited field', function () {
	$p = fs_product( array( 'manage_stock' => true, 'stock_quantity' => 3, 'regular_price' => '100' ) );
	fisha_studio_product_edit( $p, array( 'stock_quantity' => 5, 'regular_price' => '110' ) );
	fs_eq( array(), fisha_studio_conflicts( wc_get_product( $p->get_id() ), fisha_studio_draft_get( $p->get_id() ) ), 'nothing moved yet' );
	wc_update_product_stock( $p->get_id(), 2, 'set' );                          // an order came in
	$c = fisha_studio_conflicts( wc_get_product( $p->get_id() ), fisha_studio_draft_get( $p->get_id() ) );
	fs_eq( 1, count( $c ) );
	fs_eq( 'stock_quantity', $c[0]['field'] );
	fs_eq( 3, $c[0]['base'] );
	fs_eq( 2, $c[0]['live'] );
	fs_eq( 5, $c[0]['mine'] );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh drafts`
Expected: FAIL, undefined `fisha_studio_product_edit`.

- [ ] **Step 3: Implement**

`studio/studio-drafts.php`:
```php
<?php
/**
 * Fisha Studio drafts. Edits to a live product are kept in one post meta ({fields, base, user, updated}) until
 * Publish; "base" remembers the live value of each edited field at the moment editing started, so a change made
 * on the site meanwhile (an order lowering stock) is noticed. A product created in the Studio is a normal
 * WooCommerce draft: its edits apply directly and customers cannot see it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_DRAFT_META', '_fisha_studio_draft' );

function fisha_studio_is_new( WC_Product $p ): bool {
	return in_array( get_post_status( $p->get_id() ), array( 'draft', 'auto-draft', 'pending' ), true );
}

function fisha_studio_draft_get( int $id ): array {
	$d = get_post_meta( $id, FISHA_STUDIO_DRAFT_META, true );
	$empty = array( 'fields' => array(), 'base' => array(), 'user' => 0, 'updated' => 0 );
	return ( is_array( $d ) && isset( $d['fields'] ) && is_array( $d['fields'] ) ) ? array_merge( $empty, $d ) : $empty;
}

function fisha_studio_draft_save( WC_Product $p, array $values ): array {
	$id   = $p->get_id();
	$d    = fisha_studio_draft_get( $id );
	$live = fisha_studio_product_read( $p );
	foreach ( $values as $k => $v ) {
		if ( ! array_key_exists( $k, $live ) ) continue;
		if ( ! array_key_exists( $k, $d['base'] ) ) $d['base'][ $k ] = $live[ $k ];
		$d['fields'][ $k ] = $v;
		if ( fisha_studio_same( $v, $live[ $k ] ) && fisha_studio_same( $d['base'][ $k ], $live[ $k ] ) ) {
			unset( $d['fields'][ $k ], $d['base'][ $k ] );
		}
	}
	if ( ! $d['fields'] ) {
		delete_post_meta( $id, FISHA_STUDIO_DRAFT_META );
		return fisha_studio_draft_get( $id );
	}
	$d['user']    = get_current_user_id();
	$d['updated'] = time();
	update_post_meta( $id, FISHA_STUDIO_DRAFT_META, wp_slash( $d ) );
	return $d;
}

function fisha_studio_draft_discard( int $id ): void {
	delete_post_meta( $id, FISHA_STUDIO_DRAFT_META );
}

function fisha_studio_conflicts( WC_Product $p, array $draft ): array {
	$live   = fisha_studio_product_read( $p );
	$schema = fisha_studio_product_fields();
	$out    = array();
	foreach ( $draft['fields'] as $k => $mine ) {
		if ( ! array_key_exists( $k, $draft['base'] ) || ! array_key_exists( $k, $live ) ) continue;
		if ( fisha_studio_same( $draft['base'][ $k ], $live[ $k ] ) || fisha_studio_same( $mine, $live[ $k ] ) ) continue;
		$out[] = array( 'field' => $k, 'label' => $schema[ $k ]['label'] ?? $k, 'base' => $draft['base'][ $k ], 'live' => $live[ $k ], 'mine' => $mine );
	}
	return $out;
}

function fisha_studio_target_status( WC_Product $p ): string {
	$s = get_post_meta( $p->get_id(), '_fisha_studio_target_status', true );
	return in_array( $s, array( 'publish', 'private' ), true ) ? $s : 'publish';
}

/** The most used card colour among products of these categories, or the template's own default. */
function fisha_studio_default_card_bg( array $cat_ids ): string {
	global $wpdb;
	$ids = implode( ',', array_filter( array_map( 'absint', $cat_ids ) ) );
	if ( ! $ids ) return '#efe8dc';
	$bg = $wpdb->get_var( "SELECT m.meta_value FROM {$wpdb->postmeta} m
		JOIN {$wpdb->term_relationships} r ON r.object_id = m.post_id
		JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
		WHERE m.meta_key = '_fisha_card_bg' AND t.taxonomy = 'product_cat' AND t.term_id IN ($ids)
		GROUP BY m.meta_value ORDER BY COUNT(*) DESC LIMIT 1" );
	$bg = $bg ? sanitize_hex_color( $bg ) : '';
	return $bg ? strtolower( $bg ) : '#efe8dc';
}

function fisha_studio_product_create( string $name ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( 'draft' );
	$p->set_slug( fisha_studio_slug_from_name( $name ) );
	$p->set_catalog_visibility( 'visible' );
	$p->set_reviews_allowed( false );
	$p->save();
	return $p;
}

/** The one entry point for an edit coming from the Studio. $values must already be cleaned. */
function fisha_studio_product_edit( WC_Product $p, array $values ): void {
	if ( ! fisha_studio_is_new( $p ) ) {
		fisha_studio_draft_save( $p, $values );
		return;
	}
	$id = $p->get_id();
	if ( array_key_exists( 'status', $values ) ) {
		update_post_meta( $id, '_fisha_studio_target_status', 'private' === $values['status'] ? 'private' : 'publish' );
		unset( $values['status'] );
	}
	if ( array_key_exists( 'slug', $values ) ) {
		$manual = '' !== $values['slug'];
		update_post_meta( $id, '_fisha_studio_slug_manual', $manual ? '1' : '' );
		if ( ! $manual ) $values['slug'] = fisha_studio_slug_from_name( $values['name'] ?? $p->get_name( 'edit' ) );
	}
	if ( isset( $values['name'] ) && ! get_post_meta( $id, '_fisha_studio_slug_manual', true ) && ! array_key_exists( 'slug', $values ) ) {
		$values['slug'] = fisha_studio_slug_from_name( $values['name'] );
	}
	fisha_studio_product_apply( $p, $values );
	if ( '' === (string) $p->get_meta( '_fisha_card_bg', true, 'edit' ) && $p->get_category_ids( 'edit' ) ) {
		$p->update_meta_data( '_fisha_card_bg', fisha_studio_default_card_bg( $p->get_category_ids( 'edit' ) ) );
	}
	if ( '' === (string) $p->get_meta( '_fisha_card_bg', true, 'edit' ) ) $p->update_meta_data( '_fisha_card_bg', '#efe8dc' );
	$p->save();
}
```

Append to `studio/studio.php`:
```php
require_once FISHA_STUDIO_DIR . 'studio-drafts.php';
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh drafts`
Expected: `8 passed, 0 failed`

- [ ] **Step 5: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio: server-side drafts, new-product flow, defaults and conflict detection

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Publish, trash and cache purge

**Files:**
- Create: `studio/studio-publish.php`
- Modify: `studio/studio.php` (require)
- Test: `tests/studio/test-publish.php`

**Interfaces:**
- Consumes: Tasks 5–7.
- Produces: `fisha_studio_publish_product( int $id, array $resolutions = [], bool $change_url = false ): array{id:int,url:string}|WP_Error` — error codes: `fisha_studio_not_found` (404), `fisha_studio_unsupported` (400), `fisha_studio_conflict` (409, `data.conflicts`), `fisha_studio_invalid` (422, `data.fields`), `fisha_studio_nothing` (400), `fisha_studio_publish_failed` (500). Action `fisha_studio_before_commit( int $id )` (fires inside the transaction; tests use it to force a failure). `fisha_studio_trash_product( int $id ): true|WP_Error`; `fisha_studio_purge_product( WC_Product $p, array $extra_cat_ids = [] ): void`; `fisha_studio_first_menu_order( array $cat_ids ): int`; `fisha_studio_default_alts( WC_Product $p ): void`.

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-publish.php`:
```php
<?php
function fs_complete_product( array $props = array() ): WC_Product_Simple {
	return fs_product( array_merge( array( 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'publish-' . wp_rand() )->term_id ) ), $props ) );
}

fs_test( 'publish: draft goes live and the draft is cleared', function () {
	$p = fs_complete_product( array( 'regular_price' => '100' ) );
	fs_as( FISHA_STUDIO_ROLE );
	fisha_studio_product_edit( $p, array( 'regular_price' => '120' ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
	fs_eq( '120', wc_get_product( $p->get_id() )->get_regular_price() );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	$log = fisha_studio_log_for( 'product', $p->get_id() );
	fs_eq( 'publish', $log[0]['action'] );
	fs_eq( '100', $log[0]['before']['regular_price'] );
	fs_eq( '120', $log[0]['after']['regular_price'] );
} );

fs_test( 'publish: invalid draft writes nothing', function () {
	$p = fs_complete_product( array( 'regular_price' => '100' ) );
	fisha_studio_product_edit( $p, array( 'regular_price' => '90', 'sale_price' => '95' ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'fisha_studio_invalid', $r->get_error_code() );
	fs_eq( 422, $r->get_error_data()['status'] );
	fs_true( isset( $r->get_error_data()['fields']['sale_price'] ) );
	fs_eq( '100', wc_get_product( $p->get_id() )->get_regular_price(), 'live untouched' );
	fs_eq( '90', fisha_studio_draft_get( $p->get_id() )['fields']['regular_price'], 'draft kept' );
} );

fs_test( 'publish: conflict must be resolved, then keep live or mine', function () {
	$p = fs_complete_product( array( 'manage_stock' => true, 'stock_quantity' => 3 ) );
	fisha_studio_product_edit( $p, array( 'stock_quantity' => 5, 'regular_price' => '150' ) );
	wc_update_product_stock( $p->get_id(), 2, 'set' );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'fisha_studio_conflict', $r->get_error_code() );
	fs_eq( 'stock_quantity', $r->get_error_data()['conflicts'][0]['field'] );
	$ok = fisha_studio_publish_product( $p->get_id(), array( 'stock_quantity' => 'live' ) );
	fs_false( is_wp_error( $ok ) );
	$after = wc_get_product( $p->get_id() );
	fs_eq( 2, $after->get_stock_quantity(), 'kept the site value' );
	fs_eq( '150', $after->get_regular_price(), 'other changes published' );
} );

fs_test( 'publish: a forced failure rolls everything back', function () {
	$p = fs_complete_product( array( 'regular_price' => '100' ) );
	fisha_studio_product_edit( $p, array( 'regular_price' => '130', 'name' => 'zz-test Rolled Back' ) );
	$boom = function () { throw new RuntimeException( 'zz-test forced failure' ); };
	add_action( 'fisha_studio_before_commit', $boom );
	fs_on_cleanup( function () use ( $boom ) { remove_action( 'fisha_studio_before_commit', $boom ); } );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'fisha_studio_publish_failed', $r->get_error_code() );
	clean_post_cache( $p->get_id() );
	$fresh = wc_get_product( $p->get_id() );
	fs_eq( '100', $fresh->get_regular_price(), 'price rolled back' );
	fs_true( 0 !== strpos( $fresh->get_name(), 'zz-test Rolled Back' ), 'name rolled back' );
	fs_eq( '130', fisha_studio_draft_get( $p->get_id() )['fields']['regular_price'], 'draft still there' );
	fs_eq( array(), fisha_studio_log_for( 'product', $p->get_id() ), 'no audit row' );
} );

fs_test( 'publish: new product goes live at /product/{slug}/ first in its category with alt texts', function () {
	$cat   = fs_term( 'publish-new' );
	$first = fs_product( array( 'category_ids' => array( $cat->term_id ), 'menu_order' => 0 ) );
	$p     = fisha_studio_product_create( 'zz-test Night Swim' );
	fs_on_cleanup( function () use ( $p ) { wp_delete_post( $p->get_id(), true ); } );
	$img = fs_image();
	update_post_meta( $img, '_wp_attachment_image_alt', '' );
	fisha_studio_product_edit( $p, array( 'category_ids' => array( $cat->term_id ), 'image_id' => $img, 'short_description' => '<p>Short.</p>', 'regular_price' => '80' ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	$live = wc_get_product( $p->get_id() );
	fs_eq( 'publish', $live->get_status() );
	fs_eq( home_url( '/product/zz-test-night-swim/' ), get_permalink( $p->get_id() ) );
	fs_true( $live->get_menu_order() < wc_get_product( $first->get_id() )->get_menu_order(), 'first in its category' );
	fs_eq( 'zz-test Night Swim by Fisha', get_post_meta( $img, '_wp_attachment_image_alt', true ) );
	fs_eq( '/%postname%/', get_option( 'permalink_structure' ), 'permalinks untouched' );
	fs_eq( 'product', wc_get_permalink_structure()['product_rewrite_slug'] );
} );

fs_test( 'publish: renaming keeps the address; changing it needs change_url and leaves a redirect', function () {
	$p = fs_complete_product( array( 'slug' => 'zz-test-keep-me' ) );
	fisha_studio_product_edit( $p, array( 'name' => 'zz-test Totally New Name' ) );
	fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'zz-test-keep-me', wc_get_product( $p->get_id() )->get_slug(), 'rename keeps slug' );

	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'slug' => 'zz-test-moved' ) );
	fs_eq( 'fisha_studio_invalid', fisha_studio_publish_product( $p->get_id() )->get_error_code(), 'needs change_url' );
	fs_false( is_wp_error( fisha_studio_publish_product( $p->get_id(), array(), true ) ) );
	fs_eq( 'zz-test-moved', wc_get_product( $p->get_id() )->get_slug() );
	fs_true( in_array( 'zz-test-keep-me', get_post_meta( $p->get_id(), '_wp_old_slug' ), true ), 'WordPress will 301 the old address' );
} );

fs_test( 'publish: variable products are refused for now', function () {
	$v = new WC_Product_Variable();
	$v->set_name( 'zz-test variable' );
	$id = $v->save();
	fs_on_cleanup( function () use ( $id ) { wp_delete_post( $id, true ); } );
	fs_eq( 'fisha_studio_unsupported', fisha_studio_publish_product( $id )->get_error_code() );
} );

fs_test( 'publish: trash is restorable', function () {
	$p = fs_complete_product();
	fs_as( FISHA_STUDIO_ROLE );
	fs_true( true === fisha_studio_trash_product( $p->get_id() ) );
	fs_eq( 'trash', get_post_status( $p->get_id() ) );
	fs_eq( 'trash', fisha_studio_log_for( 'product', $p->get_id() )[0]['action'] );
	fs_true( wp_untrash_post( $p->get_id() ), 'can be restored' );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh publish`
Expected: FAIL, undefined `fisha_studio_publish_product`.

- [ ] **Step 3: Implement**

`studio/studio-publish.php`:
```php
<?php
/**
 * Fisha Studio publish: validate the whole product, resolve conflicts, snapshot the live state into the audit log,
 * then apply the draft through WooCommerce inside one database transaction (all or nothing) and purge caches.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** @return array{id:int,url:string}|WP_Error */
function fisha_studio_publish_product( int $id, array $resolutions = array(), bool $change_url = false ) {
	$p = wc_get_product( $id );
	if ( ! $p || 'trash' === get_post_status( $id ) ) return new WP_Error( 'fisha_studio_not_found', 'This product no longer exists.', array( 'status' => 404 ) );
	if ( ! $p->is_type( 'simple' ) ) return new WP_Error( 'fisha_studio_unsupported', 'Products with sizes or options can be published from the Studio soon. Nothing was changed.', array( 'status' => 400 ) );

	$is_new  = fisha_studio_is_new( $p );
	$draft   = fisha_studio_draft_get( $id );
	$before  = fisha_studio_product_read( $p );
	$changes = $draft['fields'];

	if ( ! $is_new ) {
		$conflicts = fisha_studio_conflicts( $p, $draft );
		$open      = array_values( array_filter( $conflicts, function ( $c ) use ( $resolutions ) {
			return ! in_array( $resolutions[ $c['field'] ] ?? '', array( 'mine', 'live' ), true );
		} ) );
		if ( $open ) return new WP_Error( 'fisha_studio_conflict', 'Some details changed on the site while you were editing.', array( 'status' => 409, 'conflicts' => $open ) );
		foreach ( $conflicts as $c ) {
			if ( 'live' === $resolutions[ $c['field'] ] ) unset( $changes[ $c['field'] ] );
		}
		if ( isset( $changes['slug'] ) && $changes['slug'] !== $before['slug'] && ! $change_url ) {
			return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => array( 'slug' => 'This product is live. Use "Change address" to change its URL.' ) ) );
		}
		if ( ! $changes ) return new WP_Error( 'fisha_studio_nothing', 'There are no changes to publish.', array( 'status' => 400 ) );
	} else {
		$changes['status'] = fisha_studio_target_status( $p );
	}

	$after  = array_merge( $before, $changes );
	$errors = fisha_studio_product_validate( $after, $p );
	if ( $errors ) return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => $errors ) );

	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		fisha_studio_product_apply( $p, $changes );
		if ( $is_new ) {
			$p->set_slug( fisha_studio_unique_product_slug( $after['slug'] ?: fisha_studio_slug_from_name( $after['name'] ), $id ) );
			$p->set_menu_order( fisha_studio_first_menu_order( $after['category_ids'] ) );
			$p->delete_meta_data( '_fisha_studio_target_status' );
			$p->delete_meta_data( '_fisha_studio_slug_manual' );
		}
		$p->delete_meta_data( FISHA_STUDIO_DRAFT_META );
		$p->save();
		fisha_studio_default_alts( $p );
		do_action( 'fisha_studio_before_commit', $id );
		$fresh = wc_get_product( $id );
		if ( ! fisha_studio_log( 'product', $id, $is_new ? 'create' : 'publish', $is_new ? null : $before, fisha_studio_product_read( $fresh ) ) ) {
			throw new RuntimeException( 'audit log write failed: ' . $wpdb->last_error );
		}
		$wpdb->query( 'COMMIT' );
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		clean_post_cache( $id );
		clean_object_term_cache( $id, 'product' );
		wc_delete_product_transients( $id );
		if ( class_exists( 'WC_Cache_Helper' ) ) WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
		fisha_studio_log_error( 'publish product ' . $id, $e );
		return new WP_Error( 'fisha_studio_publish_failed', 'Publishing failed, so nothing was changed. Please try again.', array( 'status' => 500 ) );
	}

	fisha_studio_purge_product( wc_get_product( $id ), $before['category_ids'] );
	return array( 'id' => $id, 'url' => get_permalink( $id ) );
}

function fisha_studio_first_menu_order( array $cat_ids ): int {
	global $wpdb;
	$ids = implode( ',', array_filter( array_map( 'absint', $cat_ids ) ) );
	if ( ! $ids ) return 0;
	$min = $wpdb->get_var( "SELECT MIN(p.menu_order) FROM {$wpdb->posts} p
		JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
		JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
		WHERE p.post_type = 'product' AND p.post_status IN ('publish','private') AND t.taxonomy = 'product_cat' AND t.term_id IN ($ids)" );
	return null === $min ? 0 : (int) $min - 1;
}

/** Images without alt text get "{name} by Fisha". */
function fisha_studio_default_alts( WC_Product $p ): void {
	foreach ( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) as $aid ) {
		if ( $aid && '' === trim( (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ) ) ) {
			update_post_meta( $aid, '_wp_attachment_image_alt', $p->get_name() . ' by Fisha' );
		}
	}
}

function fisha_studio_purge_product( WC_Product $p, array $extra_cat_ids = array() ): void {
	$urls = array( get_permalink( $p->get_id() ), get_permalink( wc_get_page_id( 'shop' ) ), home_url( '/' ) );
	foreach ( array_unique( array_merge( $p->get_category_ids(), $extra_cat_ids ) ) as $cid ) {
		foreach ( array_merge( array( $cid ), get_ancestors( $cid, 'product_cat' ) ) as $tid ) {
			$link = get_term_link( (int) $tid, 'product_cat' );
			if ( ! is_wp_error( $link ) ) $urls[] = $link;
		}
	}
	foreach ( array_unique( array_filter( $urls ) ) as $url ) do_action( 'litespeed_purge_url', $url );
	do_action( 'litespeed_purge_post', $p->get_id() );
	wc_delete_product_transients( $p->get_id() );
}

/** @return true|WP_Error */
function fisha_studio_trash_product( int $id ) {
	$p = wc_get_product( $id );
	if ( ! $p ) return new WP_Error( 'fisha_studio_not_found', 'This product no longer exists.', array( 'status' => 404 ) );
	$before = fisha_studio_product_read( $p );
	if ( ! wp_trash_post( $id ) ) return new WP_Error( 'fisha_studio_trash_failed', 'The product could not be moved to the trash.', array( 'status' => 500 ) );
	fisha_studio_log( 'product', $id, 'trash', $before, null );
	fisha_studio_purge_product( $p );
	return true;
}
```

Append to `studio/studio.php`:
```php
require_once FISHA_STUDIO_DIR . 'studio-publish.php';
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh publish`
Expected: `8 passed, 0 failed`. If "a forced failure rolls everything back" fails on the name/price, check that the WooCommerce lookup tables are InnoDB (`SHOW TABLE STATUS LIKE 'wp_wc_product_meta_lookup'`) and that nothing between `START TRANSACTION` and the action causes an implicit commit.

- [ ] **Step 5: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio: transactional publish with conflicts, URL change rules, trash and cache purge

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 9: Live preview (token + draft overlay)

**Files:**
- Create: `studio/studio-preview.php`
- Modify: `studio/studio.php` (require)
- Test: `tests/studio/test-preview.php`

**Interfaces:**
- Consumes: Tasks 5, 7 (`fisha_studio_product_read`, `fisha_studio_product_fields`, `fisha_studio_draft_get`, `fisha_studio_is_new`).
- Produces: `fisha_studio_preview_token( int $user_id, string $type, int $id, int $expires = 0 ): string`; `fisha_studio_preview_verify( string $token ): ?array{type:string,id:int}`; `fisha_studio_preview_install_product( int $id, array $fields ): void`; `fisha_studio_preview_uninstall(): void`; `fisha_studio_preview_active(): bool`; `fisha_studio_preview_targets( WC_Product $p, array $cat_ids ): array<array{key:string,label:string,url:string}>` (keys `item`, `category` (when the product has one), `shop`, `home`); `fisha_studio_effective_stock_status( array $values ): string`; `fisha_studio_effective_price( array $values ): string`. Query arg: `fisha_preview`.

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-preview.php`:
```php
<?php
fs_test( 'preview: tokens only work for the person they were made for', function () {
	$owner = fs_as( FISHA_STUDIO_ROLE );
	$tok   = fisha_studio_preview_token( $owner->ID, 'product', 123 );
	fs_eq( array( 'type' => 'product', 'id' => 123 ), fisha_studio_preview_verify( $tok ) );
	fs_eq( null, fisha_studio_preview_verify( $tok . 'x' ), 'tampered signature' );
	fs_eq( null, fisha_studio_preview_verify( str_replace( '-123-', '-124-', $tok ) ), 'tampered id' );
	fs_eq( null, fisha_studio_preview_verify( fisha_studio_preview_token( $owner->ID, 'product', 123, time() - 1 ) ), 'expired' );
	fs_eq( null, fisha_studio_preview_verify( fisha_studio_preview_token( $owner->ID, 'page', 2 ) ), 'unknown type' );
	fs_eq( null, fisha_studio_preview_verify( 'garbage' ) );
	fs_as( FISHA_STUDIO_ROLE );
	fs_eq( null, fisha_studio_preview_verify( $tok ), 'another owner' );
	wp_set_current_user( 0 );
	fs_eq( null, fisha_studio_preview_verify( $tok ), 'signed out' );
} );

fs_test( 'preview: draft values show through the normal APIs, only for that product', function () {
	$p     = fs_product( array( 'regular_price' => '100' ) );
	$other = fs_product();
	fisha_studio_product_edit( $p, array( 'name' => 'zz-test Preview Name', 'regular_price' => '150', 'card_bg' => '#123456', 'short_description' => '<p>Draft short</p>', 'description' => '<p>Draft long</p>' ) );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	$v = wc_get_product( $p->get_id() );
	fs_eq( 'zz-test Preview Name', $v->get_name() );
	fs_eq( '150', $v->get_price() );
	fs_eq( '150', $v->get_regular_price() );
	fs_eq( '#123456', get_post_meta( $p->get_id(), '_fisha_card_bg', true ) );
	fs_eq( 'zz-test Preview Name', apply_filters( 'the_title', 'x', $p->get_id() ) );
	fs_eq( '<p>Draft short</p>', apply_filters( 'get_the_excerpt', 'x', get_post( $p->get_id() ) ) );
	fs_eq( $other->get_name(), wc_get_product( $other->get_id() )->get_name(), 'other products untouched' );
	fs_eq( 'x', apply_filters( 'the_title', 'x', $other->get_id() ) );
	fs_eq( '100', wc_get_product( $p->get_id() )->get_regular_price( 'edit' ), 'edit context (what publish reads) untouched' );
	fisha_studio_preview_uninstall();
	fs_true( 'zz-test Preview Name' !== wc_get_product( $p->get_id() )->get_name(), 'gone after uninstall' );
} );

fs_test( 'preview: sold out and sale show the way they will after publish', function () {
	$p = fs_product( array( 'manage_stock' => true, 'stock_quantity' => 3, 'regular_price' => '100' ) );
	fisha_studio_product_edit( $p, array( 'stock_quantity' => 0, 'sale_price' => '80' ) );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	$v = wc_get_product( $p->get_id() );
	fs_eq( 'outofstock', $v->get_stock_status() );
	fs_false( $v->is_in_stock() );
	fs_eq( '80', $v->get_price() );
	fs_true( $v->is_on_sale() );
} );

fs_test( 'preview: category pages follow the draft categories', function () {
	$a = fs_term( 'pv-a' );
	$b = fs_term( 'pv-b' );
	$p = fs_product( array( 'category_ids' => array( $a->term_id ) ) );
	fisha_studio_product_edit( $p, array( 'category_ids' => array( $b->term_id ) ) );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	$in = function ( $slug ) { return wc_get_products( array( 'category' => array( $slug ), 'status' => 'publish', 'return' => 'ids', 'limit' => -1 ) ); };
	fs_false( in_array( $p->get_id(), $in( $a->slug ), true ), 'left the old category' );
	fs_true( in_array( $p->get_id(), $in( $b->slug ), true ), 'appears in the new category' );
} );

fs_test( 'preview: a new product shows on its category page in preview only', function () {
	$cat = fs_term( 'pv-new' );
	$p   = fisha_studio_product_create( 'zz-test Preview New' );
	fs_on_cleanup( function () use ( $p ) { wp_delete_post( $p->get_id(), true ); } );
	fisha_studio_product_edit( $p, array( 'category_ids' => array( $cat->term_id ) ) );
	$in = function () use ( $cat ) { return wc_get_products( array( 'category' => array( $cat->slug ), 'status' => 'publish', 'return' => 'ids', 'limit' => -1 ) ); };
	fs_false( in_array( $p->get_id(), $in(), true ), 'customers do not see it' );
	fisha_studio_preview_install_product( $p->get_id(), array() );
	fs_true( in_array( $p->get_id(), $in(), true ), 'preview shows it' );
} );

fs_test( 'preview: targets carry a token for every page', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$cat = fs_term( 'pv-targets' );
	$p   = fs_product( array( 'category_ids' => array( $cat->term_id ) ) );
	$t   = fisha_studio_preview_targets( $p, array( $cat->term_id ) );
	fs_eq( array( 'item', 'category', 'shop', 'home' ), wp_list_pluck( $t, 'key' ) );
	foreach ( $t as $x ) fs_true( false !== strpos( $x['url'], 'fisha_preview=' ), $x['key'] . ' has a token' );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh preview`
Expected: FAIL, undefined `fisha_studio_preview_token`.

- [ ] **Step 3: Implement**

`studio/studio-preview.php`:
```php
<?php
/**
 * Fisha Studio preview. The editor's iframe loads the real page with ?fisha_preview=<token>. For that request only,
 * and only for the signed-in user the token was made for, the product's draft is laid over the live data through
 * WooCommerce and WordPress filters, so the preview comes from exactly the same templates customers get.
 * Preview responses are never cached and never indexed.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_preview_sig( int $user_id, string $type, int $id, int $expires ): string {
	return substr( hash_hmac( 'sha256', "$user_id|$type|$id|$expires", wp_salt( 'auth' ) ), 0, 32 );
}
function fisha_studio_preview_token( int $user_id, string $type, int $id, int $expires = 0 ): string {
	$expires = $expires ?: time() + 30 * MINUTE_IN_SECONDS;
	return implode( '-', array( $user_id, $type, $id, $expires, fisha_studio_preview_sig( $user_id, $type, $id, $expires ) ) );
}
function fisha_studio_preview_verify( string $token ): ?array {
	$parts = explode( '-', $token );
	if ( 5 !== count( $parts ) ) return null;
	list( $user, $type, $id, $expires, $sig ) = $parts;
	if ( ! ctype_digit( $user ) || ! ctype_digit( $id ) || ! ctype_digit( $expires ) ) return null;
	if ( ! in_array( $type, array( 'product', 'category' ), true ) || (int) $expires < time() ) return null;
	if ( ! hash_equals( fisha_studio_preview_sig( (int) $user, $type, (int) $id, (int) $expires ), $sig ) ) return null;
	if ( (int) $user !== get_current_user_id() || ! current_user_can( 'fisha_studio' ) ) return null;
	return array( 'type' => $type, 'id' => (int) $id );
}

function fisha_studio_preview_active(): bool {
	return ! empty( $GLOBALS['fisha_studio_preview'] );
}
function fisha_studio_preview_add( string $hook, callable $fn, int $priority = 99, int $args = 2 ): void {
	add_filter( $hook, $fn, $priority, $args );
	$GLOBALS['fisha_studio_preview_hooks'][] = array( $hook, $fn, $priority );
}
function fisha_studio_preview_uninstall(): void {
	foreach ( $GLOBALS['fisha_studio_preview_hooks'] ?? array() as $h ) remove_filter( $h[0], $h[1], $h[2] );
	$GLOBALS['fisha_studio_preview_hooks'] = array();
	unset( $GLOBALS['fisha_studio_preview'] );
}

/** The stock status WooCommerce will store for these values (it derives it when stock is tracked). */
function fisha_studio_effective_stock_status( array $v ): string {
	if ( empty( $v['manage_stock'] ) ) return (string) $v['stock_status'];
	if ( (int) $v['stock_quantity'] > (int) get_option( 'woocommerce_notify_no_stock_amount', 0 ) ) return 'instock';
	return 'no' === $v['backorders'] ? 'outofstock' : 'onbackorder';
}
/** The price customers will pay today for these values. */
function fisha_studio_effective_price( array $v ): string {
	$today   = current_time( 'Y-m-d' );
	$sale_on = '' !== (string) $v['sale_price']
		&& ( '' === (string) $v['date_on_sale_from'] || $v['date_on_sale_from'] <= $today )
		&& ( '' === (string) $v['date_on_sale_to'] || $v['date_on_sale_to'] >= $today );
	return (string) ( $sale_on ? $v['sale_price'] : $v['regular_price'] );
}

/** null = the query has no category, true/false = whether the draft categories (or their parents) match it. */
function fisha_studio_preview_category_match( array $fields, array $args ): ?bool {
	if ( empty( $args['category'] ) || ! array_key_exists( 'category_ids', $fields ) ) return null;
	$slugs = array();
	foreach ( $fields['category_ids'] as $cid ) {
		foreach ( array_merge( array( (int) $cid ), get_ancestors( (int) $cid, 'product_cat' ) ) as $tid ) {
			$t = get_term( (int) $tid, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) ) $slugs[] = $t->slug;
		}
	}
	return (bool) array_intersect( $slugs, (array) $args['category'] );
}

function fisha_studio_preview_install_product( int $id, array $fields ): void {
	fisha_studio_preview_uninstall();
	$p = wc_get_product( $id );
	if ( ! $p ) return;
	$GLOBALS['fisha_studio_preview'] = array( 'type' => 'product', 'id' => $id, 'fields' => $fields );

	$merged = array_merge( fisha_studio_product_read( $p ), $fields );
	$over   = array_intersect_key( $merged, $fields );
	if ( array_intersect_key( $fields, array_flip( array( 'manage_stock', 'stock_quantity', 'stock_status', 'backorders' ) ) ) ) {
		$over['stock_status'] = fisha_studio_effective_stock_status( $merged );
	}
	if ( array_intersect_key( $fields, array_flip( array( 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to' ) ) ) ) {
		$over['price'] = fisha_studio_effective_price( $merged );
	}

	// 1. WooCommerce product props (the 'view' context getters run these filters).
	$props = array( 'name', 'status', 'catalog_visibility', 'featured', 'short_description', 'description', 'reviews_allowed', 'menu_order',
		'regular_price', 'sale_price', 'price', 'date_on_sale_from', 'date_on_sale_to', 'sku', 'manage_stock', 'stock_quantity',
		'stock_status', 'backorders', 'sold_individually', 'low_stock_amount', 'image_id', 'gallery_image_ids', 'category_ids',
		'tag_ids', 'weight', 'length', 'width', 'height' );
	foreach ( $props as $prop ) {
		if ( ! array_key_exists( $prop, $over ) ) continue;
		$value = $over[ $prop ];
		if ( 'date_on_sale_from' === $prop || 'date_on_sale_to' === $prop ) {
			$value = $value ? new WC_DateTime( $value . ( 'date_on_sale_to' === $prop ? ' 23:59:59' : ' 00:00:00' ), wp_timezone() ) : null;
		}
		fisha_studio_preview_add( "woocommerce_product_get_$prop", function ( $v, $obj = null ) use ( $id, $value ) {
			return ( $obj instanceof WC_Product && $obj->get_id() === $id ) ? $value : $v;
		} );
	}

	// 2. Post fields read by the block template (title, excerpt, description tab, reviews).
	if ( array_key_exists( 'name', $over ) ) {
		$name = $over['name'];
		fisha_studio_preview_add( 'the_title', function ( $t, $pid = 0 ) use ( $id, $name ) { return (int) $pid === $id ? esc_html( $name ) : $t; } );
		fisha_studio_preview_add( 'single_post_title', function ( $t, $post = null ) use ( $id, $name ) { return ( $post && (int) $post->ID === $id ) ? $name : $t; } );
	}
	if ( array_key_exists( 'short_description', $over ) ) {
		$short = $over['short_description'];
		fisha_studio_preview_add( 'get_the_excerpt', function ( $e, $post = null ) use ( $id, $short ) { return ( $post && (int) $post->ID === $id ) ? $short : $e; } );
	}
	if ( array_key_exists( 'description', $over ) ) {
		$long = $over['description'];
		fisha_studio_preview_add( 'the_content', function ( $c ) use ( $id, $long ) { return get_the_ID() === $id ? $long : $c; }, 1, 1 );
	}
	if ( array_key_exists( 'reviews_allowed', $over ) ) {
		$open = (bool) $over['reviews_allowed'];
		fisha_studio_preview_add( 'comments_open', function ( $o, $pid = 0 ) use ( $id, $open ) { return (int) $pid === $id ? $open : $o; } );
	}

	// 3. Post meta read with get_post_meta() by the Fisha templates (card colour, meta line, series, SEO, thumbnail).
	$meta = array();
	foreach ( fisha_studio_product_fields() as $k => $def ) {
		if ( isset( $def['meta'] ) && array_key_exists( $k, $over ) ) $meta[ $def['meta'] ] = is_bool( $over[ $k ] ) ? ( $over[ $k ] ? '1' : '' ) : $over[ $k ];
	}
	if ( array_key_exists( 'image_id', $over ) ) $meta['_thumbnail_id'] = $over['image_id'] ?: '';
	if ( array_key_exists( 'gallery_image_ids', $over ) ) $meta['_product_image_gallery'] = implode( ',', $over['gallery_image_ids'] );
	if ( $meta ) {
		fisha_studio_preview_add( 'get_post_metadata', function ( $v, $oid, $key = '' ) use ( $id, $meta ) {
			return ( (int) $oid === $id && '' !== $key && array_key_exists( $key, $meta ) ) ? array( $meta[ $key ] ) : $v;
		}, 99, 3 );
	}

	// 4. Lists (category pages, Shop, Home, related): follow draft categories; show a never-published product.
	$is_new = fisha_studio_is_new( $p );
	if ( $is_new ) {
		fisha_studio_preview_add( 'woocommerce_product_object_query_args', function ( $args ) {
			$status = $args['status'] ?? '';
			if ( 'publish' === $status || array( 'publish' ) === $status ) {
				$args['status']        = array( 'publish', 'draft' );
				$args['fisha_preview'] = 1;
			}
			return $args;
		}, 99, 1 );
	}
	fisha_studio_preview_add( 'woocommerce_product_object_query', function ( $results, $args ) use ( $id, $fields ) {
		if ( ! is_array( $results ) ) return $results;
		$match = fisha_studio_preview_category_match( $fields, $args );
		$out   = array();
		$seen  = false;
		foreach ( $results as $r ) {
			$rid = is_object( $r ) ? $r->get_id() : (int) $r;
			if ( $rid === $id ) {
				$seen = true;
				if ( false !== $match ) $out[] = $r;
				continue;
			}
			if ( ! empty( $args['fisha_preview'] ) && 'publish' !== get_post_status( $rid ) ) continue;
			$out[] = $r;
		}
		if ( ! $seen && true === $match ) array_unshift( $out, 'ids' === ( $args['return'] ?? '' ) ? $id : wc_get_product( $id ) );
		return $out;
	} );
}

add_action( 'init', function () {
	if ( empty( $_GET['fisha_preview'] ) || is_admin() || wp_doing_ajax() ) return;
	$item = fisha_studio_preview_verify( sanitize_text_field( wp_unslash( $_GET['fisha_preview'] ) ) );
	if ( ! $item || 'product' !== $item['type'] ) return;
	fisha_studio_preview_install_product( $item['id'], fisha_studio_draft_get( $item['id'] )['fields'] );
}, 20 );

add_action( 'send_headers', function () {
	if ( ! fisha_studio_preview_active() ) return;
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	do_action( 'litespeed_control_set_nocache', 'fisha studio preview' );
} );
add_filter( 'show_admin_bar', function ( $show ) { return fisha_studio_preview_active() ? false : $show; }, 20 );

/** Pages the editor can preview this product on, each with a fresh token. */
function fisha_studio_preview_targets( WC_Product $p, array $cat_ids ): array {
	$token   = fisha_studio_preview_token( get_current_user_id(), 'product', $p->get_id() );
	$targets = array( array( 'key' => 'item', 'label' => 'Product page', 'url' => get_permalink( $p->get_id() ) ) );
	foreach ( $cat_ids as $cid ) {
		$t = get_term( (int) $cid, 'product_cat' );
		if ( $t && ! is_wp_error( $t ) ) {
			$targets[] = array( 'key' => 'category', 'label' => $t->name . ' page', 'url' => get_term_link( $t ) );
			break;
		}
	}
	$targets[] = array( 'key' => 'shop', 'label' => 'Shop', 'url' => get_permalink( wc_get_page_id( 'shop' ) ) );
	$targets[] = array( 'key' => 'home', 'label' => 'Home', 'url' => home_url( '/' ) );
	foreach ( $targets as &$t ) $t['url'] = add_query_arg( 'fisha_preview', $token, $t['url'] );
	return $targets;
}
```

Append to `studio/studio.php`:
```php
require_once FISHA_STUDIO_DIR . 'studio-preview.php';
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh preview`
Expected: `6 passed, 0 failed`

- [ ] **Step 5: Check a real preview over HTTP**

Run (creates a throwaway product, a draft name, prints the preview title with and without a valid session; then cleans up):
```bash
MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp eval '
$p = new WC_Product_Simple(); $p->set_name("zz-test http preview"); $p->set_status("publish"); $p->set_regular_price("10"); $id = $p->save();
fisha_studio_draft_save( wc_get_product( $id ), array( "name" => "zz-test DRAFT NAME" ) );
echo get_permalink( $id ), "?fisha_preview=", fisha_studio_preview_token( 1, "product", $id ), "\n", $id, "\n";' 2>&1 | grep -v -e Container -e level=
```
Then `curl -s "<printed url>" | grep -o '<title>[^<]*'` → shows the **live** name (no session = no preview, token useless). Delete the product afterwards: `MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php /usr/local/bin/wp post delete <id> --force`. The signed-in preview is verified in the browser in Task 15.

- [ ] **Step 6: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio: signed live preview that overlays the draft on the real templates

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: REST foundation — session, lookups, media

**Files:**
- Create: `studio/api/api.php`, `studio/api/api-session.php`, `studio/api/api-lookups.php`, `studio/api/api-media.php`
- Modify: `studio/studio.php` (require `api/api.php`)
- Test: `tests/studio/test-api-base.php`, `tests/studio/test-media.php`

**Interfaces:**
- Produces: `fisha_studio_permission( string $cap = 'fisha_studio' ): callable` (401 `fisha_studio_signed_out` / 403 `fisha_studio_forbidden`); `fisha_studio_body( WP_REST_Request $r ): array`; `fisha_studio_image_payload( int $id ): ?array{id:int,thumb:string,medium:string,alt:string,name:string}`; `fisha_studio_store_image( array $file ): int|WP_Error`; routes `GET /session` → `{user:{id,name,email}, nonce}`; `POST /login` `{login,password}` → `{nonce,user}` (401 / 429); `GET /lookups` → `{categories:[{id,name,parent,slug,path}], tags:[{id,name}], series:string[], palette:[{color,count}], shipping_classes:[{id,name}], tax_classes:[{slug,name}], product_base:string, currency:string}`; `POST /tags {name}` → `{id,name}`; `GET /media?page=` → `{items, pages}`; `POST /media` (multipart `file`) → image payload; `PUT /media/{id} {alt}` → image payload.

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-api-base.php`:
```php
<?php
fs_test( 'api: signed-out and non-studio users are refused', function () {
	list( $s, $d ) = fs_api( 'GET', '/session' );
	fs_eq( 401, $s );
	fs_eq( 'fisha_studio_signed_out', $d['code'] );
	fs_as( 'customer' );
	list( $s, $d ) = fs_api( 'GET', '/lookups' );
	fs_eq( 403, $s );
} );

fs_test( 'api: session returns the user and a nonce', function () {
	$u = fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'GET', '/session' );
	fs_eq( 200, $s );
	fs_eq( $u->ID, $d['user']['id'] );
	fs_true( strlen( $d['nonce'] ) >= 10, 'nonce' );
} );

fs_test( 'api: login refuses a wrong password with a generic message', function () {
	$u = fs_user( FISHA_STUDIO_ROLE );
	fs_on_cleanup( function () use ( $u ) { fisha_studio_login_clear( $u->user_login, fisha_client_ip() ); } );
	list( $s, $d ) = fs_api( 'POST', '/login', array( 'login' => $u->user_login, 'password' => 'wrong' ) );
	fs_eq( 401, $s );
	fs_eq( 'That email/username and password do not match.', $d['message'] );
} );

fs_test( 'api: lookups list categories with paths, palette and the product base url', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$parent = fs_term( 'lk-parent' );
	$child  = fs_term( 'lk-child', $parent->term_id );
	list( $s, $d ) = fs_api( 'GET', '/lookups' );
	fs_eq( 200, $s );
	$row = current( array_filter( $d['categories'], fn( $c ) => $c['id'] === $child->term_id ) );
	fs_eq( 'zz-test lk-parent › zz-test lk-child', $row['path'] );
	fs_eq( home_url( '/product/' ), $d['product_base'] );
	fs_true( is_array( $d['palette'] ) && is_array( $d['series'] ) && is_array( $d['tax_classes'] ) );
} );

fs_test( 'api: adding a tag reuses an existing one', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $a ) = fs_api( 'POST', '/tags', array( 'name' => 'zz-test-tag-api' ) );
	fs_eq( 200, $s );
	fs_on_cleanup( function () use ( $a ) { wp_delete_term( $a['id'], 'product_tag' ); } );
	list( , $b ) = fs_api( 'POST', '/tags', array( 'name' => 'zz-test-tag-api' ) );
	fs_eq( $a['id'], $b['id'] );
	list( $s ) = fs_api( 'POST', '/tags', array( 'name' => '' ) );
	fs_eq( 422, $s );
} );
```

`tests/studio/test-media.php`:
```php
<?php
function fs_tmp_file( string $name, string $bytes ): array {
	$tmp = wp_tempnam( $name );
	file_put_contents( $tmp, $bytes );
	fs_on_cleanup( function () use ( $tmp ) { @unlink( $tmp ); } );
	return array( 'name' => $name, 'tmp_name' => $tmp, 'size' => strlen( $bytes ), 'error' => 0 );
}

fs_test( 'media: a png is stored as an image', function () {
	$id = fisha_studio_store_image( fs_tmp_file( 'zz-test-photo.png', file_get_contents( FISHA_DESIGN_DIR . 'img/fisha-logo.png' ) ) );
	fs_true( is_int( $id ), is_wp_error( $id ) ? $id->get_error_message() : '' );
	fs_on_cleanup( function () use ( $id ) { wp_delete_attachment( $id, true ); } );
	fs_true( wp_attachment_is_image( $id ) );
	fs_eq( $id, fisha_studio_image_payload( $id )['id'] );
} );

fs_test( 'media: heic rejected with a clear message', function () {
	$r = fisha_studio_store_image( fs_tmp_file( 'IMG_0001.HEIC', "\0\0\0\x18ftypheic" . str_repeat( "\0", 64 ) ) );
	fs_eq( 'fisha_studio_upload', $r->get_error_code() );
	fs_true( false !== strpos( $r->get_error_message(), 'JPG' ), 'names the formats that work' );
} );

fs_test( 'media: a script pretending to be a jpg is rejected', function () {
	$r = fisha_studio_store_image( fs_tmp_file( 'evil.jpg', '<?php echo "pwned";' ) );
	fs_true( is_wp_error( $r ) );
} );

fs_test( 'media: files over 15 MB are rejected before anything is stored', function () {
	$f = fs_tmp_file( 'big.png', file_get_contents( FISHA_DESIGN_DIR . 'img/fisha-logo.png' ) );
	$f['size'] = 16 * MB_IN_BYTES;
	$r = fisha_studio_store_image( $f );
	fs_true( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), '15 MB' ) );
} );

fs_test( 'media: alt text update and library listing', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$img = fs_image();
	list( $s, $d ) = fs_api( 'PUT', "/media/$img", array( 'alt' => 'A blue <b>Fisha</b>' ) );
	fs_eq( 200, $s );
	fs_eq( 'A blue Fisha', get_post_meta( $img, '_wp_attachment_image_alt', true ) );
	list( $s, $d ) = fs_api( 'GET', '/media' );
	fs_eq( 200, $s );
	fs_true( in_array( $img, wp_list_pluck( $d['items'], 'id' ), true ), 'newest image listed' );
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'zz-test', 'post_status' => 'draft' ) );
	fs_on_cleanup( function () use ( $page ) { wp_delete_post( $page, true ); } );
	list( $s ) = fs_api( 'PUT', "/media/$page", array( 'alt' => 'x' ) );
	fs_eq( 404, $s, 'only images' );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh api:` and `bash tests/studio/run.sh media`
Expected: FAIL (404 `rest_no_route` instead of 401, undefined `fisha_studio_store_image`).

- [ ] **Step 3: Implement**

`studio/api/api.php`:
```php
<?php
/**
 * Fisha Studio REST API (namespace fisha-studio/v1): shared helpers, then one file per resource.
 * Errors are WP_Error with a plain-English message; field errors travel in data.fields.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_permission( string $cap = 'fisha_studio' ): callable {
	return function () use ( $cap ) {
		if ( ! is_user_logged_in() ) return new WP_Error( 'fisha_studio_signed_out', 'Please sign in again.', array( 'status' => 401 ) );
		if ( ! current_user_can( 'fisha_studio' ) || ! current_user_can( $cap ) ) return new WP_Error( 'fisha_studio_forbidden', 'You do not have access to this.', array( 'status' => 403 ) );
		return true;
	};
}

function fisha_studio_body( WP_REST_Request $r ): array {
	$b = $r->get_json_params();
	return is_array( $b ) ? $b : array();
}

require_once __DIR__ . '/api-session.php';
require_once __DIR__ . '/api-lookups.php';
require_once __DIR__ . '/api-media.php';
```

`studio/api/api-session.php`:
```php
<?php
/**
 * Fisha Studio REST: who is signed in (and a fresh nonce), and signing in again from inside the app.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/session', array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_session', 'permission_callback' => fisha_studio_permission() ) );
	register_rest_route( FISHA_STUDIO_NS, '/login', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_login', 'permission_callback' => '__return_true' ) );
} );

function fisha_studio_rest_session(): array {
	$u = wp_get_current_user();
	return array( 'user' => array( 'id' => $u->ID, 'name' => $u->display_name, 'email' => $u->user_email ), 'nonce' => wp_create_nonce( 'wp_rest' ) );
}

function fisha_studio_rest_login( WP_REST_Request $r ) {
	$b = fisha_studio_body( $r );
	$u = fisha_studio_sign_in( (string) ( $b['login'] ?? '' ), (string) ( $b['password'] ?? '' ), fisha_client_ip() );
	if ( is_wp_error( $u ) ) {
		return new WP_Error( $u->get_error_code(), $u->get_error_message(), array( 'status' => 'fisha_studio_locked' === $u->get_error_code() ? 429 : 401 ) );
	}
	return array( 'nonce' => wp_create_nonce( 'wp_rest' ), 'user' => array( 'id' => $u->ID, 'name' => $u->display_name ) );
}
```

`studio/api/api-lookups.php`:
```php
<?php
/**
 * Fisha Studio REST: the lists the editor's pickers need (categories, tags, design series, card colours,
 * shipping and tax classes) and adding a tag.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/lookups', array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_lookups', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, '/tags', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_add_tag', 'permission_callback' => fisha_studio_permission( 'manage_product_terms' ) ) );
} );

function fisha_studio_rest_lookups(): array {
	global $wpdb;
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	$terms = is_wp_error( $terms ) ? array() : $terms;
	$by_id = array();
	foreach ( $terms as $t ) $by_id[ $t->term_id ] = $t;
	$path = function ( WP_Term $t ) use ( $by_id ): string {
		$names = array( $t->name );
		while ( $t->parent && isset( $by_id[ $t->parent ] ) ) {
			$t = $by_id[ $t->parent ];
			array_unshift( $names, $t->name );
		}
		return implode( ' › ', $names );
	};
	$categories = array();
	foreach ( $terms as $t ) $categories[] = array( 'id' => $t->term_id, 'name' => $t->name, 'parent' => $t->parent, 'slug' => $t->slug, 'path' => $path( $t ) );
	usort( $categories, function ( $a, $b ) { return strcasecmp( $a['path'], $b['path'] ); } );

	$tag_terms = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
	$tags      = array();
	foreach ( is_wp_error( $tag_terms ) ? array() : $tag_terms as $t ) $tags[] = array( 'id' => $t->term_id, 'name' => $t->name );

	$ship_terms = get_terms( array( 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ) );
	$shipping   = array();
	foreach ( is_wp_error( $ship_terms ) ? array() : $ship_terms as $t ) $shipping[] = array( 'id' => $t->term_id, 'name' => $t->name );

	$tax = array( array( 'slug' => '', 'name' => 'Standard' ) );
	foreach ( array_combine( WC_Tax::get_tax_class_slugs(), WC_Tax::get_tax_classes() ) ?: array() as $slug => $name ) $tax[] = array( 'slug' => $slug, 'name' => $name );

	$palette = array();
	foreach ( $wpdb->get_results( "SELECT LOWER(meta_value) AS color, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = '_fisha_card_bg' AND meta_value <> '' GROUP BY LOWER(meta_value) ORDER BY n DESC LIMIT 16", ARRAY_A ) as $row ) {
		if ( sanitize_hex_color( $row['color'] ) ) $palette[] = array( 'color' => $row['color'], 'count' => (int) $row['n'] );
	}

	return array(
		'categories'       => $categories,
		'tags'             => $tags,
		'series'           => $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_fisha_series' AND meta_value <> '' ORDER BY meta_value" ),
		'palette'          => $palette,
		'shipping_classes' => $shipping,
		'tax_classes'      => $tax,
		'product_base'     => trailingslashit( home_url( wc_get_permalink_structure()['product_rewrite_slug'] ) ),
		'currency'         => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
	);
}

function fisha_studio_rest_add_tag( WP_REST_Request $r ) {
	$name = trim( sanitize_text_field( (string) ( fisha_studio_body( $r )['name'] ?? '' ) ) );
	if ( '' === $name || mb_strlen( $name ) > 40 ) {
		return new WP_Error( 'fisha_studio_invalid', 'A tag needs a name of up to 40 characters.', array( 'status' => 422, 'fields' => array( 'name' => 'A tag needs a name of up to 40 characters.' ) ) );
	}
	$existing = get_term_by( 'name', $name, 'product_tag' );
	if ( $existing ) return array( 'id' => $existing->term_id, 'name' => $existing->name );
	$t = wp_insert_term( $name, 'product_tag' );
	if ( is_wp_error( $t ) ) return new WP_Error( 'fisha_studio_invalid', $t->get_error_message(), array( 'status' => 422 ) );
	return array( 'id' => (int) $t['term_id'], 'name' => $name );
}
```

`studio/api/api-media.php`:
```php
<?php
/**
 * Fisha Studio REST: product photos. Only JPG, PNG and WebP up to 15 MB; the type is checked from the file's
 * content, not its name. Alt text is saved straight to the image (it is not part of a product draft).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_MAX_UPLOAD', 15 * MB_IN_BYTES );

function fisha_studio_image_mimes(): array {
	return array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
}

function fisha_studio_image_payload( int $id ): ?array {
	if ( ! $id || ! wp_attachment_is_image( $id ) ) return null;
	return array(
		'id'     => $id,
		'thumb'  => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
		'medium' => (string) wp_get_attachment_image_url( $id, 'medium' ),
		'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		'name'   => get_the_title( $id ),
	);
}

/** Stores an uploaded/temporary file as a media library image. @return int|WP_Error */
function fisha_studio_store_image( array $file ) {
	if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) || ! file_exists( $file['tmp_name'] ) ) {
		return new WP_Error( 'fisha_studio_upload', 'The photo did not arrive. Please try again.', array( 'status' => 422 ) );
	}
	if ( (int) ( $file['size'] ?? 0 ) > FISHA_STUDIO_MAX_UPLOAD ) {
		return new WP_Error( 'fisha_studio_upload', 'This photo is larger than 15 MB. Please use a smaller one.', array( 'status' => 422 ) );
	}
	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], fisha_studio_image_mimes() );
	if ( empty( $check['type'] ) || ! in_array( $check['type'], fisha_studio_image_mimes(), true ) ) {
		return new WP_Error( 'fisha_studio_upload', 'Only JPG, PNG or WebP photos can be uploaded. On an iPhone, set Settings > Camera > Formats to "Most Compatible", or share the photo as JPG.', array( 'status' => 422 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$id = media_handle_sideload( array( 'name' => $check['proper_filename'] ?: $file['name'], 'tmp_name' => $file['tmp_name'] ), 0 );
	if ( is_wp_error( $id ) ) return new WP_Error( 'fisha_studio_upload', 'The photo could not be saved. ' . $id->get_error_message(), array( 'status' => 422 ) );
	return (int) $id;
}

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/media', array(
		array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_media_list', 'permission_callback' => fisha_studio_permission( 'upload_files' ) ),
		array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_media_upload', 'permission_callback' => fisha_studio_permission( 'upload_files' ) ),
	) );
	register_rest_route( FISHA_STUDIO_NS, '/media/(?P<id>\d+)', array( 'methods' => 'PUT', 'callback' => 'fisha_studio_rest_media_alt', 'permission_callback' => fisha_studio_permission( 'upload_files' ) ) );
} );

function fisha_studio_rest_media_list( WP_REST_Request $r ): array {
	$q = new WP_Query( array(
		'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'fields' => 'ids',
		'posts_per_page' => 40, 'paged' => max( 1, (int) $r['page'] ), 'orderby' => 'date', 'order' => 'DESC',
	) );
	return array( 'items' => array_values( array_filter( array_map( 'fisha_studio_image_payload', array_map( 'intval', $q->posts ) ) ) ), 'pages' => (int) $q->max_num_pages );
}

function fisha_studio_rest_media_upload( WP_REST_Request $r ) {
	$files = $r->get_file_params();
	$file  = $files['file'] ?? null;
	if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'fisha_studio_upload', 'The photo did not arrive. Please try again.', array( 'status' => 422 ) );
	}
	$id = fisha_studio_store_image( $file );
	return is_wp_error( $id ) ? $id : fisha_studio_image_payload( $id );
}

function fisha_studio_rest_media_alt( WP_REST_Request $r ) {
	$id = (int) $r['id'];
	if ( ! wp_attachment_is_image( $id ) ) return new WP_Error( 'fisha_studio_not_found', 'This image no longer exists.', array( 'status' => 404 ) );
	$alt = mb_substr( trim( sanitize_text_field( (string) ( fisha_studio_body( $r )['alt'] ?? '' ) ) ), 0, 200 );
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	return fisha_studio_image_payload( $id );
}
```

Append to `studio/studio.php`:
```php
require_once FISHA_STUDIO_DIR . 'api/api.php';
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh api:` then `bash tests/studio/run.sh media`
Expected: `5 passed` and `5 passed`, 0 failed.

- [ ] **Step 5: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio REST: session, re-login, lookups, tags and safe photo uploads

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: REST — products

**Files:**
- Create: `studio/api/api-products.php`
- Modify: `studio/api/api.php` (require)
- Test: `tests/studio/test-api-products.php`

**Interfaces:**
- Consumes: Tasks 5–10.
- Produces: `fisha_studio_product_payload( WC_Product $p ): array` = `{id, type, editable:bool, state:'new'|'changed'|'live', values:{…all field keys}, live:{…}, changed:string[], url:string, product_base:string, previews:[{key,label,url}], images:{<id>:imagePayload}, conflicts:[…]}`; `fisha_studio_product_row( WC_Product $p ): array` = `{id, name, sku, type, editable, state, hidden, regular_price, sale_price, price_text, manage_stock, stock_quantity, stock_status, low, thumb, categories:string[]}`. Routes:
  - `GET /products?search=&category=&state=all|live|changed|new|hidden&stock=all|instock|outofstock|low&page=&per_page=` → `{items:[row], total, pages}`
  - `POST /products {name}` → 201 payload
  - `GET /products/{id}` → payload
  - `PUT /products/{id}/draft {fields}` → payload + `field_errors` (valid fields are saved even when others are not)
  - `DELETE /products/{id}/draft` → payload
  - `POST /products/{id}/publish {resolutions?:{field:'mine'|'live'}, change_url?:bool}` → payload + `published_url`
  - `POST /products/{id}/quick {fields:{regular_price?,stock_quantity?,stock_status?}}` → row (409 `fisha_studio_has_draft` when the product has unpublished changes)
  - `POST /products/{id}/trash` → `{trashed:true}`

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-api-products.php`:
```php
<?php
function fs_ready_product( array $props = array() ): WC_Product_Simple {
	return fs_product( array_merge( array( 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'api-' . wp_rand() )->term_id ) ), $props ) );
}

fs_test( 'api products: list, search by name and sku, filters', function () {
	$p = fs_ready_product( array( 'sku' => 'ZZ-TEST-LIST-' . wp_rand( 100, 999 ), 'manage_stock' => true, 'stock_quantity' => 0 ) );
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'GET', '/products', array( 'search' => $p->get_name() ) );
	fs_eq( 200, $s );
	fs_eq( array( $p->get_id() ), wp_list_pluck( $d['items'], 'id' ) );
	list( , $d ) = fs_api( 'GET', '/products', array( 'search' => $p->get_sku() ) );
	fs_true( in_array( $p->get_id(), wp_list_pluck( $d['items'], 'id' ), true ), 'found by SKU' );
	list( , $d ) = fs_api( 'GET', '/products', array( 'stock' => 'outofstock', 'per_page' => 100 ) );
	fs_true( in_array( $p->get_id(), wp_list_pluck( $d['items'], 'id' ), true ), 'sold-out filter' );
	fisha_studio_product_edit( $p, array( 'regular_price' => '1' ) );
	list( , $d ) = fs_api( 'GET', '/products', array( 'state' => 'changed', 'per_page' => 100 ) );
	fs_true( in_array( $p->get_id(), wp_list_pluck( $d['items'], 'id' ), true ), 'changed filter' );
} );

fs_test( 'api products: create, edit (with a bad field), publish', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'POST', '/products', array( 'name' => 'zz-test Api Created' ) );
	fs_eq( 201, $s );
	$id = $d['id'];
	fs_on_cleanup( function () use ( $id ) { wp_delete_post( $id, true ); } );
	fs_eq( 'new', $d['state'] );
	fs_eq( 'publish', $d['values']['status'], 'new items default to Live' );

	list( $s, $d ) = fs_api( 'PUT', "/products/$id/draft", array( 'fields' => array( 'regular_price' => '₪95', 'sale_price' => 'abc', 'short_description' => '<p>Hi</p>', 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'api-create' )->term_id ) ) ) );
	fs_eq( 200, $s );
	fs_eq( '95', $d['values']['regular_price'], 'valid field saved' );
	fs_true( isset( $d['field_errors']['sale_price'] ), 'bad field reported' );

	list( $s, $d ) = fs_api( 'POST', "/products/$id/publish" );
	fs_eq( 200, $s, wp_json_encode( $d ) );
	fs_eq( 'live', $d['state'] );
	fs_eq( home_url( '/product/zz-test-api-created/' ), $d['published_url'] );
} );

fs_test( 'api products: payload of a live product', function () {
	$p = fs_ready_product();
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'GET', '/products/' . $p->get_id() );
	fs_eq( 200, $s );
	fs_eq( 'live', $d['state'] );
	fs_true( $d['editable'] );
	fs_eq( array_keys( fisha_studio_product_fields() ), array_keys( $d['values'] ) );
	fs_eq( array( 'item', 'category', 'shop', 'home' ), wp_list_pluck( $d['previews'], 'key' ) );
	fs_true( isset( $d['images'][ $p->get_image_id() ] ), 'image payloads included' );
	list( $s ) = fs_api( 'GET', '/products/99999999' );
	fs_eq( 404, $s );
} );

fs_test( 'api products: conflict comes back as 409 with the details', function () {
	$p = fs_ready_product( array( 'manage_stock' => true, 'stock_quantity' => 3 ) );
	fs_as( FISHA_STUDIO_ROLE );
	fs_api( 'PUT', '/products/' . $p->get_id() . '/draft', array( 'fields' => array( 'stock_quantity' => 5 ) ) );
	wc_update_product_stock( $p->get_id(), 2, 'set' );
	list( $s, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/publish' );
	fs_eq( 409, $s );
	fs_eq( 2, $d['data']['conflicts'][0]['live'] );
	list( $s, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/publish', array( 'resolutions' => array( 'stock_quantity' => 'mine' ) ) );
	fs_eq( 200, $s );
	fs_eq( 5, wc_get_product( $p->get_id() )->get_stock_quantity() );
} );

fs_test( 'api products: quick edit publishes at once, but not over pending changes', function () {
	$p = fs_ready_product( array( 'regular_price' => '100' ) );
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $row ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'regular_price' => '105', 'name' => 'ignored' ) ) );
	fs_eq( 200, $s );
	fs_eq( '105', $row['regular_price'] );
	fs_true( 'ignored' !== wc_get_product( $p->get_id() )->get_name(), 'only price/stock allowed' );
	fs_api( 'PUT', '/products/' . $p->get_id() . '/draft', array( 'fields' => array( 'meta_line' => 'pending' ) ) );
	list( $s, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'regular_price' => '110' ) ) );
	fs_eq( 409, $s );
	fs_eq( 'fisha_studio_has_draft', $d['code'] );
} );

fs_test( 'api products: discard and trash', function () {
	$p = fs_ready_product();
	fs_as( FISHA_STUDIO_ROLE );
	fs_api( 'PUT', '/products/' . $p->get_id() . '/draft', array( 'fields' => array( 'regular_price' => '7' ) ) );
	list( $s, $d ) = fs_api( 'DELETE', '/products/' . $p->get_id() . '/draft' );
	fs_eq( 'live', $d['state'] );
	list( $s ) = fs_api( 'POST', '/products/' . $p->get_id() . '/trash' );
	fs_eq( 200, $s );
	fs_eq( 'trash', get_post_status( $p->get_id() ) );
	list( $s ) = fs_api( 'GET', '/products/' . $p->get_id() );
	fs_eq( 404, $s, 'trashed products are gone from the Studio' );
} );

fs_test( 'api products: variable products are read-only for now', function () {
	$v = new WC_Product_Variable();
	$v->set_name( 'zz-test api variable' );
	$v->set_status( 'publish' );
	$id = $v->save();
	fs_on_cleanup( function () use ( $id ) { wp_delete_post( $id, true ); } );
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'GET', "/products/$id" );
	fs_eq( 200, $s );
	fs_false( $d['editable'] );
	list( $s ) = fs_api( 'PUT', "/products/$id/draft", array( 'fields' => array( 'name' => 'x' ) ) );
	fs_eq( 400, $s );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh "api products"`
Expected: FAIL (`rest_no_route`, 404).

- [ ] **Step 3: Implement**

`studio/api/api-products.php`:
```php
<?php
/**
 * Fisha Studio REST: products. List with filters, create (as a hidden draft), read, autosave a draft, discard,
 * publish, quick price/stock edit from the list, and move to trash.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	$id = '/products/(?P<id>\d+)';
	register_rest_route( FISHA_STUDIO_NS, '/products', array(
		array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_products_list', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ),
		array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_create', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ),
	) );
	register_rest_route( FISHA_STUDIO_NS, $id, array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_products_get', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/draft', array(
		array( 'methods' => 'PUT', 'callback' => 'fisha_studio_rest_products_draft', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ),
		array( 'methods' => 'DELETE', 'callback' => 'fisha_studio_rest_products_discard', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ),
	) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/publish', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_publish', 'permission_callback' => fisha_studio_permission( 'publish_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/quick', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_quick', 'permission_callback' => fisha_studio_permission( 'publish_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/trash', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_trash', 'permission_callback' => fisha_studio_permission( 'delete_products' ) ) );
} );

/** @return WC_Product|WP_Error */
function fisha_studio_rest_product( WP_REST_Request $r ) {
	$id = (int) $r['id'];
	$p  = ( 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) ? wc_get_product( $id ) : null;
	return $p ?: new WP_Error( 'fisha_studio_not_found', 'This product no longer exists.', array( 'status' => 404 ) );
}

function fisha_studio_product_state( WC_Product $p ): string {
	if ( fisha_studio_is_new( $p ) ) return 'new';
	return fisha_studio_draft_get( $p->get_id() )['fields'] ? 'changed' : 'live';
}

function fisha_studio_product_payload( WC_Product $p ): array {
	$id     = $p->get_id();
	$live   = fisha_studio_product_read( $p );
	$draft  = fisha_studio_draft_get( $id );
	$is_new = fisha_studio_is_new( $p );
	if ( $is_new ) $live['status'] = fisha_studio_target_status( $p );
	$values = array_merge( $live, $draft['fields'] );
	$images = array();
	foreach ( array_merge( array( (int) $values['image_id'] ), $values['gallery_image_ids'] ) as $aid ) {
		$img = fisha_studio_image_payload( (int) $aid );
		if ( $img ) $images[ $aid ] = $img;
	}
	return array(
		'id'           => $id,
		'type'         => $p->get_type(),
		'editable'     => $p->is_type( 'simple' ),
		'state'        => fisha_studio_product_state( $p ),
		'values'       => $values,
		'live'         => $live,
		'changed'      => array_keys( $draft['fields'] ),
		'url'          => $is_new ? '' : get_permalink( $id ),
		'product_base' => trailingslashit( home_url( wc_get_permalink_structure()['product_rewrite_slug'] ) ),
		'previews'     => fisha_studio_preview_targets( $p, $values['category_ids'] ),
		'images'       => (object) $images,
		'conflicts'    => $is_new ? array() : fisha_studio_conflicts( $p, $draft ),
	);
}

function fisha_studio_product_row( WC_Product $p ): array {
	$qty   = $p->get_manage_stock() ? $p->get_stock_quantity() : null;
	$alert = '' !== (string) $p->get_low_stock_amount() ? (int) $p->get_low_stock_amount() : 2;
	$names = array();
	foreach ( $p->get_category_ids() as $cid ) {
		$t = get_term( $cid, 'product_cat' );
		if ( $t && ! is_wp_error( $t ) ) $names[] = $t->name;
	}
	return array(
		'id'             => $p->get_id(),
		'name'           => $p->get_name( 'edit' ),
		'sku'            => $p->get_sku( 'edit' ),
		'type'           => $p->get_type(),
		'editable'       => $p->is_type( 'simple' ),
		'state'          => fisha_studio_product_state( $p ),
		'hidden'         => 'private' === get_post_status( $p->get_id() ),
		'regular_price'  => (string) $p->get_regular_price( 'edit' ),
		'sale_price'     => (string) $p->get_sale_price( 'edit' ),
		'price_text'     => html_entity_decode( wp_strip_all_tags( $p->get_price_html() ), ENT_QUOTES, 'UTF-8' ),
		'manage_stock'   => (bool) $p->get_manage_stock(),
		'stock_quantity' => $qty,
		'stock_status'   => $p->get_stock_status( 'edit' ),
		'low'            => null !== $qty && $qty > 0 && $qty <= $alert,
		'thumb'          => $p->get_image_id() ? (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : '',
		'categories'     => $names,
	);
}

function fisha_studio_rest_products_list( WP_REST_Request $r ): array {
	$args   = array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ) );
	$search = trim( sanitize_text_field( (string) $r['search'] ) );
	if ( '' !== $search ) $args['s'] = $search;
	if ( $r['category'] ) $args['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'terms' => (int) $r['category'], 'include_children' => true ) );
	$ids = ( new WP_Query( $args ) )->posts;
	if ( '' !== $search && ( $by_sku = wc_get_product_id_by_sku( $search ) ) && ! in_array( $by_sku, $ids, true ) ) array_unshift( $ids, $by_sku );

	$state = (string) ( $r['state'] ?: 'all' );
	$stock = (string) ( $r['stock'] ?: 'all' );
	$rows  = array();
	foreach ( $ids as $id ) {
		$p = wc_get_product( (int) $id );
		if ( ! $p ) continue;
		$row = fisha_studio_product_row( $p );
		if ( 'hidden' === $state && ! $row['hidden'] ) continue;
		if ( in_array( $state, array( 'live', 'changed', 'new' ), true ) && $row['state'] !== $state ) continue;
		if ( 'low' === $stock && ! $row['low'] ) continue;
		if ( in_array( $stock, array( 'instock', 'outofstock' ), true ) && $row['stock_status'] !== $stock ) continue;
		$rows[] = $row;
	}
	$per  = min( 100, max( 1, (int) ( $r['per_page'] ?: 50 ) ) );
	$page = max( 1, (int) ( $r['page'] ?: 1 ) );
	return array( 'items' => array_slice( $rows, ( $page - 1 ) * $per, $per ), 'total' => count( $rows ), 'pages' => (int) ceil( count( $rows ) / $per ) );
}

function fisha_studio_rest_products_create( WP_REST_Request $r ) {
	$name = trim( sanitize_text_field( (string) ( fisha_studio_body( $r )['name'] ?? '' ) ) );
	if ( '' === $name || mb_strlen( $name ) > 120 ) {
		return new WP_Error( 'fisha_studio_invalid', 'Give the product a name (up to 120 characters).', array( 'status' => 422, 'fields' => array( 'name' => 'Give the product a name (up to 120 characters).' ) ) );
	}
	$p = fisha_studio_product_create( $name );
	fisha_studio_log( 'product', $p->get_id(), 'new', null, array( 'name' => $name ) );
	return new WP_REST_Response( fisha_studio_product_payload( $p ), 201 );
}

function fisha_studio_rest_products_get( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	return is_wp_error( $p ) ? $p : fisha_studio_product_payload( $p );
}

function fisha_studio_rest_products_draft( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	if ( ! $p->is_type( 'simple' ) ) return new WP_Error( 'fisha_studio_unsupported', 'Products with sizes or options can be edited in the Studio soon.', array( 'status' => 400 ) );
	list( $values, $errors ) = fisha_studio_sanitize( fisha_studio_product_fields(), (array) ( fisha_studio_body( $r )['fields'] ?? array() ) );
	$values = array_diff_key( $values, $errors );
	if ( $values ) fisha_studio_product_edit( $p, $values );
	return array_merge( fisha_studio_product_payload( wc_get_product( $p->get_id() ) ), array( 'field_errors' => (object) $errors ) );
}

function fisha_studio_rest_products_discard( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	fisha_studio_draft_discard( $p->get_id() );
	return fisha_studio_product_payload( wc_get_product( $p->get_id() ) );
}

function fisha_studio_rest_products_publish( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	$b   = fisha_studio_body( $r );
	$res = fisha_studio_publish_product( $p->get_id(), array_map( 'strval', (array) ( $b['resolutions'] ?? array() ) ), ! empty( $b['change_url'] ) );
	if ( is_wp_error( $res ) ) return $res;
	return array_merge( fisha_studio_product_payload( wc_get_product( $p->get_id() ) ), array( 'published_url' => $res['url'] ) );
}

function fisha_studio_rest_products_quick( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	if ( ! $p->is_type( 'simple' ) || fisha_studio_is_new( $p ) ) return new WP_Error( 'fisha_studio_unsupported', 'Open this product to edit it.', array( 'status' => 400 ) );
	if ( fisha_studio_draft_get( $p->get_id() )['fields'] ) {
		return new WP_Error( 'fisha_studio_has_draft', 'This product has unpublished changes. Open it to review and publish them together.', array( 'status' => 409 ) );
	}
	$input = array_intersect_key( (array) ( fisha_studio_body( $r )['fields'] ?? array() ), array_flip( array( 'regular_price', 'stock_quantity', 'stock_status' ) ) );
	list( $values, $errors ) = fisha_studio_sanitize( fisha_studio_product_fields(), $input );
	if ( $errors ) return new WP_Error( 'fisha_studio_invalid', reset( $errors ), array( 'status' => 422, 'fields' => $errors ) );
	fisha_studio_draft_save( $p, $values );
	$res = fisha_studio_publish_product( $p->get_id() );
	if ( is_wp_error( $res ) ) {
		fisha_studio_draft_discard( $p->get_id() );
		if ( 'fisha_studio_nothing' !== $res->get_error_code() ) {
			$fields = $res->get_error_data()['fields'] ?? array();
			return $fields ? new WP_Error( $res->get_error_code(), reset( $fields ), $res->get_error_data() ) : $res;
		}
	}
	return fisha_studio_product_row( wc_get_product( $p->get_id() ) );
}

function fisha_studio_rest_products_trash( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	if ( ! current_user_can( 'delete_post', $p->get_id() ) ) return new WP_Error( 'fisha_studio_forbidden', 'You do not have access to this.', array( 'status' => 403 ) );
	$res = fisha_studio_trash_product( $p->get_id() );
	return is_wp_error( $res ) ? $res : array( 'trashed' => true );
}
```

Append to `studio/api/api.php`:
```php
require_once __DIR__ . '/api-products.php';
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh "api products"`
Expected: `7 passed, 0 failed`. Then the whole suite: `bash tests/studio/run.sh` → all passed.

- [ ] **Step 5: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio REST: products list, create, draft, publish, quick edit and trash

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Per-product SEO title and description

**Files:**
- Modify: `wp-content/mu-plugins/fisha-design/seo.php` (inside `fisha_seo_description()`, and one new filter after the front-page title filter)
- Test: `tests/studio/test-seo.php`

**Interfaces:**
- Consumes: post meta `fisha_seo_title`, `fisha_seo_desc` (written by the Studio field schema, overlaid by the preview).
- Produces: product `<title>` = `{fisha_seo_title} | {site name}` when set; meta description = `fisha_seo_desc` when set; otherwise the existing behaviour, unchanged.

- [ ] **Step 1: Write the failing test**

`tests/studio/test-seo.php`:
```php
<?php
function fs_view( int $id ): void {
	$GLOBALS['wp_query']     = new WP_Query( array( 'p' => $id, 'post_type' => 'product', 'post_status' => array( 'publish' ) ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	fs_on_cleanup( function () { wp_reset_query(); } );
}

fs_test( 'seo: product SEO title and description from the Studio', function () {
	$p = fs_product();
	update_post_meta( $p->get_id(), 'fisha_seo_title', 'Night Swim Original' );
	update_post_meta( $p->get_id(), 'fisha_seo_desc', 'An ink Fisha swimming at night.' );
	fs_view( $p->get_id() );
	fs_eq( 'An ink Fisha swimming at night.', fisha_seo_description() );
	fs_eq( 'Night Swim Original | ' . get_bloginfo( 'name' ), apply_filters( 'pre_get_document_title', '' ) );
} );

fs_test( 'seo: products without Studio SEO keep the old behaviour', function () {
	$p = fs_product( array( 'short_description' => 'Old short text.' ) );
	fs_view( $p->get_id() );
	fs_eq( 'Old short text.', fisha_seo_description() );
	fs_eq( '', apply_filters( 'pre_get_document_title', '' ) );
} );

fs_test( 'seo: preview shows the draft SEO text', function () {
	$p = fs_product();
	fisha_studio_product_edit( $p, array( 'seo_desc' => 'Draft description.' ) );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	fs_view( $p->get_id() );
	fs_eq( 'Draft description.', fisha_seo_description() );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh seo`
Expected: first and third tests FAIL (description is the short description; title empty).

- [ ] **Step 3: Implement**

In `seo.php`, inside `fisha_seo_description()`, directly after the block
```php
		if ( get_post_meta( $id, 'hostinger_ai_post_meta_description', true ) ) {
			return ''; // the theme prints it
		}
```
add:
```php
		// Products: the SEO description written in Fisha Studio wins over everything below.
		if ( 'product' === get_post_type( $id ) ) {
			$custom = trim( (string) get_post_meta( $id, 'fisha_seo_desc', true ) );
			if ( '' !== $custom ) return fisha_seo_trim( $custom );
		}
```

After the front-page `pre_get_document_title` filter (the one returning `FISHA_HOME_TITLE`), add:
```php
// Products: the SEO title written in Fisha Studio, when there is one.
add_filter( 'pre_get_document_title', function ( $title ) {
	if ( fisha_seo_plugin_active() || ! is_singular( 'product' ) ) {
		return $title;
	}
	$custom = trim( (string) get_post_meta( get_queried_object_id(), 'fisha_seo_title', true ) );
	return '' !== $custom ? $custom . ' | ' . get_bloginfo( 'name' ) : $title;
}, 25 );
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh seo`
Expected: `3 passed, 0 failed`. Also open one live product page on localhost and confirm its `<title>` is unchanged (no Studio SEO set yet).

- [ ] **Step 5: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/seo.php
git commit -m "SEO: per-product title and description from Fisha Studio

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 13: UI foundation — styles, helpers, API client, shell and product list

**Files:**
- Replace placeholder: `studio/app/studio.css`, `studio/app/js/app.js`
- Create: `studio/app/js/ui.js`, `studio/app/js/api.js`, `studio/app/js/views/products-list.js`

**Interfaces:**
- Consumes: `#fs-config` JSON (`api, nonce, home, logout, user, currency, logo`), REST routes from Tasks 10–11.
- Produces (ui.js): `h( tag: 'name.class1.class2', attrs?, ...children ): HTMLElement` (attrs: `on*` listeners, `dataset`, `value`, `checked`, booleans); `toast( message, kind = 'ok'|'error', action?: {href,label} )`; `modal( {title, body, actions:[{label, value, kind?, submit?}], onOpen?(dialog, close)} ): Promise<value|null>` (a `submit` action's `value` may be `form => any`); `confirmDialog( title, text, okLabel, kind ): Promise<boolean>`; `debounce( fn, ms )` with `.cancel()`.
- Produces (api.js): `config`; `api( method, path, body?, {form?: FormData, retry?: boolean} ): Promise<any>` (re-login modal on 401 and retry; GET/PUT/DELETE retry while offline, POST never); `class ApiError { status, code, data, message }`; window event `fs:online` (detail: boolean).
- Produces (views): `productsList( main: HTMLElement ): {destroy()}`.

- [ ] **Step 1: `studio/app/js/ui.js`**

```js
/**
 * Fisha Studio DOM helpers: h() builds elements, toast() and modal() talk to the owner.
 */
export function h( tag, attrs = {}, ...children ) {
	const [ name, ...classes ] = tag.split( '.' );
	const el = document.createElement( name || 'div' );
	if ( classes.length ) el.className = classes.join( ' ' );
	for ( const [ k, v ] of Object.entries( attrs || {} ) ) {
		if ( v === undefined || v === null || v === false ) continue;
		if ( k === 'class' ) el.className = [ el.className, v ].filter( Boolean ).join( ' ' );
		else if ( k === 'dataset' ) Object.assign( el.dataset, v );
		else if ( k.startsWith( 'on' ) && typeof v === 'function' ) el.addEventListener( k.slice( 2 ), v );
		else if ( k === 'value' ) el.value = v;
		else if ( k === 'checked' ) el.checked = !! v;
		else if ( v === true ) el.setAttribute( k, '' );
		else el.setAttribute( k, v );
	}
	for ( const c of children.flat( Infinity ) ) {
		if ( c === null || c === undefined || c === false ) continue;
		el.append( c instanceof Node ? c : document.createTextNode( String( c ) ) );
	}
	return el;
}

let toastBox = null;
export function toast( message, kind = 'ok', action = null ) {
	toastBox ??= document.body.appendChild( h( 'div.fs-toasts', { role: 'status', 'aria-live': 'polite' } ) );
	const t = h( 'div.fs-toast.fs-toast--' + kind, {},
		h( 'span', {}, message ),
		action && action.href ? h( 'a', { href: action.href, target: '_blank', rel: 'noopener' }, action.label ) : null );
	toastBox.append( t );
	setTimeout( () => t.classList.add( 'is-leaving' ), kind === 'error' ? 8000 : 4500 );
	setTimeout( () => t.remove(), kind === 'error' ? 8600 : 5100 );
}

export function modal( { title, body, actions = [ { label: 'OK', value: true, kind: 'primary' } ], onOpen } ) {
	return new Promise( ( resolve ) => {
		const dlg = h( 'dialog.fs-modal', { 'aria-labelledby': 'fs-modal-title' } );
		let done = false;
		const close = ( v ) => {
			if ( done ) return;
			done = true;
			dlg.close();
			dlg.remove();
			resolve( v );
		};
		const form = h( 'form.fs-modal__form', {
			onsubmit: ( e ) => {
				e.preventDefault();
				const a = actions.find( ( x ) => x.submit );
				if ( a ) close( typeof a.value === 'function' ? a.value( form ) : a.value );
			},
		},
		h( 'h2.fs-modal__title', { id: 'fs-modal-title' }, title ),
		h( 'div.fs-modal__body', {}, body ),
		h( 'div.fs-modal__actions', {}, actions.map( ( a ) => h( 'button.fs-btn' + ( a.kind ? '.fs-btn--' + a.kind : '' ), {
			type: a.submit ? 'submit' : 'button',
			onclick: a.submit ? null : () => close( a.value ),
		}, a.label ) ) ) );
		dlg.append( form );
		dlg.addEventListener( 'cancel', ( e ) => {
			e.preventDefault();
			close( null );
		} );
		document.body.append( dlg );
		dlg.showModal();
		onOpen?.( dlg, close );
	} );
}

export function confirmDialog( title, text, okLabel = 'OK', kind = 'primary' ) {
	return modal( { title, body: h( 'p', {}, text ), actions: [ { label: 'Cancel', value: false }, { label: okLabel, value: true, kind } ] } ).then( Boolean );
}

export function debounce( fn, ms ) {
	let t;
	const d = ( ...args ) => {
		clearTimeout( t );
		t = setTimeout( () => fn( ...args ), ms );
	};
	d.cancel = () => clearTimeout( t );
	return d;
}
```

- [ ] **Step 2: `studio/app/js/api.js`**

```js
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

async function send( method, path, body, form ) {
	const headers = { 'X-WP-Nonce': nonce, Accept: 'application/json' };
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
				const r = await send( 'POST', 'login', values );
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
```

- [ ] **Step 3: `studio/app/js/views/products-list.js`**

```js
/**
 * Fisha Studio product list: search and filters (remembered for the browser tab), status badges, and quick
 * price/stock edits that publish straight away for live simple products without pending changes.
 */
import { h, toast, modal, debounce } from '../ui.js';
import { api } from '../api.js';

export function productsList( main ) {
	const state = { search: '', category: '', state: 'all', stock: 'all', page: 1 };
	try {
		Object.assign( state, JSON.parse( sessionStorage.getItem( 'fs-list' ) || '{}' ) );
	} catch {}
	let alive = true;
	let seq = 0;

	const table = h( 'div.fs-list' );
	const pager = h( 'div.fs-pager' );
	const select = ( key, label, options ) => h( 'select.fs-input', { 'aria-label': label, onchange: ( e ) => update( { [ key ]: e.target.value } ) },
		options.map( ( [ v, t ] ) => h( 'option', { value: v, selected: state[ key ] === v }, t ) ) );
	const catSelect = h( 'select.fs-input', { 'aria-label': 'Category', onchange: ( e ) => update( { category: e.target.value } ) }, h( 'option', { value: '' }, 'All categories' ) );

	main.append(
		h( 'div.fs-page-head', {},
			h( 'h1', {}, 'Products' ),
			h( 'button.fs-btn.fs-btn--primary', { type: 'button', onclick: createProduct }, '+ New product' ) ),
		h( 'div.fs-filters', {},
			h( 'input.fs-input.fs-search', { type: 'search', placeholder: 'Search by name or SKU', value: state.search, 'aria-label': 'Search products', oninput: debounce( ( e ) => update( { search: e.target.value } ), 300 ) } ),
			catSelect,
			select( 'state', 'Status', [ [ 'all', 'Any status' ], [ 'live', 'Live' ], [ 'changed', 'Unpublished changes' ], [ 'new', 'Drafts' ], [ 'hidden', 'Hidden' ] ] ),
			select( 'stock', 'Stock', [ [ 'all', 'Any stock' ], [ 'instock', 'In stock' ], [ 'low', 'Low stock' ], [ 'outofstock', 'Sold out' ] ] ) ),
		table,
		pager );

	api( 'GET', 'lookups' ).then( ( l ) => {
		for ( const c of l.categories ) catSelect.append( h( 'option', { value: c.id, selected: String( state.category ) === String( c.id ) }, c.path ) );
	} ).catch( () => {} );

	function update( patch ) {
		Object.assign( state, { page: 1 }, patch );
		try {
			sessionStorage.setItem( 'fs-list', JSON.stringify( state ) );
		} catch {}
		load();
	}

	async function load() {
		const my = ++seq;
		table.classList.add( 'is-loading' );
		const q = new URLSearchParams( { search: state.search, category: state.category, state: state.state, stock: state.stock, page: state.page, per_page: 50 } );
		try {
			const d = await api( 'GET', 'products?' + q );
			if ( alive && my === seq ) render( d );
		} catch ( e ) {
			if ( alive ) table.replaceChildren( h( 'p.fs-empty', {}, e.message ) );
		} finally {
			table.classList.remove( 'is-loading' );
		}
	}

	function render( d ) {
		if ( ! d.items.length ) {
			table.replaceChildren( h( 'p.fs-empty', {}, 'No products match. Try another search or filter.' ) );
			pager.replaceChildren();
			return;
		}
		table.replaceChildren( h( 'table.fs-table', {},
			h( 'thead', {}, h( 'tr', {},
				h( 'th', { scope: 'col' }, h( 'span.fs-sr', {}, 'Photo' ) ),
				h( 'th', { scope: 'col' }, 'Product' ),
				h( 'th', { scope: 'col' }, 'Price' ),
				h( 'th', { scope: 'col' }, 'Stock' ),
				h( 'th', { scope: 'col' }, h( 'span.fs-sr', {}, 'Actions' ) ) ) ),
			h( 'tbody', {}, d.items.map( row ) ) ) );
		pager.replaceChildren( ...( d.pages > 1 ? [
			h( 'button.fs-btn.fs-btn--small', { type: 'button', disabled: state.page <= 1, onclick: () => update( { page: state.page - 1 } ) }, '← Previous' ),
			h( 'span', {}, `Page ${ state.page } of ${ d.pages } · ${ d.total } products` ),
			h( 'button.fs-btn.fs-btn--small', { type: 'button', disabled: state.page >= d.pages, onclick: () => update( { page: state.page + 1 } ) }, 'Next →' ),
		] : [ h( 'span', {}, `${ d.total } products` ) ] ) );
	}

	function badges( r ) {
		const b = [];
		if ( r.state === 'new' ) b.push( [ 'Draft', 'draft' ] );
		if ( r.state === 'changed' ) b.push( [ 'Unpublished changes', 'changed' ] );
		if ( r.hidden ) b.push( [ 'Hidden', 'hidden' ] );
		if ( r.stock_status === 'outofstock' ) b.push( [ 'Sold out', 'out' ] );
		else if ( r.low ) b.push( [ 'Low stock', 'low' ] );
		if ( ! r.editable ) b.push( [ 'Has sizes', 'info' ] );
		return b.map( ( [ t, k ] ) => h( 'span.fs-badge.fs-badge--' + k, {}, t ) );
	}

	function row( r ) {
		const quick = r.editable && r.state === 'live';
		const price = quick
			? h( 'input.fs-input.fs-inline', { inputmode: 'decimal', value: r.regular_price, 'aria-label': `Price of ${ r.name }` } )
			: h( 'span', {}, r.price_text || 'Price on request' );
		const stock = quick && r.manage_stock
			? h( 'input.fs-input.fs-inline.fs-inline--short', { inputmode: 'numeric', value: r.stock_quantity ?? '', 'aria-label': `Stock of ${ r.name }` } )
			: h( 'span', {}, r.manage_stock ? String( r.stock_quantity ?? 0 ) : ( r.stock_status === 'outofstock' ? 'Sold out' : 'In stock' ) );
		const save = h( 'button.fs-btn.fs-btn--small.fs-btn--primary', { type: 'button', hidden: true, onclick: () => quickSave() }, 'Publish' );
		const tr = h( 'tr', {},
			h( 'td.fs-table__thumb', {}, r.thumb ? h( 'img', { src: r.thumb, alt: '', width: 48, height: 48, loading: 'lazy' } ) : h( 'span.fs-thumb-empty' ) ),
			h( 'td', {},
				h( 'a.fs-table__name', { href: '#/products/' + r.id }, r.name ),
				h( 'div.fs-table__meta', {}, [ r.categories.join( ', ' ), r.sku ].filter( Boolean ).join( ' · ' ) ),
				h( 'div.fs-badges', {}, badges( r ) ) ),
			h( 'td', { 'data-label': 'Price' }, price ),
			h( 'td', { 'data-label': 'Stock' }, stock ),
			h( 'td.fs-table__actions', {}, save, h( 'a.fs-btn.fs-btn--small', { href: '#/products/' + r.id }, 'Edit' ) ) );
		const changed = () => ( price.tagName === 'INPUT' && price.value !== r.regular_price )
			|| ( stock.tagName === 'INPUT' && stock.value !== String( r.stock_quantity ?? '' ) );
		for ( const input of [ price, stock ] ) {
			if ( input.tagName !== 'INPUT' ) continue;
			input.addEventListener( 'input', () => { save.hidden = ! changed(); } );
			input.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Enter' && changed() ) quickSave(); } );
		}
		async function quickSave() {
			const fields = {};
			if ( price.tagName === 'INPUT' && price.value !== r.regular_price ) fields.regular_price = price.value;
			if ( stock.tagName === 'INPUT' && stock.value !== String( r.stock_quantity ?? '' ) ) fields.stock_quantity = stock.value;
			save.disabled = true;
			try {
				const next = await api( 'POST', `products/${ r.id }/quick`, { fields } );
				tr.replaceWith( row( next ) );
				toast( `${ r.name } is updated on the site.` );
			} catch ( e ) {
				save.disabled = false;
				toast( e.message, 'error' );
			}
		}
		return tr;
	}

	async function createProduct() {
		const name = await modal( {
			title: 'New product',
			body: [
				h( 'label.fs-field__label', {}, 'Name', h( 'input.fs-input', { name: 'name', required: true, maxlength: 120, autocomplete: 'off' } ) ),
				h( 'p.fs-field__help', {}, 'It stays hidden from the shop until you publish it.' ),
			],
			actions: [ { label: 'Cancel', value: null }, { label: 'Create', kind: 'primary', submit: true, value: ( f ) => f.elements.name.value.trim() } ],
		} );
		if ( ! name ) return;
		try {
			const p = await api( 'POST', 'products', { name } );
			location.hash = '#/products/' + p.id;
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	load();
	return { destroy() { alive = false; } };
}
```

- [ ] **Step 4: `studio/app/js/app.js`**

```js
/**
 * Fisha Studio app: top bar, navigation, offline banner and the hash router (#/products, #/products/123).
 */
import { h } from './ui.js';
import { config } from './api.js';
import { productsList } from './views/products-list.js';

const root = document.getElementById( 'fs-app' );
const main = h( 'main.fs-main', { id: 'fs-main', tabindex: '-1' } );
const offline = h( 'div.fs-offline', { role: 'status', hidden: true }, 'You are offline. Your changes are kept and will be saved when the connection is back.' );
const nav = h( 'nav.fs-nav', { 'aria-label': 'Studio sections' },
	h( 'a.fs-nav__item', { href: '#/products', dataset: { section: 'products' } }, 'Products' ) );

root.replaceChildren(
	h( 'header.fs-top', {},
		h( 'a.fs-brand', { href: '#/products' }, h( 'img', { src: config.logo, alt: '', width: 44, height: 31 } ), h( 'span', {}, 'Fisha Studio' ) ),
		h( 'div.fs-top__right', {},
			h( 'a.fs-link', { href: config.home, target: '_blank', rel: 'noopener' }, 'View site' ),
			h( 'span.fs-user', {}, config.user ),
			h( 'a.fs-link', { href: config.logout }, 'Sign out' ) ) ),
	offline,
	h( 'div.fs-shell', {}, nav, main ) );
window.addEventListener( 'fs:online', ( e ) => { offline.hidden = e.detail; } );

const routes = [
	[ /^#\/products$/, () => productsList( main ) ],
];

let view = null;
let current = '';
async function route() {
	const hash = location.hash || '#/products';
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
	view = match ? match[ 1 ]( hash.match( match[ 0 ] ) ) : productsList( main );
	main.focus( { preventScroll: true } );
}
window.addEventListener( 'hashchange', route );
window.addEventListener( 'beforeunload', ( e ) => {
	if ( view?.dirty?.() ) {
		e.preventDefault();
		e.returnValue = '';
	}
} );
if ( ! location.hash ) history.replaceState( null, '', '#/products' );
route();
```

- [ ] **Step 5: `studio/app/studio.css`**

```css
/* Fisha Studio. Brand: Nunito, orange #E48734 (text orange #B5651D), paper #FAF7F2, ink #2B2622. */
:root {
	--fs-ink: #2b2622; --fs-muted: #6b625b; --fs-line: #e6dfd6; --fs-paper: #faf7f2; --fs-card: #ffffff;
	--fs-orange: #e48734; --fs-orange-text: #b5651d; --fs-orange-soft: #fbeee2;
	--fs-green: #2f7d4f; --fs-green-soft: #e3f2e8; --fs-red: #b42318; --fs-red-soft: #fde8e6;
	--fs-blue: #1f5f8b; --fs-blue-soft: #e4eff7; --fs-yellow-soft: #fff4d6;
	--fs-radius: 12px; --fs-shadow: 0 1px 2px rgba(43, 38, 34, .06), 0 4px 16px rgba(43, 38, 34, .06);
	color-scheme: light;
}
* { box-sizing: border-box; }
html, body { margin: 0; }
body { font: 16px/1.5 Nunito, system-ui, sans-serif; color: var(--fs-ink); background: var(--fs-paper); -webkit-font-smoothing: antialiased; }
a { color: var(--fs-orange-text); }
h1 { font-size: 1.6rem; line-height: 1.2; margin: 0; font-weight: 800; }
h2 { font-size: 1.15rem; margin: 0 0 .5rem; }
:focus-visible { outline: 3px solid var(--fs-orange); outline-offset: 2px; }
.fs-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.fs-boot { padding: 3rem; text-align: center; color: var(--fs-muted); }

/* Buttons and inputs */
.fs-btn { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; min-height: 44px; padding: .5rem 1rem; border: 1px solid var(--fs-line); border-radius: 999px; background: var(--fs-card); color: var(--fs-ink); font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
.fs-btn:hover { border-color: var(--fs-ink); }
.fs-btn:disabled { opacity: .5; cursor: not-allowed; }
.fs-btn--primary { background: var(--fs-ink); border-color: var(--fs-ink); color: #fff; }
.fs-btn--primary:hover { background: #000; }
.fs-btn--danger { color: var(--fs-red); }
.fs-btn--small { min-height: 36px; padding: .3rem .8rem; font-size: .9rem; }
.fs-btn--tiny { min-height: 32px; padding: .2rem .6rem; font-size: .85rem; }
.fs-link { color: var(--fs-ink); font-weight: 700; text-decoration: none; }
.fs-link:hover { text-decoration: underline; }
.fs-input { width: 100%; min-height: 44px; padding: .55rem .75rem; border: 1px solid var(--fs-line); border-radius: 10px; background: #fff; font: inherit; color: inherit; }
.fs-input:focus { border-color: var(--fs-orange); outline: none; box-shadow: 0 0 0 3px var(--fs-orange-soft); }
textarea.fs-input { resize: vertical; }
.fs-input--small { min-height: 36px; padding: .3rem .5rem; width: auto; }
.fs-input--hex { width: 7.5rem; }

/* Sign-in page */
.fs-login-page { min-height: 100vh; display: grid; place-items: center; padding: 16px; }
.fs-login { width: 100%; max-width: 380px; background: var(--fs-card); border-radius: 20px; box-shadow: var(--fs-shadow); padding: 2rem 1.5rem; text-align: center; }
.fs-login__logo { width: 96px; height: auto; }
.fs-login__sub { color: var(--fs-muted); margin: .25rem 0 1.25rem; }
.fs-login form { display: grid; gap: .9rem; text-align: left; }
.fs-login label { display: grid; gap: .3rem; font-weight: 700; }
.fs-login input { min-height: 44px; padding: .55rem .75rem; border: 1px solid var(--fs-line); border-radius: 10px; font: inherit; }
.fs-login__error { background: var(--fs-red-soft); color: var(--fs-red); padding: .6rem .8rem; border-radius: 10px; }
.fs-login__forgot { display: inline-block; margin-top: 1rem; font-size: .9rem; }

/* Shell */
.fs-top { position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .5rem 16px; background: var(--fs-card); border-bottom: 1px solid var(--fs-line); }
.fs-brand { display: flex; align-items: center; gap: .5rem; color: var(--fs-ink); font-weight: 800; text-decoration: none; }
.fs-top__right { display: flex; align-items: center; gap: 1rem; font-size: .95rem; }
.fs-user { color: var(--fs-muted); }
.fs-offline { background: var(--fs-yellow-soft); padding: .6rem 16px; text-align: center; font-weight: 700; }
.fs-shell { display: grid; grid-template-columns: 200px 1fr; min-height: calc(100vh - 60px); }
.fs-nav { padding: 1rem .75rem; border-right: 1px solid var(--fs-line); display: grid; align-content: start; gap: .25rem; }
.fs-nav__item { display: block; padding: .6rem .9rem; border-radius: 10px; color: var(--fs-ink); font-weight: 700; text-decoration: none; }
.fs-nav__item.is-active { background: var(--fs-orange-soft); color: var(--fs-orange-text); }
.fs-main { padding: 1.25rem 1.5rem 4rem; min-width: 0; outline: none; }
.fs-page-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }

/* List */
.fs-filters { display: grid; grid-template-columns: 2fr repeat(3, 1fr); gap: .6rem; margin-bottom: 1rem; }
.fs-list.is-loading { opacity: .55; }
.fs-table { width: 100%; border-collapse: separate; border-spacing: 0; background: var(--fs-card); border-radius: var(--fs-radius); box-shadow: var(--fs-shadow); overflow: hidden; }
.fs-table th { text-align: left; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: var(--fs-muted); padding: .7rem .8rem; border-bottom: 1px solid var(--fs-line); }
.fs-table td { padding: .6rem .8rem; border-bottom: 1px solid var(--fs-line); vertical-align: middle; }
.fs-table tr:last-child td { border-bottom: 0; }
.fs-table__thumb { width: 64px; }
.fs-table__thumb img, .fs-thumb-empty { display: block; width: 48px; height: 48px; border-radius: 8px; object-fit: cover; background: var(--fs-paper); }
.fs-table__name { font-weight: 800; color: var(--fs-ink); text-decoration: none; }
.fs-table__name:hover { text-decoration: underline; }
.fs-table__meta { color: var(--fs-muted); font-size: .85rem; }
.fs-table__actions { text-align: right; white-space: nowrap; }
.fs-table__actions .fs-btn + .fs-btn { margin-left: .4rem; }
.fs-inline { width: 7rem; min-height: 38px; }
.fs-inline--short { width: 5rem; }
.fs-badges { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .25rem; }
.fs-badge, .fs-pill { display: inline-block; padding: .1rem .55rem; border-radius: 999px; font-size: .78rem; font-weight: 800; }
.fs-badge--draft, .fs-pill--draft { background: var(--fs-blue-soft); color: var(--fs-blue); }
.fs-badge--changed, .fs-pill--changed { background: var(--fs-orange-soft); color: var(--fs-orange-text); }
.fs-pill--live { background: var(--fs-green-soft); color: var(--fs-green); }
.fs-badge--hidden, .fs-badge--info { background: #eee9e3; color: var(--fs-muted); }
.fs-badge--out { background: var(--fs-red-soft); color: var(--fs-red); }
.fs-badge--low { background: var(--fs-yellow-soft); color: #7a5a00; }
.fs-empty { padding: 2rem; text-align: center; color: var(--fs-muted); background: var(--fs-card); border-radius: var(--fs-radius); }
.fs-pager { display: flex; align-items: center; justify-content: center; gap: 1rem; margin-top: 1rem; color: var(--fs-muted); }

/* Toasts and modal */
.fs-toasts { position: fixed; z-index: 100; right: 16px; bottom: 16px; display: grid; gap: .5rem; max-width: min(420px, calc(100vw - 32px)); }
.fs-toast { display: flex; gap: 1rem; justify-content: space-between; align-items: center; padding: .8rem 1rem; border-radius: 12px; background: var(--fs-ink); color: #fff; box-shadow: var(--fs-shadow); transition: opacity .4s, transform .4s; }
.fs-toast--error { background: var(--fs-red); }
.fs-toast a { color: #fff; font-weight: 800; white-space: nowrap; }
.fs-toast.is-leaving { opacity: 0; transform: translateY(8px); }
.fs-modal { border: 0; border-radius: 18px; padding: 0; width: min(520px, calc(100vw - 32px)); box-shadow: 0 20px 60px rgba(0, 0, 0, .25); }
.fs-modal::backdrop { background: rgba(43, 38, 34, .45); }
.fs-modal__form { padding: 1.4rem; display: grid; gap: 1rem; }
.fs-modal__body { display: grid; gap: .8rem; max-height: 60vh; overflow: auto; }
.fs-modal__actions { display: flex; justify-content: flex-end; gap: .5rem; flex-wrap: wrap; }
.fs-conflict { border: 1px solid var(--fs-line); border-radius: 12px; padding: .8rem 1rem; }
.fs-conflict legend { font-weight: 800; padding: 0 .3rem; }
.fs-radio { display: flex; gap: .5rem; align-items: center; min-height: 36px; }
.fs-library { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: .5rem; }
.fs-library__item { padding: 0; border: 2px solid transparent; border-radius: 10px; background: none; cursor: pointer; width: 100%; }
.fs-library__item:hover, .fs-library__item:focus-visible { border-color: var(--fs-orange); }
.fs-library__item img { display: block; width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; }

/* Editor */
.fs-main--editor { padding: 0; }
.fs-editor { display: grid; grid-template-columns: minmax(420px, 1fr) minmax(380px, 46%); min-height: calc(100vh - 60px); }
.fs-ed-main { padding: 1rem 1.5rem 5rem; min-width: 0; }
.fs-ed-side { position: sticky; top: 60px; height: calc(100vh - 60px); border-left: 1px solid var(--fs-line); background: #efe9e1; min-width: 0; }
.fs-ed-head { display: grid; gap: .5rem; margin-bottom: 1rem; }
.fs-back { font-weight: 700; text-decoration: none; color: var(--fs-muted); }
.fs-ed-title { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; }
.fs-save-state { color: var(--fs-muted); font-size: .9rem; }
.fs-ed-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
.fs-note { margin: 0; padding: .7rem .9rem; border-radius: 10px; background: var(--fs-blue-soft); color: var(--fs-blue); }
.fs-tabs { display: flex; gap: .25rem; overflow-x: auto; border-bottom: 1px solid var(--fs-line); margin-bottom: 1.25rem; scrollbar-width: thin; }
.fs-tab { flex: none; min-height: 44px; padding: .5rem .9rem; border: 0; border-bottom: 3px solid transparent; background: none; font: inherit; font-weight: 700; color: var(--fs-muted); cursor: pointer; }
.fs-tab[aria-selected="true"] { color: var(--fs-ink); border-bottom-color: var(--fs-orange); }
.fs-tab.has-error::after { content: " •"; color: var(--fs-red); }
.fs-panel { display: grid; gap: 1.1rem; max-width: 720px; }
.fs-panel[hidden] { display: none; }
.fs-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.fs-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: .75rem; }
.fs-field { display: grid; gap: .35rem; }
.fs-field[hidden] { display: none; }
.fs-field__label { font-weight: 800; display: grid; gap: .35rem; }
.fs-field__help { margin: 0; color: var(--fs-muted); font-size: .88rem; }
.fs-field__error { margin: 0; color: var(--fs-red); font-weight: 700; font-size: .9rem; }
.fs-field.has-error .fs-input, .fs-field.has-error .fs-rt { border-color: var(--fs-red); }
.fs-control { display: flex; align-items: center; gap: .5rem; }
.fs-affix { font-weight: 800; color: var(--fs-muted); }
.fs-counter { font-size: .8rem; color: var(--fs-muted); white-space: nowrap; }
.fs-counter.is-over { color: var(--fs-red); font-weight: 800; }
.fs-field--toggle { grid-template-columns: auto 1fr; align-items: center; column-gap: .75rem; }
.fs-field--toggle label { font-weight: 800; }
.fs-field--toggle .fs-field__help { grid-column: 2; }
.fs-switch { appearance: none; width: 48px; height: 28px; border-radius: 999px; background: #d6cec5; position: relative; cursor: pointer; margin: 0; transition: background .2s; }
.fs-switch::after { content: ""; position: absolute; top: 3px; left: 3px; width: 22px; height: 22px; border-radius: 50%; background: #fff; transition: transform .2s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
.fs-switch:checked { background: var(--fs-green); }
.fs-switch:checked::after { transform: translateX(20px); }
.fs-checks { display: grid; gap: .1rem; max-height: 260px; overflow: auto; padding: .4rem; border: 1px solid var(--fs-line); border-radius: 10px; background: #fff; }
.fs-check { display: flex; align-items: center; gap: .5rem; min-height: 36px; padding-left: calc(var(--depth, 0) * 1.25rem + .25rem); }
.fs-chips { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .4rem; }
.fs-chip { display: inline-flex; align-items: center; gap: .25rem; padding: .15rem .35rem .15rem .7rem; border-radius: 999px; background: var(--fs-orange-soft); font-weight: 700; font-size: .9rem; }
.fs-chip button { border: 0; background: none; font-size: 1.1rem; line-height: 1; cursor: pointer; min-width: 28px; min-height: 28px; }
.fs-color-row { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; }
.fs-swatches { display: flex; flex-wrap: wrap; gap: .4rem; }
.fs-swatch { width: 36px; height: 36px; border-radius: 50%; border: 2px solid var(--fs-line); cursor: pointer; }
.fs-swatch.is-on { border-color: var(--fs-ink); box-shadow: 0 0 0 2px #fff inset; }
.fs-color { width: 44px; height: 44px; padding: 0; border: 0; background: none; cursor: pointer; }
.fs-url { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding: .55rem .75rem; border: 1px dashed var(--fs-line); border-radius: 10px; background: #fff; word-break: break-all; }
.fs-url code { font: inherit; font-weight: 700; }
.fs-snippet { padding: 1rem; border-radius: 12px; background: #fff; border: 1px solid var(--fs-line); font-family: Arial, sans-serif; }
.fs-snippet__url { color: #202124; font-size: .85rem; }
.fs-snippet__title { color: #1a0dab; font-size: 1.2rem; margin: .15rem 0; }
.fs-snippet__desc { color: #4d5156; font-size: .9rem; }
.fs-danger-zone { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--fs-line); }
.fs-stock-actions { display: flex; flex-wrap: wrap; gap: .5rem; }

/* Rich text */
.fs-rt { border: 1px solid var(--fs-line); border-radius: 10px; background: #fff; overflow: hidden; }
.fs-rt__bar { display: flex; flex-wrap: wrap; gap: .15rem; padding: .3rem; border-bottom: 1px solid var(--fs-line); background: var(--fs-paper); }
.fs-rt__btn { min-width: 40px; min-height: 36px; border: 0; border-radius: 8px; background: none; font: inherit; font-weight: 800; cursor: pointer; }
.fs-rt__btn:hover { background: #fff; }
.fs-rt__area { min-height: 160px; padding: .75rem; outline: none; }
.fs-rt__area--small { min-height: 90px; }
.fs-rt__area:focus { box-shadow: inset 0 0 0 2px var(--fs-orange-soft); }
.fs-rt__area p { margin: 0 0 .6rem; }

/* Images */
.fs-images__list { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: .8rem; }
.fs-img { display: grid; gap: .4rem; padding: .5rem; border: 1px solid var(--fs-line); border-radius: 12px; background: #fff; }
.fs-img--main { border-color: var(--fs-orange); box-shadow: 0 0 0 2px var(--fs-orange-soft); }
.fs-img img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; background: var(--fs-paper); }
.fs-img.is-dragging { opacity: .4; }
.fs-img--busy { place-items: center; min-height: 170px; color: var(--fs-muted); }
.fs-img__tools { display: flex; flex-wrap: wrap; gap: .3rem; }
.fs-img__label { font-weight: 800; color: var(--fs-orange-text); font-size: .85rem; align-self: center; }
.fs-img__note { margin: 0; font-size: .8rem; color: var(--fs-muted); }
.fs-images__actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .8rem; }

/* Preview pane */
.fs-preview { display: grid; grid-template-rows: auto 1fr; height: 100%; }
.fs-preview__bar { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding: .5rem .75rem; background: var(--fs-card); border-bottom: 1px solid var(--fs-line); }
.fs-seg-group { display: inline-flex; border: 1px solid var(--fs-line); border-radius: 999px; overflow: hidden; }
.fs-seg { min-height: 36px; padding: .2rem .8rem; border: 0; background: #fff; font: inherit; font-weight: 700; cursor: pointer; }
.fs-seg[aria-pressed="true"] { background: var(--fs-ink); color: #fff; }
.fs-preview__stage { position: relative; overflow: auto; display: flex; justify-content: center; padding: .75rem; }
.fs-preview__device { position: relative; width: 100%; height: 100%; background: #fff; box-shadow: var(--fs-shadow); border-radius: 8px; overflow: hidden; }
.fs-preview[data-device="phone"] .fs-preview__device { width: 390px; max-width: 100%; border-radius: 28px; border: 10px solid var(--fs-ink); }
.fs-preview__frame { width: 100%; height: 100%; border: 0; display: block; }
.fs-preview__loading { position: absolute; top: .6rem; left: 50%; transform: translateX(-50%); padding: .25rem .8rem; border-radius: 999px; background: var(--fs-ink); color: #fff; font-size: .85rem; }
.fs-preview__error { position: absolute; inset: 0; display: grid; place-content: center; gap: .6rem; text-align: center; background: #fff; }
.fs-preview-toggle { display: none; }

/* Phones and small tablets */
@media (max-width: 1100px) {
	.fs-editor { grid-template-columns: 1fr; }
	.fs-ed-side { display: none; position: fixed; inset: 60px 0 0; height: auto; z-index: 30; border-left: 0; }
	.fs-editor.is-previewing .fs-ed-side { display: block; }
	.fs-preview-toggle { display: inline-flex; }
}
@media (max-width: 760px) {
	.fs-shell { grid-template-columns: 1fr; }
	.fs-nav { display: flex; padding: .5rem 16px; border-right: 0; border-bottom: 1px solid var(--fs-line); overflow-x: auto; }
	.fs-main { padding: 1rem 16px 4rem; }
	.fs-ed-main { padding: 1rem 16px 5rem; }
	.fs-top__right .fs-user { display: none; }
	.fs-filters, .fs-grid-2, .fs-grid-4 { grid-template-columns: 1fr; }
	.fs-table thead { display: none; }
	.fs-table, .fs-table tbody, .fs-table tr, .fs-table td { display: block; }
	.fs-table tr { display: grid; grid-template-columns: 56px 1fr; gap: .25rem .75rem; padding: .75rem; border-bottom: 1px solid var(--fs-line); }
	.fs-table td { border: 0; padding: 0; }
	.fs-table__thumb { grid-row: span 4; }
	.fs-table td[data-label]::before { content: attr(data-label) ": "; color: var(--fs-muted); font-weight: 700; }
	.fs-table__actions { text-align: left; }
	.fs-ed-actions { position: fixed; left: 0; right: 0; bottom: 0; z-index: 25; padding: .6rem 16px; background: var(--fs-card); border-top: 1px solid var(--fs-line); }
	.fs-ed-actions .fs-btn { flex: 1; }
}
```

- [ ] **Step 6: Check in the browser**

1. Create the local test owner once (Task 2's `tests/studio/README.md` commands).
2. Open http://localhost:8484/studio/ in the Browser pane → redirected to the sign-in page; sign in with the credentials in `tests/studio/local-owner.txt`.
3. Expected: the Products list loads with the shop's 45 products, thumbnails, prices, "Has sizes" badges on the 18 variable products, "Sold out" on the four Originals.
4. Search "clipper" → only Clippers. Filter Stock = Sold out → the four Originals. Reload: filters are remembered.
5. On a live simple product, change the price in the row → "Publish" appears → press Enter → toast "… is updated on the site." Open that product page on the site and confirm the new price; then set it back the same way.
6. `read_console_messages` with `onlyErrors: true` → no errors (no CSP violations).
7. Resize to mobile (375×812) → list turns into cards, no horizontal scroll. Reset to desktop.
8. Visit http://localhost:8484/wp-admin/ while signed in as the owner → lands back on `/studio/`.

- [ ] **Step 7: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/app
git commit -m "Studio UI: shell, API client with re-login and offline handling, product list with quick edit

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Product editor — fields, autosave, publish

**Files:**
- Create: `studio/app/js/fields.js`, `studio/app/js/richtext.js`, `studio/app/js/images.js`, `studio/app/js/views/product-editor.js`
- Modify: `studio/app/js/app.js` (add the editor route)

**Interfaces:**
- Consumes: ui.js, api.js (Task 13); REST products/lookups/media/tags (Tasks 10–11).
- Produces (fields.js): every widget returns `{ el, set(value), setError(message), focus() }` — `textField( label, value, onChange, {help, max, counter, type, inputmode, prefix, placeholder, list, multiline} )`, `selectField( label, value, options: [[value, text]], onChange, {help} )`, `toggleField( label, value, onChange, {help} )`, `categoriesField( label, ids, categories, onChange )`, `tagsField( label, ids, tags, onChange, onCreate(name): Promise<{id,name}> )`, `colorField( label, value, palette, onChange )`.
- Produces (richtext.js): `richText( label, html, onChange, {help, small} )` → widget; pastes as plain text.
- Produces (images.js): `imagesField( {image_id, gallery_image_ids}, images: {id: payload}, onChange({image_id, gallery_image_ids}) )` → `{ el, set(values, images), setError(msg), focus() }`.
- Produces (editor): `productEditor( main, id ): { destroy(), canLeave(): Promise<boolean>, dirty(): boolean }`; `ed.hooks = { loaded: [], saved: [], published: [] }` arrays of `( payload ) => void` (used by Task 15).

- [ ] **Step 1: `studio/app/js/fields.js`**

```js
/**
 * Fisha Studio form widgets. Each returns { el, set(value), setError(message), focus() } and reports edits through
 * onChange. set() never overwrites a field the owner is typing in.
 */
import { h } from './ui.js';

let uid = 0;
const nextId = () => 'fs-f' + ++uid;

function wrap( label, control, id, help ) {
	const err = h( 'p.fs-field__error', { id: id + '-err', hidden: true } );
	const el = h( 'div.fs-field', {},
		label ? h( 'label.fs-field__label', { for: id }, label ) : null,
		control,
		help ? h( 'p.fs-field__help', {}, help ) : null,
		err );
	return {
		el,
		setError( msg ) {
			err.hidden = ! msg;
			err.textContent = msg || '';
			el.classList.toggle( 'has-error', !! msg );
		},
	};
}

export function textField( label, value, onChange, opts = {} ) {
	const id = nextId();
	const attrs = { id, value: value ?? '', maxlength: opts.max, inputmode: opts.inputmode, placeholder: opts.placeholder, list: opts.list, autocomplete: 'off', 'aria-describedby': id + '-err' };
	const input = opts.multiline ? h( 'textarea.fs-input', { ...attrs, rows: 3 } ) : h( 'input.fs-input', { ...attrs, type: opts.type || 'text' } );
	const counter = opts.counter ? h( 'span.fs-counter', { 'aria-live': 'polite' } ) : null;
	const count = () => {
		if ( ! counter ) return;
		counter.textContent = `${ input.value.length } / ${ opts.counter }`;
		counter.classList.toggle( 'is-over', input.value.length > opts.counter );
	};
	input.addEventListener( 'input', () => {
		count();
		onChange( input.value );
	} );
	count();
	const w = wrap( label, h( 'div.fs-control', {}, opts.prefix ? h( 'span.fs-affix', {}, opts.prefix ) : null, input, counter ), id, opts.help );
	return {
		...w,
		set( v ) {
			if ( document.activeElement === input ) return;
			input.value = v ?? '';
			count();
		},
		focus: () => input.focus(),
	};
}

export function selectField( label, value, options, onChange, opts = {} ) {
	const id = nextId();
	const sel = h( 'select.fs-input', { id, 'aria-describedby': id + '-err', onchange: () => onChange( sel.value ) },
		options.map( ( [ v, t ] ) => h( 'option', { value: v }, t ) ) );
	sel.value = String( value ?? '' );
	const w = wrap( label, sel, id, opts.help );
	return { ...w, set( v ) { sel.value = String( v ?? '' ); }, focus: () => sel.focus() };
}

export function toggleField( label, value, onChange, opts = {} ) {
	const id = nextId();
	const box = h( 'input.fs-switch', { id, type: 'checkbox', role: 'switch', checked: !! value, onchange: () => onChange( box.checked ) } );
	const el = h( 'div.fs-field.fs-field--toggle', {}, box, h( 'label', { for: id }, label ), opts.help ? h( 'p.fs-field__help', {}, opts.help ) : null );
	return { el, set( v ) { box.checked = !! v; }, setError() {}, focus: () => box.focus() };
}

export function categoriesField( label, value, categories, onChange ) {
	const id = nextId();
	let selected = new Set( ( value || [] ).map( Number ) );
	const kids = {};
	categories.forEach( ( c ) => ( kids[ c.parent ] ||= [] ).push( c ) );
	const ordered = [];
	const walk = ( pid, depth ) => ( kids[ pid ] || [] ).sort( ( a, b ) => a.name.localeCompare( b.name ) ).forEach( ( c ) => {
		ordered.push( [ c, depth ] );
		walk( c.id, depth + 1 );
	} );
	walk( 0, 0 );
	const boxes = {};
	const list = h( 'div.fs-checks', { role: 'group', 'aria-labelledby': id }, ordered.map( ( [ c, depth ] ) => {
		const b = h( 'input', { type: 'checkbox', value: c.id, checked: selected.has( c.id ), onchange: () => {
			if ( b.checked ) selected.add( c.id );
			else selected.delete( c.id );
			onChange( [ ...selected ].sort( ( x, y ) => x - y ) );
		} } );
		boxes[ c.id ] = b;
		return h( 'label.fs-check', { style: `--depth:${ depth }` }, b, c.name );
	} ) );
	const w = wrap( null, h( 'div', {}, h( 'p.fs-field__label', { id }, label ), list ), id, 'Pick the most specific one. A product in a sub-category also shows on its parent category page.' );
	return {
		...w,
		set( v ) {
			selected = new Set( ( v || [] ).map( Number ) );
			for ( const [ cid, b ] of Object.entries( boxes ) ) b.checked = selected.has( Number( cid ) );
		},
		focus: () => list.querySelector( 'input' )?.focus(),
	};
}

export function tagsField( label, value, tags, onChange, onCreate ) {
	const id = nextId();
	let ids = [ ...( value || [] ) ];
	const names = Object.fromEntries( tags.map( ( t ) => [ t.id, t.name ] ) );
	const chips = h( 'div.fs-chips' );
	const datalist = h( 'datalist', { id: id + '-list' }, tags.map( ( t ) => h( 'option', { value: t.name } ) ) );
	const input = h( 'input.fs-input', { id, list: id + '-list', placeholder: 'Type a tag and press Enter', autocomplete: 'off' } );
	const render = () => chips.replaceChildren( ...ids.map( ( tid ) => h( 'span.fs-chip', {}, names[ tid ] || '#' + tid,
		h( 'button', { type: 'button', 'aria-label': `Remove ${ names[ tid ] || tid }`, onclick: () => {
			ids = ids.filter( ( x ) => x !== tid );
			render();
			onChange( ids );
		} }, '×' ) ) ) );
	input.addEventListener( 'keydown', async ( e ) => {
		if ( e.key !== 'Enter' ) return;
		e.preventDefault();
		const name = input.value.trim();
		if ( ! name ) return;
		let t = tags.find( ( x ) => x.name.toLowerCase() === name.toLowerCase() );
		if ( ! t ) {
			try {
				t = await onCreate( name );
			} catch ( err ) {
				w.setError( err.message );
				return;
			}
			tags.push( t );
			names[ t.id ] = t.name;
			datalist.append( h( 'option', { value: t.name } ) );
		}
		w.setError( '' );
		if ( ! ids.includes( t.id ) ) {
			ids.push( t.id );
			onChange( ids );
		}
		input.value = '';
		render();
	} );
	render();
	const w = wrap( label, h( 'div', {}, chips, input, datalist ), id, 'Tags show on the product page under the description.' );
	return { ...w, set( v ) { ids = [ ...( v || [] ) ]; render(); }, focus: () => input.focus() };
}

export function colorField( label, value, palette, onChange ) {
	const id = nextId();
	let current = value || '';
	const swatches = h( 'div.fs-swatches', { role: 'radiogroup', 'aria-label': label } );
	const picker = h( 'input.fs-color', { type: 'color', 'aria-label': 'Pick any colour' } );
	const hex = h( 'input.fs-input.fs-input--hex', { id, maxlength: 7, 'aria-label': 'Colour code' } );
	const pick = ( c ) => {
		current = c.toLowerCase();
		render();
		onChange( current );
	};
	picker.addEventListener( 'input', () => pick( picker.value ) );
	hex.addEventListener( 'change', () => pick( hex.value.trim() ) );
	function render() {
		swatches.replaceChildren( ...palette.map( ( p ) => h( 'button.fs-swatch' + ( p.color === current ? '.is-on' : '' ), {
			type: 'button', role: 'radio', 'aria-checked': String( p.color === current ), 'aria-label': p.color,
			title: `${ p.color } · used on ${ p.count }`, style: `background:${ p.color }`, onclick: () => pick( p.color ),
		} ) ) );
		if ( /^#[0-9a-f]{6}$/.test( current ) ) picker.value = current;
		if ( document.activeElement !== hex ) hex.value = current;
	}
	render();
	const w = wrap( label, h( 'div.fs-color-row', {}, swatches, picker, hex ), id, 'The background behind the photo on product cards. The colours used most in the shop come first.' );
	return { ...w, set( v ) { current = v || ''; render(); }, focus: () => hex.focus() };
}
```

- [ ] **Step 2: `studio/app/js/richtext.js`**

```js
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
```

- [ ] **Step 3: `studio/app/js/images.js`**

```js
/**
 * Fisha Studio photos: the main image plus the gallery (drag or arrow buttons to reorder; the first gallery
 * photo is the one shoppers see when they hover a product card). Uploads go to the media library at once;
 * which photos the product uses is part of the draft. Photo descriptions (alt text) save straight away.
 */
import { h, toast, modal } from './ui.js';
import { api } from './api.js';

const OK_TYPES = /^image\/(jpeg|png|webp)$/;
const MAX = 15 * 1024 * 1024;

export function imagesField( values, images, onChange ) {
	const known = { ...images };
	let main = values.image_id || 0;
	let gallery = [ ...( values.gallery_image_ids || [] ) ];
	let dragFrom = null;

	const list = h( 'ul.fs-images__list' );
	const err = h( 'p.fs-field__error', { role: 'alert', hidden: true } );
	const fileInput = h( 'input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', multiple: true, hidden: true, onchange: ( e ) => upload( [ ...e.target.files ] ) } );
	const el = h( 'div.fs-field.fs-images', {},
		h( 'p.fs-field__label', {}, 'Photos' ),
		list,
		h( 'div.fs-images__actions', {},
			h( 'button.fs-btn', { type: 'button', onclick: () => fileInput.click() }, 'Upload photos' ),
			h( 'button.fs-btn', { type: 'button', onclick: pick }, 'Choose from uploaded' ),
			fileInput ),
		h( 'p.fs-field__help', {}, 'JPG, PNG or WebP, up to 15 MB. Square photos look best on cards.' ),
		err );

	const emit = () => {
		onChange( { image_id: main, gallery_image_ids: [ ...gallery ] } );
		render();
	};
	const move = ( i, by ) => {
		const j = i + by;
		if ( j < 0 || j >= gallery.length ) return;
		[ gallery[ i ], gallery[ j ] ] = [ gallery[ j ], gallery[ i ] ];
		emit();
	};

	function tile( id, isMain, index ) {
		const img = known[ id ];
		const li = h( 'li.fs-img' + ( isMain ? '.fs-img--main' : '' ), { draggable: isMain ? null : 'true' },
			img ? h( 'img', { src: img.medium || img.thumb, alt: img.alt || '' } ) : h( 'span.fs-img__missing', {}, 'Photo missing' ),
			h( 'div.fs-img__tools', {},
				isMain
					? h( 'span.fs-img__label', {}, 'Main photo' )
					: h( 'button.fs-btn.fs-btn--tiny', { type: 'button', onclick: () => {
						gallery.splice( index, 1 );
						if ( main ) gallery.unshift( main );
						main = id;
						emit();
					} }, 'Make main' ),
				! isMain && index > 0 ? h( 'button.fs-btn.fs-btn--tiny', { type: 'button', 'aria-label': 'Move earlier', onclick: () => move( index, -1 ) }, '←' ) : null,
				! isMain && index < gallery.length - 1 ? h( 'button.fs-btn.fs-btn--tiny', { type: 'button', 'aria-label': 'Move later', onclick: () => move( index, 1 ) }, '→' ) : null,
				h( 'button.fs-btn.fs-btn--tiny.fs-btn--danger', { type: 'button', 'aria-label': 'Remove photo', onclick: () => {
					if ( isMain ) main = gallery.shift() || 0;
					else gallery.splice( index, 1 );
					emit();
				} }, 'Remove' ) ),
			img ? h( 'input.fs-input', { value: img.alt, placeholder: 'Describe the photo', 'aria-label': 'Photo description', onchange: ( e ) => saveAlt( id, e.target.value ) } ) : null,
			! isMain && index === 0 ? h( 'p.fs-img__note', {}, 'Shown when shoppers hover the card' ) : null );
		if ( ! isMain ) {
			li.addEventListener( 'dragstart', () => { dragFrom = index; li.classList.add( 'is-dragging' ); } );
			li.addEventListener( 'dragend', () => li.classList.remove( 'is-dragging' ) );
			li.addEventListener( 'dragover', ( e ) => e.preventDefault() );
			li.addEventListener( 'drop', ( e ) => {
				e.preventDefault();
				if ( dragFrom === null || dragFrom === index ) return;
				const [ moved ] = gallery.splice( dragFrom, 1 );
				gallery.splice( index, 0, moved );
				dragFrom = null;
				emit();
			} );
		}
		return li;
	}

	function render() {
		list.replaceChildren(
			...( main ? [ tile( main, true, -1 ) ] : [ h( 'li.fs-img.fs-img--busy', {}, 'No main photo yet' ) ] ),
			...gallery.map( ( id, i ) => tile( id, false, i ) ) );
	}

	function add( img ) {
		known[ img.id ] = img;
		if ( ! main ) main = img.id;
		else if ( img.id !== main && ! gallery.includes( img.id ) ) gallery.push( img.id );
		emit();
	}

	async function upload( files ) {
		for ( const file of files ) {
			if ( ! OK_TYPES.test( file.type ) ) {
				toast( `${ file.name }: only JPG, PNG or WebP photos can be uploaded. On an iPhone, share the photo as JPG.`, 'error' );
				continue;
			}
			if ( file.size > MAX ) {
				toast( `${ file.name } is larger than 15 MB. Please use a smaller photo.`, 'error' );
				continue;
			}
			const busy = h( 'li.fs-img.fs-img--busy', {}, `Uploading ${ file.name }…` );
			list.append( busy );
			const form = new FormData();
			form.append( 'file', file );
			try {
				add( await api( 'POST', 'media', undefined, { form } ) );
			} catch ( e ) {
				toast( e.message, 'error' );
			} finally {
				busy.remove();
			}
		}
		fileInput.value = '';
	}

	async function pick() {
		let d;
		try {
			d = await api( 'GET', 'media' );
		} catch ( e ) {
			toast( e.message, 'error' );
			return;
		}
		const chosen = await modal( {
			title: 'Choose a photo',
			body: d.items.length
				? h( 'ul.fs-library', {}, d.items.map( ( img ) => h( 'li', {}, h( 'button.fs-library__item', { type: 'button', dataset: { id: img.id } }, h( 'img', { src: img.thumb, alt: img.alt || img.name } ) ) ) ) )
				: h( 'p', {}, 'No photos uploaded yet.' ),
			actions: [ { label: 'Cancel', value: null } ],
			onOpen: ( dlg, close ) => dlg.querySelectorAll( '.fs-library__item' ).forEach( ( b ) =>
				b.addEventListener( 'click', () => close( d.items.find( ( x ) => String( x.id ) === b.dataset.id ) ) ) ),
		} );
		if ( chosen ) add( chosen );
	}

	async function saveAlt( id, alt ) {
		try {
			known[ id ] = await api( 'PUT', 'media/' + id, { alt } );
			toast( 'Photo description saved.' );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	render();
	return {
		el,
		set( v, imgs ) {
			Object.assign( known, imgs || {} );
			main = v.image_id || 0;
			gallery = [ ...( v.gallery_image_ids || [] ) ];
			render();
		},
		setError( msg ) {
			err.hidden = ! msg;
			err.textContent = msg || '';
		},
		focus: () => fileInput.previousElementSibling?.focus(),
	};
}
```

- [ ] **Step 4: `studio/app/js/views/product-editor.js`**

```js
/**
 * Fisha Studio product editor. Every edit autosaves (0.6 s after typing stops) as a server-side draft; Publish
 * first waits for any pending save, then publishes exactly what is on screen. Live products stay untouched until
 * Publish; new products stay hidden until their first Publish.
 */
import { h, toast, modal, confirmDialog, debounce } from '../ui.js';
import { api } from '../api.js';
import { textField, selectField, toggleField, categoriesField, tagsField, colorField } from '../fields.js';
import { richText } from '../richtext.js';
import { imagesField } from '../images.js';

const TABS = [ [ 'basics', 'Basics' ], [ 'price', 'Price' ], [ 'inventory', 'Inventory' ], [ 'images', 'Photos' ], [ 'design', 'Fisha design' ], [ 'seo', 'SEO' ], [ 'shipping', 'Shipping & tax' ] ];
const TAB_OF = {
	name: 'basics', slug: 'basics', status: 'basics', category_ids: 'basics', tag_ids: 'basics', short_description: 'basics', description: 'basics', catalog_visibility: 'basics', featured: 'basics', reviews_allowed: 'basics', menu_order: 'basics',
	regular_price: 'price', sale_price: 'price', date_on_sale_from: 'price', date_on_sale_to: 'price',
	sku: 'inventory', manage_stock: 'inventory', stock_quantity: 'inventory', stock_status: 'inventory', low_stock_amount: 'inventory', backorders: 'inventory', sold_individually: 'inventory',
	image_id: 'images', gallery_image_ids: 'images',
	card_bg: 'design', meta_line: 'design', series: 'design', hero_skip: 'design',
	seo_title: 'seo', seo_desc: 'seo',
	weight: 'shipping', length: 'shipping', width: 'shipping', height: 'shipping', shipping_class_id: 'shipping', tax_status: 'shipping', tax_class: 'shipping',
};
let lookups = null;

export function productEditor( main, id ) {
	const ed = { data: null, pending: {}, saving: null, alive: true, changeUrl: false, widgets: {}, hooks: { loaded: [], saved: [], published: [] } };
	let tab = sessionStorage.getItem( 'fs-tab' ) || 'basics';

	const status = h( 'span.fs-save-state', { role: 'status', 'aria-live': 'polite' } );
	const head = h( 'div.fs-ed-head' );
	const tabBar = h( 'div.fs-tabs', { role: 'tablist', 'aria-label': 'Product sections' } );
	const panels = h( 'div' );
	const side = h( 'aside.fs-ed-side', { 'aria-label': 'Live preview' } );
	const editor = h( 'div.fs-editor', {}, h( 'div.fs-ed-main', {}, head, tabBar, panels ), side );
	main.classList.add( 'fs-main--editor' );
	main.append( editor );
	main.append( h( 'p.fs-empty', { id: 'fs-ed-loading' }, 'Loading product…' ) );

	const autosave = debounce( () => flush(), 600 );

	function change( key, value ) {
		ed.pending[ key ] = value;
		ed.data.values[ key ] = value;
		ed.widgets[ key ]?.setError( '' );
		markTab( key, false );
		status.textContent = 'Saving…';
		autosave();
		refreshDerived();
	}

	/** Saves pending edits. Resolves true when everything on screen is saved. */
	async function flush() {
		autosave.cancel();
		if ( ed.saving ) await ed.saving;
		if ( ! Object.keys( ed.pending ).length ) return true;
		const fields = ed.pending;
		ed.pending = {};
		let ok = true;
		ed.saving = ( async () => {
			try {
				const d = await api( 'PUT', `products/${ id }/draft`, { fields } );
				if ( ! ed.alive ) return;
				apply( d );
				const errs = d.field_errors || {};
				for ( const [ k, msg ] of Object.entries( errs ) ) setError( k, msg );
				if ( Object.keys( errs ).length ) ok = false;
				status.textContent = Object.keys( errs ).length ? 'Some fields need a look' : ( d.state === 'new' ? 'Saved (not on the site yet)' : 'Saved as draft' );
				ed.hooks.saved.forEach( ( f ) => f( d ) );
			} catch ( e ) {
				ok = false;
				ed.pending = { ...fields, ...ed.pending };
				status.textContent = 'Not saved yet';
				toast( e.message, 'error' );
			} finally {
				ed.saving = null;
			}
		} )();
		await ed.saving;
		if ( ok && Object.keys( ed.pending ).length ) return flush();
		return ok;
	}

	/** Takes a payload from the server, keeping edits typed since the request left. */
	function apply( d ) {
		const local = { ...ed.pending };
		ed.data = d;
		Object.assign( ed.data.values, local );
		renderHead();
		for ( const [ key, w ] of Object.entries( ed.widgets ) ) {
			if ( key === 'images' ) w.set( ed.data.values, ed.data.images );
			else if ( key in ed.data.values ) w.set( ed.data.values[ key ] );
		}
		refreshDerived();
	}

	function setError( key, msg ) {
		const w = ed.widgets[ key === 'image_id' || key === 'gallery_image_ids' ? 'images' : key ];
		w?.setError( msg );
		markTab( key, !! msg );
	}
	function clearErrors() {
		Object.values( ed.widgets ).forEach( ( w ) => w.setError( '' ) );
		tabBar.querySelectorAll( '.has-error' ).forEach( ( t ) => t.classList.remove( 'has-error' ) );
	}
	function markTab( key, on ) {
		const t = tabBar.querySelector( `[data-tab="${ TAB_OF[ key ] }"]` );
		if ( t && on ) t.classList.add( 'has-error' );
	}
	function showErrors( e ) {
		const fields = e.data?.fields || {};
		Object.entries( fields ).forEach( ( [ k, m ] ) => setError( k, m ) );
		const first = Object.keys( fields )[ 0 ];
		if ( first ) {
			openTab( TAB_OF[ first ] || 'basics' );
			ed.widgets[ first === 'image_id' || first === 'gallery_image_ids' ? 'images' : first ]?.focus();
		}
		toast( e.message, 'error' );
	}

	/* ---------- header and actions ---------- */

	function renderHead() {
		const d = ed.data;
		const pill = { new: [ 'Draft · not on the site yet', 'draft' ], changed: [ 'Unpublished changes', 'changed' ], live: [ 'Live', 'live' ] }[ d.state ];
		const actions = [];
		if ( ! d.editable ) {
			actions.push( h( 'p.fs-note', {}, 'This product has sizes or options. Editing those arrives in the next Studio update, so it is read-only here for now.' ) );
		} else {
			if ( d.state === 'changed' ) actions.push( h( 'button.fs-btn', { type: 'button', onclick: discard }, 'Discard changes' ) );
			if ( d.url ) actions.push( h( 'a.fs-btn', { href: d.url, target: '_blank', rel: 'noopener' }, 'View on site' ) );
			actions.push( h( 'button.fs-btn.fs-btn--primary.fs-publish', { type: 'button', onclick: () => publish(), disabled: d.state === 'live' }, d.state === 'new' ? 'Publish to the shop' : 'Publish changes' ) );
		}
		actions.push( h( 'button.fs-btn.fs-preview-toggle', { type: 'button', onclick: () => editor.classList.toggle( 'is-previewing' ) }, 'Preview' ) );
		head.replaceChildren(
			h( 'a.fs-back', { href: '#/products' }, '← All products' ),
			h( 'div.fs-ed-title', {}, h( 'h1', {}, d.values.name || 'Untitled' ), h( 'span.fs-pill.fs-pill--' + pill[ 1 ], {}, pill[ 0 ] ), status ),
			h( 'div.fs-ed-actions', {}, actions ) );
	}

	async function publish( resolutions = {} ) {
		const btn = head.querySelector( '.fs-publish' );
		if ( btn ) btn.disabled = true;
		status.textContent = 'Publishing…';
		try {
			if ( ! ( await flush() ) ) {
				status.textContent = 'Fix the highlighted fields, then publish';
				return;
			}
			const d = await api( 'POST', `products/${ id }/publish`, { resolutions, change_url: ed.changeUrl } );
			ed.changeUrl = false;
			clearErrors();
			apply( d );
			status.textContent = 'Published';
			toast( d.values.status === 'private' ? 'Saved. It is hidden from the shop.' : 'Published. It is on the site now.', 'ok', { href: d.published_url, label: 'View on site' } );
			ed.hooks.published.forEach( ( f ) => f( d ) );
		} catch ( e ) {
			if ( e.code === 'fisha_studio_conflict' ) {
				const chosen = await resolveConflicts( e.data.conflicts );
				if ( chosen ) return publish( { ...resolutions, ...chosen } );
				status.textContent = 'Not published';
				return;
			}
			status.textContent = 'Not published';
			showErrors( e );
		} finally {
			const b = head.querySelector( '.fs-publish' );
			if ( b ) b.disabled = ed.data.state === 'live';
		}
	}

	function show( c, v ) {
		if ( Array.isArray( v ) ) return v.length ? v.join( ', ' ) : 'none';
		if ( v === '' || v === null || v === undefined ) return c.field === 'stock_quantity' ? 'not counted' : '(empty)';
		if ( v === true ) return 'on';
		if ( v === false ) return 'off';
		return String( v );
	}
	function resolveConflicts( conflicts ) {
		return modal( {
			title: 'Something changed on the site',
			body: conflicts.map( ( c ) => h( 'fieldset.fs-conflict', {},
				h( 'legend', {}, c.label ),
				h( 'p', {}, `When you started editing it was ${ show( c, c.base ) }. The site now has ${ show( c, c.live ) }${ c.field === 'stock_quantity' ? ', probably because an order came in' : '' }.` ),
				h( 'label.fs-radio', {}, h( 'input', { type: 'radio', name: c.field, value: 'live', checked: true } ), `Keep ${ show( c, c.live ) }` ),
				h( 'label.fs-radio', {}, h( 'input', { type: 'radio', name: c.field, value: 'mine' } ), `Use mine: ${ show( c, c.mine ) }` ) ) ),
			actions: [ { label: 'Cancel', value: null }, { label: 'Publish', kind: 'primary', submit: true, value: ( f ) => Object.fromEntries( conflicts.map( ( c ) => [ c.field, f.elements[ c.field ].value ] ) ) } ],
		} );
	}

	async function discard() {
		if ( ! ( await confirmDialog( 'Discard changes?', 'Your unpublished changes to this product will be thrown away. The live product stays exactly as it is.', 'Discard', 'danger' ) ) ) return;
		autosave.cancel();
		ed.pending = {};
		if ( ed.saving ) await ed.saving;
		try {
			const d = await api( 'DELETE', `products/${ id }/draft` );
			clearErrors();
			apply( d );
			status.textContent = '';
			toast( 'Changes discarded.' );
			ed.hooks.saved.forEach( ( f ) => f( d ) );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	async function trash() {
		const isNew = ed.data.state === 'new';
		if ( ! ( await confirmDialog( isNew ? 'Delete this draft?' : 'Move to trash?', isNew ? 'This product was never published. It will be moved to the trash.' : 'The product disappears from the shop. It stays in the trash for 30 days and can be restored.', isNew ? 'Delete draft' : 'Move to trash', 'danger' ) ) ) return;
		autosave.cancel();
		ed.pending = {};
		try {
			await api( 'POST', `products/${ id }/trash` );
			toast( isNew ? 'Draft deleted.' : 'Moved to the trash.' );
			ed.alive = false;
			location.hash = '#/products';
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	async function changeAddress() {
		const v = ed.data.values;
		const slug = await modal( {
			title: 'Change the address',
			body: [
				h( 'p', {}, 'This product is live. Links people already shared, and search engines, use the current address. The old address will keep working and send visitors to the new one.' ),
				h( 'label.fs-field__label', {}, 'New address', h( 'input.fs-input', { name: 'slug', value: v.slug, required: true, pattern: '[a-z0-9]+(-[a-z0-9]+)*', maxlength: 60, title: 'Lowercase letters, numbers and single dashes' } ) ),
				h( 'p.fs-field__help', {}, ed.data.product_base + '…' ),
			],
			actions: [ { label: 'Cancel', value: null }, { label: 'Use this address', kind: 'primary', submit: true, value: ( f ) => f.elements.slug.value.trim() } ],
		} );
		if ( ! slug || slug === v.slug ) return;
		ed.changeUrl = true;
		change( 'slug', slug );
		urlBox.set( slug );
	}

	/* ---------- tabs and fields ---------- */

	function openTab( key ) {
		tab = key;
		try { sessionStorage.setItem( 'fs-tab', key ); } catch {}
		tabBar.querySelectorAll( '[role="tab"]' ).forEach( ( t ) => t.setAttribute( 'aria-selected', String( t.dataset.tab === key ) ) );
		panels.querySelectorAll( '[role="tabpanel"]' ).forEach( ( p ) => { p.hidden = p.dataset.tab !== key; } );
	}

	let urlBox = null;
	function urlField( v ) {
		const code = h( 'code' );
		const isNew = () => ed.data.state === 'new';
		const input = h( 'input.fs-input', { placeholder: 'made from the name', maxlength: 60, 'aria-label': 'Address' } );
		input.addEventListener( 'input', () => change( 'slug', input.value.trim().toLowerCase() ) );
		const err = h( 'p.fs-field__error', { hidden: true } );
		const box = h( 'div.fs-field', {}, h( 'p.fs-field__label', {}, 'Address' ), h( 'div.fs-url' ), err );
		const paint = ( slug ) => {
			code.textContent = ed.data.product_base + ( slug || '…' ) + '/';
			box.querySelector( '.fs-url' ).replaceChildren( code, isNew()
				? h( 'span.fs-field__help', {}, 'Made from the name until you type your own:' )
				: h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: changeAddress }, 'Change address' ) );
			if ( isNew() ) {
				if ( document.activeElement !== input ) input.value = slug || '';
				box.querySelector( '.fs-url' ).append( input );
			}
		};
		paint( v );
		return { el: box, set: paint, setError( m ) { err.hidden = ! m; err.textContent = m || ''; }, focus: () => ( isNew() ? input : box.querySelector( 'button' ) )?.focus() };
	}

	const derived = [];
	function refreshDerived() { derived.forEach( ( f ) => f( ed.data.values ) ); }
	const showIf = ( widget, test ) => derived.push( ( v ) => { widget.el.hidden = ! test( v ); } );

	function build( d ) {
		const v = d.values;
		const L = lookups;
		const W = ed.widgets;
		const on = ( key ) => ( value ) => change( key, value );
		const money = { inputmode: 'decimal', prefix: L.currency };

		W.name = textField( 'Name', v.name, on( 'name' ), { max: 120 } );
		W.slug = urlBox = urlField( v.slug );
		W.status = selectField( 'Visibility', v.status, [ [ 'publish', 'Live in the shop' ], [ 'private', 'Hidden (only you can see it)' ] ], on( 'status' ) );
		W.category_ids = categoriesField( 'Categories', v.category_ids, L.categories, on( 'category_ids' ) );
		W.tag_ids = tagsField( 'Tags', v.tag_ids, L.tags, on( 'tag_ids' ), ( name ) => api( 'POST', 'tags', { name } ) );
		W.short_description = richText( 'Short description', v.short_description, on( 'short_description' ), { small: true, help: 'Shown next to the photos and used by Google when there is no SEO description.' } );
		W.description = richText( 'Full description', v.description, on( 'description' ), { help: 'Shown in the Description tab under the product.' } );
		W.catalog_visibility = selectField( 'Where it shows', v.catalog_visibility, [ [ 'visible', 'Shop and search' ], [ 'catalog', 'Shop only' ], [ 'search', 'Search only' ], [ 'hidden', 'Only by direct link' ] ], on( 'catalog_visibility' ) );
		W.featured = toggleField( 'Featured product', v.featured, on( 'featured' ) );
		W.reviews_allowed = toggleField( 'Allow reviews', v.reviews_allowed, on( 'reviews_allowed' ) );

		// "Price on request" = no price at all. A new product starts with the price fields open.
		let onRequest = d.state !== 'new' && v.regular_price === '';
		const onRequestField = toggleField( 'Price on request', onRequest, ( yes ) => {
			onRequest = yes;
			if ( yes ) {
				change( 'regular_price', '' );
				change( 'sale_price', '' );
				W.regular_price.set( '' );
				W.sale_price.set( '' );
			} else {
				refreshDerived();
				W.regular_price.focus();
			}
		}, { help: 'Cards show "Price on request" and the product cannot be added to the bag.' } );
		W.regular_price = textField( 'Price', v.regular_price, on( 'regular_price' ), money );
		W.sale_price = textField( 'Sale price', v.sale_price, on( 'sale_price' ), { ...money, help: 'Leave empty for no sale. Cards show a "Sale" badge while it runs.' } );
		W.date_on_sale_from = textField( 'Sale starts', v.date_on_sale_from, on( 'date_on_sale_from' ), { type: 'date', help: 'Optional' } );
		W.date_on_sale_to = textField( 'Sale ends', v.date_on_sale_to, on( 'date_on_sale_to' ), { type: 'date', help: 'Optional' } );
		showIf( W.regular_price, () => ! onRequest );
		showIf( W.sale_price, ( x ) => ! onRequest && x.regular_price !== '' );
		showIf( W.date_on_sale_from, ( x ) => ! onRequest && x.sale_price !== '' );
		showIf( W.date_on_sale_to, ( x ) => ! onRequest && x.sale_price !== '' );

		W.sku = textField( 'SKU', v.sku, on( 'sku' ), { max: 64, help: 'Your own stock code. Optional.' } );
		W.manage_stock = toggleField( 'Count stock', v.manage_stock, on( 'manage_stock' ), { help: 'The shop marks it sold out when the count reaches 0.' } );
		W.stock_quantity = textField( 'In stock', v.stock_quantity ?? '', on( 'stock_quantity' ), { inputmode: 'numeric' } );
		W.stock_status = selectField( 'Stock status', v.stock_status, [ [ 'instock', 'In stock' ], [ 'outofstock', 'Sold out' ], [ 'onbackorder', 'Can be ordered (made to order)' ] ], on( 'stock_status' ) );
		W.low_stock_amount = textField( 'Low stock alert at', v.low_stock_amount ?? '', on( 'low_stock_amount' ), { inputmode: 'numeric', help: 'The Studio flags it as low stock at this count (2 when empty).' } );
		W.backorders = selectField( 'When sold out', v.backorders, [ [ 'no', 'Stop selling' ], [ 'notify', 'Keep selling, tell the customer' ], [ 'yes', 'Keep selling' ] ], on( 'backorders' ) );
		W.sold_individually = toggleField( 'One per order', v.sold_individually, on( 'sold_individually' ), { help: 'For one-of-a-kind pieces.' } );
		showIf( W.stock_quantity, ( x ) => x.manage_stock );
		showIf( W.low_stock_amount, ( x ) => x.manage_stock );
		showIf( W.backorders, ( x ) => x.manage_stock );
		showIf( W.stock_status, ( x ) => ! x.manage_stock );
		const soldOut = h( 'button.fs-btn', { type: 'button', onclick: () => {
			if ( ed.data.values.manage_stock ) { change( 'stock_quantity', 0 ); W.stock_quantity.set( 0 ); }
			else { change( 'stock_status', 'outofstock' ); W.stock_status.set( 'outofstock' ); }
		} }, 'Mark sold out' );
		const backIn = h( 'button.fs-btn', { type: 'button', onclick: () => {
			if ( ed.data.values.manage_stock ) { openTab( 'inventory' ); W.stock_quantity.focus(); }
			else { change( 'stock_status', 'instock' ); W.stock_status.set( 'instock' ); }
		} }, 'Back in stock' );

		W.images = imagesField( v, d.images, ( x ) => {
			change( 'image_id', x.image_id );
			change( 'gallery_image_ids', x.gallery_image_ids );
		} );

		W.card_bg = colorField( 'Card background', v.card_bg, L.palette, on( 'card_bg' ) );
		W.meta_line = textField( 'Line under the name', v.meta_line, on( 'meta_line' ), { max: 80, help: 'Shown on cards under the name, e.g. "Ink on cotton paper · 33 × 33 cm" or "4 colours".' } );
		const seriesList = 'fs-series-list';
		W.series = textField( 'Design series', v.series, on( 'series' ), { list: seriesList, help: 'Products with the same series show a "Pick your design" row of each other. Leave empty for none.' } );
		W.hero_skip = toggleField( 'Keep out of the Shop page hero', v.hero_skip, on( 'hero_skip' ) );

		W.seo_title = textField( 'SEO title', v.seo_title, on( 'seo_title' ), { counter: 60, max: 70, help: '" | Fisha" is added after it. Empty: the product name is used.' } );
		W.seo_desc = textField( 'SEO description', v.seo_desc, on( 'seo_desc' ), { counter: 155, max: 170, multiline: true, help: 'One or two sentences for Google. Empty: the short description is used.' } );
		const snippet = h( 'div.fs-snippet', { 'aria-label': 'How it may look in Google' } );
		derived.push( ( x ) => {
			const strip = ( s ) => s.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
			const desc = x.seo_desc || strip( x.short_description || '' );
			snippet.replaceChildren(
				h( 'div.fs-snippet__url', {}, ed.data.product_base + ( x.slug || '…' ) + '/' ),
				h( 'div.fs-snippet__title', {}, ( x.seo_title || x.name || 'Untitled' ) + ' | Fisha' ),
				h( 'div.fs-snippet__desc', {}, desc.length > 158 ? desc.slice( 0, 155 ) + '…' : desc ) );
		} );

		W.weight = textField( 'Weight (kg)', v.weight, on( 'weight' ), { inputmode: 'decimal' } );
		W.length = textField( 'Length (cm)', v.length, on( 'length' ), { inputmode: 'decimal' } );
		W.width = textField( 'Width (cm)', v.width, on( 'width' ), { inputmode: 'decimal' } );
		W.height = textField( 'Height (cm)', v.height, on( 'height' ), { inputmode: 'decimal' } );
		W.shipping_class_id = selectField( 'Shipping class', v.shipping_class_id, [ [ '0', 'Standard' ], ...L.shipping_classes.map( ( s ) => [ String( s.id ), s.name ] ) ], on( 'shipping_class_id' ) );
		W.tax_status = selectField( 'Tax', v.tax_status, [ [ 'taxable', 'Taxable' ], [ 'shipping', 'Shipping only' ], [ 'none', 'No tax' ] ], on( 'tax_status' ) );
		W.tax_class = selectField( 'Tax class', v.tax_class, L.tax_classes.map( ( t ) => [ t.slug, t.name ] ), on( 'tax_class' ) );

		const panel = ( key, ...children ) => h( 'section.fs-panel', { role: 'tabpanel', id: 'fs-panel-' + key, 'aria-labelledby': 'fs-tab-' + key, dataset: { tab: key }, hidden: key !== tab }, children );
		tabBar.replaceChildren( ...TABS.map( ( [ key, label ] ) => h( 'button.fs-tab', { type: 'button', role: 'tab', id: 'fs-tab-' + key, 'aria-controls': 'fs-panel-' + key, 'aria-selected': String( key === tab ), dataset: { tab: key }, onclick: () => openTab( key ) }, label ) ) );
		const readOnly = ! d.editable;
		panels.replaceChildren(
			panel( 'basics', W.name.el, W.slug.el, W.status.el, W.category_ids.el, W.short_description.el, W.description.el, W.tag_ids.el,
				h( 'div.fs-grid-2', {}, W.catalog_visibility.el, h( 'div', {}, W.featured.el, W.reviews_allowed.el ) ),
				readOnly ? null : h( 'div.fs-danger-zone', {}, h( 'button.fs-btn.fs-btn--danger', { type: 'button', onclick: trash }, d.state === 'new' ? 'Delete this draft' : 'Move to trash' ) ) ),
			panel( 'price', onRequestField.el, h( 'div.fs-grid-2', {}, W.regular_price.el, W.sale_price.el ), h( 'div.fs-grid-2', {}, W.date_on_sale_from.el, W.date_on_sale_to.el ) ),
			panel( 'inventory', h( 'div.fs-stock-actions', {}, soldOut, backIn ), W.manage_stock.el, h( 'div.fs-grid-2', {}, W.stock_quantity.el, W.low_stock_amount.el ), W.stock_status.el, W.backorders.el, W.sold_individually.el, W.sku.el ),
			panel( 'images', W.images.el ),
			panel( 'design', W.card_bg.el, W.meta_line.el, W.series.el, h( 'datalist', { id: seriesList }, L.series.map( ( s ) => h( 'option', { value: s } ) ) ), W.hero_skip.el ),
			panel( 'seo', W.seo_title.el, W.seo_desc.el, h( 'p.fs-field__label', {}, 'Google preview' ), snippet ),
			panel( 'shipping', h( 'div.fs-grid-4', {}, W.weight.el, W.length.el, W.width.el, W.height.el ), W.shipping_class_id.el, h( 'div.fs-grid-2', {}, W.tax_status.el, W.tax_class.el ) ) );
		if ( readOnly ) panels.querySelectorAll( 'input, select, textarea, button, [contenteditable]' ).forEach( ( x ) => {
			if ( x.hasAttribute( 'contenteditable' ) ) x.setAttribute( 'contenteditable', 'false' );
			else x.disabled = true;
		} );
		refreshDerived();
	}

	/* ---------- load ---------- */

	( async () => {
		try {
			const [ d, l ] = await Promise.all( [ api( 'GET', `products/${ id }` ), lookups ? Promise.resolve( lookups ) : api( 'GET', 'lookups' ) ] );
			if ( ! ed.alive ) return;
			lookups = l;
			ed.data = d;
			main.querySelector( '#fs-ed-loading' )?.remove();
			renderHead();
			build( d );
			if ( d.conflicts?.length ) status.textContent = 'Something changed on the site since you started. You will be asked when you publish.';
			ed.hooks.loaded.forEach( ( f ) => f( d ) );
		} catch ( e ) {
			main.querySelector( '#fs-ed-loading' )?.replaceWith( h( 'p.fs-empty', {}, e.message, ' ', h( 'a', { href: '#/products' }, 'Back to products' ) ) );
		}
	} )();

	// Preview pane: wired in by Task 15 through ed.hooks.

	return {
		destroy() {
			ed.alive = false;
			autosave.cancel();
		},
		dirty: () => Object.keys( ed.pending ).length > 0 || !! ed.saving,
		async canLeave() {
			if ( ! ed.data || ( ! Object.keys( ed.pending ).length && ! ed.saving ) ) return true;
			if ( await flush() ) return true;
			return confirmDialog( 'Leave without saving?', 'Some changes could not be saved yet.', 'Leave', 'danger' );
		},
		ed,
	};
}
```

- [ ] **Step 5: Add the editor route in `studio/app/js/app.js`**

Add the import under the products-list import:
```js
import { productEditor } from './views/product-editor.js';
```
and replace the `routes` array with:
```js
const routes = [
	[ /^#\/products$/, () => productsList( main ) ],
	[ /^#\/products\/(\d+)$/, ( m ) => productEditor( main, Number( m[ 1 ] ) ) ],
];
```

- [ ] **Step 6: Check in the browser (signed in as the test owner)**

1. Products → "+ New product" → name "Test Studio Fish" → Create. Expected: editor opens, pill "Draft · not on the site yet", address shows `…/product/test-studio-fish/`.
2. Basics: change the name to "Test Studio Fish Two" → address follows (`test-studio-fish-two`). Pick a category (e.g. Originals). Status line shows "Saving…" then "Saved (not on the site yet)".
3. Paste formatted text from a web page into Short description → it arrives as plain text. Bold one word with **B**.
4. Price: type `₪120` → after save, reload the page (F5): Price shows `120`.
5. Photos: upload a JPG → becomes the main photo; upload a second → gallery with the hover note. Try a `.heic` file → error toast naming JPG/PNG/WebP, nothing added.
6. Publish to the shop → toast "Published. It is on the site now." with "View on site"; pill becomes "Live". Open the link: the product page shows the name, price, photos and short description, in the same layout as other products.
7. Edit the live product: change Price to `130` → pill "Unpublished changes"; the public page (other tab, not signed in or private window) still shows 120. Publish → shows 130.
8. **Publish waits for the pending save:** type a new price `140` and click Publish within half a second → the published page shows 140 (not 130).
9. Conflict: with Inventory → Count stock on and stock 3 published, change stock to 5 (do not publish). In a terminal: `MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp eval 'wc_update_product_stock( <id>, 2, "set" );'`. Publish → dialog "Something changed on the site" explaining 3 → 2; choose "Keep 2" → published stock 2.
10. Live product → Basics → "Change address" → `test-studio-moved` → Publish → old URL redirects to the new one.
11. Discard: change the name, then "Discard changes" → name returns, pill "Live".
12. "Move to trash" → back to the list, product gone. `read_console_messages` (errors only) → none.
13. Open a variable product (e.g. a T-shirt) → read-only note, all fields disabled, no Publish button.

- [ ] **Step 7: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/app
git commit -m "Studio UI: product editor with autosaved drafts, photos, rich text, publish and conflicts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Live preview pane

**Files:**
- Create: `studio/app/js/preview.js`
- Modify: `studio/app/js/views/product-editor.js` (import + replace the comment line `// Preview pane: wired in by Task 15 through ed.hooks.`)

**Interfaces:**
- Consumes: payload `previews: [{key,label,url}]` (Task 11), `ed.hooks` (Task 14).
- Produces: `previewPane( targets ): { el, reload(), setTargets( targets ) }`.

- [ ] **Step 1: `studio/app/js/preview.js`**

```js
/**
 * Fisha Studio preview pane: the real page in an iframe, with the draft laid over it by the server
 * (studio-preview.php). Pick the page (product, its category, Shop, Home), desktop or phone width.
 * Reloads after every autosave and keeps the scroll position.
 */
import { h } from './ui.js';

const DEVICES = [ [ 'desktop', 'Desktop' ], [ 'phone', 'Phone' ] ];

export function previewPane( targets ) {
	let list = targets;
	let current = 'item';
	let device = 'desktop';
	try { device = sessionStorage.getItem( 'fs-device' ) || 'desktop'; } catch {}
	let scrollY = 0;

	const frame = h( 'iframe.fs-preview__frame', { title: 'Live preview of the page' } );
	const loading = h( 'div.fs-preview__loading', { hidden: true, role: 'status' }, 'Updating preview…' );
	const error = h( 'div.fs-preview__error', { hidden: true },
		h( 'p', {}, 'The preview could not load.' ),
		h( 'button.fs-btn', { type: 'button', onclick: () => reload() }, 'Try again' ) );
	const select = h( 'select.fs-input.fs-input--small', { 'aria-label': 'Page to preview', onchange: () => { current = select.value; scrollY = 0; load(); } } );
	const deviceBtns = DEVICES.map( ( [ key, label ] ) => h( 'button.fs-seg', { type: 'button', 'aria-pressed': String( key === device ), onclick: () => {
		device = key;
		try { sessionStorage.setItem( 'fs-device', key ); } catch {}
		paint();
	} }, label ) );
	const open = h( 'a.fs-link', { target: '_blank', rel: 'noopener' }, 'Open in new tab' );
	const el = h( 'section.fs-preview', { 'aria-label': 'Preview' },
		h( 'div.fs-preview__bar', {}, select, h( 'div.fs-seg-group', {}, deviceBtns ), h( 'button.fs-btn.fs-btn--small', { type: 'button', 'aria-label': 'Refresh preview', onclick: () => reload() }, '↻' ), open ),
		h( 'div.fs-preview__stage', {}, h( 'div.fs-preview__device', {}, frame, loading, error ) ) );

	frame.addEventListener( 'load', () => {
		loading.hidden = true;
		try {
			const doc = frame.contentDocument;
			const text = doc?.body?.textContent?.slice( 0, 3000 ) || '';
			const broken = ! doc || doc.querySelector( '.wp-die-message, #error-page' ) || /critical error/i.test( text );
			error.hidden = ! broken;
			if ( ! broken ) frame.contentWindow.scrollTo( 0, scrollY );
		} catch {
			error.hidden = true;
		}
	} );

	const url = () => ( list.find( ( t ) => t.key === current ) || list[ 0 ] ).url;
	function paint() {
		el.dataset.device = device;
		deviceBtns.forEach( ( b, i ) => b.setAttribute( 'aria-pressed', String( DEVICES[ i ][ 0 ] === device ) ) );
	}
	function load() {
		loading.hidden = false;
		error.hidden = true;
		open.href = url();
		frame.src = url();
	}
	function reload() {
		try { scrollY = frame.contentWindow.scrollY; } catch { scrollY = 0; }
		load();
	}
	function setTargets( next ) {
		list = next;
		if ( ! list.some( ( t ) => t.key === current ) ) current = 'item';
		select.replaceChildren( ...list.map( ( t ) => h( 'option', { value: t.key, selected: t.key === current }, t.label ) ) );
		open.href = url();
	}

	setTargets( targets );
	paint();
	load();
	return { el, reload, setTargets };
}
```

- [ ] **Step 2: Wire it into the editor**

In `studio/app/js/views/product-editor.js` add under the other imports:
```js
import { previewPane } from '../preview.js';
```
and replace the line `// Preview pane: wired in by Task 15 through ed.hooks.` with:
```js
	let pane = null;
	ed.hooks.loaded.push( ( d ) => {
		pane = previewPane( d.previews );
		side.append( pane.el );
	} );
	const refreshPreview = ( d ) => {
		if ( ! pane ) return;
		pane.setTargets( d.previews );
		pane.reload();
	};
	ed.hooks.saved.push( refreshPreview );
	ed.hooks.published.push( refreshPreview );
```

- [ ] **Step 3: Check in the browser**

1. Open a live simple product (e.g. "Single Fisha No. 2"). Expected: right pane shows its real product page, no admin bar, no console errors.
2. Change the name → ~1 s later the preview shows the new name in the H1; scroll position kept. Open the same product in a private window → still the old name (draft is private).
3. "Page to preview" → its category page (Originals) → the card shows the draft name; switch to Shop and Home.
4. Inventory → Mark sold out → preview product page shows the sold-out state and the "Want the next one?" sign-up; category preview shows the "Sold out" badge.
5. Fisha design → pick another card colour → category preview card background changes.
6. Phone toggle → preview at phone width with the site's mobile layout.
7. Discard changes → preview returns to the live page.
8. Resize the Browser pane to tablet (768×1024) → preview hidden; the "Preview" button toggles it full screen. Reset to desktop.
9. In a terminal: `curl -s -D - -o /dev/null "<the preview URL copied from 'Open in new tab'>" | grep -i -e cache-control -e x-robots` (not signed in) → normal page headers, and the HTML shows the **live** name.

- [ ] **Step 4: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/app
git commit -m "Studio UI: live preview pane on the real product, category, Shop and Home pages

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 16: Full verification and release to dev

**Files:**
- No product code changes unless a check fails (then fix it in the owning task's files and re-run).

- [ ] **Step 1: Prove the visible site did not change**

The baseline was captured in Task 1 (`tests/studio/baseline-before.txt`). The browser checks in Tasks 13–15 changed a few products locally; undo those first (trash the "Test Studio Fish" product if it still exists, and set back any price you changed). Then:
```bash
MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp litespeed-purge all
bash tests/studio/baseline.sh > /tmp/after.txt
diff tests/studio/baseline-before.txt /tmp/after.txt && echo "IDENTICAL"
```
Expected: `IDENTICAL`. Any difference must be explained by a deliberate local data change from the browser checks, not by code.

- [ ] **Step 2: Run the whole test suite**

Run: `bash tests/studio/run.sh`
Expected: every test passes, `0 failed`. Then confirm no fixtures were left behind:
```bash
MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_title LIKE \"zz-test%\""), " posts, ", $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->terms WHERE slug LIKE \"zz-test%\""), " terms, ", $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users WHERE user_login LIKE \"zz-test%\""), " users\n";'
```
Expected: `0 posts, 0 terms, 0 users`.

- [ ] **Step 3: Security spot checks over HTTP (signed in as the test owner in the Browser pane)**

1. `/wp-admin/`, `/wp-admin/edit.php?post_type=product`, `/wp-admin/profile.php` → each lands on `/studio/`.
2. In the Studio tab run (javascript_tool): `await fetch('/wp-json/wp/v2/users/me', {headers:{'X-WP-Nonce': JSON.parse(document.getElementById('fs-config').textContent).nonce}}).then(r => r.status)` → `403`.
3. Same with `/wp-json/wc/v3/products` → `403`.
4. Sign out, then 5 wrong passwords on `/studio/login/` → "Too many tries…" even with the right password. Clear the lock afterwards:
   ```bash
   MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp eval 'global $wpdb; echo $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \"\\_transient%fs\\_login\\_%\"" ), " lock rows removed\n";'
   ```
5. `curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8484/wp-content/fisha-studio-logs/studio.log` → `403`.

- [ ] **Step 4: Push to develop (deploys to dev.fisha.shop)**

```bash
git push origin develop
```
Watch the run under the repo's Actions tab (the deploy lints PHP first). If the push asks for credentials in a non-interactive shell and hangs, stop it and ask the user to run `git push origin develop` themselves.

- [ ] **Step 5: Hand-off notes for the user (do not run these on dev/live yourself)**

Tell the user, in plain words:
- The Studio is at `https://dev.fisha.shop/studio/`. Administrators can sign in there with their normal account.
- To create the owner's account on dev (and later on live), they run over SSH in the site folder: `wp fisha-studio create-owner <owner-email>` → the owner gets a "set your password" email (on dev, emails go to the admin address because of `devsite.php`).
- The audit-log table is created automatically on the first page load after deploy.
- Phase 2 (sizes/variations, attributes, inventory table) is the next plan.

---

## Self-review notes (for the plan author; already applied)

- Spec coverage for phase 1: role/gates/login (§7) → Tasks 2–4; field schema §2.3 → Task 5 (all product fields incl. Fisha design + SEO); URLs §4.3 → Tasks 5, 8; drafts/preview/publish §6 → Tasks 7–9; audit §7 → Task 6; REST §3 → Tasks 10–11; product SEO §4.4 (per-product part) → Task 12; screens §5.2 product editor + list → Tasks 13–15; error handling §8 → api.js (offline, re-login), editor (field errors), preview (error state), Task 6 (error log); testing §9 → every task + Task 16; defaults §4.2 (products) → Tasks 7–8.
- Deliberately deferred to later phase plans (spec §10): variations/attributes/inventory table (2), categories/settings migration/hidden categories/category SEO (3), dashboard/history UI/restore/duplicate (4). Product "Duplicate" (§4.2) is phase 4.
- Deviations from the spec, all small and intentional: the error log lives in `wp-content/fisha-studio-logs/` with its own deny rule (the spec's single file needed a rule in an `.htaccess` that is not in git); `/wp-login.php` is not redirected — an owner who signs in there lands in the Studio via `login_redirect` instead; the owner role also has `delete_others_products`, `edit_private_products` and `delete_private_products`, needed to trash or edit products created by the admin.
