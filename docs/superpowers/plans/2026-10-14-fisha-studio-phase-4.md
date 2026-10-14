# Fisha Studio — Phase 4 (Dashboard, History & restore, Duplicate, My account, phone polish) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The owner opens the Studio on a Dashboard of what needs attention, can read and restore past versions of products and categories, bring back trashed products, duplicate a product or category, change their password and backup codes, and use every screen on a phone.

**Architecture:** Server first:
- `studio-history.php`: readable History entries from the existing audit log (`wp_fisha_studio_log`).
- `studio-restore.php`: restore a snapshot into the normal draft, and bring back trashed products.
- `studio-duplicate.php`: product copies via WooCommerce's own duplicator, and category copies.
- `studio-account.php`: password change, new backup codes, sign out of other devices.

Each gets a small REST file, plus `GET /dashboard`. Then the UI:
- Dashboard view.
- History screen, plus a History list inside both editors.
- Duplicate buttons and a My account screen.
- A phone pass.

Nothing visible to shoppers changes.

**Tech Stack:** WordPress 7.1, WooCommerce 11.1, PHP 8.3, MariaDB (InnoDB), vanilla ES modules (no build), WP-CLI tests in Docker.

**Spec:** `docs/superpowers/specs/2026-10-14-fisha-studio-phase-4-design.md` (binding), parent `docs/superpowers/specs/2026-10-12-fisha-studio-design.md`.

**Plan rulings on the spec (recorded here):**
1. **Dashboard counts** reuse `fisha_studio_product_row()` instead of new SQL.
   - Why: the shop has about 60 products, and the counts then always equal what the Products list shows for the same filter, so each count is a link to that filter.
   - "Sold out" = the row's `stock_status` is `outofstock`, which for a product with sizes means every size is sold out. This matches the list's Sold out filter.
   - "Low stock" uses the row's `low`, which is true when any size is low.
2. **The category editor has no tabs.** Its History is a section under the fields, titled "History".
3. **"Who" in History** is the user's display name. User id 0 is shown as "Studio sign-in" for `user` rows, else "Site admin (WP-CLI)".

**Code this plan builds on (exact names):**
- Audit: `fisha_studio_log( $type, $id, $action, $before, $after ): int`, `fisha_studio_log_table()`. Row columns: `id, created (UTC), user_id, item_type, item_id, action, before_json, after_json`.
- Products:
  - `fisha_studio_product_values( WC_Product ): array`, keyed by field, `attributes` and `var:{vid}:{field}`.
  - `fisha_studio_sanitize_product_input( WC_Product, array ): [values, errors]`.
  - `fisha_studio_product_edit( WC_Product, $values, $seen )`, which writes a draft, or applies directly when the product was never published.
  - `fisha_studio_value_label( WC_Product, string $key ): string`, `fisha_studio_product_fields()`.
  - `fisha_studio_variation_ids( WC_Product ): int[]` (publish and private sizes), `fisha_studio_variation_label( WC_Product $v )`, `fisha_studio_combo_key( array $attrs ): string`.
  - `fisha_studio_product_state( WC_Product ): 'new'|'changed'|'live'`, `fisha_studio_product_row( WC_Product ): array` (keys used: `id, name, state, hidden, stock_status, low`), `fisha_studio_product_payload( WC_Product ): array`.
  - `fisha_studio_rest_product( WP_REST_Request ): WC_Product|WP_Error`, `fisha_studio_slug_from_name()`, `fisha_studio_unique_product_slug( $slug, $id )`.
  - Constants `FISHA_STUDIO_DRAFT_META`, `FISHA_STUDIO_CREATED_META`, `FISHA_STUDIO_PUBLISHED_META`, `FISHA_STUDIO_PENDING_META`.
- Drafts: `fisha_studio_draft_get( int $id, string $meta_type = 'post' ): array{fields,base,user,updated}`.
- Categories:
  - `fisha_studio_category( int ): ?WP_Term`, `fisha_studio_category_values( WP_Term ): array` (`name, slug, parent, lead, hero_ids, shown`), `fisha_studio_category_fields()` (each has `label`).
  - `fisha_studio_category_state( WP_Term )`, `fisha_studio_category_edit( WP_Term, $values, $seen ): array` (field errors), `fisha_studio_category_create( string $name = '', int $parent = 0 ): WP_Term|WP_Error`.
  - `fisha_studio_category_payload( WP_Term ): array`, `fisha_studio_rest_category( WP_REST_Request ): WP_Term|WP_Error`, `fisha_studio_category_missing(): WP_Error`.
- Sign-in:
  - `fisha_studio_2fa_finish( string $token, WP_User $u, string $ip, string $action, bool $end_other_sessions = false )`.
  - `$GLOBALS['fisha_studio_2fa_ok']`, the only flag that lets an owner's auth cookie be sent.
  - `fisha_studio_2fa_enabled( int ): bool`, `fisha_studio_2fa_backup_left( int ): int`, `fisha_studio_2fa_new_backup_codes( int ): string[]`, `fisha_studio_totp_verify( int $uid, string $code ): bool`.
  - `fisha_studio_is_owner( WP_User ): bool`.
  - `fisha_studio_login_locked( $login, $ip ): bool`, `fisha_studio_login_reserve( $login, $ip ): bool` (counts one try; false when over the limit), `fisha_studio_login_failed( $login, $ip )`, `fisha_studio_login_clear( $login, $ip )`, `fisha_client_ip()`.
- REST: `FISHA_STUDIO_NS`, `fisha_studio_permission( $cap )`, `fisha_studio_body( WP_REST_Request )`.
- JS:
  - `h, toast, modal, confirmDialog, debounce` (ui.js), `api, config, ApiError` (api.js), `textField` (fields.js).
  - Router `routes` array in app.js.
  - `productEditor` tabs (`TABS`, `TABS_SIZES`, `panel( key, ...children )` inside `build()`), `categoryEditor` (`renderHead()`, `build()`).
  - Products list state in `sessionStorage['fs-list']` (`{ search, category, state, stock, page }`).
- Tests: `fs_*` helpers (`fs_product`, `fs_variable`, `fs_var_id`, `fs_cat`, `fs_studio_cat`, `fs_image`, `fs_as`, `fs_user`, `fs_api`) in `tests/studio/helpers.php`. Run with `bash tests/studio/run.sh [filter]` (262 passing at the start).

## Global Constraints

- **Where code goes:** Studio code in `wp-content/mu-plugins/fisha-design/studio/`, tests in `tests/studio/`.
  - New PHP files start with `<?php`, a doc comment and `if ( ! defined( 'ABSPATH' ) ) exit;`.
  - WordPress style, prefix `fisha_studio_`, REST namespace `fisha-studio/v1`.
- **Restore and undelete never publish:** restore writes a draft, and a product brought back from the trash is a draft. Publishing stays the owner's click.
- **History limits:** History lists only Studio audit rows, and they are kept 365 days (unchanged).
- **Duplicate product:**
  - Name is `"{name} (copy)"`, status draft, unique slug made from the new name.
  - SKU and every size SKU are empty, featured is off, total sales 0.
  - `_fisha_studio_created` = 1, with no `_fisha_studio_published`, no draft, no pending meta.
- **Duplicate category:** name `"{name} (copy)"`, same parent, intro and hero photos copied, "Show on site" copied, never published, products not copied.
- **Password change:**
  - The new password must be at least 12 characters, differ from the current one, and match the confirmation.
  - A wrong current password counts toward the sign-in lock.
  - On success every other session ends and this browser stays signed in.
- **New backup codes** need a valid app code (not a backup code), and the old codes stop working.
- **Copy:** owner-facing text is plain English with no em or en dashes. Touch targets are at least 44px on phones.
- **No visible change on the public site:** `bash tests/studio/baseline.sh` output equals `tests/studio/baseline-phase3-before.txt`.
- **Git:** never push; never touch `main`. Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Restoring a version whose size was deleted since** → the other values go into the draft, and the reply lists "1 size from that version no longer exists". Pinned by `restore: values go to the draft, missing sizes and photos are skipped` (Task 2).
2. **Duplicating a product that has unpublished changes and sizes** → the copy shows the on-screen values (draft included) with the same sizes, and the source is unchanged. Pinned by `duplicate: a product with sizes copies sizes and the draft, clears SKUs` (Task 3).
3. **A wrong current password typed 5 times on My account** → the 6th try is refused as locked, exactly like the sign-in page. Pinned by `account: a wrong current password counts toward the lock` (Task 4).
4. **Changing the password from the Studio** → the next Studio request still works without a sign-in prompt, because the REST nonce is refreshed. Pinned by `account: changing the password keeps this session and returns a fresh nonce` (Task 4).
5. **History of a product whose sizes were quick-edited from Inventory** → those size edits show on the product's History with the size name. Pinned by `history: a product's list includes its sizes' quick edits` (Task 1).

---

## File map

| Path | Change |
|---|---|
| `studio/studio-history.php` | **new**: History rows, titles, change lines, restorable flag |
| `studio/studio-restore.php` | **new**: restore a snapshot into a draft; bring back trashed products; trash list |
| `studio/studio-duplicate.php` | **new**: duplicate product / category |
| `studio/studio-account.php` | **new**: password change, new backup codes, sign out other devices, `fisha_studio_issue_session()` |
| `studio/studio-2fa.php` | `fisha_studio_2fa_finish()` uses `fisha_studio_issue_session()` |
| `studio/studio.php` | require the four new files (after `studio-convert.php`) |
| `studio/api/api-history.php` | **new**: `GET /history`, restore, trash list, bring back |
| `studio/api/api-duplicate.php` | **new**: duplicate routes |
| `studio/api/api-account.php` | **new**: account routes |
| `studio/api/api-dashboard.php` | **new**: `GET /dashboard` |
| `studio/api/api.php` | require the four new API files |
| `studio/app/js/api.js` | `export function setNonce( n )` |
| `studio/app/js/history.js` | **new**: shared History list widget |
| `studio/app/js/views/dashboard.js`, `history.js`, `account.js` | **new** views |
| `studio/app/js/views/product-editor.js`, `category-editor.js` | History tab/section, Duplicate button |
| `studio/app/js/app.js` | nav, routes, start page |
| `studio/app/studio.css` | new view styles + phone pass |
| `tests/studio/test-history.php`, `test-restore.php`, `test-duplicate.php`, `test-account.php`, `test-dashboard.php` | **new** tests |

---

### Task 1: History entries from the audit log

**Files:**
- Create: `wp-content/mu-plugins/fisha-design/studio/studio-history.php`, `studio/api/api-history.php` (the `GET /history` route only; Task 2 adds more routes to it)
- Modify: `studio/studio.php` (require after `studio-convert.php`), `studio/api/api.php` (require `api-history.php`)
- Test: `tests/studio/test-history.php`

**Interfaces:**
- Produces:
  - `FISHA_STUDIO_SNAPSHOT_ACTIONS` = `array( 'create', 'publish', 'convert' )`
  - `fisha_studio_history_query( array $args ): array{items: array[], total: int, pages: int}`, with `$args`: `kind` (`all|product|category|signin|other`), `item_type` (`product|category|''`), `item_id` (int), `days` (0 = all), `page` (≥1), `per_page` (1..100)
  - `fisha_studio_history_entry( array $row ): array{id:int, when:string (ISO 8601 UTC), who:string, title:string, item:{type,id,name,exists}|null, changes:string[], more:int, restorable:bool}`
  - `fisha_studio_history_changes( string $type, int $id, ?array $before, ?array $after ): string[]`
  - REST `GET /history` with the args above as query params → `{ items, total, pages }`

- [ ] **Step 1: Write the failing tests** — `tests/studio/test-history.php`

```php
<?php
function fs_log_row( string $type, int $id, string $action, ?array $before, ?array $after ): int {
	$log = fisha_studio_log( $type, $id, $action, $before, $after );
	fs_on_cleanup( function () use ( $log ) { global $wpdb; $wpdb->delete( fisha_studio_log_table(), array( 'id' => $log ) ); } );
	return $log;
}

fs_test( 'history: a publish row reads as plain change lines', function () {
	$p   = fs_product( array( 'regular_price' => '119' ) );
	$v   = fisha_studio_product_values( $p );
	$log = fs_log_row( 'product', $p->get_id(), 'publish', array_merge( $v, array( 'regular_price' => '119', 'description' => '<p>Old</p>' ) ), array_merge( $v, array( 'regular_price' => '109', 'description' => '<p>New</p>' ) ) );
	$res = fisha_studio_history_query( array( 'item_type' => 'product', 'item_id' => $p->get_id() ) );
	$e   = $res['items'][0];
	fs_eq( $log, $e['id'] );
	fs_eq( 'Published changes', $e['title'] );
	fs_true( in_array( 'Price: 119 → 109', $e['changes'], true ), wp_json_encode( $e['changes'] ) );
	fs_true( in_array( 'Full description changed', $e['changes'], true ) );
	fs_true( $e['restorable'] );
	fs_eq( $p->get_id(), $e['item']['id'] );
	fs_true( $e['item']['exists'] );
	fs_true( (bool) preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $e['when'] ), 'ISO UTC' );
} );

fs_test( 'history: a product\'s list includes its sizes\' quick edits', function () {
	$p   = fs_variable( array( 's', 'm' ) );
	$vid = fs_var_id( $p, 'm' );
	fs_log_row( 'variation', $vid, 'quick', array( 'stock_quantity' => 4 ), array( 'stock_quantity' => 3 ) );
	$items = fisha_studio_history_query( array( 'item_type' => 'product', 'item_id' => $p->get_id() ) )['items'];
	fs_eq( 1, count( $items ) );
	fs_eq( 'Quick edit of a size', $items[0]['title'] );
	fs_false( $items[0]['restorable'], 'size quick rows are activity only' );
	fs_true( (bool) array_filter( $items[0]['changes'], function ( $l ) { return false !== strpos( $l, '4 → 3' ) && false !== stripos( $l, 'M' ); } ), wp_json_encode( $items[0]['changes'] ) );
} );

fs_test( 'history: kinds, days and paging filter the list', function () {
	$t = fs_cat( 'hist-kind' );
	$p = fs_product();
	$u = fs_user( FISHA_STUDIO_ROLE );
	fs_log_row( 'category', $t->term_id, 'shown', array( 'shown' => true ), array( 'shown' => false ) );
	fs_log_row( 'product', $p->get_id(), 'trash', null, null );
	fs_log_row( 'user', $u->ID, '2fa-signin', null, array( 'ip' => '1.2.3.4' ) );
	$ids = function ( $args ) { return wp_list_pluck( fisha_studio_history_query( $args )['items'], 'title' ); };
	fs_true( in_array( 'Signed in', $ids( array( 'kind' => 'signin', 'per_page' => 100 ) ), true ) );
	fs_false( in_array( 'Signed in', $ids( array( 'kind' => 'product', 'per_page' => 100 ) ), true ) );
	fs_true( in_array( 'Switched Show on site off', $ids( array( 'kind' => 'category', 'per_page' => 100 ) ), true ) );
	$page = fisha_studio_history_query( array( 'per_page' => 1, 'page' => 2 ) );
	fs_eq( 1, count( $page['items'] ) );
	fs_true( $page['pages'] >= 3 );
	global $wpdb;
	$old = fs_log_row( 'product', $p->get_id(), 'publish', array( 'name' => 'a' ), array( 'name' => 'b' ) );
	$wpdb->update( fisha_studio_log_table(), array( 'created' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ), array( 'id' => $old ) );
	fs_false( in_array( $old, wp_list_pluck( fisha_studio_history_query( array( 'days' => 7, 'per_page' => 100 ) )['items'], 'id' ), true ), '7 days' );
	fs_true( in_array( $old, wp_list_pluck( fisha_studio_history_query( array( 'days' => 30, 'per_page' => 100 ) )['items'], 'id' ), true ), '30 days' );
} );

fs_test( 'history: long change lists are cut at 6 with a count', function () {
	$p = fs_product();
	$b = array(); $a = array();
	foreach ( array( 'name', 'sku', 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' ) as $i => $k ) { $b[ $k ] = 'x' . $i; $a[ $k ] = 'y' . $i; }
	fs_log_row( 'product', $p->get_id(), 'publish', $b, $a );
	$e = fisha_studio_history_query( array( 'item_type' => 'product', 'item_id' => $p->get_id() ) )['items'][0];
	fs_eq( 6, count( $e['changes'] ) );
	fs_eq( 2, $e['more'] );
} );

fs_test( 'history: REST lists for the owner only', function () {
	fs_as( 'customer' );
	fs_eq( 403, fs_api( 'GET', '/history' )[0] );
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'GET', '/history', array( 'kind' => 'all', 'per_page' => 5 ) );
	fs_eq( 200, $s );
	fs_true( isset( $d['items'], $d['total'], $d['pages'] ) );
} );
```

- [ ] **Step 2: Run them to see them fail**

Run: `bash tests/studio/run.sh history` → FAIL (undefined `fisha_studio_history_query`; `/history` returns 404 `rest_no_route`).

- [ ] **Step 3: Implement** — create `studio/studio-history.php`

```php
<?php
/**
 * Fisha Studio History: the audit log read as plain English for the owner. A product, category or size change, a
 * sign-in, a size list edit; each with when, who, what changed, and whether it can be restored (full snapshots only).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_SNAPSHOT_ACTIONS', array( 'create', 'publish', 'convert' ) );

/** @return array{items: array[], total: int, pages: int} */
function fisha_studio_history_query( array $args ): array {
	global $wpdb;
	$table = fisha_studio_log_table();
	$kind  = (string) ( $args['kind'] ?? 'all' );
	$per   = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
	$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
	$where = array( '1=1' );
	$vals  = array();
	if ( 'product' === $kind ) $where[] = "item_type IN ('product','variation')";
	elseif ( 'category' === $kind ) $where[] = "item_type = 'category'";
	elseif ( 'signin' === $kind ) $where[] = "item_type = 'user'";
	elseif ( 'other' === $kind ) $where[] = "item_type NOT IN ('product','variation','category','user')";
	$type = (string) ( $args['item_type'] ?? '' );
	$id   = (int) ( $args['item_id'] ?? 0 );
	if ( 'product' === $type && $id ) {
		$vids = get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $id, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
		$sizes = $vids ? " OR (item_type = 'variation' AND item_id IN (" . implode( ',', array_map( 'intval', $vids ) ) . '))' : '';
		$where[] = "((item_type = 'product' AND item_id = %d)$sizes)";
		$vals[]  = $id;
	} elseif ( 'category' === $type && $id ) {
		$where[] = "item_type = 'category' AND item_id = %d";
		$vals[]  = $id;
	}
	$days = (int) ( $args['days'] ?? 0 );
	if ( $days > 0 ) {
		$where[] = 'created >= %s';
		$vals[]  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}
	$sql_where = implode( ' AND ', $where );
	$total     = (int) $wpdb->get_var( $vals ? $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $sql_where", $vals ) : "SELECT COUNT(*) FROM $table WHERE $sql_where" );
	$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $sql_where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $vals, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
	return array( 'items' => array_map( 'fisha_studio_history_entry', $rows ?: array() ), 'total' => $total, 'pages' => (int) ceil( $total / $per ) );
}

/** One log row as the owner reads it. */
function fisha_studio_history_entry( array $row ): array {
	$before = null === $row['before_json'] ? null : json_decode( (string) $row['before_json'], true );
	$after  = null === $row['after_json'] ? null : json_decode( (string) $row['after_json'], true );
	$type   = (string) $row['item_type'];
	$id     = (int) $row['item_id'];
	$lines  = fisha_studio_history_changes( $type, $id, is_array( $before ) ? $before : null, is_array( $after ) ? $after : null );
	$item   = fisha_studio_history_item( $type, $id, is_array( $after ) ? $after : ( is_array( $before ) ? $before : array() ) );
	$u      = (int) $row['user_id'] ? get_user_by( 'id', (int) $row['user_id'] ) : null;
	return array(
		'id'         => (int) $row['id'],
		'when'       => str_replace( ' ', 'T', (string) $row['created'] ) . 'Z',
		'who'        => $u ? $u->display_name : ( 'user' === $type ? 'Studio sign-in' : 'Site admin (WP-CLI)' ),
		'title'      => fisha_studio_history_title( $type, (string) $row['action'], $after ),
		'item'       => $item,
		'changes'    => array_slice( $lines, 0, 6 ),
		'more'       => max( 0, count( $lines ) - 6 ),
		'restorable' => in_array( $type, array( 'product', 'category' ), true ) && in_array( $row['action'], FISHA_STUDIO_SNAPSHOT_ACTIONS, true ) && is_array( $after ) && $item && $item['exists'],
	);
}

function fisha_studio_history_title( string $type, string $action, $after ): string {
	$map = array(
		'product'   => array( 'new' => 'Started a new product', 'create' => 'Published a new product', 'publish' => 'Published changes', 'convert' => 'Converted the sizes', 'trash' => 'Moved to the trash', 'untrash' => 'Brought back from the trash', 'duplicate' => 'Made a copy' ),
		'variation' => array( 'quick' => 'Quick edit of a size' ),
		'category'  => array( 'new' => 'Started a new category', 'create' => 'Published a new category', 'publish' => 'Published changes', 'reorder' => 'Changed the order', 'delete' => 'Deleted the category', 'duplicate' => 'Made a copy' ),
		'user'      => array( '2fa-signin' => 'Signed in', 'backup-code' => 'Signed in with a backup code', '2fa-setup' => 'Set up 2-step sign-in', '2fa-reset' => '2-step sign-in was reset', 'backup-codes-new' => 'Made new backup codes', '2fa-code-lock' => 'Too many wrong codes', 'password-change' => 'Changed the password', 'sign-out-others' => 'Signed out of other devices' ),
		'attribute' => array( 'create' => 'Added a size list', 'rename' => 'Renamed a size list', 'add value' => 'Added a value to a size list', 'rename value' => 'Renamed a size list value', 'delete value' => 'Removed a size list value', 'reorder' => 'Reordered a size list' ),
	);
	if ( 'category' === $type && 'shown' === $action ) return ! empty( $after['shown'] ) ? 'Switched Show on site on' : 'Switched Show on site off';
	return $map[ $type ][ $action ] ?? ucfirst( $action );
}

/** The thing a row is about: {type, id, name, exists} or null for sign-ins and size lists. */
function fisha_studio_history_item( string $type, int $id, array $values ): ?array {
	if ( 'product' === $type || 'variation' === $type ) {
		$pid  = 'variation' === $type ? (int) wp_get_post_parent_id( $id ) : $id;
		$post = $pid ? get_post( $pid ) : null;
		$name = $post ? fisha_studio_plain( $post->post_title ) : (string) ( $values['name'] ?? '' );
		return array( 'type' => 'product', 'id' => $pid, 'name' => '' !== $name ? $name : 'Product #' . $pid, 'exists' => $post && 'product' === $post->post_type && 'trash' !== $post->post_status );
	}
	if ( 'category' === $type ) {
		$t = fisha_studio_category( $id );
		$name = $t ? fisha_studio_plain( $t->name ) : (string) ( $values['name'] ?? 'Category #' . $id );
		return array( 'type' => 'category', 'id' => $id, 'name' => $name, 'exists' => (bool) $t );
	}
	return null;
}

/** Plain lines for what changed between two value sets ("Regular price: 119 → 109", "Photos changed"). */
function fisha_studio_history_changes( string $type, int $id, ?array $before, ?array $after ): array {
	if ( ! $before || ! $after ) return array();
	$p      = 'product' === $type ? wc_get_product( $id ) : ( 'variation' === $type ? wc_get_product( (int) wp_get_post_parent_id( $id ) ) : null );
	$cat    = 'category' === $type ? wp_list_pluck( fisha_studio_category_fields(), 'label' ) : array();
	$photos = false;
	$lines  = array();
	foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $k ) {
		$k = (string) $k;
		$a = $before[ $k ] ?? null;
		$b = $after[ $k ] ?? null;
		if ( fisha_studio_same( $a, $b ) ) continue;
		if ( preg_match( '/(^|:)(image_id|gallery_image_ids|hero_ids)$/', $k ) ) { $photos = true; continue; }
		if ( 'variation' === $type ) {
			$v     = wc_get_product( $id );
			$label = ( $v ? fisha_studio_variation_label( $v ) . ': ' : '' ) . ( fisha_studio_variation_fields()[ $k ]['label'] ?? $k );
		} elseif ( $p ) {
			$label = fisha_studio_value_label( $p, $k );
		} else {
			$label = $cat[ $k ] ?? ( fisha_studio_product_fields()[ $k ]['label'] ?? $k );
		}
		$lines[] = fisha_studio_history_short( $a ) && fisha_studio_history_short( $b )
			? sprintf( '%s: %s → %s', $label, fisha_studio_history_text( $a ), fisha_studio_history_text( $b ) )
			: $label . ' changed';
	}
	if ( $photos ) array_unshift( $lines, 'Photos changed' );
	return $lines;
}

function fisha_studio_history_short( $v ): bool {
	return ! is_array( $v ) && mb_strlen( (string) $v ) <= 40 && false === strpos( (string) $v, '<' );
}
function fisha_studio_history_text( $v ): string {
	if ( true === $v ) return 'on';
	if ( false === $v ) return 'off';
	if ( null === $v || '' === $v ) return '(empty)';
	return fisha_studio_plain( (string) $v );
}
```

Create `studio/api/api-history.php`:

```php
<?php
/**
 * Fisha Studio REST: History (the audit log for the owner), restore a version, recently deleted products.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/history', array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_history', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
} );

function fisha_studio_rest_history( WP_REST_Request $r ): array {
	return fisha_studio_history_query( array(
		'kind'      => in_array( $r['kind'], array( 'all', 'product', 'category', 'signin', 'other' ), true ) ? $r['kind'] : 'all',
		'item_type' => in_array( $r['item_type'], array( 'product', 'category' ), true ) ? $r['item_type'] : '',
		'item_id'   => absint( $r['item_id'] ),
		'days'      => in_array( (int) $r['days'], array( 1, 7, 30 ), true ) ? (int) $r['days'] : 0,
		'page'      => max( 1, (int) $r['page'] ),
		'per_page'  => (int) ( $r['per_page'] ?: 20 ),
	) );
}
```

Add `require_once FISHA_STUDIO_DIR . 'studio-history.php';` to `studio.php` after the `studio-convert.php` require, and `require_once __DIR__ . '/api-history.php';` at the end of `api/api.php`.

- [ ] **Step 4: Run the tests to see them pass**

Run: `bash tests/studio/run.sh history` → PASS. Then `bash tests/studio/run.sh` → 267 passed, 0 failed, with no PHP warnings.

- [ ] **Step 5: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/studio-history.php wp-content/mu-plugins/fisha-design/studio/api/api-history.php wp-content/mu-plugins/fisha-design/studio/studio.php wp-content/mu-plugins/fisha-design/studio/api/api.php tests/studio/test-history.php
git commit -m "Studio: History entries from the audit log (titles, change lines, filters)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Restore a version; bring back trashed products

**Files:**
- Create: `studio/studio-restore.php`
- Modify: `studio/studio.php` (require after `studio-history.php`), `studio/api/api-history.php` (add 4 routes)
- Test: `tests/studio/test-restore.php`

**Interfaces:**
- Consumes: Task 1 `FISHA_STUDIO_SNAPSHOT_ACTIONS`.
- Produces:
  - `fisha_studio_restore( string $type, int $id, int $log_id ): array{skipped: string[]}|WP_Error`. Error codes: `fisha_studio_not_found` 404, `fisha_studio_not_restorable` 400, `fisha_studio_same` 400.
  - `fisha_studio_trash_list(): array[]`, each `{id, name, deleted: ISO UTC, thumb}`, trashed in the last 30 days, newest first.
  - `fisha_studio_untrash_product( int $id ): WC_Product|WP_Error`.
  - REST:
    - `POST /products/{id}/restore {log_id}` and `POST /categories/{id}/restore {log_id}` → the editor payload + `skipped`.
    - `GET /trash` → `{ items }`.
    - `POST /products/{id}/untrash` → product payload.

- [ ] **Step 1: Write the failing tests** — `tests/studio/test-restore.php`

```php
<?php
function fs_snapshot( string $type, int $id, array $after ): int {
	$log = fisha_studio_log( $type, $id, 'publish', null, $after );
	fs_on_cleanup( function () use ( $log ) { global $wpdb; $wpdb->delete( fisha_studio_log_table(), array( 'id' => $log ) ); } );
	return $log;
}

fs_test( 'restore: values go to the draft, missing sizes and photos are skipped', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p    = fs_variable( array( 's', 'm' ) );
	$live = fisha_studio_product_values( $p );
	$old  = $live;
	$old['name'] = $live['name'] . ' old';
	$old[ 'var:' . fs_var_id( $p, 'm' ) . ':regular_price' ] = '77';
	$old['var:999999:regular_price'] = '10';
	$old['image_id'] = 999999;
	$log = fs_snapshot( 'product', $p->get_id(), $old );
	$res = fisha_studio_restore( 'product', $p->get_id(), $log );
	fs_false( is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : '' );
	$d = fisha_studio_draft_get( $p->get_id() );
	fs_eq( $live['name'] . ' old', $d['fields']['name'] );
	fs_eq( '77', $d['fields'][ 'var:' . fs_var_id( $p, 'm' ) . ':regular_price' ] );
	fs_false( isset( $d['fields']['var:999999:regular_price'] ) );
	fs_false( isset( $d['fields']['image_id'] ) );
	fs_eq( array( '1 size from that version no longer exists.', '1 photo from that version no longer exists.' ), $res['skipped'] );
	fs_eq( $live['name'], wc_get_product( $p->get_id() )->get_name(), 'live untouched until Publish' );
} );

fs_test( 'restore: a version equal to live is refused; activity rows are not restorable', function () {
	$p   = fs_product();
	$log = fs_snapshot( 'product', $p->get_id(), fisha_studio_product_values( $p ) );
	fs_eq( 'fisha_studio_same', fisha_studio_restore( 'product', $p->get_id(), $log )->get_error_code() );
	$trash = fisha_studio_log( 'product', $p->get_id(), 'trash', fisha_studio_product_values( $p ), null );
	fs_on_cleanup( function () use ( $trash ) { global $wpdb; $wpdb->delete( fisha_studio_log_table(), array( 'id' => $trash ) ); } );
	fs_eq( 'fisha_studio_not_restorable', fisha_studio_restore( 'product', $p->get_id(), $trash )->get_error_code() );
	$other = fs_product();
	fs_eq( 'fisha_studio_not_restorable', fisha_studio_restore( 'product', $other->get_id(), $log )->get_error_code(), 'row of another item' );
} );

fs_test( 'restore: a category version, missing parent skipped', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t   = fs_cat( 'restore-cat' );
	$v   = fisha_studio_category_values( $t );
	$log = fs_snapshot( 'category', $t->term_id, array_merge( $v, array( 'lead' => 'Older intro.', 'parent' => 999999 ) ) );
	$res = fisha_studio_restore( 'category', $t->term_id, $log );
	fs_eq( array( 'The parent category from that version no longer exists.' ), $res['skipped'] );
	fs_eq( 'Older intro.', fisha_studio_draft_get( $t->term_id, 'term' )['fields']['lead'] );
} );

fs_test( 'restore: a never-published product takes the values directly', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p = fisha_studio_product_create( 'zz-test restore new' );
	fs_on_cleanup( function () use ( $p ) { fs_delete_product( $p->get_id() ); } );
	$v   = fisha_studio_product_values( $p );
	$log = fs_snapshot( 'product', $p->get_id(), array_merge( $v, array( 'regular_price' => '55' ) ) );
	fisha_studio_restore( 'product', $p->get_id(), $log );
	fs_eq( '55', wc_get_product( $p->get_id() )->get_regular_price( 'edit' ) );
} );

fs_test( 'trash: a trashed product is listed and comes back as a draft with its sizes', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p = fs_variable( array( 's', 'm' ) );
	fisha_studio_trash_product( $p->get_id() );
	fs_true( in_array( $p->get_id(), wp_list_pluck( fisha_studio_trash_list(), 'id' ), true ) );
	$back = fisha_studio_untrash_product( $p->get_id() );
	fs_false( is_wp_error( $back ) );
	fs_eq( 'draft', get_post_status( $p->get_id() ) );
	fs_eq( 'publish', get_post_status( fs_var_id( $p, 'm' ) ), 'sizes back' );
	fs_false( in_array( $p->get_id(), wp_list_pluck( fisha_studio_trash_list(), 'id' ), true ) );
	fs_eq( 'untrash', fisha_studio_log_for( 'product', $p->get_id(), 1 )[0]['action'] );
	fs_eq( 'fisha_studio_not_found', fisha_studio_untrash_product( fs_product()->get_id() )->get_error_code(), 'not in trash' );
} );

fs_test( 'restore: REST restore returns the payload and skipped list', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p   = fs_product();
	$log = fs_snapshot( 'product', $p->get_id(), array_merge( fisha_studio_product_values( $p ), array( 'regular_price' => '88' ) ) );
	list( $s, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/restore', array( 'log_id' => $log ) );
	fs_eq( 200, $s, wp_json_encode( $d ) );
	fs_eq( 'changed', $d['state'] );
	fs_eq( '88', $d['values']['regular_price'] );
	fs_eq( array(), $d['skipped'] );
} );
```

- [ ] **Step 2: Run them to see them fail**

Run: `bash tests/studio/run.sh restore` and `bash tests/studio/run.sh "trash:"`.\|trash:"`. The filter is a plain substring, so run `bash tests/studio/run.sh restore` and then `bash tests/studio/run.sh "trash:"`. Both FAIL with undefined functions.

- [ ] **Step 3: Implement** — create `studio/studio-restore.php`

```php
<?php
/**
 * Fisha Studio restore: an older published version of a product or category goes back into its draft (the owner then
 * previews and publishes as usual), and products from the WordPress trash come back as drafts.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** @return array{skipped: string[]}|WP_Error */
function fisha_studio_restore( string $type, int $id, int $log_id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . fisha_studio_log_table() . ' WHERE id = %d', $log_id ), ARRAY_A );
	$snapshot = $row && null !== $row['after_json'] ? json_decode( (string) $row['after_json'], true ) : null;
	if ( ! $row || $row['item_type'] !== $type || (int) $row['item_id'] !== $id || ! in_array( $row['action'], FISHA_STUDIO_SNAPSHOT_ACTIONS, true ) || ! is_array( $snapshot ) ) {
		return new WP_Error( 'fisha_studio_not_restorable', 'This entry cannot be restored.', array( 'status' => 400 ) );
	}
	if ( 'product' === $type ) {
		$p = ( 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) ? wc_get_product( $id ) : null;
		if ( ! $p ) return new WP_Error( 'fisha_studio_not_found', 'This product no longer exists.', array( 'status' => 404 ) );
		$live = fisha_studio_product_values( $p );
	} else {
		$t = fisha_studio_category( $id );
		if ( ! $t ) return fisha_studio_category_missing();
		$live = fisha_studio_category_values( $t );
	}

	$skipped = array();
	$photos  = 0;
	$values  = array();
	foreach ( $snapshot as $k => $v ) {
		$k = (string) $k;
		if ( ! array_key_exists( $k, $live ) ) continue;
		if ( preg_match( '/(^|:)image_id$/', $k ) && $v && ! wp_attachment_is_image( (int) $v ) ) { $photos++; continue; }
		if ( preg_match( '/(^|:)(gallery_image_ids|hero_ids)$/', $k ) && is_array( $v ) ) {
			$keep = array_values( array_filter( $v, function ( $a ) { return wp_attachment_is_image( (int) $a ); } ) );
			$photos += count( $v ) - count( $keep );
			$v = $keep;
		}
		if ( 'category_ids' === $k && is_array( $v ) ) {
			$keep = array_values( array_filter( $v, function ( $c ) { return (bool) fisha_studio_category( (int) $c ); } ) );
			if ( count( $keep ) < count( $v ) ) $skipped[] = 'A category from that version no longer exists.';
			$v = $keep;
		}
		if ( 'parent' === $k && (int) $v && ! fisha_studio_category( (int) $v ) ) {
			$skipped[] = 'The parent category from that version no longer exists.';
			continue;
		}
		if ( 'attributes' === $k && ! fisha_studio_restore_options_exist( (array) $v ) ) {
			$skipped[] = 'The size options from that version no longer exist, so they were left as they are.';
			continue;
		}
		$values[ $k ] = $v;
	}
	$gone_sizes = fisha_studio_restore_size_count( $snapshot, $live );
	if ( $gone_sizes ) array_unshift( $skipped, sprintf( _n( '%d size from that version no longer exists.', '%d sizes from that version no longer exist.', $gone_sizes ), $gone_sizes ) );
	if ( $photos ) $skipped[] = sprintf( _n( '%d photo from that version no longer exists.', '%d photos from that version no longer exist.', $photos ), $photos );

	$changes = array();
	foreach ( $values as $k => $v ) {
		if ( ! fisha_studio_same( $v, $live[ $k ] ) ) $changes[ $k ] = $v;
	}
	if ( ! $changes ) return new WP_Error( 'fisha_studio_same', 'This version is the same as the live one.', array( 'status' => 400 ) );
	$seen = array_intersect_key( $live, $changes );
	if ( 'product' === $type ) {
		list( $clean, $errors ) = fisha_studio_sanitize_product_input( $p, $changes );
		fisha_studio_product_edit( $p, array_diff_key( $clean, $errors ), $seen );
	} else {
		list( $clean, $errors ) = fisha_studio_sanitize( fisha_studio_category_fields(), $changes );
		fisha_studio_category_edit( $t, array_diff_key( $clean, $errors ), $seen );
	}
	return array( 'skipped' => $skipped );
}

/** How many different sizes of the snapshot are gone. */
function fisha_studio_restore_size_count( array $snapshot, array $live ): int {
	$gone = array();
	foreach ( array_keys( $snapshot ) as $k ) {
		if ( preg_match( '/^var:(\d+):/', (string) $k, $m ) && ! array_key_exists( (string) $k, $live ) ) $gone[ $m[1] ] = true;
	}
	return count( $gone );
}

/** Whether every shop-wide option value a snapshot's options use still exists (local options always do). */
function fisha_studio_restore_options_exist( array $list ): bool {
	foreach ( $list as $a ) {
		if ( empty( $a['taxonomy'] ) ) continue;
		foreach ( (array) ( $a['options'] ?? array() ) as $term_id ) {
			$t = get_term( (int) $term_id, (string) $a['name'] );
			if ( ! $t || is_wp_error( $t ) ) return false;
		}
	}
	return true;
}

/** Products moved to the trash in the last 30 days, newest first. */
function fisha_studio_trash_list(): array {
	$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'trash', 'numberposts' => 100, 'fields' => 'ids', 'meta_key' => '_wp_trash_meta_time', 'orderby' => 'meta_value_num', 'order' => 'DESC' ) );
	$out = array();
	foreach ( $ids as $id ) {
		$when = (int) get_post_meta( $id, '_wp_trash_meta_time', true );
		if ( $when < time() - 30 * DAY_IN_SECONDS ) continue;
		$thumb = get_post_thumbnail_id( $id );
		$out[] = array( 'id' => (int) $id, 'name' => fisha_studio_plain( get_the_title( $id ) ) ?: 'Product #' . $id, 'deleted' => gmdate( 'Y-m-d\TH:i:s\Z', $when ), 'thumb' => $thumb ? (string) wp_get_attachment_image_url( $thumb, 'thumbnail' ) : '' );
	}
	return $out;
}

/** Brings a trashed product (and its sizes) back as a draft. @return WC_Product|WP_Error */
function fisha_studio_untrash_product( int $id ) {
	if ( 'product' !== get_post_type( $id ) || 'trash' !== get_post_status( $id ) ) {
		return new WP_Error( 'fisha_studio_not_found', 'This product is not in the trash.', array( 'status' => 404 ) );
	}
	$force = function () { return 'draft'; };
	add_filter( 'wp_untrash_post_status', $force );
	$ok = wp_untrash_post( $id );
	remove_filter( 'wp_untrash_post_status', $force );
	if ( ! $ok ) return new WP_Error( 'fisha_studio_untrash_failed', 'The product could not be brought back. Please try again.', array( 'status' => 500 ) );
	clean_post_cache( $id );
	wc_delete_product_transients( $id );
	fisha_studio_log( 'product', $id, 'untrash', null, array( 'status' => 'draft' ) );
	return wc_get_product( $id );
}
```

Append to `studio/api/api-history.php`, inside the existing `rest_api_init` callback:

```php
	register_rest_route( FISHA_STUDIO_NS, '/products/(?P<id>\d+)/restore', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_product_restore', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, '/categories/(?P<id>\d+)/restore', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_category_restore', 'permission_callback' => fisha_studio_permission( 'manage_product_terms' ) ) );
	register_rest_route( FISHA_STUDIO_NS, '/trash', array( 'methods' => 'GET', 'callback' => function () { return array( 'items' => fisha_studio_trash_list() ); }, 'permission_callback' => fisha_studio_permission( 'delete_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, '/products/(?P<id>\d+)/untrash', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_product_untrash', 'permission_callback' => fisha_studio_permission( 'delete_products' ) ) );
```

and these callbacks at the end of the file:

```php
function fisha_studio_rest_product_restore( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	$res = fisha_studio_restore( 'product', $p->get_id(), absint( fisha_studio_body( $r )['log_id'] ?? 0 ) );
	if ( is_wp_error( $res ) ) return $res;
	return array_merge( fisha_studio_product_payload( wc_get_product( $p->get_id() ) ), array( 'skipped' => $res['skipped'] ) );
}
function fisha_studio_rest_category_restore( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	if ( is_wp_error( $t ) ) return $t;
	$res = fisha_studio_restore( 'category', (int) $t->term_id, absint( fisha_studio_body( $r )['log_id'] ?? 0 ) );
	if ( is_wp_error( $res ) ) return $res;
	return array_merge( fisha_studio_category_payload( fisha_studio_category( (int) $t->term_id ) ), array( 'skipped' => $res['skipped'] ) );
}
function fisha_studio_rest_product_untrash( WP_REST_Request $r ) {
	$p = fisha_studio_untrash_product( (int) $r['id'] );
	return is_wp_error( $p ) ? $p : fisha_studio_product_payload( $p );
}
```

Add `require_once FISHA_STUDIO_DIR . 'studio-restore.php';` to `studio.php` after the `studio-history.php` require.

- [ ] **Step 4: Run the tests to see them pass**

Run: `bash tests/studio/run.sh restore` and `bash tests/studio/run.sh "trash:"` → PASS. Then the full suite → 273 passed, 0 failed.

- [ ] **Step 5: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/studio-restore.php wp-content/mu-plugins/fisha-design/studio/api/api-history.php wp-content/mu-plugins/fisha-design/studio/studio.php tests/studio/test-restore.php
git commit -m "Studio: restore an older version into the draft; bring back trashed products as drafts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Duplicate a product or a category

**Files:**
- Create: `studio/studio-duplicate.php`, `studio/api/api-duplicate.php`
- Modify: `studio/studio.php` (require after `studio-restore.php`), `studio/api/api.php`
- Test: `tests/studio/test-duplicate.php`

**Interfaces:**
- Produces:
  - `fisha_studio_duplicate_product( int $id ): WC_Product|WP_Error`
  - `fisha_studio_duplicate_category( int $id ): WP_Term|WP_Error`
  - REST:
    - `POST /products/{id}/duplicate` → 201 product payload of the copy.
    - `POST /categories/{id}/duplicate` → 201 category payload of the copy.

- [ ] **Step 1: Write the failing tests** — `tests/studio/test-duplicate.php`

```php
<?php
fs_test( 'duplicate: a simple product becomes a never-published copy without SKU', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$src = fs_product( array( 'sku' => 'ZZ-DUP-' . wp_rand( 1000, 9999 ), 'featured' => true, 'image_id' => fs_image() ) );
	update_post_meta( $src->get_id(), FISHA_STUDIO_PUBLISHED_META, '1' );
	$copy = fisha_studio_duplicate_product( $src->get_id() );
	fs_false( is_wp_error( $copy ) );
	fs_on_cleanup( function () use ( $copy ) { fs_delete_product( $copy->get_id() ); } );
	fs_eq( $src->get_name() . ' (copy)', $copy->get_name() );
	fs_eq( 'draft', $copy->get_status() );
	fs_eq( '', $copy->get_sku( 'edit' ) );
	fs_false( $copy->get_featured() );
	fs_eq( $src->get_image_id(), $copy->get_image_id() );
	fs_eq( $src->get_regular_price(), $copy->get_regular_price() );
	fs_eq( '1', get_post_meta( $copy->get_id(), FISHA_STUDIO_CREATED_META, true ) );
	fs_eq( '', (string) get_post_meta( $copy->get_id(), FISHA_STUDIO_PUBLISHED_META, true ) );
	fs_true( fisha_studio_never_live( $copy ), 'treated as never published' );
	fs_eq( sanitize_title( $copy->get_name() ), $copy->get_slug(), 'slug from the new name' );
	fs_eq( 'duplicate', fisha_studio_log_for( 'product', $copy->get_id(), 1 )[0]['action'] );
} );

fs_test( 'duplicate: a product with sizes copies sizes and the draft, clears SKUs', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$src = fs_variable( array( 's', 'm' ) );
	$vm  = fs_var_id( $src, 'm' );
	$v   = wc_get_product( $vm );
	$v->set_sku( 'ZZ-DUP-M-' . wp_rand( 1000, 9999 ) );
	$v->save();
	fisha_studio_product_edit( $src, array( 'short_description' => '<p>Draft text</p>', fisha_studio_var_key( $vm, 'regular_price' ) => '150' ) );
	$copy = fisha_studio_duplicate_product( $src->get_id() );
	fs_on_cleanup( function () use ( $copy ) { fs_delete_product( $copy->get_id() ); } );
	fs_eq( 2, count( fisha_studio_variation_ids( $copy ) ) );
	$cm = fs_var_id( $copy, 'm' );
	fs_true( $cm !== $vm );
	fs_eq( '', wc_get_product( $cm )->get_sku( 'edit' ), 'size SKU cleared' );
	fs_eq( '150', wc_get_product( $cm )->get_regular_price( 'edit' ), 'draft price of M on the copy' );
	fs_eq( '<p>Draft text</p>', $copy->get_short_description( 'edit' ) );
	fs_eq( array(), fisha_studio_draft_get( $copy->get_id() )['fields'], 'a never-published copy keeps no draft' );
	fs_true( (bool) fisha_studio_draft_get( $src->get_id() )['fields'], 'source draft untouched' );
} );

fs_test( 'duplicate: a category copy keeps intro, photos and Show on site, not products', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$top = fs_cat( 'dup-parent' );
	$src = fs_cat( 'dup-src', $top->term_id );
	$img = fs_image();
	update_term_meta( $src->term_id, 'fisha_lead', 'Source intro.' );
	update_term_meta( $src->term_id, 'fisha_hero_ids', (string) $img );
	fs_product( array( 'category_ids' => array( $src->term_id ) ) );
	$copy = fisha_studio_duplicate_category( $src->term_id );
	fs_false( is_wp_error( $copy ) );
	fs_on_cleanup( function () use ( $copy ) { wp_delete_term( $copy->term_id, 'product_cat' ); } );
	fs_eq( 'zz-test dup-src (copy)', $copy->name );
	fs_eq( (int) $top->term_id, (int) $copy->parent );
	fs_eq( 'Source intro.', get_term_meta( $copy->term_id, 'fisha_lead', true ) );
	fs_eq( (string) $img, get_term_meta( $copy->term_id, 'fisha_hero_ids', true ) );
	fs_eq( '1', get_term_meta( $copy->term_id, 'fisha_studio_hidden', true ), 'never published' );
	fs_eq( array(), get_objects_in_term( $copy->term_id, 'product_cat' ) );
} );

fs_test( 'duplicate: REST returns 201 with the copy', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$src = fs_product();
	list( $s, $d ) = fs_api( 'POST', '/products/' . $src->get_id() . '/duplicate' );
	fs_eq( 201, $s, wp_json_encode( $d ) );
	fs_on_cleanup( function () use ( $d ) { fs_delete_product( (int) $d['id'] ); } );
	fs_eq( 'new', $d['state'] );
	fs_true( $d['id'] !== $src->get_id() );
} );
```

- [ ] **Step 2: Run them to see them fail**

Run: `bash tests/studio/run.sh duplicate` → FAIL (undefined functions).

- [ ] **Step 3: Implement** — create `studio/studio-duplicate.php`

```php
<?php
/**
 * Fisha Studio duplicate: a copy of a product (WooCommerce's own duplicator: sizes, options, photos, prices, stock
 * settings) or of a category, as a never-published draft named "… (copy)". The copy shows what the owner sees on screen:
 * the source's live values plus its unpublished draft.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Studio bookkeeping that must never travel to a copy. */
function fisha_studio_duplicate_skip_meta( array $exclude ): array {
	return array_merge( $exclude, array( FISHA_STUDIO_DRAFT_META, FISHA_STUDIO_PUBLISHED_META, FISHA_STUDIO_PENDING_META, FISHA_STUDIO_CREATED_META, '_fisha_studio_target_status', '_fisha_studio_slug_manual', '_fisha_studio_bg_manual' ) );
}

/** @return WC_Product|WP_Error */
function fisha_studio_duplicate_product( int $id ) {
	$src = ( 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) ? wc_get_product( $id ) : null;
	if ( ! $src ) return new WP_Error( 'fisha_studio_not_found', 'This product no longer exists.', array( 'status' => 404 ) );
	if ( ! $src->is_type( array( 'simple', 'variable' ) ) ) return new WP_Error( 'fisha_studio_unsupported', 'This kind of product cannot be copied here.', array( 'status' => 400 ) );
	if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) require_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';

	$draft = fisha_studio_draft_get( $id )['fields'];
	add_filter( 'woocommerce_duplicate_product_exclude_meta', 'fisha_studio_duplicate_skip_meta' );
	try {
		$copy = ( new WC_Admin_Duplicate_Product() )->product_duplicate( $src );
	} finally {
		remove_filter( 'woocommerce_duplicate_product_exclude_meta', 'fisha_studio_duplicate_skip_meta' );
	}
	if ( ! $copy || ! $copy->get_id() ) return new WP_Error( 'fisha_studio_duplicate_failed', 'The copy could not be made. Please try again.', array( 'status' => 500 ) );
	$cid = $copy->get_id();

	// Old size id → new size id, matched by their option values.
	$map = array();
	$new = array();
	foreach ( fisha_studio_variation_ids( $copy ) as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v ) continue;
		$new[ fisha_studio_combo_key( $v->get_attributes( 'edit' ) ) ] = $vid;
		$v->set_sku( '' );
		$v->save();
	}
	foreach ( fisha_studio_variation_ids( $src ) as $vid ) {
		$v = wc_get_product( $vid );
		$k = $v ? fisha_studio_combo_key( $v->get_attributes( 'edit' ) ) : '';
		if ( isset( $new[ $k ] ) ) $map[ $vid ] = $new[ $k ];
	}

	$name = fisha_studio_plain( (string) ( $draft['name'] ?? $src->get_name( 'edit' ) ) ) . ' (copy)';
	$copy = wc_get_product( $cid );
	$copy->set_name( $name );
	$copy->set_slug( fisha_studio_unique_product_slug( fisha_studio_slug_from_name( $name ), $cid ) );
	$copy->set_sku( '' );
	$copy->set_featured( false );
	$copy->update_meta_data( FISHA_STUDIO_CREATED_META, '1' );
	$copy->save();

	// The source's unpublished edits go onto the copy (a never-published product takes them directly).
	$values = array();
	foreach ( $draft as $k => $v ) {
		$k = (string) $k;
		if ( in_array( $k, array( 'name', 'slug', 'sku', 'status' ), true ) ) continue;
		$parsed = fisha_studio_parse_var_key( $k );
		if ( $parsed ) {
			if ( ! isset( $map[ $parsed[0] ] ) || 'sku' === $parsed[1] ) continue;
			$k = fisha_studio_var_key( $map[ $parsed[0] ], $parsed[1] );
		}
		$values[ $k ] = $v;
	}
	if ( $values ) fisha_studio_product_edit( wc_get_product( $cid ), $values );
	fisha_studio_log( 'product', $cid, 'duplicate', null, array( 'source' => $id ) );
	return wc_get_product( $cid );
}

/** @return WP_Term|WP_Error */
function fisha_studio_duplicate_category( int $id ) {
	$src = fisha_studio_category( $id );
	if ( ! $src ) return fisha_studio_category_missing();
	$v    = array_merge( fisha_studio_category_values( $src ), fisha_studio_draft_get( $id, 'term' )['fields'] );
	$base = mb_substr( (string) $v['name'], 0, 53 );
	$copy = fisha_studio_category_create( $base . ' (copy)', (int) $v['parent'] );
	if ( is_wp_error( $copy ) ) return $copy;
	fisha_studio_category_edit( $copy, array( 'lead' => (string) $v['lead'], 'hero_ids' => (array) $v['hero_ids'], 'shown' => (bool) $v['shown'] ) );
	fisha_studio_log( 'category', (int) $copy->term_id, 'duplicate', null, array( 'source' => $id ) );
	return fisha_studio_category( (int) $copy->term_id );
}
```

Create `studio/api/api-duplicate.php`:

```php
<?php
/**
 * Fisha Studio REST: duplicate a product or a category (the copy opens in the editor).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/products/(?P<id>\d+)/duplicate', array( 'methods' => 'POST', 'callback' => function ( WP_REST_Request $r ) {
		$p = fisha_studio_duplicate_product( (int) $r['id'] );
		return is_wp_error( $p ) ? $p : new WP_REST_Response( fisha_studio_product_payload( $p ), 201 );
	}, 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, '/categories/(?P<id>\d+)/duplicate', array( 'methods' => 'POST', 'callback' => function ( WP_REST_Request $r ) {
		$t = fisha_studio_duplicate_category( (int) $r['id'] );
		return is_wp_error( $t ) ? $t : new WP_REST_Response( fisha_studio_category_payload( $t ), 201 );
	}, 'permission_callback' => fisha_studio_permission( 'manage_product_terms' ) ) );
} );
```

Require `studio-duplicate.php` in `studio.php` after `studio-restore.php`, and `api-duplicate.php` at the end of `api/api.php`.

- [ ] **Step 4: Run the tests to see them pass**

Run: `bash tests/studio/run.sh duplicate` → PASS. Full suite → 277 passed, 0 failed.

- [ ] **Step 5: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/studio-duplicate.php wp-content/mu-plugins/fisha-design/studio/api/api-duplicate.php wp-content/mu-plugins/fisha-design/studio/studio.php wp-content/mu-plugins/fisha-design/studio/api/api.php tests/studio/test-duplicate.php
git commit -m "Studio: duplicate a product (sizes, draft, no SKU) or a category as a never-published copy

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: My account on the server (password, backup codes, other devices)

**Files:**
- Create: `studio/studio-account.php`, `studio/api/api-account.php`
- Modify: `studio/studio-2fa.php` (`fisha_studio_2fa_finish` uses the new session helper), `studio/studio.php` (require after `studio-duplicate.php`), `studio/api/api.php`
- Test: `tests/studio/test-account.php`

**Interfaces:**
- Produces:
  - `fisha_studio_issue_session( WP_User $u ): void`: the one place that sets an auth cookie for an owner. It is used by sign-in and by password change.
  - `fisha_studio_account_info( WP_User $u ): array{login, email, name, owner: bool, twofa: {enabled, since: ISO|null, backup_left}}`
  - `fisha_studio_change_password( WP_User $u, string $current, string $new, string $confirm, string $ip ): true|WP_Error`. The 422 carries `fields`; the lock gives 429.
  - `fisha_studio_new_backup_codes_checked( WP_User $u, string $code, string $ip ): string[]|WP_Error`
  - `fisha_studio_sign_out_others( WP_User $u ): void`
  - REST: `GET /account`, `POST /account/password {current,new,confirm}` → `{ nonce }`, `POST /account/backup-codes {code}` → `{ codes }`, `POST /account/sign-out-others` → `{ ok: true }`.

- [ ] **Step 1: Write the failing tests** — `tests/studio/test-account.php`

```php
<?php
function fs_owner_with_password( string $pw ): WP_User {
	$u = fs_user( FISHA_STUDIO_ROLE );
	wp_set_password( $pw, $u->ID );
	wp_set_current_user( $u->ID );
	fs_on_cleanup( function () use ( $u ) { fisha_studio_login_clear( $u->user_login, '10.9.8.7' ); } );
	return get_user_by( 'id', $u->ID );
}

fs_test( 'account: password rules give field errors', function () {
	$u = fs_owner_with_password( 'Current-pass-123' );
	$e = fisha_studio_change_password( $u, 'Current-pass-123', 'short', 'short', '10.9.8.7' );
	fs_true( isset( $e->get_error_data()['fields']['new'] ) );
	$e = fisha_studio_change_password( $u, 'Current-pass-123', 'Long-enough-pass-1', 'Different-pass-12', '10.9.8.7' );
	fs_true( isset( $e->get_error_data()['fields']['confirm'] ) );
	$e = fisha_studio_change_password( $u, 'Current-pass-123', 'Current-pass-123', 'Current-pass-123', '10.9.8.7' );
	fs_true( isset( $e->get_error_data()['fields']['new'] ), 'same as current' );
} );

fs_test( 'account: a wrong current password counts toward the lock', function () {
	$u = fs_owner_with_password( 'Current-pass-123' );
	for ( $i = 0; $i < 5; $i++ ) {
		$e = fisha_studio_change_password( $u, 'wrong', 'Long-enough-pass-1', 'Long-enough-pass-1', '10.9.8.7' );
		fs_true( isset( $e->get_error_data()['fields']['current'] ), "try $i" );
	}
	$e = fisha_studio_change_password( $u, 'Current-pass-123', 'Long-enough-pass-1', 'Long-enough-pass-1', '10.9.8.7' );
	fs_eq( 429, $e->get_error_data()['status'], 'locked even with the right password' );
} );

fs_test( 'account: changing the password keeps this session and returns a fresh nonce', function () {
	$u = fs_owner_with_password( 'Current-pass-123' );
	$old = WP_Session_Tokens::get_instance( $u->ID )->create( time() + HOUR_IN_SECONDS );
	$sent = 0;
	$count = function ( $send, $expire = 0, $expiration = 0, $uid = 0 ) use ( &$sent, $u ) { if ( (int) $uid === $u->ID && $send ) $sent++; return $send; };
	add_filter( 'send_auth_cookies', $count, PHP_INT_MAX, 4 );
	fs_on_cleanup( function () use ( $count ) { remove_filter( 'send_auth_cookies', $count, PHP_INT_MAX ); } );
	fs_true( true === fisha_studio_change_password( $u, 'Current-pass-123', 'Long-enough-pass-1', 'Long-enough-pass-1', '10.9.8.7' ) );
	fs_true( wp_check_password( 'Long-enough-pass-1', get_user_by( 'id', $u->ID )->user_pass, $u->ID ) );
	fs_false( (bool) WP_Session_Tokens::get_instance( $u->ID )->get( $old ), 'older session ended' );
	fs_eq( 1, count( WP_Session_Tokens::get_instance( $u->ID )->get_all() ), 'exactly this new session' );
	fs_eq( 1, $sent, 'the owner cookie was allowed through the guard once' );
	fs_eq( $u->ID, get_current_user_id() );
	fs_eq( 'password-change', fisha_studio_log_for( 'user', $u->ID, 1 )[0]['action'] );
} );

fs_test( 'account: new backup codes need a valid app code and replace the old ones', function () {
	$u = fs_owner_with_password( 'Current-pass-123' );
	$secret = random_bytes( 20 );
	fisha_studio_2fa_enable( $u->ID, $secret );
	fs_on_cleanup( function () use ( $u ) { fisha_studio_2fa_reset( $u->ID ); } );
	$old = fisha_studio_2fa_new_backup_codes( $u->ID );
	fs_true( is_wp_error( fisha_studio_new_backup_codes_checked( $u, '000000', '10.9.8.7' ) ) );
	fs_true( is_wp_error( fisha_studio_new_backup_codes_checked( $u, $old[0], '10.9.8.7' ) ), 'a backup code is not accepted here' );
	$codes = fisha_studio_new_backup_codes_checked( $u, fisha_studio_totp( $secret, fisha_studio_totp_counter() ), '10.9.8.7' );
	fs_eq( 10, count( $codes ) );
	fs_false( fisha_studio_2fa_backup_use( $u->ID, $old[1] ), 'old code no longer works' );
	fs_true( fisha_studio_2fa_backup_use( $u->ID, $codes[0] ) );
} );

fs_test( 'account: info and sign out of other devices', function () {
	$u = fs_owner_with_password( 'Current-pass-123' );
	$a = WP_Session_Tokens::get_instance( $u->ID )->create( time() + HOUR_IN_SECONDS );
	$b = WP_Session_Tokens::get_instance( $u->ID )->create( time() + HOUR_IN_SECONDS );
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $u->ID, time() + HOUR_IN_SECONDS, 'logged_in', $a );
	fs_on_cleanup( function () { unset( $_COOKIE[ LOGGED_IN_COOKIE ] ); } );
	fisha_studio_sign_out_others( $u );
	fs_true( (bool) WP_Session_Tokens::get_instance( $u->ID )->get( $a ), 'this one stays' );
	fs_false( (bool) WP_Session_Tokens::get_instance( $u->ID )->get( $b ) );
	$info = fisha_studio_account_info( $u );
	fs_eq( $u->user_login, $info['login'] );
	fs_true( $info['owner'] );
	fs_false( $info['twofa']['enabled'] );
} );

fs_test( 'account: REST is for the signed-in Studio user only', function () {
	fs_as( 'customer' );
	fs_eq( 403, fs_api( 'GET', '/account' )[0] );
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'GET', '/account' );
	fs_eq( 200, $s );
	fs_true( isset( $d['twofa']['backup_left'] ) );
} );
```

- [ ] **Step 2: Run them to see them fail**

Run: `bash tests/studio/run.sh account` → FAIL (undefined functions).

- [ ] **Step 3: Implement** — create `studio/studio-account.php`

```php
<?php
/**
 * Fisha Studio "My account": change the password (this browser stays signed in, every other session ends), make new
 * backup codes (needs a code from the authenticator app), and sign out of other devices.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Sets the auth cookie for this browser; the only path allowed past the 2-step cookie guard for an owner. */
function fisha_studio_issue_session( WP_User $u ): void {
	$GLOBALS['fisha_studio_2fa_ok'] = $u->ID;
	try {
		wp_set_auth_cookie( $u->ID, false, is_ssl() );
	} finally {
		unset( $GLOBALS['fisha_studio_2fa_ok'] );
	}
	wp_set_current_user( $u->ID );
}

function fisha_studio_account_info( WP_User $u ): array {
	global $wpdb;
	$owner = fisha_studio_is_owner( $u );
	$since = $owner ? $wpdb->get_var( $wpdb->prepare( 'SELECT created FROM ' . fisha_studio_log_table() . " WHERE item_type = 'user' AND item_id = %d AND action = '2fa-setup' ORDER BY id DESC LIMIT 1", $u->ID ) ) : null;
	return array(
		'login' => $u->user_login,
		'email' => $u->user_email,
		'name'  => $u->display_name,
		'owner' => $owner,
		'twofa' => array(
			'enabled'     => $owner && fisha_studio_2fa_enabled( $u->ID ),
			'since'       => $since ? str_replace( ' ', 'T', $since ) . 'Z' : null,
			'backup_left' => $owner ? fisha_studio_2fa_backup_left( $u->ID ) : 0,
		),
	);
}

/** @return true|WP_Error */
function fisha_studio_change_password( WP_User $u, string $current, string $new, string $confirm, string $ip ) {
	$invalid = function ( array $fields ) {
		return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => $fields ) );
	};
	if ( ! fisha_studio_login_reserve( $u->user_login, $ip ) ) {
		return new WP_Error( 'fisha_studio_locked', 'Too many tries. Please wait 15 minutes and try again.', array( 'status' => 429 ) );
	}
	if ( ! wp_check_password( $current, $u->user_pass, $u->ID ) ) return $invalid( array( 'current' => 'That is not your current password.' ) );
	if ( mb_strlen( $new ) < 12 ) return $invalid( array( 'new' => 'Use at least 12 characters.' ) );
	if ( $new === $current ) return $invalid( array( 'new' => 'Choose a password different from the current one.' ) );
	if ( $new !== $confirm ) return $invalid( array( 'confirm' => 'The two new passwords are not the same.' ) );

	fisha_studio_login_clear( $u->user_login, $ip );
	wp_set_password( $new, $u->ID );
	WP_Session_Tokens::get_instance( $u->ID )->destroy_all();
	clean_user_cache( $u->ID );
	fisha_studio_issue_session( get_user_by( 'id', $u->ID ) );
	fisha_studio_log( 'user', $u->ID, 'password-change', null, array( 'ip' => $ip ) );
	wp_mail( $u->user_email, 'Your Fisha Studio password was changed', "Hi {$u->display_name},\n\nThe password of your Fisha Studio account was just changed. If this was not you, contact your web developer right away.\n" );
	return true;
}

/** @return string[]|WP_Error the 10 new codes, shown once */
function fisha_studio_new_backup_codes_checked( WP_User $u, string $code, string $ip ) {
	if ( ! fisha_studio_is_owner( $u ) || ! fisha_studio_2fa_enabled( $u->ID ) ) {
		return new WP_Error( 'fisha_studio_no_2fa', '2-step sign-in is not set up for this account.', array( 'status' => 400 ) );
	}
	if ( ! fisha_studio_login_reserve( $u->user_login, $ip ) ) {
		return new WP_Error( 'fisha_studio_locked', 'Too many tries. Please wait 15 minutes and try again.', array( 'status' => 429 ) );
	}
	$code = preg_replace( '/\s+/', '', $code );
	if ( ! preg_match( '/^\d{6}$/', $code ) || ! fisha_studio_totp_verify( $u->ID, $code ) ) {
		return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => array( 'code' => 'That code is not right. Use the 6-digit code from your app.' ) ) );
	}
	fisha_studio_login_clear( $u->user_login, $ip );
	$codes = fisha_studio_2fa_new_backup_codes( $u->ID );
	fisha_studio_log( 'user', $u->ID, 'backup-codes-new', null, array( 'ip' => $ip ) );
	return $codes;
}

function fisha_studio_sign_out_others( WP_User $u ): void {
	WP_Session_Tokens::get_instance( $u->ID )->destroy_others( wp_get_session_token() );
	fisha_studio_log( 'user', $u->ID, 'sign-out-others', null, null );
}
```

In `studio-2fa.php`, inside `fisha_studio_2fa_finish()`, replace the block from `$GLOBALS['fisha_studio_2fa_ok'] = $u->ID;` through `wp_set_current_user( $u->ID );` with `fisha_studio_issue_session( $u );`. Keep the comment line "The only place an owner's auth cookie may be sent…" above it, reworded to "Through the one session helper allowed past the cookie guard."

Create `studio/api/api-account.php`:

```php
<?php
/**
 * Fisha Studio REST: My account (details, change password, new backup codes, sign out of other devices).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	$me = fisha_studio_permission();
	register_rest_route( FISHA_STUDIO_NS, '/account', array( 'methods' => 'GET', 'callback' => function () { return fisha_studio_account_info( wp_get_current_user() ); }, 'permission_callback' => $me ) );
	register_rest_route( FISHA_STUDIO_NS, '/account/password', array( 'methods' => 'POST', 'callback' => function ( WP_REST_Request $r ) {
		$b   = fisha_studio_body( $r );
		$res = fisha_studio_change_password( wp_get_current_user(), (string) ( $b['current'] ?? '' ), (string) ( $b['new'] ?? '' ), (string) ( $b['confirm'] ?? '' ), fisha_client_ip() );
		return is_wp_error( $res ) ? $res : array( 'nonce' => wp_create_nonce( 'wp_rest' ) );
	}, 'permission_callback' => $me ) );
	register_rest_route( FISHA_STUDIO_NS, '/account/backup-codes', array( 'methods' => 'POST', 'callback' => function ( WP_REST_Request $r ) {
		$res = fisha_studio_new_backup_codes_checked( wp_get_current_user(), (string) ( fisha_studio_body( $r )['code'] ?? '' ), fisha_client_ip() );
		return is_wp_error( $res ) ? $res : array( 'codes' => $res );
	}, 'permission_callback' => $me ) );
	register_rest_route( FISHA_STUDIO_NS, '/account/sign-out-others', array( 'methods' => 'POST', 'callback' => function () {
		fisha_studio_sign_out_others( wp_get_current_user() );
		return array( 'ok' => true );
	}, 'permission_callback' => $me ) );
} );
```

Require `studio-account.php` in `studio.php` after `studio-duplicate.php`, and `api-account.php` at the end of `api/api.php`.

- [ ] **Step 4: Run the tests to see them pass**

Run: `bash tests/studio/run.sh account`, then `bash tests/studio/run.sh 2fa` (sign-in must stay green after the `finish()` change) → PASS. Full suite → 283 passed, 0 failed.

- [ ] **Step 5: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/studio-account.php wp-content/mu-plugins/fisha-design/studio/api/api-account.php wp-content/mu-plugins/fisha-design/studio/studio-2fa.php wp-content/mu-plugins/fisha-design/studio/studio.php wp-content/mu-plugins/fisha-design/studio/api/api.php tests/studio/test-account.php
git commit -m "Studio: My account on the server (change password and stay signed in, new backup codes, sign out others)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Dashboard data

**Files:**
- Create: `studio/api/api-dashboard.php`
- Modify: `studio/api/api.php`
- Test: `tests/studio/test-dashboard.php`

**Interfaces:**
- Consumes: Task 1 `fisha_studio_history_query`.
- Produces:
  - `fisha_studio_dashboard(): array{counts:{live,sold_out,low,drafts}, waiting: array[], recent: array[], activity: array[]}`
    - `waiting` items: `{kind:'product'|'category', id, name, state, updated: ISO|null}`, at most 20.
    - `recent` items: `{kind, id, name, when}`, at most 10.
    - `activity` = the newest 15 History entries.
  - REST `GET /dashboard`.

- [ ] **Step 1: Write the failing test** — `tests/studio/test-dashboard.php`

```php
<?php
fs_test( 'dashboard: counts follow the product rows', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$before = fisha_studio_dashboard()['counts'];
	fs_product( array( 'manage_stock' => true, 'stock_quantity' => 0, 'stock_status' => 'outofstock' ) );
	fs_product( array( 'manage_stock' => true, 'stock_quantity' => 1, 'low_stock_amount' => 2 ) );
	$p = fisha_studio_product_create( 'zz-test dash draft' );
	fs_on_cleanup( function () use ( $p ) { fs_delete_product( $p->get_id() ); } );
	$after = fisha_studio_dashboard()['counts'];
	fs_eq( $before['live'] + 2, $after['live'] );
	fs_eq( $before['sold_out'] + 1, $after['sold_out'] );
	fs_eq( $before['low'] + 1, $after['low'] );
	fs_eq( $before['drafts'] + 1, $after['drafts'] );
} );

fs_test( 'dashboard: waiting lists products and categories with unpublished changes', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p = fs_product();
	fisha_studio_product_edit( $p, array( 'regular_price' => '7' ) );
	$t = fs_cat( 'dash-wait' );
	fisha_studio_category_edit( $t, array( 'lead' => 'Draft.' ) );
	$w = fisha_studio_dashboard()['waiting'];
	$keys = array_map( function ( $x ) { return $x['kind'] . ':' . $x['id']; }, $w );
	fs_true( in_array( 'product:' . $p->get_id(), $keys, true ) );
	fs_true( in_array( 'category:' . $t->term_id, $keys, true ) );
	fs_true( count( $w ) <= 20 );
} );

fs_test( 'dashboard: recent and activity come from History; REST works', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p   = fs_product();
	$log = fisha_studio_log( 'product', $p->get_id(), 'publish', array( 'name' => 'a' ), array( 'name' => 'b' ) );
	fs_on_cleanup( function () use ( $log ) { global $wpdb; $wpdb->delete( fisha_studio_log_table(), array( 'id' => $log ) ); } );
	$d = fisha_studio_dashboard();
	fs_eq( $p->get_id(), $d['recent'][0]['id'] );
	fs_eq( $log, $d['activity'][0]['id'] );
	fs_true( count( $d['activity'] ) <= 15 );
	list( $s, $r ) = fs_api( 'GET', '/dashboard' );
	fs_eq( 200, $s );
	fs_true( isset( $r['counts']['live'] ) );
} );
```

- [ ] **Step 2: Run it to see it fail**

Run: `bash tests/studio/run.sh dashboard` → FAIL.

- [ ] **Step 3: Implement** — create `studio/api/api-dashboard.php`

```php
<?php
/**
 * Fisha Studio REST: the Dashboard (counts that link to the products list, what waits to be published, recently
 * edited items, latest activity).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/dashboard', array( 'methods' => 'GET', 'callback' => 'fisha_studio_dashboard', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
} );

function fisha_studio_dashboard(): array {
	global $wpdb;
	$counts  = array( 'live' => 0, 'sold_out' => 0, 'low' => 0, 'drafts' => 0 );
	$waiting = array();
	$ids = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private', 'draft' ), 'numberposts' => -1, 'fields' => 'ids' ) );
	foreach ( $ids as $id ) {
		$p = wc_get_product( (int) $id );
		if ( ! $p ) continue;
		$row = fisha_studio_product_row( $p );
		if ( 'new' === $row['state'] ) $counts['drafts']++;
		elseif ( ! $row['hidden'] ) {
			$counts['live']++;
			if ( 'outofstock' === $row['stock_status'] ) $counts['sold_out']++;
			if ( $row['low'] ) $counts['low']++;
		}
		if ( 'live' !== $row['state'] ) {
			$d = fisha_studio_draft_get( (int) $id );
			$t = $d['updated'] ?: (int) get_post_modified_time( 'U', true, (int) $id );
			$waiting[] = array( 'kind' => 'product', 'id' => (int) $id, 'name' => $row['name'] ?: 'Untitled', 'state' => $row['state'], 'ts' => $t );
		}
	}
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
		$state = fisha_studio_category_state( $t );
		if ( 'live' === $state ) continue;
		$waiting[] = array( 'kind' => 'category', 'id' => (int) $t->term_id, 'name' => fisha_studio_plain( $t->name ), 'state' => $state, 'ts' => (int) fisha_studio_draft_get( (int) $t->term_id, 'term' )['updated'] );
	}
	usort( $waiting, function ( $a, $b ) { return $b['ts'] <=> $a['ts']; } );
	$waiting = array_map( function ( $w ) {
		$w['updated'] = $w['ts'] ? gmdate( 'Y-m-d\TH:i:s\Z', $w['ts'] ) : null;
		unset( $w['ts'] );
		return $w;
	}, array_slice( $waiting, 0, 20 ) );

	$rows = $wpdb->get_results( 'SELECT item_type, item_id, MAX(id) AS last, MAX(created) AS created FROM ' . fisha_studio_log_table() . " WHERE item_type IN ('product','category') AND action IN ('create','publish','convert') GROUP BY item_type, item_id ORDER BY last DESC LIMIT 10", ARRAY_A );
	$recent = array();
	foreach ( $rows ?: array() as $r ) {
		$item = fisha_studio_history_item( $r['item_type'], (int) $r['item_id'], array() );
		if ( ! $item || ! $item['exists'] ) continue;
		$recent[] = array( 'kind' => $item['type'], 'id' => $item['id'], 'name' => $item['name'], 'when' => str_replace( ' ', 'T', $r['created'] ) . 'Z' );
	}
	return array( 'counts' => $counts, 'waiting' => $waiting, 'recent' => $recent, 'activity' => fisha_studio_history_query( array( 'per_page' => 15 ) )['items'] );
}
```

Require `api-dashboard.php` at the end of `api/api.php`.

- [ ] **Step 4: Run the tests to see them pass**

Run: `bash tests/studio/run.sh dashboard` → PASS. Full suite → 286 passed, 0 failed.

- [ ] **Step 5: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/api/api-dashboard.php wp-content/mu-plugins/fisha-design/studio/api/api.php tests/studio/test-dashboard.php
git commit -m "Studio: dashboard data (counts, waiting to publish, recently edited, latest activity)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Dashboard screen, navigation, start page

**Files:**
- Create: `studio/app/js/views/dashboard.js`, `studio/app/js/time.js`
- Modify: `studio/app/js/app.js`, `studio/app/studio.css`

**Interfaces:**
- Consumes: `GET /dashboard` (Task 5).
- Produces:
  - `dashboardView( main ): { destroy() }`
  - `ago( iso: string ): string` and `when( iso: string ): string` (time.js)
  - Routes `#/dashboard` (also the default), `#/history`, `#/account`. Tasks 7 and 8 replace the stubs below.

- [ ] **Step 1: `app/js/time.js`**

```js
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
```

- [ ] **Step 2: `app/js/views/dashboard.js`**

```js
/**
 * Fisha Studio Dashboard: counts that open the products list with that filter, what is waiting to be published,
 * recently edited items and the latest activity.
 */
import { h } from '../ui.js';
import { api } from '../api.js';
import { ago } from '../time.js';

const COUNTS = [
	[ 'live', 'Live products', { state: 'all', stock: 'all' } ],
	[ 'sold_out', 'Sold out', { stock: 'outofstock' } ],
	[ 'low', 'Low stock', { stock: 'low' } ],
	[ 'drafts', 'Drafts', { state: 'new' } ],
];
const STATE = { new: 'Not published yet', changed: 'Unpublished changes' };
const link = ( x ) => `#/${ x.kind === 'category' ? 'categories' : 'products' }/${ x.id }`;

export function dashboardView( main ) {
	let alive = true;
	const body = h( 'div.fs-dash', {}, h( 'p.fs-empty', {}, 'Loading…' ) );
	main.append( h( 'div.fs-page-head', {}, h( 'h1', {}, 'Dashboard' ) ), body );

	function openList( filter ) {
		try { sessionStorage.setItem( 'fs-list', JSON.stringify( { search: '', category: '', state: 'all', stock: 'all', page: 1, ...filter } ) ); } catch {}
		location.hash = '#/products';
	}
	const section = ( title, items, empty, row ) => h( 'section.fs-dash__card', {},
		h( 'h2', {}, title ),
		items.length ? h( 'ul.fs-dash__list', {}, items.map( row ) ) : h( 'p.fs-field__help', {}, empty ) );

	( async () => {
		try {
			const d = await api( 'GET', 'dashboard' );
			if ( ! alive ) return;
			body.replaceChildren(
				h( 'div.fs-dash__counts', {}, COUNTS.map( ( [ key, label, filter ] ) => h( 'button.fs-dash__count', { type: 'button', onclick: () => openList( filter ) },
					h( 'span.fs-dash__num', {}, String( d.counts[ key ] ) ), h( 'span', {}, label ) ) ) ),
				h( 'div.fs-dash__grid', {},
					section( 'Waiting to publish', d.waiting, 'Nothing waiting to publish.', ( x ) => h( 'li', {},
						h( 'a', { href: link( x ) }, x.name ), h( 'span.fs-pill.fs-pill--' + ( x.state === 'new' ? 'draft' : 'changed' ), {}, STATE[ x.state ] ),
						x.updated ? h( 'span.fs-dash__when', {}, ago( x.updated ) ) : null ) ),
					section( 'Recently edited', d.recent, 'Nothing published yet.', ( x ) => h( 'li', {},
						h( 'a', { href: link( x ) }, x.name ), h( 'span.fs-dash__when', {}, ago( x.when ) ) ) ),
					section( 'Latest activity', d.activity, 'No activity yet.', ( e ) => h( 'li', {},
						h( 'span', {}, e.title, e.item ? ' · ' : '', e.item ? ( e.item.exists ? h( 'a', { href: link( { kind: e.item.type, id: e.item.id } ) }, e.item.name ) : e.item.name ) : null ),
						h( 'span.fs-dash__when', {}, `${ e.who }, ${ ago( e.when ) }` ) ) ) ),
				h( 'p.fs-dash__more', {}, h( 'a', { href: '#/history' }, 'See all history →' ) ) );
		} catch ( e ) {
			body.replaceChildren( h( 'p.fs-empty', {}, e.message ) );
		}
	} )();
	return { destroy() { alive = false; } };
}
```

- [ ] **Step 3: Router, nav, start page** — `app/js/app.js`

Add these imports:
```js
import { dashboardView } from './views/dashboard.js';
import { historyView } from './views/history.js';
import { accountView } from './views/account.js';
```

Change the nav so it reads, in this order:
```js
const nav = h( 'nav.fs-nav', { 'aria-label': 'Studio sections' },
	h( 'a.fs-nav__item', { href: '#/dashboard', dataset: { section: 'dashboard' } }, 'Dashboard' ),
	h( 'a.fs-nav__item', { href: '#/products', dataset: { section: 'products' } }, 'Products' ),
	h( 'a.fs-nav__item', { href: '#/categories', dataset: { section: 'categories' } }, 'Categories' ),
	h( 'a.fs-nav__item', { href: '#/inventory', dataset: { section: 'inventory' } }, 'Inventory' ),
	h( 'a.fs-nav__item', { href: '#/options', dataset: { section: 'options' } }, 'Size lists' ),
	h( 'a.fs-nav__item', { href: '#/history', dataset: { section: 'history' } }, 'History' ),
	h( 'a.fs-nav__item', { href: '#/account', dataset: { section: 'account' } }, 'My account' ) );
```

In the top bar:
- Change `h( 'span.fs-user', {}, config.user )` to `h( 'a.fs-user', { href: '#/account' }, config.user )`.
- Change the brand link's `href` to `'#/dashboard'`.

Add these routes at the top of `routes`:
```js
	[ /^#\/dashboard$/, () => dashboardView( main ) ],
	[ /^#\/history$/, () => historyView( main ) ],
	[ /^#\/account$/, () => accountView( main ) ],
```

Replace `'#/products'` with `'#/dashboard'` in both the `const hash = location.hash || …` default and the final `history.replaceState( null, '', '#/products' )`, as well as the no-match fallback (`: productsList( main )` → `: dashboardView( main )`).

Create the stubs `app/js/views/history.js` and `app/js/views/account.js` (Tasks 7 and 8 replace them):
```js
/** Fisha Studio History (built in the next task). */
import { h } from '../ui.js';
export function historyView( main ) { main.append( h( 'p.fs-empty', {}, 'Coming next.' ) ); return {}; }
```
```js
/** Fisha Studio My account (built in a later task). */
import { h } from '../ui.js';
export function accountView( main ) { main.append( h( 'p.fs-empty', {}, 'Coming next.' ) ); return {}; }
```

- [ ] **Step 4: Styles** — append to `app/studio.css`

```css
/* ---------- dashboard ---------- */
.fs-dash__counts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; margin-bottom: 1rem; }
.fs-dash__count { display: grid; gap: .2rem; text-align: left; padding: 1rem; background: var(--fs-card); border: 1px solid var(--fs-line); border-radius: var(--fs-radius); box-shadow: var(--fs-shadow); cursor: pointer; font: inherit; color: inherit; }
.fs-dash__count:hover, .fs-dash__count:focus-visible { border-color: var(--fs-orange-text); }
.fs-dash__num { font-size: 1.8rem; font-weight: 800; }
.fs-dash__grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }
.fs-dash__card { background: var(--fs-card); border-radius: var(--fs-radius); box-shadow: var(--fs-shadow); padding: 1rem; min-width: 0; }
.fs-dash__card h2 { font-size: 1rem; margin: 0 0 .6rem; }
.fs-dash__list { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; }
.fs-dash__list li { display: flex; flex-wrap: wrap; gap: .3rem .6rem; align-items: center; overflow-wrap: anywhere; }
.fs-dash__when { color: var(--fs-muted); font-size: .85rem; }
.fs-dash__more { margin-top: 1rem; }
@media (max-width: 1100px) { .fs-dash__grid { grid-template-columns: 1fr; } }
@media (max-width: 760px) { .fs-dash__counts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
```

- [ ] **Step 5: Check**

- Run `node --check` on `app.js`, `views/dashboard.js`, `views/history.js`, `views/account.js` and `time.js`.
- Run `bash tests/studio/run.sh` → 286 passed. No PHP changed in this task.
- Browser check: ask the controller. The owner's Chrome is needed; never type credentials.
  - `/studio/` opens on Dashboard.
  - Each count opens Products with that filter.
  - The lists link to their editors.
  - At 375px the counts form 2 columns and there is no sideways scroll.

- [ ] **Step 6: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/app/js/time.js wp-content/mu-plugins/fisha-design/studio/app/js/views/dashboard.js wp-content/mu-plugins/fisha-design/studio/app/js/views/history.js wp-content/mu-plugins/fisha-design/studio/app/js/views/account.js wp-content/mu-plugins/fisha-design/studio/app/js/app.js wp-content/mu-plugins/fisha-design/studio/app/studio.css
git commit -m "Studio UI: Dashboard as the start page, History and My account in the menu

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: History screen, History in both editors, restore and bring back

**Files:**
- Create: `studio/app/js/history.js`
- Replace: `studio/app/js/views/history.js` (stub)
- Modify: `studio/app/js/views/product-editor.js`, `studio/app/js/views/category-editor.js`, `studio/app/studio.css`

**Interfaces:**
- Consumes:
  - `GET /history` (Task 1).
  - `POST /products|categories/{id}/restore` → payload + `skipped`; `GET /trash`; `POST /products/{id}/untrash` (Task 2).
  - `ago`, `when` (Task 6).
- Produces: `historyList( { query: object, onRestore?: ( entry ) => Promise<void> } ): { el, reload() }`

- [ ] **Step 1: `app/js/history.js`** (shared list)

```js
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
```

- [ ] **Step 2: `app/js/views/history.js`** (replaces the stub)

```js
/**
 * Fisha Studio History screen: every change made in the Studio, filtered by kind and date, plus recently deleted
 * products with "Bring back" (they return as drafts).
 */
import { h, toast } from '../ui.js';
import { api } from '../api.js';
import { historyList } from '../history.js';
import { ago } from '../time.js';

const KINDS = [ [ 'all', 'Everything' ], [ 'product', 'Products' ], [ 'category', 'Categories' ], [ 'signin', 'Sign-ins' ], [ 'other', 'Size lists and other' ] ];
const DAYS = [ [ '0', 'Any time' ], [ '1', 'Today' ], [ '7', 'Last 7 days' ], [ '30', 'Last 30 days' ] ];

export function historyView( main ) {
	const q = { kind: 'all', days: '0', per_page: 50 };
	const box = h( 'div' );
	const trash = h( 'section.fs-trash' );
	const pick = ( key, options, label ) => h( 'select.fs-input', { 'aria-label': label, onchange: ( e ) => { q[ key ] = e.target.value; render(); } }, options.map( ( [ v, t ] ) => h( 'option', { value: v }, t ) ) );
	main.append( h( 'div.fs-page-head', {}, h( 'h1', {}, 'History' ) ),
		h( 'p.fs-field__help', {}, 'Changes made in the Studio, newest first. Stock that changes because of an order is not listed here.' ),
		h( 'div.fs-filters', {}, pick( 'kind', KINDS, 'What' ), pick( 'days', DAYS, 'When' ) ),
		box, trash );

	function render() {
		box.replaceChildren( historyList( { query: { ...q } } ).el );
	}

	async function loadTrash() {
		try {
			const d = await api( 'GET', 'trash' );
			trash.replaceChildren( h( 'h2', {}, 'Recently deleted products' ),
				d.items.length
					? h( 'ul.fs-trash__list', {}, d.items.map( ( x ) => h( 'li', {},
						x.thumb ? h( 'img', { src: x.thumb, alt: '', width: 44, height: 44 } ) : null,
						h( 'span', {}, x.name, h( 'span.fs-hist__when', {}, ' deleted ' + ago( x.deleted ) ) ),
						h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: () => bringBack( x ) }, 'Bring back' ) ) ) )
					: h( 'p.fs-field__help', {}, 'Nothing deleted in the last 30 days.' ) );
		} catch ( e ) {
			trash.replaceChildren();
		}
	}

	async function bringBack( x ) {
		try {
			const d = await api( 'POST', `products/${ x.id }/untrash` );
			toast( `${ x.name } is back as a draft. Publish it when it is ready.` );
			location.hash = '#/products/' + d.id;
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	render();
	loadTrash();
	return {};
}
```

- [ ] **Step 3: History in the product editor** — `app/js/views/product-editor.js`

1. Add imports at the top: `import { historyList } from '../history.js';`.
2. Add `[ 'history', 'History' ]` as the last entry of both `TABS` and `TABS_SIZES`.
3. In `build( d )`, add a panel after the shipping panel inside `panels.replaceChildren( … )`:

```js
			panel( 'history', historyBox ),
```

Before `panels.replaceChildren`, add:

```js
		const historyBox = h( 'div', {},
			h( 'p.fs-field__help', {}, 'Restoring puts that version into your draft. Check it in the preview, then publish.' ),
			historyList( { query: { item_type: 'product', item_id: id, per_page: 20 }, onRestore: restoreVersion } ).el );
```

4. Add the restore function next to `discard()`:

```js
	async function restoreVersion( entry ) {
		if ( ! ( await flush() ) ) return;
		try {
			const d = await api( 'POST', `products/${ id }/restore`, { log_id: entry.id } );
			reload( d );
			toast( 'That version is now in your draft. Check the preview, then publish.' );
			( d.skipped || [] ).forEach( ( s ) => toast( s, 'warn' ) );
		} catch ( e ) {
			toast( e.message, e.code === 'fisha_studio_same' ? 'ok' : 'error' );
		}
	}
```

(`reload( d )` already rebuilds the editor from a payload and fires the saved hooks, which refreshes the preview.)

- [ ] **Step 4: History in the category editor** — `app/js/views/category-editor.js`

1. Add `import { historyList } from '../history.js';`.
2. In `build( d )`, before the danger zone in `body.replaceChildren( … )`, add:

```js
			h( 'details.fs-cat-history', {},
				h( 'summary', {}, 'History' ),
				h( 'p.fs-field__help', {}, 'Restoring puts that version into your draft. Check it in the preview, then publish.' ),
				historyList( { query: { item_type: 'category', item_id: id, per_page: 20 }, onRestore: restoreVersion } ).el ),
```

3. Add the restore function:

```js
	async function restoreVersion( entry ) {
		if ( ! ( await flush() ) ) return;
		try {
			const d = await api( 'POST', `categories/${ id }/restore`, { log_id: entry.id } );
			clearErrors();
			apply( d );
			for ( const k of Object.keys( ed.widgets ) ) delete ed.widgets[ k ];
			build( d );
			refreshPreview( d );
			toast( 'That version is now in your draft. Check the preview, then publish.' );
			( d.skipped || [] ).forEach( ( s ) => toast( s, 'warn' ) );
		} catch ( e ) {
			toast( e.message, e.code === 'fisha_studio_same' ? 'ok' : 'error' );
		}
	}
```

- [ ] **Step 5: Styles** — append to `app/studio.css`

```css
/* ---------- history ---------- */
.fs-hist { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; }
.fs-hist__row { background: var(--fs-card); border: 1px solid var(--fs-line); border-radius: 10px; padding: .7rem .8rem; display: grid; gap: .35rem; justify-items: start; }
.fs-hist__head { display: flex; flex-wrap: wrap; gap: .25rem .5rem; align-items: baseline; overflow-wrap: anywhere; }
.fs-hist__when { color: var(--fs-muted); font-size: .85rem; margin-left: auto; }
.fs-hist__changes { margin: 0; padding-left: 1.1rem; color: var(--fs-text, inherit); font-size: .92rem; }
.fs-trash { margin-top: 2rem; }
.fs-trash__list { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; }
.fs-trash__list li { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.fs-trash__list img { border-radius: 6px; object-fit: cover; }
.fs-cat-history { margin: 1.5rem 0; }
.fs-cat-history summary { cursor: pointer; font-weight: 800; min-height: 44px; display: flex; align-items: center; }
```

- [ ] **Step 6: Check**

- Run `node --check` on the changed JS files.
- Run the full suite → 286 passed.
- Browser check by the controller in the owner's Chrome:
  - Open a test product's History tab, restore an older price, preview, then Discard.
  - The History screen filters work.
  - Trash a test product, then Bring back. It opens as a draft.
  - The category History section works.
  - At 375px there is no sideways scroll.

- [ ] **Step 7: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/app/js/history.js wp-content/mu-plugins/fisha-design/studio/app/js/views/history.js wp-content/mu-plugins/fisha-design/studio/app/js/views/product-editor.js wp-content/mu-plugins/fisha-design/studio/app/js/views/category-editor.js wp-content/mu-plugins/fisha-design/studio/app/studio.css
git commit -m "Studio UI: History screen, History in the editors with restore, bring back deleted products

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Duplicate buttons and the My account screen

**Files:**
- Replace: `studio/app/js/views/account.js` (stub)
- Modify: `studio/app/js/api.js` (`setNonce`), `studio/app/js/views/product-editor.js`, `studio/app/js/views/category-editor.js`, `studio/app/studio.css`

**Interfaces:**
- Consumes:
  - `POST /products|categories/{id}/duplicate` (Task 3).
  - `GET /account`, `POST /account/password` → `{ nonce }`, `POST /account/backup-codes` → `{ codes }`, `POST /account/sign-out-others` (Task 4).
- Produces: `export function setNonce( n: string ): void` (api.js), `accountView( main )`.

- [ ] **Step 1: `api.js`**: below `let nonce = config.nonce;` add:

```js
/** After a password change the server starts a new session; its REST nonce replaces the old one. */
export function setNonce( n ) {
	if ( n ) nonce = n;
}
```

- [ ] **Step 2: Duplicate in the product editor** (`product-editor.js`)

In `renderHead()`, inside the `else` branch for editable products, add before the "View on site" link:

```js
			actions.push( h( 'button.fs-btn', { type: 'button', onclick: duplicate }, 'Duplicate' ) );
```

and add the function next to `trash()`:

```js
	async function duplicate() {
		if ( ! ( await flush() ) ) return;
		try {
			const d = await api( 'POST', `products/${ id }/duplicate` );
			toast( `Made "${ d.values.name }". It is not on the site yet.` );
			location.hash = '#/products/' + d.id;
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}
```

- [ ] **Step 3: Duplicate in the category editor** (`category-editor.js`)

In `renderHead()`, add `h( 'button.fs-btn', { type: 'button', onclick: duplicate }, 'Duplicate' ),` before the "View on site" link, and add:

```js
	async function duplicate() {
		if ( ! ( await flush() ) ) return;
		try {
			const d = await api( 'POST', `categories/${ id }/duplicate` );
			toast( `Made "${ d.values.name }". It is not on the site yet.` );
			location.hash = '#/categories/' + d.id;
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}
```

- [ ] **Step 4: `app/js/views/account.js`** (replaces the stub)

```js
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
```

(`textField` passes `opts.type` to its input, so `{ type: 'password' }` works as is.)

- [ ] **Step 5: Styles** — append to `app/studio.css`

```css
/* ---------- my account ---------- */
.fs-account { display: grid; gap: 1rem; max-width: 40rem; }
.fs-account__card { background: var(--fs-card); border-radius: var(--fs-radius); box-shadow: var(--fs-shadow); padding: 1rem 1.1rem; display: grid; gap: .6rem; justify-items: start; }
.fs-account__card h2 { font-size: 1.05rem; margin: 0; }
.fs-account__card .fs-field { width: 100%; }
.fs-codes { columns: 2; padding-left: 1.4rem; font-size: 1.05rem; }
```

- [ ] **Step 6: Check**

- Run `node --check` on the changed JS files.
- Run the full suite → 286 passed.
- Browser check by the controller in the owner's Chrome:
  - Duplicate a test product with sizes. The copy opens with "(copy)", the sizes are present and the SKU fields are empty. Duplicate a test category.
  - My account: change the password and stay signed in, so that clicking Products still works without a sign-in prompt. Then change it back.
  - Make new backup codes with an app code.
  - Sign out of other devices.
  - Delete the copies.

- [ ] **Step 7: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/app/js/api.js wp-content/mu-plugins/fisha-design/studio/app/js/views/account.js wp-content/mu-plugins/fisha-design/studio/app/js/views/product-editor.js wp-content/mu-plugins/fisha-design/studio/app/js/views/category-editor.js wp-content/mu-plugins/fisha-design/studio/app/studio.css
git commit -m "Studio UI: Duplicate buttons and the My account screen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Phone pass

**Files:**
- Modify: `studio/app/studio.css` (and, only where a screen cannot be fixed with CSS, the view's JS)

**Interfaces:** none new.

This task is an inspection, not a fixed list. Its acceptance criteria are exact; its fixes depend on what the inspection finds. Every fix stays inside the existing `@media (max-width: 760px)` / `@media (pointer: coarse), (max-width: 760px)` blocks, or a new `@media (max-width: 1100px)` block.

- [ ] **Step 1: Measure** (controller, owner's Chrome, viewport 375 × 812 then 768 × 1024)

Check every screen: sign-in pages, Dashboard, Products, product editor (each tab), Inventory, Size lists, Categories, category editor, History, My account.

On each screen, run this in the console with the JavaScript tool and record the output:

```js
( () => {
	const over = document.documentElement.scrollWidth > window.innerWidth;
	const small = [ ...document.querySelectorAll( 'button, a, input, select, textarea, [role="switch"]' ) ]
		.filter( ( e ) => e.offsetParent && ( e.getBoundingClientRect().height < 44 ) )
		.map( ( e ) => ( e.className || e.tagName ) + ':' + Math.round( e.getBoundingClientRect().height ) );
	const tiny = [ ...document.querySelectorAll( 'body *' ) ].filter( ( e ) => e.offsetParent && e.childNodes.length && [ ...e.childNodes ].some( ( n ) => n.nodeType === 3 && n.textContent.trim() ) && parseFloat( getComputedStyle( e ).fontSize ) < 14 ).length;
	return { sidewaysScroll: over, smallTargets: [ ...new Set( small ) ].slice( 0, 30 ), textUnder14px: tiny };
} )()
```

At 375px, every screen must show `sidewaysScroll: false`, `smallTargets: []` (inline links inside running text are allowed), and `textUnder14px: 0`, except the muted meta lines (`.fs-dash__when`, `.fs-hist__when`, `.fs-badge`, `.fs-pill`), which may stay at 13px.

- [ ] **Step 2: Fix**

For each failing element, add a rule to the right media block. Common fixes:

```css
@media (pointer: coarse), (max-width: 760px) {
	.fs-btn--small, .fs-btn--tiny, .fs-input--small, .fs-seg, .fs-tab, .fs-nav__item, .fs-user, .fs-back, .fs-link { min-height: 44px; }
	.fs-btn--tiny { min-width: 44px; }
}
@media (max-width: 760px) {
	.fs-editor { grid-template-columns: 1fr; }
	.fs-ed-head { position: sticky; top: 0; z-index: 5; background: var(--fs-bg); padding-block: .5rem; }
	.fs-ed-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
	.fs-filters { display: grid; grid-template-columns: 1fr; }
	.fs-modal { width: calc(100vw - 32px); max-height: calc(100dvh - 32px); overflow: auto; }
	.fs-page-head { flex-wrap: wrap; }
}
```

Keep only the rules the measurement shows are needed. If a rule already exists, change it instead of adding a duplicate.

- [ ] **Step 3: Re-measure** every screen at 375 and 768. All three results must be clean. Take one screenshot each of the Dashboard, the product editor and History at 375px for the report.

- [ ] **Step 4: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/app/studio.css
git commit -m "Studio UI: phone pass (no sideways scroll, 44px targets, readable text on every screen)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Verification

**Files:** none (report only)

- [ ] **Step 1:** `bash tests/studio/run.sh` → all pass (286), with no PHP warnings or notices.
- [ ] **Step 2:** `docker exec fisha-wordpress-1 apachectl graceful`, then `bash tests/studio/baseline.sh > /tmp/p4-final.txt; diff tests/studio/baseline-phase3-before.txt /tmp/p4-final.txt` → no output.
- [ ] **Step 3: Browser end to end** (owner's Chrome, desktop and 375px):
  - Dashboard counts match the Products list filters.
  - Restore a price on a test product and publish it.
  - Bring back a trashed test product.
  - Duplicate a product with sizes and a category.
  - Change the password and change it back.
  - Make new backup codes.
  - Delete or trash every test item afterwards.
- [ ] **Step 4:** Leftovers: `wp term list product_cat --fields=slug | grep zz-test` and `wp post list --post_type=product --s=zz-test --post_status=any --format=ids` → nothing new since the start of this phase.
