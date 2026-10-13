# Fisha Studio — Phase 2 (Sizes, Stock per Size, Inventory, Full New-Product Flow) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The owner can create and edit every product from the Studio, including products with sizes or options: choose the options (Size: S, M, L…), create the sizes, set price, sale price, stock, SKU, photo and on/off per size, see it in the live preview and publish it. Plus an Inventory screen for fast stock updates of every product and size, an Options screen to manage the shop-wide values (S, M, L, XL…), and a "New product" button that opens the full editor straight away.

**Architecture:** Builds on phase 1 (`wp-content/mu-plugins/fisha-design/studio/`). A variable product's draft uses flat keys next to the normal product fields: `attributes` (the product's options) and `var:{variationId}:{field}` for each size. Because they are ordinary draft keys, the existing draft, "seen" conflict check, preview overlay, validation and transactional publish work per size with small extensions. Sizes added to a live product are created immediately as **private** WooCommerce variations marked `_fisha_studio_pending` (customers cannot see them); the draft switches them on, Publish makes them live, Discard deletes them (spec §6.1).

**Tech Stack:** WordPress 7.1, WooCommerce 11.1, PHP 8.3, MariaDB 11 (InnoDB), vanilla ES modules (no build), WP-CLI tests in Docker.

**Spec:** `docs/superpowers/specs/2026-10-12-fisha-studio-design.md` (§5.2 Variations tab, §5.4 Attributes, §5.5 Inventory, §6.1 variations in drafts). Owner direction on 2026-10-13 overrides the spec where they differ: "New product" opens the full editor (no name-only popup); the **SEO** and **Fisha design** tabs are removed from the editor (the server keeps filling those fields with defaults); categories editing follows in the next plan.

**Phase 1 code this plan builds on (exact names):** `fisha_studio_product_fields()`, `fisha_studio_clean( $def, $v )`, `fisha_studio_product_read( WC_Product )`, `fisha_studio_sanitize( $schema, $input )`, `fisha_studio_product_apply( WC_Product, $values )`, `fisha_studio_product_validate( $values, WC_Product )`, `fisha_studio_same()`, `fisha_studio_drop_default_cat()`; drafts `FISHA_STUDIO_DRAFT_META`, `fisha_studio_is_new()`, `fisha_studio_draft_get()`, `fisha_studio_draft_save( $p, $values, $seen )`, `fisha_studio_draft_discard( $id )`, `fisha_studio_conflicts( $p, $draft )`, `fisha_studio_target_status()`, `fisha_studio_product_create( $name )`, `fisha_studio_clean_seen( $seen )`, `fisha_studio_product_edit( $p, $values, $seen )`; publish `fisha_studio_publish_product( $id, $resolutions, $change_url )`, `fisha_studio_purge_product()`; preview `fisha_studio_preview_install_product( $id, $fields )`, `fisha_studio_preview_add( $hook, $fn, $priority = 99, $args = 2 )`, `fisha_studio_effective_price( $v )`, `fisha_studio_effective_stock_status( $v )`; audit `fisha_studio_log( $type, $id, $action, $before, $after )`, `fisha_studio_log_error()`; REST `fisha_studio_permission( $cap )`, `fisha_studio_body()`, `fisha_studio_rest_product()`, `fisha_studio_product_payload()`, `fisha_studio_product_row()`, lookups `fisha_studio_rest_lookups()`; JS `h, toast, modal, confirmDialog, debounce` (ui.js), `api, config` (api.js), widgets in fields.js, `productEditor`, `productsList`, router `routes` array in app.js. Tests: `fs_*` helpers in `tests/studio/helpers.php`, `bash tests/studio/run.sh [filter]` (85 passing at the start).

## Global Constraints

- All code in `wp-content/mu-plugins/fisha-design/studio/` and `tests/studio/`. Same file conventions as phase 1 (doc comment + `if ( ! defined( 'ABSPATH' ) ) exit;`, WordPress style, prefix `fisha_studio_`, REST namespace `fisha-studio/v1`).
- Writes only through WooCommerce/WordPress APIs (variations via `WC_Product_Variation`, options via `WC_Product_Attribute`, global options via `wc_create_attribute` / `wp_insert_term`).
- A size that is already live is never deleted by the Studio; it can only be switched off (status `private`). Only sizes that never went live (pending, or on a never-published product) can be deleted.
- Existing variation attribute values and existing products' option data are never rewritten unless the owner edits them (product 21 has legacy local options with quoted values like `"White"`; they must round-trip unchanged).
- Every stock/price write that publishes at once (quick edits) compares against the value the owner saw (`seen`) and refuses with 409 when it moved (phase 1 rule C1).
- Owner-facing copy: plain English, no em/en dashes. Touch targets ≥ 44px on phones (reuse the phase 1 CSS classes; new small controls join the `@media (pointer: coarse), (max-width: 760px)` block).
- Never push; never touch `main`. Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **An order lowers a size's stock while the owner edits that size** → Publish asks which value to keep, per size, labelled with the size ("M: Stock"). Pinned by `sizes publish: a size's stock conflict is asked about` (Task 3).
2. **The owner removes a value (e.g. XL) from Size while the XL size is still switched on** → Publish refuses with a message on that size ("Switch it off or add the value back"); nothing is written. Pinned by `sizes publish: a size whose value was removed must be switched off` (Task 3).
3. **Legacy product 21 (local options "Size"/"Color" with quoted values)** → opening, editing an unrelated field and publishing leaves its options and sizes byte-for-byte the same. Pinned by `variations: legacy options of product 21 survive a round trip` (Task 1) and `sizes publish: editing another field leaves options untouched` (Task 3).
4. **Too many combinations (e.g. 8 colours × 7 sizes)** → a clear refusal naming the number, nothing created. Pinned by `sizes: too many combinations and no size options are refused` (Task 2).
5. **The owner adds sizes to a live product, then discards** → shoppers never saw the new sizes and they are deleted. Pinned by `sizes: a new size on a live product stays hidden until publish, discard deletes it` (Task 2) and `sizes preview: a new size shows only in the preview` (Task 5).

---

## File map

| Path | Change |
|---|---|
| `studio/studio-variations.php` | **new**: size field schema, option cleaning/reading/writing, flat keys, values for all keys, sanitize input, apply all, labels, options catalog, create/generate/remove sizes, pending cleanup, type switch, variable validation, size quick edit |
| `studio/studio.php` | require `studio-variations.php` after `studio-drafts.php` |
| `studio/studio-drafts.php` | values for all keys, size-aware labels/seen/discard, `product_create( $name, $type )`, apply-all for new products |
| `studio/studio-publish.php` | publish variable products (validation, sizes, pending cleared, sync, rollback caches) |
| `studio/studio-preview.php` | per-size overlay, price range, sizes offered, parent stock status, options |
| `studio/api/api-products.php` | payload/row/create/draft for variable, size routes, type route |
| `studio/api/api-lookups.php` | `attributes` catalog in lookups |
| `studio/api/api-attributes.php` | **new**: Options screen REST |
| `studio/api/api-inventory.php` | **new**: inventory list + size quick edit |
| `studio/api/api.php` | require the two new files |
| `studio/app/js/views/product-variations.js` | **new**: Sizes & options tab |
| `studio/app/js/views/product-editor.js` | sizes tab, tabs without SEO/Fisha design, type switch, blank-draft cleanup |
| `studio/app/js/views/products-list.js` | "New product" opens the editor, size rows |
| `studio/app/js/views/inventory.js` | **new** |
| `studio/app/js/views/options.js` | **new** (global options screen) |
| `studio/app/js/app.js` | routes + nav |
| `studio/app/studio.css` | styles for the new views |
| `tests/studio/helpers.php` | `fs_variable`, `fs_var_id`, `fs_delete_product`, `fs_ready_variable` |
| `tests/studio/test-variations.php`, `test-sizes.php`, `test-sizes-publish.php`, `test-sizes-quick.php`, `test-sizes-preview.php`, `test-api-sizes.php`, `test-api-attributes.php`, `test-api-inventory.php` | **new** tests |
| `tests/studio/test-publish.php`, `test-api-products.php` | the two "variable products are refused/read-only" tests switch to a grouped product |

---

### Task 1: Size schema, options and flat keys

**Files:**
- Create: `studio/studio-variations.php`
- Modify: `studio/studio.php` (require after the `studio-drafts.php` line), `tests/studio/helpers.php` (append helpers)
- Test: `tests/studio/test-variations.php`

**Interfaces:**
- Produces: `FISHA_STUDIO_PENDING_META` (`'_fisha_studio_pending'`); `fisha_studio_variation_fields(): array` (keys `enabled, regular_price, sale_price, sku, manage_stock, stock_quantity, stock_status, image_id`, same definition shape as product fields); `fisha_studio_var_key( int $vid, string $field ): string`; `fisha_studio_parse_var_key( string $key ): ?array{0:int,1:string}`; `fisha_studio_variation_ids( WC_Product $p ): int[]` (publish + private children, menu order); `fisha_studio_attributes_read( WC_Product $p ): array`; `fisha_studio_clean_attributes( mixed $v ): array{0:array,1:?string}`; `fisha_studio_attribute_objects( array $list ): WC_Product_Attribute[]` (keyed by `sanitize_title( name )`); `fisha_studio_attributes_apply( WC_Product $p, array $list ): void`; `fisha_studio_product_values( WC_Product $p ): array` (product fields + for variable: `attributes` + every `var:` key); `fisha_studio_sanitize_product_input( WC_Product $p, array $input ): array{0:array,1:array}`; `fisha_studio_product_apply_all( WC_Product $p, array $values ): void` (parent object not saved, sizes saved); `fisha_studio_variation_label( WC_Product $v ): string`; `fisha_studio_value_label( WC_Product $p, string $key ): string`; `fisha_studio_attributes_catalog(): array` (`[{id, name:'pa_size', label, terms:[{id,name,slug}]}]`).
- Option shape (the value of the `attributes` key, a list): `{ name: 'pa_size' | 'Color', label: string, taxonomy: bool, options: int[] (term ids, sorted ascending) | string[], variation: bool, visible: bool }`.
- Test helpers: `fs_variable( array $sizes = ['s','m','l'], string $status = 'publish' ): WC_Product_Variable` (pa_size option, one published size per slug at price 100, stock not counted), `fs_var_id( WC_Product $p, string $slug ): int`, `fs_delete_product( int $id ): void`, `fs_ready_variable( array $sizes = ['s','m','l'] ): WC_Product_Variable` (adds a photo and a category so it can be published).

- [ ] **Step 0: Record product 21 before any change** (Task 12 compares against it)

Run and paste the output into the Task 1 report under "Product 21 before":
```bash
MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp eval '$p = wc_get_product( 21 ); if ( ! $p ) { echo "no product 21\n"; return; } foreach ( $p->get_attributes() as $k => $a ) echo $k, " ", wp_json_encode( $a->get_data() ), "\n"; foreach ( $p->get_children() as $v ) echo $v, " ", wp_json_encode( wc_get_product( $v )->get_attributes() ), " ", get_post_status( $v ), "\n";'
```

- [ ] **Step 1: Add the test helpers**

Append to `tests/studio/helpers.php`:
```php
/* ---------- products with sizes ---------- */

/** Deletes a product, its sizes and their audit rows (fixture cleanup). */
function fs_delete_product( int $id ): void {
	global $wpdb;
	$log = $wpdb->prefix . 'fisha_studio_log';
	foreach ( get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $id, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $vid ) {
		$wpdb->delete( $log, array( 'item_type' => 'variation', 'item_id' => $vid ) );
		wp_delete_post( (int) $vid, true );
	}
	$wpdb->delete( $log, array( 'item_type' => 'product', 'item_id' => $id ) );
	wp_delete_post( $id, true );
}

/** A variable product with the pa_size option and one published size per slug (price 100, stock not counted). */
function fs_variable( array $sizes = array( 's', 'm', 'l' ), string $status = 'publish' ): WC_Product_Variable {
	$term_ids = array();
	foreach ( $sizes as $slug ) {
		$t = get_term_by( 'slug', $slug, 'pa_size' );
		if ( ! $t ) throw new Fs_Fail( "pa_size value $slug is missing" );
		$term_ids[] = (int) $t->term_id;
	}
	$p = new WC_Product_Variable();
	$p->set_name( 'zz-test variable ' . strtolower( wp_generate_password( 5, false ) ) );
	$p->set_status( $status );
	$p->set_short_description( 'Test short description.' );
	$a = new WC_Product_Attribute();
	$a->set_id( wc_attribute_taxonomy_id_by_name( 'pa_size' ) );
	$a->set_name( 'pa_size' );
	$a->set_options( $term_ids );
	$a->set_visible( true );
	$a->set_variation( true );
	$p->set_attributes( array( $a ) );
	$id = $p->save();
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	foreach ( $sizes as $i => $slug ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $id );
		$v->set_attributes( array( 'pa_size' => $slug ) );
		$v->set_regular_price( '100' );
		$v->set_status( 'publish' );
		$v->set_menu_order( $i );
		$v->save();
	}
	WC_Product_Variable::sync( $id );
	wc_delete_product_transients( $id );
	return wc_get_product( $id );
}

function fs_var_id( WC_Product $p, string $slug ): int {
	$ids = get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $p->get_id(), 'post_status' => array( 'publish', 'private' ), 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => 'attribute_pa_size', 'meta_value' => $slug ) );
	if ( ! $ids ) throw new Fs_Fail( "no $slug size" );
	return (int) $ids[0];
}

/** fs_variable() plus a photo and a category, so it passes publish validation. */
function fs_ready_variable( array $sizes = array( 's', 'm', 'l' ) ): WC_Product_Variable {
	$p = fs_variable( $sizes );
	$p->set_image_id( fs_image() );
	$p->set_category_ids( array( fs_term( 'sz-' . wp_rand() )->term_id ) );
	$p->save();
	return wc_get_product( $p->get_id() );
}
```

- [ ] **Step 2: Write the failing tests**

`tests/studio/test-variations.php`:
```php
<?php
function fs_size_term( string $slug ): int { return (int) get_term_by( 'slug', $slug, 'pa_size' )->term_id; }

fs_test( 'variations: keys', function () {
	fs_eq( 'var:12:regular_price', fisha_studio_var_key( 12, 'regular_price' ) );
	fs_eq( array( 12, 'stock_quantity' ), fisha_studio_parse_var_key( 'var:12:stock_quantity' ) );
	fs_eq( null, fisha_studio_parse_var_key( 'var:12:nope' ) );
	fs_eq( null, fisha_studio_parse_var_key( 'regular_price' ) );
} );

fs_test( 'variations: values include options and every size', function () {
	$p = fs_variable();
	$s = fs_var_id( $p, 's' );
	$v = fisha_studio_product_values( $p );
	fs_eq( 'pa_size', $v['attributes'][0]['name'] );
	fs_true( $v['attributes'][0]['variation'], 'used for sizes' );
	fs_eq( 3, count( $v['attributes'][0]['options'] ) );
	fs_eq( '100', $v[ "var:$s:regular_price" ] );
	fs_eq( true, $v[ "var:$s:enabled" ] );
	fs_eq( 3 * count( fisha_studio_variation_fields() ), count( preg_grep( '/^var:/', array_keys( $v ) ) ) );
	fs_false( isset( fisha_studio_product_values( fs_product() )['attributes'] ), 'simple products have no options key' );
} );

fs_test( 'variations: options are cleaned', function () {
	$m = fs_size_term( 'm' );
	$s = fs_size_term( 's' );
	list( $c, $e ) = fisha_studio_clean_attributes( array( array( 'name' => 'pa_size', 'taxonomy' => true, 'options' => array( $m, (string) $s, $m ), 'variation' => 1, 'visible' => 1 ) ) );
	fs_eq( null, $e );
	$want = array( $m, $s );
	sort( $want );
	fs_eq( $want, $c[0]['options'], 'unique, sorted ids' );
	fs_eq( wc_attribute_label( 'pa_size' ), $c[0]['label'] );
	fs_true( fisha_studio_clean_attributes( array( array( 'name' => 'pa_size', 'taxonomy' => true, 'options' => array( 99999999 ) ) ) )[1], 'unknown value' );
	fs_true( fisha_studio_clean_attributes( array( array( 'name' => 'pa_zz_nope', 'taxonomy' => true, 'options' => array() ) ) )[1], 'unknown option' );
	fs_true( fisha_studio_clean_attributes( array( array( 'name' => 'Color', 'options' => array( 'a' ) ), array( 'name' => 'color', 'options' => array( 'b' ) ) ) )[1], 'listed twice' );
	list( $c2 ) = fisha_studio_clean_attributes( array( array( 'name' => 'Color', 'options' => array( '"White"', ' Black ', '', '<b>Pink</b>' ), 'variation' => true ) ) );
	fs_eq( array( '"White"', 'Black', 'Pink' ), $c2[0]['options'], 'trimmed, tags stripped, quotes kept' );
	fs_true( fisha_studio_clean_attributes( 'x' )[1], 'not a list' );
} );

fs_test( 'variations: legacy options of product 21 survive a round trip', function () {
	$p = wc_get_product( 21 );
	if ( ! $p || ! $p->is_type( 'variable' ) ) return; // only on a copy of the shop database
	$read = fisha_studio_attributes_read( $p );
	fs_eq( $read, fisha_studio_clean_attributes( $read )[0] );
} );

fs_test( "variations: sanitize keeps only this product's sizes", function () {
	$p     = fs_variable();
	$other = fs_variable( array( 'm' ) );
	$s     = fs_var_id( $p, 's' );
	$om    = fs_var_id( $other, 'm' );
	list( $vals, $errs ) = fisha_studio_sanitize_product_input( $p, array( "var:$s:regular_price" => '₪120', "var:$om:regular_price" => '5', "var:$s:sale_price" => 'abc', 'name' => 'X', "var:$s:nope" => 1 ) );
	fs_eq( '120', $vals[ "var:$s:regular_price" ] );
	fs_false( isset( $vals[ "var:$om:regular_price" ] ), "another product's size is ignored" );
	fs_true( isset( $errs[ "var:$s:sale_price" ] ), 'bad price reported on its key' );
	fs_eq( 'X', $vals['name'] );
} );

fs_test( 'variations: apply writes sizes and options', function () {
	$p     = fs_variable();
	$s     = fs_var_id( $p, 's' );
	$l     = fs_var_id( $p, 'l' );
	$attrs = fisha_studio_product_values( $p )['attributes'];
	$attrs[0]['options'] = array_values( array_diff( $attrs[0]['options'], array( fs_size_term( 'l' ) ) ) );
	fisha_studio_product_apply_all( $p, array( 'attributes' => $attrs, "var:$s:regular_price" => '130', "var:$s:sku" => 'ZZ-TEST-S-' . $s, "var:$l:enabled" => false, "var:$s:manage_stock" => true, "var:$s:stock_quantity" => 4 ) );
	$p->save();
	$after = fisha_studio_product_values( wc_get_product( $p->get_id() ) );
	fs_eq( '130', $after[ "var:$s:regular_price" ] );
	fs_eq( 4, $after[ "var:$s:stock_quantity" ] );
	fs_eq( false, $after[ "var:$l:enabled" ] );
	fs_eq( 2, count( $after['attributes'][0]['options'] ) );
} );

fs_test( 'variations: labels name the size', function () {
	$p = fs_variable();
	$s = fs_var_id( $p, 's' );
	fs_eq( get_term_by( 'slug', 's', 'pa_size' )->name . ': Stock', fisha_studio_value_label( $p, "var:$s:stock_quantity" ) );
	fs_eq( 'Price', fisha_studio_value_label( $p, 'regular_price' ) );
	fs_eq( 'Sizes and options', fisha_studio_value_label( $p, 'attributes' ) );
} );

fs_test( 'variations: catalog lists the shop-wide options with their values', function () {
	$size = current( array_filter( fisha_studio_attributes_catalog(), function ( $a ) { return 'pa_size' === $a['name']; } ) );
	fs_true( $size, 'pa_size listed' );
	fs_eq( array(), array_diff( array( 's', 'm', 'l', 'xl' ), wp_list_pluck( $size['terms'], 'slug' ) ) );
} );
```

- [ ] **Step 3: Run to verify failure**

Run: `bash tests/studio/run.sh variations`
Expected: FAIL, undefined `fisha_studio_var_key`.

- [ ] **Step 4: Implement**

`studio/studio-variations.php`:
```php
<?php
/**
 * Fisha Studio sizes and options (variable products). A variable product's draft uses flat keys next to the normal
 * product fields: "attributes" (the options shoppers choose, e.g. Size: S, M, L) and "var:{id}:{field}" for each
 * size's own on/off, price, sale price, SKU, stock and photo. The same draft, conflict, preview and publish code then
 * works per size.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_PENDING_META', '_fisha_studio_pending' );

function fisha_studio_variation_fields(): array {
	static $fields = null;
	if ( null !== $fields ) return $fields;
	$prop = function ( string $prop, string $type, array $extra = array() ): array {
		return array_merge( array(
			'type' => $type,
			'get'  => function ( WC_Product $v ) use ( $prop ) { return $v->{"get_$prop"}( 'edit' ); },
			'set'  => function ( WC_Product $v, $x ) use ( $prop ) { $v->{"set_$prop"}( $x ); },
		), $extra );
	};
	$fields = array(
		'enabled'        => array(
			'type'  => 'bool',
			'label' => 'On sale',
			'get'   => function ( WC_Product $v ) { return 'publish' === $v->get_status( 'edit' ); },
			'set'   => function ( WC_Product $v, $x ) { $v->set_status( $x ? 'publish' : 'private' ); },
		),
		'regular_price'  => $prop( 'regular_price', 'money', array( 'label' => 'Price' ) ),
		'sale_price'     => $prop( 'sale_price', 'money', array( 'label' => 'Sale price' ) ),
		'sku'            => $prop( 'sku', 'text', array( 'label' => 'SKU', 'max' => 64 ) ),
		'manage_stock'   => $prop( 'manage_stock', 'bool', array( 'label' => 'Count stock' ) ),
		'stock_quantity' => $prop( 'stock_quantity', 'int', array( 'label' => 'Stock', 'nullable' => true, 'min' => 0 ) ),
		'stock_status'   => $prop( 'stock_status', 'enum', array( 'label' => 'Stock status', 'options' => array( 'instock', 'outofstock', 'onbackorder' ) ) ),
		'image_id'       => $prop( 'image_id', 'attachment', array( 'label' => 'Photo' ) ),
	);
	return $fields;
}

function fisha_studio_var_key( int $vid, string $field ): string {
	return "var:$vid:$field";
}

/** [variation id, field] for a "var:{id}:{field}" key, or null. */
function fisha_studio_parse_var_key( string $key ): ?array {
	if ( ! preg_match( '/^var:(\d+):([a-z_]+)$/', $key, $m ) ) return null;
	return isset( fisha_studio_variation_fields()[ $m[2] ] ) ? array( (int) $m[1], $m[2] ) : null;
}

/** Every size of a variable product (switched on or off), in shop order. */
function fisha_studio_variation_ids( WC_Product $p ): array {
	if ( ! $p->is_type( 'variable' ) ) return array();
	return array_map( 'intval', get_posts( array(
		'post_type' => 'product_variation', 'post_parent' => $p->get_id(), 'post_status' => array( 'publish', 'private' ),
		'numberposts' => -1, 'fields' => 'ids', 'orderby' => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
	) ) );
}

/* ---------- options ---------- */

function fisha_studio_attributes_read( WC_Product $p ): array {
	$out = array();
	foreach ( $p->get_attributes( 'edit' ) as $a ) {
		if ( ! $a instanceof WC_Product_Attribute ) continue;
		$tax  = $a->is_taxonomy();
		$opts = $tax ? array_map( 'intval', $a->get_options() ) : array_map( 'strval', $a->get_options() );
		if ( $tax ) sort( $opts );
		$out[] = array(
			'name'      => $a->get_name(),
			'label'     => $tax ? wc_attribute_label( $a->get_name() ) : $a->get_name(),
			'taxonomy'  => $tax,
			'options'   => array_values( $opts ),
			'variation' => (bool) $a->get_variation(),
			'visible'   => (bool) $a->get_visible(),
		);
	}
	return $out;
}

/** Cleans an "attributes" list sent by the Studio. Returns [list, error message or null]. */
function fisha_studio_clean_attributes( $v ): array {
	if ( ! is_array( $v ) ) return array( array(), 'The options could not be read. Please reload the page.' );
	$out   = array();
	$names = array();
	foreach ( array_values( $v ) as $a ) {
		if ( ! is_array( $a ) ) return array( $out, 'The options could not be read. Please reload the page.' );
		$tax  = ! empty( $a['taxonomy'] );
		$name = $tax ? wc_sanitize_taxonomy_name( (string) ( $a['name'] ?? '' ) ) : trim( sanitize_text_field( (string) ( $a['name'] ?? '' ) ) );
		if ( '' === $name || ( $tax && ( 0 !== strpos( $name, 'pa_' ) || ! taxonomy_exists( $name ) ) ) ) {
			return array( $out, 'An option no longer exists. Please reload the page.' );
		}
		if ( ! $tax && mb_strlen( $name ) > 40 ) return array( $out, 'Option names can be at most 40 characters.' );
		$key = strtolower( $tax ? $name : sanitize_title( $name ) );
		if ( isset( $names[ $key ] ) ) return array( $out, sprintf( '"%s" is listed twice.', $tax ? wc_attribute_label( $name ) : $name ) );
		$names[ $key ] = true;
		if ( $tax ) {
			$opts = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $a['options'] ?? array() ) ) ) ) );
			foreach ( $opts as $tid ) {
				$t = get_term( $tid, $name );
				if ( ! $t || is_wp_error( $t ) ) return array( $out, sprintf( 'A value of %s no longer exists. Please reload the page.', wc_attribute_label( $name ) ) );
			}
			sort( $opts );
		} else {
			$opts = array();
			foreach ( (array) ( $a['options'] ?? array() ) as $o ) {
				$o = trim( sanitize_text_field( (string) $o ) );
				if ( '' !== $o && ! in_array( $o, $opts, true ) ) $opts[] = $o;
			}
		}
		$out[] = array(
			'name'      => $name,
			'label'     => $tax ? wc_attribute_label( $name ) : $name,
			'taxonomy'  => $tax,
			'options'   => $opts,
			'variation' => ! empty( $a['variation'] ),
			'visible'   => ! empty( $a['visible'] ),
		);
	}
	return array( $out, null );
}

/** WooCommerce attribute objects for an options list, keyed the way WooCommerce keys them. */
function fisha_studio_attribute_objects( array $list ): array {
	$objs = array();
	foreach ( array_values( $list ) as $i => $a ) {
		$o = new WC_Product_Attribute();
		if ( $a['taxonomy'] ) $o->set_id( wc_attribute_taxonomy_id_by_name( $a['name'] ) );
		$o->set_name( $a['name'] );
		$o->set_options( $a['options'] );
		$o->set_position( $i );
		$o->set_visible( (bool) $a['visible'] );
		$o->set_variation( (bool) $a['variation'] );
		$objs[ sanitize_title( $a['name'] ) ] = $o;
	}
	return $objs;
}

function fisha_studio_attributes_apply( WC_Product $p, array $list ): void {
	$p->set_attributes( fisha_studio_attribute_objects( $list ) );
}

/** The shop-wide options (Size, Shoe size…) with their values in shop order. */
function fisha_studio_attributes_catalog(): array {
	$out = array();
	foreach ( wc_get_attribute_taxonomies() as $a ) {
		$tax = wc_attribute_taxonomy_name( $a->attribute_name );
		if ( ! taxonomy_exists( $tax ) ) continue;
		$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'menu_order' ) );
		$list  = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) $list[] = array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
		$out[] = array( 'id' => (int) $a->attribute_id, 'name' => $tax, 'label' => $a->attribute_label, 'terms' => $list );
	}
	return $out;
}

/* ---------- values across the product and its sizes ---------- */

/** Every value the Studio edits: the normal fields, plus for a variable product its options and every size key. */
function fisha_studio_product_values( WC_Product $p ): array {
	$out = fisha_studio_product_read( $p );
	if ( ! $p->is_type( 'variable' ) ) return $out;
	$out['attributes'] = fisha_studio_attributes_read( $p );
	$defs = fisha_studio_variation_fields();
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v ) continue;
		foreach ( $defs as $f => $def ) $out[ fisha_studio_var_key( $vid, $f ) ] = fisha_studio_clean( $def, call_user_func( $def['get'], $v ) )[0];
	}
	return $out;
}

/** Cleans an edit: normal fields, and for a variable product "attributes" and this product's own "var:" keys. [values, errors] */
function fisha_studio_sanitize_product_input( WC_Product $p, array $input ): array {
	list( $values, $errors ) = fisha_studio_sanitize( fisha_studio_product_fields(), $input );
	if ( ! $p->is_type( 'variable' ) ) return array( $values, $errors );
	$kids = array_flip( fisha_studio_variation_ids( $p ) );
	$defs = fisha_studio_variation_fields();
	foreach ( $input as $k => $v ) {
		$k = (string) $k;
		if ( 'attributes' === $k ) {
			list( $clean, $err ) = fisha_studio_clean_attributes( $v );
			$values[ $k ] = $clean;
			if ( $err ) $errors[ $k ] = $err;
			continue;
		}
		$parsed = fisha_studio_parse_var_key( $k );
		if ( ! $parsed || ! isset( $kids[ $parsed[0] ] ) ) continue;
		list( $clean, $err ) = fisha_studio_clean( $defs[ $parsed[1] ], $v );
		$values[ $k ] = $clean;
		if ( $err ) $errors[ $k ] = $err;
	}
	return array( $values, $errors );
}

/** Sets values on the product object (not saved) and on its sizes (each size saved). */
function fisha_studio_product_apply_all( WC_Product $p, array $values ): void {
	$plain = array();
	$sizes = array();
	foreach ( $values as $k => $v ) {
		if ( 'attributes' === $k ) {
			fisha_studio_attributes_apply( $p, (array) $v );
			continue;
		}
		$parsed = fisha_studio_parse_var_key( (string) $k );
		if ( $parsed ) {
			$sizes[ $parsed[0] ][ $parsed[1] ] = $v;
			continue;
		}
		$plain[ $k ] = $v;
	}
	fisha_studio_product_apply( $p, $plain );
	$defs = fisha_studio_variation_fields();
	foreach ( $sizes as $vid => $fields ) {
		$var = wc_get_product( $vid );
		if ( ! $var || $var->get_parent_id() !== $p->get_id() ) continue;
		foreach ( $fields as $f => $x ) call_user_func( $defs[ $f ]['set'], $var, $x );
		$var->save();
	}
}

/* ---------- labels ---------- */

function fisha_studio_variation_label( WC_Product $v ): string {
	$label = wc_get_formatted_variation( $v, true, false );
	return '' !== $label ? $label : '#' . $v->get_id();
}

/** A plain-English name for any edited key ("M: Stock", "Price"). */
function fisha_studio_value_label( WC_Product $p, string $key ): string {
	if ( 'attributes' === $key ) return 'Sizes and options';
	$parsed = fisha_studio_parse_var_key( $key );
	if ( ! $parsed ) return fisha_studio_product_fields()[ $key ]['label'] ?? $key;
	$v = wc_get_product( $parsed[0] );
	return ( $v ? fisha_studio_variation_label( $v ) . ': ' : '' ) . fisha_studio_variation_fields()[ $parsed[1] ]['label'];
}
```

In `studio/studio.php`, directly after `require_once FISHA_STUDIO_DIR . 'studio-drafts.php';` add:
```php
require_once FISHA_STUDIO_DIR . 'studio-variations.php';
```

- [ ] **Step 5: Run to verify pass**

Run: `bash tests/studio/run.sh variations` → `8 passed`. Then `bash tests/studio/run.sh` → all pass (85 + 8).

- [ ] **Step 6: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio sizes: size schema, options, flat draft keys

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Drafts with sizes, creating and removing sizes, product type

**Files:**
- Modify: `studio/studio-drafts.php`, `studio/studio-variations.php` (append)
- Test: `tests/studio/test-sizes.php`

**Interfaces:**
- Consumes: Task 1.
- Changes: `fisha_studio_draft_save()` and `fisha_studio_conflicts()` work on `fisha_studio_product_values()` (all keys); conflict labels come from `fisha_studio_value_label()`; `fisha_studio_draft_discard( $id )` also deletes pending sizes; `fisha_studio_clean_seen( $seen, ?WC_Product $p = null )` cleans size keys when `$p` is given; `fisha_studio_product_create( string $name, string $type = 'simple' ): WC_Product` (`'variable'` → `WC_Product_Variable`; empty name allowed); new products apply edits with `fisha_studio_product_apply_all()`.
- Produces: `fisha_studio_draft_put( int $id, array $draft ): array`; `fisha_studio_combo_key( array $attrs ): string`; `fisha_studio_default_size_price( WC_Product $p ): string`; `fisha_studio_variation_create( WC_Product $p, array $attrs ): WC_Product_Variation`; `fisha_studio_variation_generate( WC_Product $p, array $attributes ): int[]|WP_Error`; `fisha_studio_variation_remove( WC_Product $p, int $vid ): true|WP_Error`; `fisha_studio_variation_discard_pending( int $pid ): void`; `fisha_studio_set_product_type( WC_Product $p, string $type ): WC_Product|WP_Error` (only never-published products).

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-sizes.php`:
```php
<?php
fs_test( 'sizes: editing a size of a live product is a draft', function () {
	$p = fs_variable();
	$s = fs_var_id( $p, 's' );
	fisha_studio_product_edit( $p, array( "var:$s:regular_price" => '150' ) );
	fs_eq( '100', wc_get_product( $s )->get_regular_price() );
	fs_eq( '150', fisha_studio_draft_get( $p->get_id() )['fields'][ "var:$s:regular_price" ] );
} );

fs_test( 'sizes: a new size on a live product stays hidden until publish, discard deletes it', function () {
	$p = fs_variable( array( 's', 'm' ) );
	$v = fisha_studio_variation_create( $p, array( 'pa_size' => 'l' ) );
	fs_eq( 'private', get_post_status( $v->get_id() ) );
	fs_eq( '1', get_post_meta( $v->get_id(), FISHA_STUDIO_PENDING_META, true ) );
	fs_eq( '100', $v->get_regular_price(), 'price copied from the other sizes' );
	fs_eq( true, fisha_studio_draft_get( $p->get_id() )['fields'][ 'var:' . $v->get_id() . ':enabled' ] );
	fs_false( in_array( $v->get_id(), wc_get_product( $p->get_id() )->get_visible_children(), true ), 'shoppers do not see it' );
	fisha_studio_draft_discard( $p->get_id() );
	fs_eq( null, get_post( $v->get_id() ), 'deleted with the draft' );
} );

fs_test( 'sizes: create missing combinations', function () {
	$p     = fs_variable( array( 's', 'm', 'l' ) );
	$attrs = fisha_studio_product_values( $p )['attributes'];
	$attrs[0]['options'][] = (int) get_term_by( 'slug', 'xl', 'pa_size' )->term_id;
	$new = fisha_studio_variation_generate( $p, $attrs );
	fs_eq( 1, count( $new ) );
	fs_eq( array( 'pa_size' => 'xl' ), wc_get_product( $new[0] )->get_attributes() );
	fs_eq( array(), fisha_studio_variation_generate( wc_get_product( $p->get_id() ), $attrs ), 'nothing missing the second time' );
} );

fs_test( 'sizes: too many combinations and no size options are refused', function () {
	$p    = fs_variable( array( 's' ) );
	$many = array(
		array( 'name' => 'A', 'taxonomy' => false, 'options' => array_map( 'strval', range( 1, 8 ) ), 'variation' => true, 'visible' => true ),
		array( 'name' => 'B', 'taxonomy' => false, 'options' => array_map( 'strval', range( 1, 7 ) ), 'variation' => true, 'visible' => true ),
	);
	$r = fisha_studio_variation_generate( $p, $many );
	fs_true( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), '56' ), 'names the number' );
	fs_eq( 1, count( fisha_studio_variation_ids( wc_get_product( $p->get_id() ) ) ), 'nothing created' );
	fs_true( is_wp_error( fisha_studio_variation_generate( $p, array() ) ), 'no options' );
} );

fs_test( 'sizes: only never-published sizes can be deleted', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_eq( 'fisha_studio_invalid', fisha_studio_variation_remove( $p, $s )->get_error_code() );
	$v = fisha_studio_variation_create( $p, array( 'pa_size' => 'm' ) );
	fs_true( true === fisha_studio_variation_remove( wc_get_product( $p->get_id() ), $v->get_id() ) );
	fs_eq( null, get_post( $v->get_id() ) );
	fs_false( isset( fisha_studio_draft_get( $p->get_id() )['fields'][ 'var:' . $v->get_id() . ':enabled' ] ), 'its draft keys are gone' );
} );

fs_test( 'sizes: a new product with sizes builds them directly', function () {
	$p = fisha_studio_product_create( 'zz-test New Hoodie', 'variable' );
	fs_on_cleanup( function () use ( $p ) { fs_delete_product( $p->get_id() ); } );
	fs_true( $p->is_type( 'variable' ) );
	$attrs = fisha_studio_clean_attributes( array( array( 'name' => 'pa_size', 'taxonomy' => true, 'options' => array( get_term_by( 'slug', 's', 'pa_size' )->term_id, get_term_by( 'slug', 'm', 'pa_size' )->term_id ), 'variation' => true, 'visible' => true ) ) )[0];
	fisha_studio_product_edit( $p, array( 'attributes' => $attrs ) );
	$fresh = wc_get_product( $p->get_id() );
	$new   = fisha_studio_variation_generate( $fresh, fisha_studio_product_values( $fresh )['attributes'] );
	fs_eq( 2, count( $new ) );
	fs_eq( 'publish', get_post_status( $new[0] ), 'on straight away (the product itself is still hidden)' );
	fs_eq( '', get_post_meta( $new[0], FISHA_STUDIO_PENDING_META, true ) );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'] );
} );

fs_test( 'sizes: a never-published product can switch between one item and sizes', function () {
	$p = fisha_studio_product_create( '' );
	fs_on_cleanup( function () use ( $p ) { fs_delete_product( $p->get_id() ); } );
	fs_eq( '', $p->get_name( 'edit' ), 'empty name allowed while drafting' );
	$v = fisha_studio_set_product_type( $p, 'variable' );
	fs_true( $v instanceof WC_Product_Variable );
	fisha_studio_variation_create( $v, array( 'pa_size' => 's' ) );
	$s = fisha_studio_set_product_type( wc_get_product( $p->get_id() ), 'simple' );
	fs_true( $s instanceof WC_Product_Simple );
	fs_eq( 0, count( get_children( array( 'post_parent' => $p->get_id(), 'post_type' => 'product_variation' ) ) ), 'its sizes are removed' );
	fs_eq( 'fisha_studio_invalid', fisha_studio_set_product_type( fs_product(), 'variable' )->get_error_code(), 'live products keep their type' );
} );

fs_test( 'sizes: conflicts name the size', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	$v = wc_get_product( $s );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 3 );
	$v->save();
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( "var:$s:stock_quantity" => 5 ), array( "var:$s:stock_quantity" => 3 ) );
	wc_update_product_stock( $s, 2, 'set' );
	$c = fisha_studio_conflicts( wc_get_product( $p->get_id() ), fisha_studio_draft_get( $p->get_id() ) );
	fs_eq( get_term_by( 'slug', 's', 'pa_size' )->name . ': Stock', $c[0]['label'] );
	fs_eq( 2, $c[0]['live'] );
} );

fs_test( 'sizes: seen values are cleaned for size keys', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_eq( array( "var:$s:regular_price" => '90' ), fisha_studio_clean_seen( array( "var:$s:regular_price" => '90.00', 'evil' => 1 ), $p ) );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh "sizes:"`
Expected: FAIL (undefined `fisha_studio_variation_create`; the first test fails because `draft_save` ignores `var:` keys).

- [ ] **Step 3: Change `studio/studio-drafts.php`**

1. In `fisha_studio_draft_save()` replace `$live = fisha_studio_product_read( $p );` with `$live = fisha_studio_product_values( $p );`, and replace its storing tail (from `if ( ! $d['fields'] ) {` to `return $d;`) with:
```php
	$d['user']    = get_current_user_id();
	$d['updated'] = time();
	return fisha_studio_draft_put( $id, $d );
```
2. Add after `fisha_studio_draft_get()`:
```php
/** Stores a draft (or removes it when it has no fields). */
function fisha_studio_draft_put( int $id, array $d ): array {
	if ( empty( $d['fields'] ) ) {
		delete_post_meta( $id, FISHA_STUDIO_DRAFT_META );
		return fisha_studio_draft_get( $id );
	}
	update_post_meta( $id, FISHA_STUDIO_DRAFT_META, wp_slash( $d ) );
	return $d;
}
```
3. Replace `fisha_studio_draft_discard()` with:
```php
function fisha_studio_draft_discard( int $id ): void {
	delete_post_meta( $id, FISHA_STUDIO_DRAFT_META );
	fisha_studio_variation_discard_pending( $id );
}
```
4. Replace `fisha_studio_conflicts()` with:
```php
function fisha_studio_conflicts( WC_Product $p, array $draft ): array {
	$live = fisha_studio_product_values( $p );
	$out  = array();
	foreach ( $draft['fields'] as $k => $mine ) {
		if ( ! array_key_exists( $k, $draft['base'] ) || ! array_key_exists( $k, $live ) ) continue;
		if ( fisha_studio_same( $draft['base'][ $k ], $live[ $k ] ) || fisha_studio_same( $mine, $live[ $k ] ) ) continue;
		$out[] = array( 'field' => $k, 'label' => fisha_studio_value_label( $p, (string) $k ), 'base' => $draft['base'][ $k ], 'live' => $live[ $k ], 'mine' => $mine );
	}
	return $out;
}
```
5. Replace `fisha_studio_product_create()` with:
```php
function fisha_studio_product_create( string $name, string $type = 'simple' ): WC_Product {
	$p = 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( 'draft' );
	$p->set_slug( fisha_studio_slug_from_name( $name ) );
	$p->set_catalog_visibility( 'visible' );
	$p->set_reviews_allowed( false );
	$p->save();
	return $p;
}
```
6. Replace `fisha_studio_clean_seen()` with:
```php
/** Cleans the "seen" values sent with an edit (size keys too when the product is given); bad or unknown keys are dropped. */
function fisha_studio_clean_seen( $seen, ?WC_Product $p = null ): array {
	if ( ! is_array( $seen ) ) return array();
	list( $values, $errors ) = $p ? fisha_studio_sanitize_product_input( $p, $seen ) : fisha_studio_sanitize( fisha_studio_product_fields(), $seen );
	return array_diff_key( $values, $errors );
}
```
7. In `fisha_studio_product_edit()` replace `fisha_studio_product_apply( $p, $values );` with `fisha_studio_product_apply_all( $p, $values );`.

- [ ] **Step 4: Append to `studio/studio-variations.php`**

```php
/* ---------- creating and removing sizes ---------- */

function fisha_studio_combo_key( array $attrs ): string {
	ksort( $attrs );
	return wp_json_encode( array_map( 'strval', $attrs ) );
}

/** The price most sizes of this product have, as the starting price of a new size. */
function fisha_studio_default_size_price( WC_Product $p ): string {
	$counts = array();
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$r = (string) get_post_meta( $vid, '_regular_price', true );
		if ( '' !== $r ) $counts[ $r ] = ( $counts[ $r ] ?? 0 ) + 1;
	}
	if ( ! $counts ) return '';
	arsort( $counts );
	return (string) array_key_first( $counts );
}

/**
 * Creates one size. $attrs maps option key (sanitize_title of the option name, e.g. "pa_size", "color") to the value
 * a size stores (a term slug, or the text). On a live product the size is created switched off and marked pending,
 * and the draft switches it on; on a never-published product it is switched on straight away.
 */
function fisha_studio_variation_create( WC_Product $p, array $attrs ): WC_Product_Variation {
	$is_new = fisha_studio_is_new( $p );
	$v      = new WC_Product_Variation();
	$v->set_parent_id( $p->get_id() );
	$v->set_attributes( array_map( 'strval', $attrs ) );
	$v->set_regular_price( fisha_studio_default_size_price( $p ) );
	$v->set_status( $is_new ? 'publish' : 'private' );
	$v->set_menu_order( count( fisha_studio_variation_ids( $p ) ) );
	if ( ! $is_new ) $v->update_meta_data( FISHA_STUDIO_PENDING_META, '1' );
	$v->save();
	if ( ! $is_new ) {
		$key = fisha_studio_var_key( $v->get_id(), 'enabled' );
		fisha_studio_draft_save( wc_get_product( $p->get_id() ), array( $key => true ), array( $key => false ) );
	}
	return $v;
}

/** Creates every missing combination of the options used for sizes (at most 50 at once). @return int[]|WP_Error new size ids */
function fisha_studio_variation_generate( WC_Product $p, array $attributes ) {
	$axes = array();
	foreach ( $attributes as $a ) {
		if ( empty( $a['variation'] ) ) continue;
		$vals = array();
		foreach ( (array) $a['options'] as $o ) {
			if ( $a['taxonomy'] ) {
				$t = get_term( (int) $o, $a['name'] );
				if ( $t && ! is_wp_error( $t ) ) $vals[] = $t->slug;
			} else {
				$vals[] = (string) $o;
			}
		}
		if ( $vals ) $axes[ sanitize_title( $a['name'] ) ] = $vals;
	}
	if ( ! $axes ) return new WP_Error( 'fisha_studio_invalid', 'Choose the values of an option used for sizes first, for example S, M and L.', array( 'status' => 422 ) );
	$combos = array( array() );
	foreach ( $axes as $key => $vals ) {
		$next = array();
		foreach ( $combos as $c ) foreach ( $vals as $val ) $next[] = $c + array( $key => (string) $val );
		$combos = $next;
	}
	if ( count( $combos ) > 50 ) {
		return new WP_Error( 'fisha_studio_invalid', sprintf( 'That would make %d sizes. Up to 50 can be created at once; choose fewer values.', count( $combos ) ), array( 'status' => 422 ) );
	}
	$have = array();
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$v = wc_get_product( $vid );
		if ( $v ) $have[ fisha_studio_combo_key( $v->get_attributes( 'edit' ) ) ] = true;
	}
	$new = array();
	foreach ( $combos as $c ) {
		if ( isset( $have[ fisha_studio_combo_key( $c ) ] ) ) continue;
		$new[] = fisha_studio_variation_create( wc_get_product( $p->get_id() ), $c )->get_id();
	}
	return $new;
}

/** Deletes a size that never went live. @return true|WP_Error */
function fisha_studio_variation_remove( WC_Product $p, int $vid ) {
	$v = wc_get_product( $vid );
	if ( ! $v || $v->get_parent_id() !== $p->get_id() ) return new WP_Error( 'fisha_studio_not_found', 'This size no longer exists.', array( 'status' => 404 ) );
	$pending = '1' === get_post_meta( $vid, FISHA_STUDIO_PENDING_META, true );
	if ( ! $pending && ! fisha_studio_is_new( $p ) ) {
		return new WP_Error( 'fisha_studio_invalid', 'A size that is already in the shop cannot be deleted. Switch it off instead.', array( 'status' => 409 ) );
	}
	$d      = fisha_studio_draft_get( $p->get_id() );
	$prefix = "var:$vid:";
	foreach ( array_keys( $d['fields'] ) as $k ) {
		if ( 0 === strpos( (string) $k, $prefix ) ) unset( $d['fields'][ $k ], $d['base'][ $k ] );
	}
	fisha_studio_draft_put( $p->get_id(), $d );
	wp_delete_post( $vid, true );
	return true;
}

/** Deletes the sizes added in the Studio that were never published (on Discard). */
function fisha_studio_variation_discard_pending( int $pid ): void {
	$ids = get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $pid, 'post_status' => array( 'publish', 'private' ), 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => FISHA_STUDIO_PENDING_META, 'meta_value' => '1' ) );
	foreach ( $ids as $vid ) wp_delete_post( (int) $vid, true );
}

/** Switches a never-published product between "one item" (simple) and "with sizes" (variable). @return WC_Product|WP_Error */
function fisha_studio_set_product_type( WC_Product $p, string $type ) {
	if ( ! in_array( $type, array( 'simple', 'variable' ), true ) ) return new WP_Error( 'fisha_studio_invalid', 'Unknown product type.', array( 'status' => 422 ) );
	if ( ! fisha_studio_is_new( $p ) ) return new WP_Error( 'fisha_studio_invalid', 'A product that is already in the shop keeps its type.', array( 'status' => 409 ) );
	if ( $p->get_type() === $type ) return $p;
	$id = $p->get_id();
	if ( 'simple' === $type ) {
		foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'product_variation', 'fields' => 'ids' ) ) as $vid ) wp_delete_post( (int) $vid, true );
		$p->set_attributes( array() );
		$p->save();
	}
	wp_set_object_terms( $id, $type, 'product_type' );
	clean_post_cache( $id );
	wc_delete_product_transients( $id );
	if ( class_exists( 'WC_Cache_Helper' ) ) WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
	return wc_get_product( $id );
}
```

- [ ] **Step 5: Run to verify pass**

Run: `bash tests/studio/run.sh "sizes:"` → `9 passed`. Then the whole suite → all pass.

- [ ] **Step 6: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio sizes: drafts per size, create/generate/delete sizes, product type switch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 3: Validate and publish products with sizes

**Files:**
- Modify: `studio/studio-publish.php`, `studio/studio-variations.php` (append), `tests/studio/test-publish.php` (one test changes)
- Test: `tests/studio/test-sizes-publish.php`

**Interfaces:**
- Consumes: Tasks 1–2.
- Produces: `fisha_studio_variable_validate( array $values, WC_Product $p ): array` (errors keyed `attributes`, `variations` or `var:{id}:{field}`); `fisha_studio_publish_product()` now publishes simple **and** variable products (other types: `fisha_studio_unsupported`, 400).

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-sizes-publish.php`:
```php
<?php
fs_test( 'sizes publish: a size price goes live and the price range follows', function () {
	$p = fs_ready_variable();
	$s = fs_var_id( $p, 's' );
	fs_as( FISHA_STUDIO_ROLE );
	fisha_studio_product_edit( $p, array( "var:$s:regular_price" => '150' ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	fs_eq( '150', wc_get_product( $s )->get_regular_price() );
	fs_eq( 150.0, (float) wc_get_product( $p->get_id() )->get_variation_regular_price( 'max' ) );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	fs_eq( '100', fisha_studio_log_for( 'product', $p->get_id() )[0]['before'][ "var:$s:regular_price" ] );
} );

fs_test( 'sizes publish: a new size goes live', function () {
	$p = fs_ready_variable( array( 's', 'm' ) );
	$v = fisha_studio_variation_create( $p, array( 'pa_size' => 'l' ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	fs_eq( 'publish', get_post_status( $v->get_id() ) );
	fs_eq( '', get_post_meta( $v->get_id(), FISHA_STUDIO_PENDING_META, true ) );
	fs_true( in_array( $v->get_id(), wc_get_product( $p->get_id() )->get_visible_children(), true ) );
} );

fs_test( 'sizes publish: a size that is on needs a price, and one size must be on', function () {
	$p = fs_ready_variable( array( 's', 'm' ) );
	$s = fs_var_id( $p, 's' );
	$m = fs_var_id( $p, 'm' );
	fisha_studio_product_edit( $p, array( "var:$s:regular_price" => '' ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'fisha_studio_invalid', $r->get_error_code() );
	fs_true( isset( $r->get_error_data()['fields'][ "var:$s:regular_price" ] ) );
	fs_eq( '100', wc_get_product( $s )->get_regular_price(), 'nothing written' );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( "var:$s:regular_price" => '100', "var:$s:enabled" => false, "var:$m:enabled" => false ) );
	fs_true( isset( fisha_studio_publish_product( $p->get_id() )->get_error_data()['fields']['variations'] ) );
} );

fs_test( 'sizes publish: a size whose value was removed must be switched off', function () {
	$p     = fs_ready_variable( array( 's', 'm' ) );
	$m     = fs_var_id( $p, 'm' );
	$attrs = fisha_studio_product_values( $p )['attributes'];
	$attrs[0]['options'] = array( (int) get_term_by( 'slug', 's', 'pa_size' )->term_id );
	fisha_studio_product_edit( $p, array( 'attributes' => $attrs ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_true( is_wp_error( $r ) && isset( $r->get_error_data()['fields'][ "var:$m:enabled" ] ) );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( "var:$m:enabled" => false ) );
	fs_false( is_wp_error( fisha_studio_publish_product( $p->get_id() ) ) );
	fs_eq( 'private', get_post_status( $m ), 'switched off, not deleted' );
} );

fs_test( 'sizes publish: SKUs must be unique', function () {
	$p   = fs_ready_variable( array( 's', 'm' ) );
	$s   = fs_var_id( $p, 's' );
	$m   = fs_var_id( $p, 'm' );
	$sku = 'ZZ-TEST-DUP-' . $s;
	fisha_studio_product_edit( $p, array( "var:$s:sku" => $sku, "var:$m:sku" => $sku ) );
	$f = fisha_studio_publish_product( $p->get_id() )->get_error_data()['fields'];
	fs_true( isset( $f[ "var:$s:sku" ] ) || isset( $f[ "var:$m:sku" ] ) );
} );

fs_test( "sizes publish: a size's stock conflict is asked about", function () {
	$p = fs_ready_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	$v = wc_get_product( $s );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 3 );
	$v->save();
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( "var:$s:stock_quantity" => 5, "var:$s:regular_price" => '120' ), array( "var:$s:stock_quantity" => 3, "var:$s:regular_price" => '100' ) );
	wc_update_product_stock( $s, 2, 'set' );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'fisha_studio_conflict', $r->get_error_code() );
	fs_eq( "var:$s:stock_quantity", $r->get_error_data()['conflicts'][0]['field'] );
	fs_false( is_wp_error( fisha_studio_publish_product( $p->get_id(), array( "var:$s:stock_quantity" => 'live' ) ) ) );
	fs_eq( 2, wc_get_product( $s )->get_stock_quantity(), 'kept the site value' );
	fs_eq( '120', wc_get_product( $s )->get_regular_price() );
} );

fs_test( 'sizes publish: a forced failure rolls back sizes too', function () {
	$p = fs_ready_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fisha_studio_product_edit( $p, array( "var:$s:regular_price" => '130' ) );
	$boom = function () { throw new RuntimeException( 'zz-test forced failure' ); };
	add_action( 'fisha_studio_before_commit', $boom );
	fs_on_cleanup( function () use ( $boom ) { remove_action( 'fisha_studio_before_commit', $boom ); } );
	fs_eq( 'fisha_studio_publish_failed', fisha_studio_publish_product( $p->get_id() )->get_error_code() );
	fs_eq( '100', wc_get_product( $s )->get_regular_price(), 'size price rolled back (the code clears the caches)' );
	fs_eq( '130', fisha_studio_draft_get( $p->get_id() )['fields'][ "var:$s:regular_price" ] );
} );

fs_test( 'sizes publish: editing another field leaves options untouched', function () {
	$p = new WC_Product_Variable();
	$p->set_name( 'zz-test local options' );
	$p->set_status( 'publish' );
	$p->set_short_description( 'Short.' );
	$p->set_image_id( fs_image() );
	$p->set_category_ids( array( fs_term( 'sz-local' )->term_id ) );
	$a = new WC_Product_Attribute();
	$a->set_name( 'Color' );
	$a->set_options( array( '"White"', 'Black' ) );
	$a->set_visible( true );
	$a->set_variation( true );
	$p->set_attributes( array( $a ) );
	$id = $p->save();
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	$v = new WC_Product_Variation();
	$v->set_parent_id( $id );
	$v->set_attributes( array( 'color' => '"White"' ) );
	$v->set_regular_price( '100' );
	$v->set_status( 'publish' );
	$vid = $v->save();
	WC_Product_Variable::sync( $id );
	$before = fisha_studio_attributes_read( wc_get_product( $id ) );
	fisha_studio_product_edit( wc_get_product( $id ), array( 'name' => 'zz-test local options renamed' ) );
	$r = fisha_studio_publish_product( $id );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	fs_eq( $before, fisha_studio_attributes_read( wc_get_product( $id ) ) );
	fs_eq( '"White"', get_post_meta( $vid, 'attribute_color', true ) );
} );

fs_test( 'sizes publish: a new product with sizes goes live in one go', function () {
	$p = fisha_studio_product_create( 'zz-test Studio Tee', 'variable' );
	$id = $p->get_id();
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	$attrs = fisha_studio_clean_attributes( array( array( 'name' => 'pa_size', 'taxonomy' => true, 'options' => array( get_term_by( 'slug', 's', 'pa_size' )->term_id, get_term_by( 'slug', 'm', 'pa_size' )->term_id ), 'variation' => true, 'visible' => true ) ) )[0];
	fisha_studio_product_edit( $p, array( 'attributes' => $attrs, 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'sz-new' )->term_id ), 'short_description' => '<p>Soft tee.</p>' ) );
	$ids = fisha_studio_variation_generate( wc_get_product( $id ), fisha_studio_product_values( wc_get_product( $id ) )['attributes'] );
	foreach ( $ids as $vid ) fisha_studio_product_edit( wc_get_product( $id ), array( "var:$vid:regular_price" => '119' ) );
	$r = fisha_studio_publish_product( $id );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	$live = wc_get_product( $id );
	fs_eq( 'publish', $live->get_status() );
	fs_eq( 2, count( $live->get_visible_children() ) );
	fs_eq( 119.0, (float) $live->get_variation_price( 'min' ) );
} );
```

In `tests/studio/test-publish.php` replace the whole test `'publish: variable products are refused for now'` with:
```php
fs_test( 'publish: kinds of product the Studio does not edit are refused', function () {
	$g = new WC_Product_Grouped();
	$g->set_name( 'zz-test grouped' );
	$id = $g->save();
	fs_on_cleanup( function () use ( $id ) { wp_delete_post( $id, true ); } );
	fs_eq( 'fisha_studio_unsupported', fisha_studio_publish_product( $id )->get_error_code() );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh "sizes publish"`
Expected: FAIL (`fisha_studio_unsupported` for variable products).

- [ ] **Step 3: Append validation to `studio/studio-variations.php`**

```php
/* ---------- checks before publishing ---------- */

/** Extra checks for a product with sizes ($v = live values merged with the draft). Errors keyed by field. */
function fisha_studio_variable_validate( array $v, WC_Product $p ): array {
	$e    = array();
	$axes = array();
	foreach ( (array) ( $v['attributes'] ?? array() ) as $a ) {
		if ( empty( $a['variation'] ) ) continue;
		if ( ! $a['options'] ) {
			$e['attributes'] = sprintf( 'Pick at least one value for %s.', $a['label'] );
			continue;
		}
		$vals = array();
		foreach ( $a['options'] as $o ) {
			if ( $a['taxonomy'] ) {
				$t = get_term( (int) $o, $a['name'] );
				if ( $t && ! is_wp_error( $t ) ) $vals[] = $t->slug;
			} else {
				$vals[] = (string) $o;
			}
		}
		$axes[ sanitize_title( $a['name'] ) ] = $vals;
	}
	if ( ! $axes && ! isset( $e['attributes'] ) ) $e['attributes'] = 'Choose the option shoppers pick from, such as Size, and its values.';

	$on     = 0;
	$combos = array();
	$skus   = array();
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$k = function ( string $f ) use ( $vid ) { return fisha_studio_var_key( $vid, $f ); };
		if ( empty( $v[ $k( 'enabled' ) ] ) ) continue;
		$on++;
		$var   = wc_get_product( $vid );
		$attrs = $var ? $var->get_attributes( 'edit' ) : array();
		foreach ( $axes as $name => $vals ) {
			$val = (string) ( $attrs[ $name ] ?? '' );
			if ( '' !== $val && ! in_array( $val, $vals, true ) ) { // '' = "any value", allowed
				$e[ $k( 'enabled' ) ] = 'This size uses a value that is no longer chosen above. Switch it off or add the value back.';
				break;
			}
		}
		$combo = fisha_studio_combo_key( array_intersect_key( $attrs, $axes ) );
		if ( isset( $combos[ $combo ] ) ) $e[ $k( 'enabled' ) ] = 'Two sizes that are on have the same values. Switch one off.';
		$combos[ $combo ] = true;
		$reg  = (string) ( $v[ $k( 'regular_price' ) ] ?? '' );
		$sale = (string) ( $v[ $k( 'sale_price' ) ] ?? '' );
		if ( '' === $reg ) $e[ $k( 'regular_price' ) ] = 'Each size that is on needs a price.';
		elseif ( '' !== $sale && (float) $sale >= (float) $reg ) $e[ $k( 'sale_price' ) ] = 'The sale price must be lower than the normal price.';
		if ( ! empty( $v[ $k( 'manage_stock' ) ] ) && null === ( $v[ $k( 'stock_quantity' ) ] ?? null ) ) $e[ $k( 'stock_quantity' ) ] = 'Enter how many are in stock.';
		$sku = (string) ( $v[ $k( 'sku' ) ] ?? '' );
		if ( '' !== $sku ) {
			if ( isset( $skus[ $sku ] ) || $sku === (string) ( $v['sku'] ?? '' ) || ! wc_product_has_unique_sku( $vid, $sku ) ) $e[ $k( 'sku' ) ] = 'Another product or size already uses this SKU.';
			$skus[ $sku ] = true;
		}
	}
	if ( ! $on ) $e['variations'] = 'Switch on at least one size and give it a price.';
	return $e;
}
```

- [ ] **Step 4: Change `studio/studio-publish.php`**

In `fisha_studio_publish_product()`:
1. Replace `if ( ! $p->is_type( 'simple' ) ) return new WP_Error( 'fisha_studio_unsupported', 'Products with sizes or options can be published from the Studio soon. Nothing was changed.', array( 'status' => 400 ) );` with:
```php
	if ( ! $p->is_type( array( 'simple', 'variable' ) ) ) return new WP_Error( 'fisha_studio_unsupported', 'This kind of product can only be edited in WP admin. Nothing was changed.', array( 'status' => 400 ) );
```
2. Replace `$before  = fisha_studio_product_read( $p );` with `$before  = fisha_studio_product_values( $p );`.
3. Replace `$errors = fisha_studio_product_validate( $after, $p );` with:
```php
	$errors = fisha_studio_product_validate( $after, $p );
	if ( $p->is_type( 'variable' ) ) $errors += fisha_studio_variable_validate( $after, $p );
```
4. In the transaction replace `fisha_studio_product_apply( $p, $changes );` with `fisha_studio_product_apply_all( $p, $changes );`.
5. Directly after `$p->save();` (inside the transaction) add:
```php
		if ( $p->is_type( 'variable' ) ) {
			foreach ( fisha_studio_variation_ids( $p ) as $vid ) delete_post_meta( $vid, FISHA_STUDIO_PENDING_META );
			WC_Product_Variable::sync( $id );
		}
```
6. Replace `fisha_studio_product_read( $fresh )` (the audit "after") with `fisha_studio_product_values( $fresh )`.
7. In the `catch` block, after `clean_post_cache( $id );` add:
```php
		foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
			clean_post_cache( $vid );
			if ( class_exists( 'WC_Cache_Helper' ) ) WC_Cache_Helper::invalidate_cache_group( 'product_' . $vid );
		}
```

- [ ] **Step 5: Run to verify pass**

Run: `bash tests/studio/run.sh "sizes publish"` → `9 passed`; `bash tests/studio/run.sh publish` → all pass (including the grouped-product test). Then the full suite.

- [ ] **Step 6: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio sizes: validate and publish products with sizes in one transaction

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Quick edit of one size (for the Inventory screen)

**Files:**
- Modify: `studio/studio-variations.php` (append)
- Test: `tests/studio/test-sizes-quick.php`

**Interfaces:**
- Produces: `fisha_studio_variation_quick( int $vid, array $input, array $seen ): WC_Product_Variation|WP_Error` — fields `regular_price`, `stock_quantity`, `stock_status`; refuses (409 `fisha_studio_conflict` with `data.conflicts`) when a sent field moved since `seen`; 409 `fisha_studio_has_draft` when the product has unpublished changes; 400 for never-published products/sizes; 422 for invalid values; saves in a transaction with an audit row (`variation`, action `quick`) and purges caches.

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-sizes-quick.php`:
```php
<?php
function fs_counted( int $vid, int $qty ): void {
	$v = wc_get_product( $vid );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( $qty );
	$v->save();
}

fs_test( 'sizes quick: a price change goes live and is logged', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_as( FISHA_STUDIO_ROLE );
	$r = fisha_studio_variation_quick( $s, array( 'regular_price' => '115' ), array( 'regular_price' => '100' ) );
	fs_true( $r instanceof WC_Product_Variation, is_wp_error( $r ) ? $r->get_error_message() : '' );
	fs_eq( '115', wc_get_product( $s )->get_regular_price() );
	fs_eq( 'quick', fisha_studio_log_for( 'variation', $s )[0]['action'] );
} );

fs_test( 'sizes quick: a stock that moved since the list loaded is not overwritten', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_counted( $s, 1 );
	wc_update_product_stock( $s, 0, 'set' );
	$r = fisha_studio_variation_quick( $s, array( 'stock_quantity' => 3 ), array( 'stock_quantity' => 1 ) );
	fs_eq( 'fisha_studio_conflict', $r->get_error_code() );
	fs_eq( 0, wc_get_product( $s )->get_stock_quantity() );
} );

fs_test( 'sizes quick: refused when the product has a draft or the size is new', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fisha_studio_product_edit( $p, array( 'meta_line' => 'pending' ) );
	fs_eq( 'fisha_studio_has_draft', fisha_studio_variation_quick( $s, array( 'regular_price' => '1' ), array() )->get_error_code() );
	$q = fs_variable( array( 's' ) );
	$v = fisha_studio_variation_create( $q, array( 'pa_size' => 'm' ) );
	fisha_studio_draft_put( $q->get_id(), array( 'fields' => array() ) ); // no draft left, but the size is still pending
	fs_eq( 'fisha_studio_unsupported', fisha_studio_variation_quick( $v->get_id(), array( 'regular_price' => '1' ), array() )->get_error_code() );
} );

fs_test( 'sizes quick: invalid values are refused', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_eq( 'fisha_studio_invalid', fisha_studio_variation_quick( $s, array( 'stock_quantity' => 3 ), array() )->get_error_code(), 'stock not counted for this size' );
	fs_eq( 'fisha_studio_invalid', fisha_studio_variation_quick( $s, array( 'regular_price' => '' ), array() )->get_error_code(), 'a size that is on needs a price' );
	fs_eq( 'fisha_studio_invalid', fisha_studio_variation_quick( $s, array( 'regular_price' => 'abc' ), array() )->get_error_code() );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh "sizes quick"` → FAIL, undefined `fisha_studio_variation_quick`.

- [ ] **Step 3: Append to `studio/studio-variations.php`**

```php
/* ---------- quick edit of one size ---------- */

/** Inventory quick edit of one size: refuses if it moved since the owner's list loaded, saves at once, logs it. @return WC_Product_Variation|WP_Error */
function fisha_studio_variation_quick( int $vid, array $input, array $seen_input ) {
	$v = wc_get_product( $vid );
	$p = ( $v && $v->is_type( 'variation' ) ) ? wc_get_product( $v->get_parent_id() ) : null;
	if ( ! $p || 'trash' === get_post_status( $p->get_id() ) ) return new WP_Error( 'fisha_studio_not_found', 'This size no longer exists.', array( 'status' => 404 ) );
	if ( fisha_studio_is_new( $p ) || '1' === get_post_meta( $vid, FISHA_STUDIO_PENDING_META, true ) ) {
		return new WP_Error( 'fisha_studio_unsupported', 'Open this product to edit it.', array( 'status' => 400 ) );
	}
	if ( fisha_studio_draft_get( $p->get_id() )['fields'] ) {
		return new WP_Error( 'fisha_studio_has_draft', 'This product has unpublished changes. Open it to review and publish them together.', array( 'status' => 409 ) );
	}
	$defs = array_intersect_key( fisha_studio_variation_fields(), array_flip( array( 'regular_price', 'stock_quantity', 'stock_status' ) ) );
	list( $values, $errors ) = fisha_studio_sanitize( $defs, $input );
	if ( $errors ) return new WP_Error( 'fisha_studio_invalid', reset( $errors ), array( 'status' => 422, 'fields' => $errors ) );
	if ( ! $values ) return $v;
	list( $seen, $seen_errors ) = fisha_studio_sanitize( $defs, $seen_input );
	$seen = array_intersect_key( array_diff_key( $seen, $seen_errors ), $values );

	$live = array();
	foreach ( $defs as $f => $def ) $live[ $f ] = fisha_studio_clean( $def, call_user_func( $def['get'], $v ) )[0];
	$conflicts = array();
	foreach ( $seen as $f => $was ) {
		if ( ! fisha_studio_same( $was, $live[ $f ] ) ) {
			$conflicts[] = array( 'field' => $f, 'label' => fisha_studio_variation_label( $v ) . ': ' . $defs[ $f ]['label'], 'base' => $was, 'live' => $live[ $f ], 'mine' => $values[ $f ] );
		}
	}
	if ( $conflicts ) return new WP_Error( 'fisha_studio_conflict', 'This changed on the site since the list was loaded, so nothing was changed.', array( 'status' => 409, 'conflicts' => $conflicts ) );

	$invalid = function ( string $f, string $msg ) { return new WP_Error( 'fisha_studio_invalid', $msg, array( 'status' => 422, 'fields' => array( $f => $msg ) ) ); };
	if ( array_key_exists( 'regular_price', $values ) ) {
		if ( '' === $values['regular_price'] && 'publish' === $v->get_status( 'edit' ) ) return $invalid( 'regular_price', 'A size that is on needs a price.' );
		$sale = (string) $v->get_sale_price( 'edit' );
		if ( '' !== $sale && '' !== $values['regular_price'] && (float) $sale >= (float) $values['regular_price'] ) return $invalid( 'regular_price', 'This size has a sale price that would be higher. Open the product to change both.' );
	}
	if ( array_key_exists( 'stock_quantity', $values ) && ! $v->get_manage_stock( 'edit' ) ) return $invalid( 'stock_quantity', 'This size does not count stock. Open the product to turn counting on.' );

	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		foreach ( $values as $f => $x ) call_user_func( $defs[ $f ]['set'], $v, $x );
		$v->save();
		WC_Product_Variable::sync( $p->get_id() );
		$fresh = wc_get_product( $vid );
		$after = array();
		foreach ( $defs as $f => $def ) $after[ $f ] = fisha_studio_clean( $def, call_user_func( $def['get'], $fresh ) )[0];
		if ( ! fisha_studio_log( 'variation', $vid, 'quick', $live, $after ) ) throw new RuntimeException( 'audit log write failed: ' . $wpdb->last_error );
		$wpdb->query( 'COMMIT' );
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		clean_post_cache( $vid );
		clean_post_cache( $p->get_id() );
		wc_delete_product_transients( $p->get_id() );
		fisha_studio_log_error( 'quick size ' . $vid, $e );
		return new WP_Error( 'fisha_studio_publish_failed', 'Saving failed, so nothing was changed. Please try again.', array( 'status' => 500 ) );
	}
	fisha_studio_purge_product( wc_get_product( $p->get_id() ) );
	return wc_get_product( $vid );
}
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh "sizes quick"` → `4 passed`; full suite passes.

- [ ] **Step 5: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio sizes: quick edit of one size with the seen-value check

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Preview of products with sizes

**Files:**
- Modify: `studio/studio-preview.php`
- Test: `tests/studio/test-sizes-preview.php`

**Interfaces:**
- Consumes: Tasks 1–2, phase 1 preview helpers.
- Produces: `fisha_studio_preview_install_sizes( WC_Product $p, array $merged, array $fields ): void`, called at the end of `fisha_studio_preview_install_product()` for variable products. Hooks used: `woocommerce_product_variation_get_{prop}`, `woocommerce_variation_is_visible`, `woocommerce_get_children` (visible children only), `woocommerce_variation_prices`, `woocommerce_product_get_stock_status`, `woocommerce_product_get_attributes`.

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-sizes-preview.php`:
```php
<?php
function fs_offered( int $pid ): array {
	return array_map( 'intval', wp_list_pluck( wc_get_product( $pid )->get_available_variations(), 'variation_id' ) );
}

fs_test( 'sizes preview: a draft size price shows in the price range', function () {
	$p = fs_variable();
	$s = fs_var_id( $p, 's' );
	fisha_studio_product_edit( $p, array( "var:$s:regular_price" => '150' ) );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	$v = wc_get_product( $p->get_id() );
	fs_eq( 150.0, (float) $v->get_variation_price( 'max' ) );
	fs_eq( 100.0, (float) $v->get_variation_price( 'min' ) );
	fs_eq( '150', wc_get_product( $s )->get_price() );
} );

fs_test( 'sizes preview: a size switched off disappears from the choice', function () {
	$p = fs_variable();
	$m = fs_var_id( $p, 'm' );
	fisha_studio_product_edit( $p, array( "var:$m:enabled" => false ) );
	fs_true( in_array( $m, fs_offered( $p->get_id() ), true ), 'still offered on the live page' );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	fs_false( in_array( $m, fs_offered( $p->get_id() ), true ), 'gone in the preview' );
} );

fs_test( 'sizes preview: a new size shows only in the preview', function () {
	$p = fs_variable( array( 's', 'm' ) );
	$v = fisha_studio_variation_create( $p, array( 'pa_size' => 'l' ) );
	fs_false( in_array( $v->get_id(), fs_offered( $p->get_id() ), true ), 'shoppers do not see it' );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	fs_true( in_array( $v->get_id(), fs_offered( $p->get_id() ), true ), 'the preview shows it' );
} );

fs_test( 'sizes preview: sold out when every size that is on is sold out', function () {
	$p      = fs_variable( array( 's', 'm' ) );
	$fields = array();
	foreach ( array( 's', 'm' ) as $slug ) {
		$id = fs_var_id( $p, $slug );
		$fields[ "var:$id:manage_stock" ]   = true;
		$fields[ "var:$id:stock_quantity" ] = 0;
	}
	fisha_studio_product_edit( $p, $fields );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	fs_eq( 'outofstock', wc_get_product( $p->get_id() )->get_stock_status() );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh "sizes preview"` → FAIL (draft size values are not overlaid).

- [ ] **Step 3: Implement in `studio/studio-preview.php`**

In `fisha_studio_preview_install_product()`:
1. Replace `$merged = array_merge( fisha_studio_product_read( $p ), $fields );` with `$merged = array_merge( fisha_studio_product_values( $p ), $fields );`.
2. As the last statement of the function add:
```php
	if ( $p->is_type( 'variable' ) ) fisha_studio_preview_install_sizes( $p, $merged, $fields );
```

Append to the file:
```php
/** Preview of a product with sizes: each changed size, which sizes are offered, the price range, stock and options. */
function fisha_studio_preview_install_sizes( WC_Product $p, array $merged, array $fields ): void {
	$id    = $p->get_id();
	$sizes = array();
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		foreach ( array_keys( fisha_studio_variation_fields() ) as $f ) $sizes[ $vid ][ $f ] = $merged[ fisha_studio_var_key( $vid, $f ) ] ?? null;
		$v = wc_get_product( $vid );
		$sizes[ $vid ]['backorders'] = $v ? $v->get_backorders( 'edit' ) : 'no';
	}
	$touched = array();
	foreach ( array_keys( $fields ) as $k ) {
		$parsed = fisha_studio_parse_var_key( (string) $k );
		if ( $parsed ) $touched[ $parsed[0] ] = true;
	}
	if ( ! $touched && ! array_key_exists( 'attributes', $fields ) ) return;

	$price_of = function ( array $x ): string {
		return fisha_studio_effective_price( array( 'regular_price' => (string) $x['regular_price'], 'sale_price' => (string) $x['sale_price'], 'date_on_sale_from' => '', 'date_on_sale_to' => '' ) );
	};
	$stock_of = function ( array $x ): string {
		return fisha_studio_effective_stock_status( array( 'manage_stock' => (bool) $x['manage_stock'], 'stock_quantity' => $x['stock_quantity'], 'stock_status' => (string) $x['stock_status'], 'backorders' => (string) $x['backorders'] ) );
	};
	$offered = array();
	foreach ( $sizes as $vid => $x ) $offered[ $vid ] = ! empty( $x['enabled'] ) && '' !== (string) $x['regular_price'];
	$on = array_keys( array_filter( $offered ) );

	// 1. Each changed size's own values.
	foreach ( array_keys( $touched ) as $vid ) {
		if ( ! isset( $sizes[ $vid ] ) ) continue;
		$x    = $sizes[ $vid ];
		$over = array(
			'status' => $x['enabled'] ? 'publish' : 'private', 'regular_price' => (string) $x['regular_price'], 'sale_price' => (string) $x['sale_price'],
			'price' => $price_of( $x ), 'sku' => (string) $x['sku'], 'manage_stock' => (bool) $x['manage_stock'], 'stock_quantity' => $x['stock_quantity'],
			'stock_status' => $stock_of( $x ), 'image_id' => (int) $x['image_id'],
		);
		foreach ( $over as $prop => $value ) {
			fisha_studio_preview_add( "woocommerce_product_variation_get_$prop", function ( $val, $obj = null ) use ( $vid, $value ) {
				return ( $obj instanceof WC_Product && $obj->get_id() === $vid ) ? $value : $val;
			} );
		}
	}
	fisha_studio_preview_add( 'woocommerce_variation_is_visible', function ( $visible, $vid = 0 ) use ( $offered ) {
		return array_key_exists( (int) $vid, $offered ) ? $offered[ (int) $vid ] : $visible;
	} );

	// 2. Which sizes are offered, and the price range.
	fisha_studio_preview_add( 'woocommerce_get_children', function ( $children, $product = null, $visible_only = false ) use ( $id, $on ) {
		return ( $visible_only && $product instanceof WC_Product && $product->get_id() === $id ) ? $on : $children;
	}, 99, 3 );
	fisha_studio_preview_add( 'woocommerce_variation_prices', function ( $prices, $product = null ) use ( $id, $on, $sizes, $price_of ) {
		if ( ! $product instanceof WC_Product || $product->get_id() !== $id ) return $prices;
		$dp  = wc_get_price_decimals();
		$out = array( 'price' => array(), 'regular_price' => array(), 'sale_price' => array() );
		foreach ( $on as $vid ) {
			$x = $sizes[ $vid ];
			$out['price'][ $vid ]         = wc_format_decimal( $price_of( $x ), $dp );
			$out['regular_price'][ $vid ] = wc_format_decimal( $x['regular_price'], $dp );
			$out['sale_price'][ $vid ]    = wc_format_decimal( '' !== (string) $x['sale_price'] ? $x['sale_price'] : $x['regular_price'], $dp );
		}
		foreach ( $out as &$list ) asort( $list );
		return $out;
	} );

	// 3. In stock when any size that is on is.
	$in = false;
	foreach ( $on as $vid ) if ( 'outofstock' !== $stock_of( $sizes[ $vid ] ) ) $in = true;
	fisha_studio_preview_add( 'woocommerce_product_get_stock_status', function ( $s, $obj = null ) use ( $id, $in ) {
		return ( $obj instanceof WC_Product && $obj->get_id() === $id ) ? ( $in ? 'instock' : 'outofstock' ) : $s;
	} );

	// 4. The options shown on the product page.
	if ( array_key_exists( 'attributes', $fields ) ) {
		$objs = fisha_studio_attribute_objects( (array) $merged['attributes'] );
		fisha_studio_preview_add( 'woocommerce_product_get_attributes', function ( $a, $obj = null ) use ( $id, $objs ) {
			return ( $obj instanceof WC_Product && $obj->get_id() === $id ) ? $objs : $a;
		} );
	}
}
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh "sizes preview"` → `4 passed`; `bash tests/studio/run.sh preview` → phase 1 preview tests still pass; full suite passes.

- [ ] **Step 5: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio sizes: live preview of sizes, price range and stock

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: REST for products with sizes

**Files:**
- Modify: `studio/api/api-products.php`, `studio/api/api-lookups.php`, `tests/studio/test-api-products.php` (one test changes)
- Test: `tests/studio/test-api-sizes.php`

**Interfaces:**
- Consumes: Tasks 1–5.
- Produces:
  - payload gains `variations: [{id, label, attrs:{}, pending:bool}]`; `editable` is true for simple and variable; `values`/`live` include `attributes` and every `var:` key; `images` also covers size photos.
  - row gains `sizes:bool`, `stock_text:string`; `editable` true for simple and variable; `low` covers sizes.
  - `POST /products {name?, type?:'simple'|'variable'}` (name may be empty, ≤120).
  - `PUT /products/{id}/draft` accepts size keys and `attributes`; seen covers size keys.
  - `POST /products/{id}/type {type}` → payload (409 for live products).
  - `POST /products/{id}/variations/generate` → payload + `created:int`.
  - `DELETE /products/{id}/variations/{vid}` → payload.
  - `GET /lookups` gains `attributes` (the catalog).
  - helpers `fisha_studio_variations_payload( WC_Product )`, `fisha_studio_sizes_stock_text( WC_Product )`, `fisha_studio_low_threshold( WC_Product ): int`.

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-api-sizes.php`:
```php
<?php
fs_test( 'api sizes: payload of a product with sizes', function () {
	$p = fs_variable();
	$s = fs_var_id( $p, 's' );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $d ) = fs_api( 'GET', '/products/' . $p->get_id() );
	fs_eq( 200, $st );
	fs_true( $d['editable'] );
	fs_eq( 3, count( $d['variations'] ) );
	fs_eq( get_term_by( 'slug', 's', 'pa_size' )->name, $d['variations'][0]['label'] );
	fs_eq( '100', $d['values'][ "var:$s:regular_price" ] );
	fs_eq( 'pa_size', $d['values']['attributes'][0]['name'] );
} );

fs_test( 'api sizes: autosave a size, bad values reported, other products ignored', function () {
	$p     = fs_variable();
	$other = fs_variable( array( 'm' ) );
	$s     = fs_var_id( $p, 's' );
	$om    = fs_var_id( $other, 'm' );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $d ) = fs_api( 'PUT', '/products/' . $p->get_id() . '/draft', array( 'fields' => array( "var:$s:regular_price" => '₪140', "var:$s:sale_price" => 'x', "var:$om:regular_price" => '1' ), 'seen' => array( "var:$s:regular_price" => '100' ) ) );
	fs_eq( 200, $st );
	fs_eq( 'changed', $d['state'] );
	fs_eq( '140', $d['values'][ "var:$s:regular_price" ] );
	fs_true( isset( $d['field_errors'][ "var:$s:sale_price" ] ) );
	fs_eq( '100', wc_get_product( $om )->get_regular_price(), 'other product untouched' );
	fs_false( isset( fisha_studio_draft_get( $other->get_id() )['fields'][ "var:$om:regular_price" ] ) );
} );

fs_test( 'api sizes: create missing sizes, delete a new one, keep live ones', function () {
	$p = fs_variable();
	fs_as( FISHA_STUDIO_ROLE );
	$attrs = fisha_studio_product_values( $p )['attributes'];
	$attrs[0]['options'][] = (int) get_term_by( 'slug', 'xl', 'pa_size' )->term_id;
	fs_api( 'PUT', '/products/' . $p->get_id() . '/draft', array( 'fields' => array( 'attributes' => $attrs ) ) );
	list( $st, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/variations/generate' );
	fs_eq( 200, $st, wp_json_encode( $d ) );
	fs_eq( 1, $d['created'] );
	fs_eq( 4, count( $d['variations'] ) );
	$new = end( $d['variations'] );
	fs_true( $new['pending'] );
	list( $st, $d ) = fs_api( 'DELETE', '/products/' . $p->get_id() . '/variations/' . $new['id'] );
	fs_eq( 200, $st );
	fs_eq( 3, count( $d['variations'] ) );
	list( $st ) = fs_api( 'DELETE', '/products/' . $p->get_id() . '/variations/' . fs_var_id( $p, 's' ) );
	fs_eq( 409, $st, 'a live size cannot be deleted' );
} );

fs_test( 'api sizes: new product with an empty name, type switch, publish', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $d ) = fs_api( 'POST', '/products', array( 'name' => '' ) );
	fs_eq( 201, $st );
	$id = $d['id'];
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	fs_eq( 'simple', $d['type'] );
	list( $st, $d ) = fs_api( 'POST', "/products/$id/type", array( 'type' => 'variable' ) );
	fs_eq( 200, $st );
	fs_eq( 'variable', $d['type'] );
	$attrs = array( array( 'name' => 'pa_size', 'taxonomy' => true, 'options' => array( get_term_by( 'slug', 's', 'pa_size' )->term_id ), 'variation' => true, 'visible' => true ) );
	fs_api( 'PUT', "/products/$id/draft", array( 'fields' => array( 'name' => 'zz-test Api Hoodie', 'attributes' => $attrs, 'short_description' => '<p>Warm.</p>', 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'api-sz' )->term_id ) ) ) );
	list( , $d ) = fs_api( 'POST', "/products/$id/variations/generate" );
	$vid = $d['variations'][0]['id'];
	fs_api( 'PUT', "/products/$id/draft", array( 'fields' => array( "var:$vid:regular_price" => '199' ) ) );
	list( $st, $d ) = fs_api( 'POST', "/products/$id/publish" );
	fs_eq( 200, $st, wp_json_encode( $d ) );
	fs_eq( 'live', $d['state'] );
	list( $st ) = fs_api( 'POST', "/products/$id/type", array( 'type' => 'simple' ) );
	fs_eq( 409, $st, 'live products keep their type' );
} );

fs_test( 'api sizes: discard removes new sizes; list rows describe sizes', function () {
	$p = fs_variable( array( 's', 'm' ) );
	fs_as( FISHA_STUDIO_ROLE );
	$v = fisha_studio_variation_create( $p, array( 'pa_size' => 'l' ) );
	list( , $d ) = fs_api( 'DELETE', '/products/' . $p->get_id() . '/draft' );
	fs_eq( 'live', $d['state'] );
	fs_eq( null, get_post( $v->get_id() ) );
	list( , $list ) = fs_api( 'GET', '/products', array( 'search' => $p->get_name() ) );
	fs_true( $list['items'][0]['sizes'] );
	fs_eq( '2 of 2 in stock', $list['items'][0]['stock_text'] );
} );

fs_test( 'api sizes: lookups include the shop-wide options', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( , $d ) = fs_api( 'GET', '/lookups' );
	fs_true( in_array( 'pa_size', wp_list_pluck( $d['attributes'], 'name' ), true ) );
} );
```

In `tests/studio/test-api-products.php` replace the whole test `'api products: variable products are read-only for now'` with:
```php
fs_test( 'api products: kinds of product the Studio does not edit stay read-only', function () {
	$g = new WC_Product_Grouped();
	$g->set_name( 'zz-test api grouped' );
	$g->set_status( 'publish' );
	$id = $g->save();
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

Run: `bash tests/studio/run.sh "api sizes"` → FAIL (`editable` false for variable, no `variations`, unknown routes).

- [ ] **Step 3: Implement in `studio/api/api-products.php`**

1. In the `rest_api_init` callback, after the `/trash` route add:
```php
	register_rest_route( FISHA_STUDIO_NS, $id . '/type', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_type', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/variations/generate', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_sizes_generate', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/variations/(?P<vid>\d+)', array( 'methods' => 'DELETE', 'callback' => 'fisha_studio_rest_sizes_delete', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
```
2. In `fisha_studio_product_payload()`:
   - replace `$live   = fisha_studio_product_read( $p );` with `$live   = fisha_studio_product_values( $p );`
   - replace the `foreach ( array_merge( array( (int) $values['image_id'] ), $values['gallery_image_ids'] ) as $aid ) {` line with:
```php
	$image_ids = array_merge( array( (int) $values['image_id'] ), $values['gallery_image_ids'] );
	foreach ( $values as $k => $x ) {
		if ( $x && preg_match( '/^var:\d+:image_id$/', (string) $k ) ) $image_ids[] = (int) $x;
	}
	foreach ( array_unique( $image_ids ) as $aid ) {
```
   - replace `'editable'     => $p->is_type( 'simple' ),` with `'editable'     => $p->is_type( array( 'simple', 'variable' ) ),`
   - add after `'conflicts' => …,`: `'variations'   => fisha_studio_variations_payload( $p ),`
3. Replace `fisha_studio_product_row()` with:
```php
function fisha_studio_low_threshold( WC_Product $p ): int {
	return '' !== (string) $p->get_low_stock_amount() ? (int) $p->get_low_stock_amount() : (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
}

/** "S 3 · M 0 · L 2" when sizes count stock, else "3 of 4 in stock". */
function fisha_studio_sizes_stock_text( WC_Product $p ): string {
	$parts   = array();
	$counted = false;
	$in      = 0;
	$n       = 0;
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v || 'publish' !== $v->get_status( 'edit' ) ) continue;
		$n++;
		if ( $v->is_in_stock() ) $in++;
		if ( true === $v->get_manage_stock( 'edit' ) ) {
			$counted = true;
			$parts[] = fisha_studio_variation_label( $v ) . ' ' . (int) $v->get_stock_quantity();
		}
	}
	if ( ! $n ) return 'No sizes on';
	return $counted ? implode( ' · ', $parts ) : sprintf( '%d of %d in stock', $in, $n );
}

function fisha_studio_variations_payload( WC_Product $p ): array {
	$out = array();
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v ) continue;
		$out[] = array( 'id' => $vid, 'label' => fisha_studio_variation_label( $v ), 'attrs' => (object) $v->get_attributes( 'edit' ), 'pending' => '1' === get_post_meta( $vid, FISHA_STUDIO_PENDING_META, true ) );
	}
	return $out;
}

function fisha_studio_product_row( WC_Product $p ): array {
	$sizes = $p->is_type( 'variable' );
	$qty   = ( ! $sizes && $p->get_manage_stock() ) ? $p->get_stock_quantity() : null;
	$low   = null !== $qty && $qty > 0 && $qty <= fisha_studio_low_threshold( $p );
	if ( $sizes ) {
		foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && 'publish' === $v->get_status( 'edit' ) && true === $v->get_manage_stock( 'edit' ) ) {
				$q = (int) $v->get_stock_quantity();
				if ( $q > 0 && $q <= fisha_studio_low_threshold( $v ) ) $low = true;
			}
		}
	}
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
		'editable'       => $p->is_type( array( 'simple', 'variable' ) ),
		'sizes'          => $sizes,
		'stock_text'     => $sizes ? fisha_studio_sizes_stock_text( $p ) : '',
		'state'          => fisha_studio_product_state( $p ),
		'hidden'         => 'private' === get_post_status( $p->get_id() ),
		'regular_price'  => (string) $p->get_regular_price( 'edit' ),
		'sale_price'     => (string) $p->get_sale_price( 'edit' ),
		'price_text'     => html_entity_decode( wp_strip_all_tags( $p->get_price_html() ), ENT_QUOTES, 'UTF-8' ),
		'manage_stock'   => (bool) $p->get_manage_stock(),
		'stock_quantity' => $qty,
		'stock_status'   => $p->get_stock_status( 'edit' ),
		'low'            => (bool) $low,
		'thumb'          => $p->get_image_id() ? (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : '',
		'categories'     => $names,
	);
}
```
4. Replace `fisha_studio_rest_products_create()` with:
```php
function fisha_studio_rest_products_create( WP_REST_Request $r ) {
	$body = fisha_studio_body( $r );
	$name = trim( sanitize_text_field( (string) ( $body['name'] ?? '' ) ) );
	if ( mb_strlen( $name ) > 120 ) {
		return new WP_Error( 'fisha_studio_invalid', 'A name can be at most 120 characters.', array( 'status' => 422, 'fields' => array( 'name' => 'A name can be at most 120 characters.' ) ) );
	}
	$p = fisha_studio_product_create( $name, 'variable' === ( $body['type'] ?? '' ) ? 'variable' : 'simple' );
	fisha_studio_log( 'product', $p->get_id(), 'new', null, array( 'name' => $name, 'type' => $p->get_type() ) );
	return new WP_REST_Response( fisha_studio_product_payload( $p ), 201 );
}
```
5. In `fisha_studio_rest_products_draft()` replace the type check and the sanitize/edit lines with:
```php
	if ( ! $p->is_type( array( 'simple', 'variable' ) ) ) return new WP_Error( 'fisha_studio_unsupported', 'This kind of product can only be edited in WP admin.', array( 'status' => 400 ) );
	$body = fisha_studio_body( $r );
	list( $values, $errors ) = fisha_studio_sanitize_product_input( $p, (array) ( $body['fields'] ?? array() ) );
	$values = array_diff_key( $values, $errors );
	if ( $values ) fisha_studio_product_edit( $p, $values, fisha_studio_clean_seen( $body['seen'] ?? array(), $p ) );
```
(keep the existing `return array_merge( …payload…, array( 'field_errors' => (object) $errors ) );`).
6. Append:
```php
function fisha_studio_rest_products_type( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	$res = fisha_studio_set_product_type( $p, (string) ( fisha_studio_body( $r )['type'] ?? '' ) );
	return is_wp_error( $res ) ? $res : fisha_studio_product_payload( $res );
}

/** @return WC_Product|WP_Error a product with sizes */
function fisha_studio_rest_variable( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	return $p->is_type( 'variable' ) ? $p : new WP_Error( 'fisha_studio_invalid', 'Switch on "Comes in sizes or options" first.', array( 'status' => 409 ) );
}

function fisha_studio_rest_sizes_generate( WP_REST_Request $r ) {
	$p = fisha_studio_rest_variable( $r );
	if ( is_wp_error( $p ) ) return $p;
	$values = array_merge( fisha_studio_product_values( $p ), fisha_studio_draft_get( $p->get_id() )['fields'] );
	$new    = fisha_studio_variation_generate( $p, (array) ( $values['attributes'] ?? array() ) );
	if ( is_wp_error( $new ) ) return $new;
	return array_merge( fisha_studio_product_payload( wc_get_product( $p->get_id() ) ), array( 'created' => count( $new ) ) );
}

function fisha_studio_rest_sizes_delete( WP_REST_Request $r ) {
	$p = fisha_studio_rest_variable( $r );
	if ( is_wp_error( $p ) ) return $p;
	$res = fisha_studio_variation_remove( $p, (int) $r['vid'] );
	return is_wp_error( $res ) ? $res : fisha_studio_product_payload( wc_get_product( $p->get_id() ) );
}
```
7. In `studio/api/api-lookups.php`, add to the array returned by `fisha_studio_rest_lookups()`:
```php
		'attributes'       => fisha_studio_attributes_catalog(),
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh "api sizes"` → `6 passed`; `bash tests/studio/run.sh "api products"` → all pass; full suite passes.

- [ ] **Step 5: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio REST: products with sizes, size creation, type switch, empty new products

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 7: REST for the shop-wide options (Size, Shoe size…)

**Files:**
- Create: `studio/api/api-attributes.php`
- Modify: `studio/api/api.php` (require)
- Test: `tests/studio/test-api-attributes.php`

**Interfaces:**
- Consumes: `fisha_studio_attributes_catalog()` (Task 1), `fisha_studio_log()`.
- Produces (all with `fisha_studio_permission( 'manage_product_terms' )`; changes apply at once, they are not product drafts):
  - `GET /attributes` → `[{id, name:'pa_size', label, terms:[{id, name, slug, sizes:int, products:int}]}]`
  - `POST /attributes {label}` → 201 one attribute payload (label ≤ 28 characters)
  - `PUT /attributes/{id} {label}` → payload (the slug never changes)
  - `POST /attributes/{id}/terms {name}` → `{id, name, slug}` (an existing value with that name is returned instead of a duplicate; new values go last)
  - `POST /attributes/{id}/order {term_ids:[…]}` → payload
  - `PUT /attributes/terms/{term} {name}` → `{id, name, slug}` (slug unchanged, so sizes keep working)
  - `DELETE /attributes/terms/{term}` → `{deleted:true}`; 409 `fisha_studio_in_use` with `data.products` (names) when a size or product uses it.
  - helpers: `fisha_studio_attribute_tax( int $id ): ?string`, `fisha_studio_ensure_attribute_tax( string $tax ): void`, `fisha_studio_attribute_usage( string $tax ): array<slug,int>`, `fisha_studio_attribute_payload( int $id ): ?array`.

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-api-attributes.php`:
```php
<?php
function fs_new_option( string $label ): array {
	list( $st, $d ) = fs_api( 'POST', '/attributes', array( 'label' => $label ) );
	if ( 201 !== $st ) throw new Fs_Fail( 'could not create option: ' . wp_json_encode( $d ) );
	fs_on_cleanup( function () use ( $d ) { wc_delete_attribute( $d['id'] ); } );
	return $d;
}

fs_test( 'api attributes: list with how many sizes use each value', function () {
	fs_variable( array( 's' ) );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $d ) = fs_api( 'GET', '/attributes' );
	fs_eq( 200, $st );
	$size = current( array_filter( $d, function ( $a ) { return 'pa_size' === $a['name']; } ) );
	$s    = current( array_filter( $size['terms'], function ( $t ) { return 's' === $t['slug']; } ) );
	fs_true( $s['sizes'] >= 1, 'S is used by sizes' );
	fs_as( 'customer' );
	list( $st ) = fs_api( 'GET', '/attributes' );
	fs_eq( 403, $st );
} );

fs_test( 'api attributes: a new option with values, renamed and reordered', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$opt = fs_new_option( 'zz-test Fit' );
	$ids = array();
	foreach ( array( 'Slim', 'Regular', 'Loose' ) as $name ) {
		list( $st, $t ) = fs_api( 'POST', '/attributes/' . $opt['id'] . '/terms', array( 'name' => $name ) );
		fs_eq( 200, $st, wp_json_encode( $t ) );
		$ids[] = $t['id'];
	}
	list( , $again ) = fs_api( 'POST', '/attributes/' . $opt['id'] . '/terms', array( 'name' => 'Slim' ) );
	fs_eq( $ids[0], $again['id'], 'no duplicate value' );
	list( , $d ) = fs_api( 'POST', '/attributes/' . $opt['id'] . '/order', array( 'term_ids' => array_reverse( $ids ) ) );
	fs_eq( array( 'Loose', 'Regular', 'Slim' ), wp_list_pluck( $d['terms'], 'name' ) );
	list( , $t ) = fs_api( 'PUT', '/attributes/terms/' . $ids[0], array( 'name' => 'Skinny' ) );
	fs_eq( 'Skinny', $t['name'] );
	fs_eq( 'slim', $t['slug'], 'the slug stays, so sizes keep working' );
	list( , $d ) = fs_api( 'PUT', '/attributes/' . $opt['id'], array( 'label' => 'zz-test Cut' ) );
	fs_eq( 'zz-test Cut', $d['label'] );
	fs_eq( $opt['name'], $d['name'], 'option slug unchanged' );
} );

fs_test( 'api attributes: values in use cannot be deleted, unused ones can', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p = fs_variable( array( 's' ) );
	list( $st, $d ) = fs_api( 'DELETE', '/attributes/terms/' . get_term_by( 'slug', 's', 'pa_size' )->term_id );
	fs_eq( 409, $st );
	fs_eq( 'fisha_studio_in_use', $d['code'] );
	fs_true( in_array( $p->get_name(), $d['data']['products'], true ) || count( $d['data']['products'] ) === 10, 'names the products' );
	$opt = fs_new_option( 'zz-test Gone' );
	list( , $t ) = fs_api( 'POST', '/attributes/' . $opt['id'] . '/terms', array( 'name' => 'Unused' ) );
	list( $st, $d ) = fs_api( 'DELETE', '/attributes/terms/' . $t['id'] );
	fs_eq( 200, $st );
	fs_eq( null, get_term( $t['id'] ) );
} );

fs_test( 'api attributes: bad names are refused', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( $st ) = fs_api( 'POST', '/attributes', array( 'label' => '' ) );
	fs_eq( 422, $st );
	list( $st ) = fs_api( 'POST', '/attributes', array( 'label' => str_repeat( 'x', 29 ) ) );
	fs_eq( 422, $st );
	list( $st ) = fs_api( 'PUT', '/attributes/terms/' . fs_term( 'not an option value' )->term_id, array( 'name' => 'x' ) );
	fs_eq( 404, $st, 'only option values can be edited here' );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh "api attributes"` → FAIL (`rest_no_route`).

- [ ] **Step 3: Implement**

`studio/api/api-attributes.php`:
```php
<?php
/**
 * Fisha Studio REST: the shop-wide options (Size, Shoe size…) and their values. Changes here apply at once, they are
 * not product drafts: add or rename an option, add, rename or reorder its values, and delete a value nothing uses.
 * Slugs never change, because sizes store them.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	$perm = fisha_studio_permission( 'manage_product_terms' );
	register_rest_route( FISHA_STUDIO_NS, '/attributes', array(
		array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_attributes', 'permission_callback' => $perm ),
		array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_attribute_add', 'permission_callback' => $perm ),
	) );
	register_rest_route( FISHA_STUDIO_NS, '/attributes/(?P<id>\d+)', array( 'methods' => 'PUT', 'callback' => 'fisha_studio_rest_attribute_rename', 'permission_callback' => $perm ) );
	register_rest_route( FISHA_STUDIO_NS, '/attributes/(?P<id>\d+)/terms', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_attribute_term_add', 'permission_callback' => $perm ) );
	register_rest_route( FISHA_STUDIO_NS, '/attributes/(?P<id>\d+)/order', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_attribute_order', 'permission_callback' => $perm ) );
	register_rest_route( FISHA_STUDIO_NS, '/attributes/terms/(?P<term>\d+)', array(
		array( 'methods' => 'PUT', 'callback' => 'fisha_studio_rest_attribute_term_rename', 'permission_callback' => $perm ),
		array( 'methods' => 'DELETE', 'callback' => 'fisha_studio_rest_attribute_term_delete', 'permission_callback' => $perm ),
	) );
} );

function fisha_studio_attribute_tax( int $id ): ?string {
	$a = wc_get_attribute( $id );
	return $a ? (string) $a->slug : null;
}

/** A just-created option is not registered until the next request; register it for this one. */
function fisha_studio_ensure_attribute_tax( string $tax ): void {
	if ( ! taxonomy_exists( $tax ) ) {
		register_taxonomy( $tax, array( 'product', 'product_variation' ), array( 'hierarchical' => false, 'public' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false ) );
	}
}

/** How many sizes use each value (by slug) of an option. */
function fisha_studio_attribute_usage( string $tax ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT pm.meta_value AS slug, COUNT(*) AS n FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE pm.meta_key = %s AND p.post_type = 'product_variation' AND p.post_status IN ('publish','private') GROUP BY pm.meta_value",
		'attribute_' . $tax
	), ARRAY_A );
	$out = array();
	foreach ( $rows as $r ) $out[ $r['slug'] ] = (int) $r['n'];
	return $out;
}

function fisha_studio_attribute_payload( int $id ): ?array {
	foreach ( fisha_studio_attributes_catalog() as $a ) {
		if ( $a['id'] !== $id ) continue;
		$usage = fisha_studio_attribute_usage( $a['name'] );
		foreach ( $a['terms'] as &$t ) {
			$term          = get_term( $t['id'], $a['name'] );
			$t['sizes']    = $usage[ $t['slug'] ] ?? 0;
			$t['products'] = ( $term && ! is_wp_error( $term ) ) ? (int) $term->count : 0;
		}
		return $a;
	}
	return null;
}

function fisha_studio_rest_attributes(): array {
	$out = array();
	foreach ( fisha_studio_attributes_catalog() as $a ) $out[] = fisha_studio_attribute_payload( $a['id'] );
	return array_values( array_filter( $out ) );
}

function fisha_studio_attribute_label_input( WP_REST_Request $r, string $key, int $max ) {
	$v = trim( sanitize_text_field( (string) ( fisha_studio_body( $r )[ $key ] ?? '' ) ) );
	if ( '' === $v || mb_strlen( $v ) > $max ) {
		$msg = sprintf( 'Use a name of 1 to %d characters.', $max );
		return new WP_Error( 'fisha_studio_invalid', $msg, array( 'status' => 422, 'fields' => array( $key => $msg ) ) );
	}
	return $v;
}

function fisha_studio_rest_attribute_add( WP_REST_Request $r ) {
	$label = fisha_studio_attribute_label_input( $r, 'label', 28 );
	if ( is_wp_error( $label ) ) return $label;
	$slug = wc_sanitize_taxonomy_name( $label );
	$id   = wc_create_attribute( array( 'name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ) );
	if ( is_wp_error( $id ) ) return new WP_Error( 'fisha_studio_invalid', $id->get_error_message(), array( 'status' => 422 ) );
	fisha_studio_ensure_attribute_tax( wc_attribute_taxonomy_name( $slug ) );
	fisha_studio_log( 'attribute', (int) $id, 'create', null, array( 'label' => $label ) );
	return new WP_REST_Response( fisha_studio_attribute_payload( (int) $id ), 201 );
}

function fisha_studio_rest_attribute_rename( WP_REST_Request $r ) {
	$id = (int) $r['id'];
	$a  = wc_get_attribute( $id );
	if ( ! $a ) return new WP_Error( 'fisha_studio_not_found', 'This option no longer exists.', array( 'status' => 404 ) );
	$label = fisha_studio_attribute_label_input( $r, 'label', 28 );
	if ( is_wp_error( $label ) ) return $label;
	$res = wc_update_attribute( $id, array( 'name' => $label, 'slug' => substr( $a->slug, 3 ), 'type' => $a->type, 'order_by' => $a->order_by, 'has_archives' => $a->has_archives ) );
	if ( is_wp_error( $res ) ) return new WP_Error( 'fisha_studio_invalid', $res->get_error_message(), array( 'status' => 422 ) );
	fisha_studio_log( 'attribute', $id, 'rename', array( 'label' => $a->name ), array( 'label' => $label ) );
	do_action( 'litespeed_purge_all' );
	return fisha_studio_attribute_payload( $id );
}

function fisha_studio_rest_attribute_term_add( WP_REST_Request $r ) {
	$tax = fisha_studio_attribute_tax( (int) $r['id'] );
	if ( ! $tax ) return new WP_Error( 'fisha_studio_not_found', 'This option no longer exists.', array( 'status' => 404 ) );
	fisha_studio_ensure_attribute_tax( $tax );
	$name = fisha_studio_attribute_label_input( $r, 'name', 40 );
	if ( is_wp_error( $name ) ) return $name;
	$existing = get_term_by( 'name', $name, $tax );
	if ( $existing ) return array( 'id' => (int) $existing->term_id, 'name' => $existing->name, 'slug' => $existing->slug );
	$t = wp_insert_term( $name, $tax );
	if ( is_wp_error( $t ) ) return new WP_Error( 'fisha_studio_invalid', $t->get_error_message(), array( 'status' => 422 ) );
	update_term_meta( (int) $t['term_id'], 'order', count( get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'ids' ) ) ) );
	$term = get_term( (int) $t['term_id'], $tax );
	fisha_studio_log( 'attribute', (int) $r['id'], 'add value', null, array( 'value' => $name ) );
	return array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug );
}

function fisha_studio_rest_attribute_order( WP_REST_Request $r ) {
	$id  = (int) $r['id'];
	$tax = fisha_studio_attribute_tax( $id );
	if ( ! $tax ) return new WP_Error( 'fisha_studio_not_found', 'This option no longer exists.', array( 'status' => 404 ) );
	$ids = array_values( array_unique( array_map( 'absint', (array) ( fisha_studio_body( $r )['term_ids'] ?? array() ) ) ) );
	foreach ( $ids as $tid ) {
		$t = get_term( $tid, $tax );
		if ( ! $t || is_wp_error( $t ) ) return new WP_Error( 'fisha_studio_invalid', 'The order could not be saved. Please reload the page.', array( 'status' => 422 ) );
	}
	foreach ( $ids as $i => $tid ) update_term_meta( $tid, 'order', $i );
	fisha_studio_log( 'attribute', $id, 'reorder', null, array( 'term_ids' => $ids ) );
	do_action( 'litespeed_purge_all' );
	return fisha_studio_attribute_payload( $id );
}

/** An option value (a term of a pa_ taxonomy), or null. */
function fisha_studio_rest_attribute_term( WP_REST_Request $r ): ?WP_Term {
	$t = get_term( (int) $r['term'] );
	return ( $t instanceof WP_Term && 0 === strpos( $t->taxonomy, 'pa_' ) ) ? $t : null;
}

function fisha_studio_rest_attribute_term_rename( WP_REST_Request $r ) {
	$t = fisha_studio_rest_attribute_term( $r );
	if ( ! $t ) return new WP_Error( 'fisha_studio_not_found', 'This value no longer exists.', array( 'status' => 404 ) );
	$name = fisha_studio_attribute_label_input( $r, 'name', 40 );
	if ( is_wp_error( $name ) ) return $name;
	$res = wp_update_term( $t->term_id, $t->taxonomy, array( 'name' => $name ) );
	if ( is_wp_error( $res ) ) return new WP_Error( 'fisha_studio_invalid', $res->get_error_message(), array( 'status' => 422 ) );
	fisha_studio_log( 'attribute', $t->term_id, 'rename value', array( 'name' => $t->name ), array( 'name' => $name ) );
	do_action( 'litespeed_purge_all' );
	$fresh = get_term( $t->term_id, $t->taxonomy );
	return array( 'id' => (int) $fresh->term_id, 'name' => $fresh->name, 'slug' => $fresh->slug );
}

function fisha_studio_rest_attribute_term_delete( WP_REST_Request $r ) {
	$t = fisha_studio_rest_attribute_term( $r );
	if ( ! $t ) return new WP_Error( 'fisha_studio_not_found', 'This value no longer exists.', array( 'status' => 404 ) );
	$product_ids = array_map( 'intval', get_objects_in_term( $t->term_id, $t->taxonomy ) );
	foreach ( get_posts( array( 'post_type' => 'product_variation', 'post_status' => array( 'publish', 'private' ), 'numberposts' => -1, 'fields' => 'id=>parent', 'meta_key' => 'attribute_' . $t->taxonomy, 'meta_value' => $t->slug ) ) as $parent ) {
		$product_ids[] = (int) $parent;
	}
	$product_ids = array_values( array_unique( array_filter( $product_ids ) ) );
	if ( $product_ids ) {
		$names = array();
		foreach ( array_slice( $product_ids, 0, 10 ) as $pid ) $names[] = get_the_title( $pid );
		return new WP_Error( 'fisha_studio_in_use', sprintf( '%s is used by %s. Remove it from those products first.', $t->name, implode( ', ', $names ) ), array( 'status' => 409, 'products' => $names ) );
	}
	wp_delete_term( $t->term_id, $t->taxonomy );
	fisha_studio_log( 'attribute', $t->term_id, 'delete value', array( 'name' => $t->name ), null );
	return array( 'deleted' => true );
}
```

Append to `studio/api/api.php`:
```php
require_once __DIR__ . '/api-attributes.php';
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh "api attributes"` → `4 passed`; full suite passes. Confirm no test option was left behind: `MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp eval 'foreach ( wc_get_attribute_taxonomies() as $a ) echo $a->attribute_label, "\n";'` → no `zz-test` labels.

- [ ] **Step 5: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio REST: shop-wide options and their values

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: REST for the Inventory screen

**Files:**
- Create: `studio/api/api-inventory.php`
- Modify: `studio/api/api.php` (require)
- Test: `tests/studio/test-api-inventory.php`

**Interfaces:**
- Consumes: `fisha_studio_variation_quick()` (Task 4), `fisha_studio_low_threshold()`, `fisha_studio_variation_label()`, `fisha_studio_variation_ids()`.
- Produces:
  - `fisha_studio_inventory_row( WC_Product $item, ?WC_Product $parent = null ): array` = `{kind:'product'|'variation', id, product_id, name, option, sku, enabled, manage_stock, stock_quantity, stock_status, regular_price, low, thumb, locked:''|reason, pos}`
  - `GET /inventory?search=&category=&stock=all|instock|outofstock|onbackorder|low&sort=name|stock|sku|price&order=asc|desc&page=&per_page=` (≤ 200, default 100) → `{items, total, pages}`. Rows: every simple product and every size of every product with sizes (drafts included, marked `locked`). Default sort keeps sizes in their shop order under their product.
  - `POST /variations/{vid}/quick {fields, seen}` → inventory row (errors as Task 4).

- [ ] **Step 1: Write the failing tests**

`tests/studio/test-api-inventory.php`:
```php
<?php
fs_test( 'api inventory: products and each size, sizes in shop order', function () {
	$simple = fs_product( array( 'manage_stock' => true, 'stock_quantity' => 5 ) );
	$p      = fs_variable( array( 's', 'm', 'l' ) );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $d ) = fs_api( 'GET', '/inventory', array( 'search' => $p->get_name() ) );
	fs_eq( 200, $st );
	fs_eq( array( 'S', 'M', 'L' ), array_map( 'strtoupper', wp_list_pluck( $d['items'], 'option' ) ) );
	fs_eq( 'variation', $d['items'][0]['kind'] );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'search' => $simple->get_name() ) );
	fs_eq( 'product', $d['items'][0]['kind'] );
	fs_eq( 5, $d['items'][0]['stock_quantity'] );
} );

fs_test( 'api inventory: filters, sort by stock, search by size SKU, locked rows', function () {
	$p = fs_variable( array( 's', 'm' ) );
	$s = fs_var_id( $p, 's' );
	$m = fs_var_id( $p, 'm' );
	foreach ( array( $s => 0, $m => 7 ) as $vid => $q ) {
		$v = wc_get_product( $vid );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( $q );
		$v->set_sku( 'ZZ-TEST-INV-' . $vid );
		$v->save();
	}
	fs_as( FISHA_STUDIO_ROLE );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'search' => $p->get_name(), 'sort' => 'stock' ) );
	fs_eq( array( $s, $m ), wp_list_pluck( $d['items'], 'id' ), 'sold out first' );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'stock' => 'outofstock', 'per_page' => 200 ) );
	fs_true( in_array( $s, wp_list_pluck( $d['items'], 'id' ), true ) );
	fs_false( in_array( $m, wp_list_pluck( $d['items'], 'id' ), true ) );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'search' => 'ZZ-TEST-INV-' . $m ) );
	fs_eq( array( $m ), wp_list_pluck( $d['items'], 'id' ) );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'meta_line' => 'pending' ) );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'search' => $p->get_name() ) );
	fs_eq( 'Unpublished changes', $d['items'][0]['locked'] );
} );

fs_test( 'api inventory: size quick edit returns the new row, refuses stale values', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	$v = wc_get_product( $s );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 2 );
	$v->save();
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $row ) = fs_api( 'POST', "/variations/$s/quick", array( 'fields' => array( 'stock_quantity' => 6 ), 'seen' => array( 'stock_quantity' => 2 ) ) );
	fs_eq( 200, $st, wp_json_encode( $row ) );
	fs_eq( 6, $row['stock_quantity'] );
	wc_update_product_stock( $s, 5, 'set' );
	list( $st, $d ) = fs_api( 'POST', "/variations/$s/quick", array( 'fields' => array( 'stock_quantity' => 9 ), 'seen' => array( 'stock_quantity' => 6 ) ) );
	fs_eq( 409, $st );
	fs_eq( 'fisha_studio_conflict', $d['code'] );
	fs_eq( 5, wc_get_product( $s )->get_stock_quantity() );
} );
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/studio/run.sh "api inventory"` → FAIL (`rest_no_route`).

- [ ] **Step 3: Implement**

`studio/api/api-inventory.php`:
```php
<?php
/**
 * Fisha Studio REST: the Inventory screen. Every simple product and every size in one list, for a quick stock count or
 * price update; each change goes live at once and is refused if it changed on the site since the list loaded.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/inventory', array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_inventory', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, '/variations/(?P<vid>\d+)/quick', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_variation_quick', 'permission_callback' => fisha_studio_permission( 'publish_products' ) ) );
} );

function fisha_studio_inventory_row( WC_Product $item, ?WC_Product $parent = null ): array {
	$p      = $parent ?: $item;
	$locked = '';
	if ( fisha_studio_is_new( $p ) ) $locked = 'Not published yet';
	elseif ( $parent && '1' === get_post_meta( $item->get_id(), FISHA_STUDIO_PENDING_META, true ) ) $locked = 'New size, not published yet';
	elseif ( fisha_studio_draft_get( $p->get_id() )['fields'] ) $locked = 'Unpublished changes';
	$manage = $parent ? true === $item->get_manage_stock( 'edit' ) : (bool) $item->get_manage_stock();
	$qty    = $manage ? $item->get_stock_quantity() : null;
	$img    = $item->get_image_id() ?: $p->get_image_id();
	return array(
		'kind'           => $parent ? 'variation' : 'product',
		'id'             => $item->get_id(),
		'product_id'     => $p->get_id(),
		'name'           => $p->get_name( 'edit' ),
		'option'         => $parent ? fisha_studio_variation_label( $item ) : '',
		'sku'            => (string) $item->get_sku( 'edit' ),
		'enabled'        => ! $parent || 'publish' === $item->get_status( 'edit' ),
		'manage_stock'   => $manage,
		'stock_quantity' => $qty,
		'stock_status'   => $item->get_stock_status( 'edit' ),
		'regular_price'  => (string) $item->get_regular_price( 'edit' ),
		'low'            => null !== $qty && $qty > 0 && $qty <= fisha_studio_low_threshold( $item ),
		'thumb'          => $img ? (string) wp_get_attachment_image_url( $img, 'thumbnail' ) : '',
		'locked'         => $locked,
		'pos'            => 0,
	);
}

function fisha_studio_rest_inventory( WP_REST_Request $r ): array {
	$args = array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => array( 'title' => 'ASC' ) );
	if ( $r['category'] ) $args['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'terms' => (int) $r['category'], 'include_children' => true ) );
	$rows = array();
	foreach ( ( new WP_Query( $args ) )->posts as $pid ) {
		$p = wc_get_product( (int) $pid );
		if ( ! $p ) continue;
		if ( $p->is_type( 'simple' ) ) {
			$rows[] = fisha_studio_inventory_row( $p );
		} elseif ( $p->is_type( 'variable' ) ) {
			foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v ) $rows[] = fisha_studio_inventory_row( $v, $p );
			}
		}
	}
	foreach ( $rows as $i => &$row ) $row['pos'] = $i;
	unset( $row );

	$search = mb_strtolower( trim( sanitize_text_field( (string) $r['search'] ) ) );
	$stock  = (string) ( $r['stock'] ?: 'all' );
	$rows   = array_values( array_filter( $rows, function ( $row ) use ( $search, $stock ) {
		if ( '' !== $search && false === mb_strpos( mb_strtolower( $row['name'] . ' ' . $row['option'] . ' ' . $row['sku'] ), $search ) ) return false;
		if ( 'low' === $stock ) return $row['low'];
		if ( in_array( $stock, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) return $row['stock_status'] === $stock;
		return true;
	} ) );

	$sort = in_array( $r['sort'], array( 'name', 'stock', 'sku', 'price' ), true ) ? $r['sort'] : 'name';
	$dir  = 'desc' === $r['order'] ? -1 : 1;
	$rank = function ( $row ) { // counted stock, else sold out lowest, other statuses highest
		if ( null !== $row['stock_quantity'] ) return (int) $row['stock_quantity'];
		return 'outofstock' === $row['stock_status'] ? -1 : PHP_INT_MAX;
	};
	usort( $rows, function ( $a, $b ) use ( $sort, $dir, $rank ) {
		switch ( $sort ) {
			case 'stock': $c = $rank( $a ) <=> $rank( $b ); break;
			case 'sku':   $c = strnatcasecmp( $a['sku'], $b['sku'] ); break;
			case 'price': $c = (float) $a['regular_price'] <=> (float) $b['regular_price']; break;
			default:      $c = strnatcasecmp( $a['name'], $b['name'] );
		}
		return $c ? $dir * $c : $a['pos'] <=> $b['pos'];
	} );

	$per  = min( 200, max( 1, (int) ( $r['per_page'] ?: 100 ) ) );
	$page = max( 1, (int) ( $r['page'] ?: 1 ) );
	return array( 'items' => array_slice( $rows, ( $page - 1 ) * $per, $per ), 'total' => count( $rows ), 'pages' => (int) ceil( count( $rows ) / $per ) );
}

function fisha_studio_rest_variation_quick( WP_REST_Request $r ) {
	$b   = fisha_studio_body( $r );
	$res = fisha_studio_variation_quick( (int) $r['vid'], (array) ( $b['fields'] ?? array() ), (array) ( $b['seen'] ?? array() ) );
	if ( is_wp_error( $res ) ) return $res;
	return fisha_studio_inventory_row( $res, wc_get_product( $res->get_parent_id() ) );
}
```

Append to `studio/api/api.php`:
```php
require_once __DIR__ . '/api-inventory.php';
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/studio/run.sh "api inventory"` → `3 passed`; full suite passes.

- [ ] **Step 5: Commit**

```bash
git add tests/studio wp-content/mu-plugins/fisha-design/studio
git commit -m "Studio REST: inventory of every product and size, quick edit of a size

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 9: Editor and list — sizes tab, full "New product", no SEO/Fisha design tabs

**Files:**
- Create: `studio/app/js/views/product-variations.js`
- Modify: `studio/app/js/views/product-editor.js`, `studio/app/js/views/products-list.js`, `studio/app/studio.css`

**Interfaces:**
- Consumes: REST from Task 6 (payload `type`, `variations`, `values` with `attributes` and `var:` keys; routes `/type`, `/variations/generate`, `DELETE /variations/{vid}`), `POST /attributes/{id}/terms` (Task 7), lookups `attributes` catalog.
- Produces: `variationsPanel( ctx ): { el, render(), setError( key, msg ), focus( key ), clearErrors() }` where `ctx = { productId, values(): object, payload(): object, change( key, value ), flush(): Promise<boolean>, apply( payload ), saved( payload ), catalog: array, currency: string, images(): [{id}] }`.

- [ ] **Step 1: `studio/app/js/views/product-variations.js`**

```js
/**
 * Fisha Studio "Sizes & options" tab for products with sizes: the options shoppers choose (Size: S, M, L…) and one
 * row per size with its own on/off, price, sale price, stock, SKU and photo. Every change is a normal draft edit
 * ("attributes" or "var:{id}:{field}"), so autosave, preview, conflicts and Publish work as for any other field.
 */
import { h, toast, modal } from '../ui.js';
import { api } from '../api.js';

const STATUSES = [ [ 'instock', 'In stock' ], [ 'outofstock', 'Sold out' ], [ 'onbackorder', 'Made to order' ] ];

export function variationsPanel( ctx ) {
	const el = h( 'div.fs-vars' );
	const errBox = h( 'p.fs-field__error', { role: 'alert', hidden: true } );
	const inputs = {};
	let signature = '';
	const key = ( vid, f ) => `var:${ vid }:${ f }`;

	function sig() {
		return JSON.stringify( [ ctx.payload().variations.map( ( s ) => [ s.id, s.pending ] ), ctx.values().attributes ] );
	}

	/** Rebuilds when sizes or options changed; otherwise only refreshes values of inputs not being typed in. */
	function render() {
		const s = sig();
		if ( s === signature && el.childElementCount ) {
			const v = ctx.values();
			for ( const [ k, w ] of Object.entries( inputs ) ) if ( k in v ) w.set( v[ k ] );
			return;
		}
		signature = s;
		for ( const k of Object.keys( inputs ) ) delete inputs[ k ];
		el.replaceChildren( errBox, optionsSection(), sizesSection() );
	}

	/* ---------- options ---------- */

	function setAttributes( list ) {
		ctx.change( 'attributes', list );
		render();
	}

	function optionsSection() {
		const list = ctx.values().attributes || [];
		const used = new Set( list.map( ( a ) => a.name ) );
		const add = h( 'select.fs-input', { 'aria-label': 'Add an option', onchange: () => {
			const v = add.value;
			add.value = '';
			if ( v ) addAttribute( v );
		} },
		h( 'option', { value: '' }, '+ Add an option…' ),
		ctx.catalog.filter( ( c ) => ! used.has( c.name ) ).map( ( c ) => h( 'option', { value: c.name }, c.label ) ),
		h( 'option', { value: '__custom' }, 'Something else, only for this product…' ) );
		return h( 'section.fs-vars__options', {},
			h( 'h2', {}, 'Options shoppers choose' ),
			h( 'p.fs-field__help', {}, 'For example Size. Pick the values this product comes in, then create the sizes below.' ),
			list.map( ( a, i ) => attributeCard( a, i, list ) ),
			add );
	}

	async function addAttribute( name ) {
		const list = [ ...( ctx.values().attributes || [] ) ];
		if ( name === '__custom' ) {
			const label = await modal( {
				title: 'New option for this product',
				body: h( 'label.fs-field__label', {}, 'Name', h( 'input.fs-input', { name: 'n', required: true, maxlength: 40, placeholder: 'For example Colour' } ) ),
				actions: [ { label: 'Cancel', value: null }, { label: 'Add', kind: 'primary', submit: true, value: ( f ) => f.elements.n.value.trim() } ],
			} );
			if ( ! label ) return;
			list.push( { name: label, label, taxonomy: false, options: [], variation: true, visible: true } );
		} else {
			const c = ctx.catalog.find( ( x ) => x.name === name );
			list.push( { name, label: c ? c.label : name, taxonomy: true, options: [], variation: true, visible: true } );
		}
		setAttributes( list );
	}

	function checkbox( label, on, onChange ) {
		const b = h( 'input', { type: 'checkbox', checked: !! on, onchange: () => onChange( b.checked ) } );
		return h( 'label.fs-check', {}, b, label );
	}

	function attributeCard( a, i, list ) {
		const update = ( patch ) => setAttributes( list.map( ( x, j ) => ( j === i ? { ...x, ...patch } : x ) ) );
		let values;
		if ( a.taxonomy ) {
			const c = ctx.catalog.find( ( x ) => x.name === a.name ) || { id: 0, terms: [] };
			const chosen = new Set( a.options.map( Number ) );
			const newValue = h( 'input.fs-input.fs-input--small', { placeholder: 'New value', 'aria-label': `New value for ${ a.label }` } );
			newValue.addEventListener( 'keydown', async ( e ) => {
				if ( e.key !== 'Enter' ) return;
				e.preventDefault();
				const name = newValue.value.trim();
				if ( ! name || ! c.id ) return;
				try {
					const t = await api( 'POST', `attributes/${ c.id }/terms`, { name } );
					if ( ! c.terms.some( ( x ) => x.id === t.id ) ) c.terms.push( t );
					update( { options: [ ...new Set( [ ...a.options.map( Number ), t.id ] ) ] } );
					toast( `${ t.name } added to ${ a.label }.` );
				} catch ( err ) {
					toast( err.message, 'error' );
				}
			} );
			values = h( 'div.fs-chips', { role: 'group', 'aria-label': `${ a.label } values` },
				c.terms.map( ( t ) => h( 'button.fs-chip.fs-chip--toggle', { type: 'button', 'aria-pressed': String( chosen.has( t.id ) ), onclick: () => update( {
					options: chosen.has( t.id ) ? a.options.filter( ( o ) => Number( o ) !== t.id ) : [ ...a.options, t.id ],
				} ) }, t.name ) ),
				newValue );
		} else {
			const add = h( 'input.fs-input.fs-input--small', { placeholder: 'Add a value and press Enter', 'aria-label': `Add a value to ${ a.label }` } );
			add.addEventListener( 'keydown', ( e ) => {
				if ( e.key !== 'Enter' ) return;
				e.preventDefault();
				const v = add.value.trim();
				if ( v && ! a.options.includes( v ) ) update( { options: [ ...a.options, v ] } );
			} );
			values = h( 'div.fs-chips', {},
				a.options.map( ( o ) => h( 'span.fs-chip', {}, o, h( 'button', { type: 'button', 'aria-label': `Remove ${ o }`, onclick: () => update( { options: a.options.filter( ( x ) => x !== o ) } ) }, '×' ) ) ),
				add );
		}
		return h( 'div.fs-vars__attr', {},
			h( 'div.fs-vars__attr-head', {},
				h( 'strong', {}, a.label ),
				h( 'button.fs-btn.fs-btn--tiny.fs-btn--danger', { type: 'button', onclick: () => setAttributes( list.filter( ( _, j ) => j !== i ) ) }, 'Remove option' ) ),
			values,
			h( 'div.fs-vars__flags', {},
				checkbox( 'Used for sizes', a.variation, ( on ) => update( { variation: on } ) ),
				checkbox( 'Show on the product page', a.visible, ( on ) => update( { visible: on } ) ) ) );
	}

	/* ---------- sizes ---------- */

	function setField( vid, f, value ) {
		ctx.change( key( vid, f ), value );
		inputs[ key( vid, f ) ]?.set( value );
	}

	function sizesSection() {
		const d = ctx.payload();
		const make = h( 'button.fs-btn', { type: 'button', onclick: generate }, 'Create missing sizes' );
		if ( ! d.variations.length ) {
			return h( 'section.fs-vars__sizes', {}, h( 'h2', {}, 'Sizes' ), h( 'p.fs-empty', {}, 'No sizes yet. Choose the values above, then create the sizes.' ), make );
		}
		const bulkPrice = h( 'input.fs-input.fs-input--small', { inputmode: 'decimal', placeholder: 'Price', 'aria-label': 'Price for every size' } );
		const bulkStock = h( 'input.fs-input.fs-input--small', { inputmode: 'numeric', placeholder: '+ stock', 'aria-label': 'Add to the stock of every counted size' } );
		const bulk = h( 'div.fs-vars__bulk', {},
			bulkPrice,
			h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: () => {
				const x = bulkPrice.value.trim();
				if ( ! x ) return;
				d.variations.forEach( ( s ) => setField( s.id, 'regular_price', x ) );
				bulkPrice.value = '';
			} }, 'Set every price' ),
			bulkStock,
			h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: () => {
				const n = parseInt( bulkStock.value, 10 );
				if ( ! n ) return;
				const v = ctx.values();
				d.variations.forEach( ( s ) => {
					if ( v[ key( s.id, 'manage_stock' ) ] ) setField( s.id, 'stock_quantity', Math.max( 0, ( Number( v[ key( s.id, 'stock_quantity' ) ] ) || 0 ) + n ) );
				} );
				bulkStock.value = '';
			} }, 'Add to stock' ),
			h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: () => d.variations.forEach( ( s ) => setField( s.id, 'enabled', true ) ) }, 'All on' ),
			h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: () => d.variations.forEach( ( s ) => setField( s.id, 'enabled', false ) ) }, 'All off' ) );
		return h( 'section.fs-vars__sizes', {}, h( 'h2', {}, 'Sizes' ), bulk, h( 'div.fs-vars__rows', {}, d.variations.map( row ) ), make );
	}

	function row( s ) {
		const v = ctx.values();
		const k = ( f ) => key( s.id, f );
		const keep = ( input, fn ) => ( x ) => { if ( document.activeElement !== input ) fn( x ); };
		const reg = ( f, input, set ) => {
			inputs[ k( f ) ] = { set, focus: () => input.focus() };
			return input;
		};
		const on = h( 'input.fs-switch', { type: 'checkbox', role: 'switch', checked: !! v[ k( 'enabled' ) ], 'aria-label': `${ s.label } on sale`, onchange: () => ctx.change( k( 'enabled' ), on.checked ) } );
		const price = h( 'input.fs-input.fs-inline', { inputmode: 'decimal', value: v[ k( 'regular_price' ) ] ?? '', 'aria-label': `${ s.label } price`, oninput: () => ctx.change( k( 'regular_price' ), price.value ) } );
		const sale = h( 'input.fs-input.fs-inline', { inputmode: 'decimal', value: v[ k( 'sale_price' ) ] ?? '', placeholder: 'Sale', 'aria-label': `${ s.label } sale price`, oninput: () => ctx.change( k( 'sale_price' ), sale.value ) } );
		const qty = h( 'input.fs-input.fs-inline.fs-inline--short', { inputmode: 'numeric', value: v[ k( 'stock_quantity' ) ] ?? '', 'aria-label': `${ s.label } in stock`, oninput: () => ctx.change( k( 'stock_quantity' ), qty.value ) } );
		const status = h( 'select.fs-input.fs-input--small', { 'aria-label': `${ s.label } stock status`, onchange: () => ctx.change( k( 'stock_status' ), status.value ) }, STATUSES.map( ( [ x, t ] ) => h( 'option', { value: x }, t ) ) );
		status.value = v[ k( 'stock_status' ) ] || 'instock';
		const paintStock = () => {
			const counted = !! ctx.values()[ k( 'manage_stock' ) ];
			qty.hidden = ! counted;
			status.hidden = counted;
		};
		const count = h( 'input', { type: 'checkbox', checked: !! v[ k( 'manage_stock' ) ], 'aria-label': `Count stock of ${ s.label }`, onchange: () => {
			ctx.change( k( 'manage_stock' ), count.checked );
			paintStock();
		} } );
		const sku = h( 'input.fs-input.fs-inline', { value: v[ k( 'sku' ) ] ?? '', placeholder: 'SKU', 'aria-label': `${ s.label } SKU`, oninput: () => ctx.change( k( 'sku' ), sku.value ) } );
		const photo = h( 'select.fs-input.fs-input--small', { 'aria-label': `${ s.label } photo`, onchange: () => ctx.change( k( 'image_id' ), Number( photo.value ) ) },
			h( 'option', { value: '0' }, 'Product photo' ),
			ctx.images().map( ( img, i ) => h( 'option', { value: String( img.id ) }, i ? `Photo ${ i + 1 }` : 'Main photo' ) ) );
		photo.value = String( v[ k( 'image_id' ) ] || 0 );
		paintStock();

		reg( 'enabled', on, ( x ) => { on.checked = !! x; } );
		reg( 'regular_price', price, keep( price, ( x ) => { price.value = x ?? ''; } ) );
		reg( 'sale_price', sale, keep( sale, ( x ) => { sale.value = x ?? ''; } ) );
		reg( 'manage_stock', count, ( x ) => { count.checked = !! x; paintStock(); } );
		reg( 'stock_quantity', qty, keep( qty, ( x ) => { qty.value = x ?? ''; } ) );
		reg( 'stock_status', status, ( x ) => { status.value = x || 'instock'; } );
		reg( 'sku', sku, keep( sku, ( x ) => { sku.value = x ?? ''; } ) );
		reg( 'image_id', photo, ( x ) => { photo.value = String( x || 0 ); } );

		const canDelete = s.pending || ctx.payload().state === 'new';
		return h( 'div.fs-vars__row', { dataset: { vid: s.id } },
			h( 'div.fs-vars__name', {}, on, h( 'strong', {}, s.label ), s.pending ? h( 'span.fs-badge.fs-badge--draft', {}, 'New' ) : null ),
			h( 'label.fs-vars__cell', {}, h( 'span.fs-vars__cap', {}, ctx.currency ), price ),
			h( 'label.fs-vars__cell', {}, sale ),
			h( 'div.fs-vars__cell', {}, h( 'label.fs-check', {}, count, 'Count' ), qty, status ),
			h( 'div.fs-vars__cell', {}, sku ),
			h( 'div.fs-vars__cell', {}, photo ),
			canDelete ? h( 'button.fs-btn.fs-btn--tiny.fs-btn--danger', { type: 'button', 'aria-label': `Delete ${ s.label }`, onclick: () => removeSize( s ) }, 'Delete' ) : null,
			h( 'p.fs-field__error.fs-vars__err', { hidden: true } ) );
	}

	/* ---------- actions ---------- */

	async function generate() {
		if ( ! ( await ctx.flush() ) ) {
			toast( 'Fix the highlighted fields first.', 'error' );
			return;
		}
		try {
			const d = await api( 'POST', `products/${ ctx.productId }/variations/generate` );
			ctx.apply( d );
			render();
			ctx.saved( d );
			toast( d.created ? `${ d.created } size${ d.created > 1 ? 's' : '' } added. Set their prices, then publish.` : 'Every combination already exists.' );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	async function removeSize( s ) {
		if ( ! ( await ctx.flush() ) ) return;
		try {
			const d = await api( 'DELETE', `products/${ ctx.productId }/variations/${ s.id }` );
			ctx.apply( d );
			render();
			ctx.saved( d );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	/* ---------- errors ---------- */

	function setError( k, msg ) {
		const m = /^var:(\d+):/.exec( k );
		const rowEl = m ? el.querySelector( `[data-vid="${ m[ 1 ] }"]` ) : null;
		const box = rowEl ? rowEl.querySelector( '.fs-vars__err' ) : errBox;
		box.hidden = ! msg;
		box.textContent = msg || '';
		rowEl?.classList.toggle( 'has-error', !! msg );
	}

	render();
	return {
		el,
		render,
		setError,
		focus: ( k ) => inputs[ k ]?.focus(),
		clearErrors() {
			el.querySelectorAll( '.fs-vars__err' ).forEach( ( b ) => { b.hidden = true; b.textContent = ''; } );
			el.querySelectorAll( '.fs-vars__row.has-error' ).forEach( ( r ) => r.classList.remove( 'has-error' ) );
			errBox.hidden = true;
		},
	};
}
```

- [ ] **Step 2: Change `studio/app/js/views/product-editor.js`**

1. Imports: replace `import { textField, selectField, toggleField, categoriesField, tagsField, colorField } from '../fields.js';` with
```js
import { textField, selectField, toggleField, categoriesField, tagsField } from '../fields.js';
import { variationsPanel } from './product-variations.js';
```
2. Replace the `TABS` and `TAB_OF` constants (lines 13–22) with:
```js
// The owner asked for the SEO and Fisha design tabs to be removed; the server keeps filling those fields with defaults.
const TABS = [ [ 'basics', 'Basics' ], [ 'price', 'Price' ], [ 'inventory', 'Inventory' ], [ 'images', 'Photos' ], [ 'shipping', 'Shipping & tax' ] ];
const TABS_SIZES = [ [ 'basics', 'Basics' ], [ 'variations', 'Sizes & options' ], [ 'inventory', 'Inventory' ], [ 'images', 'Photos' ], [ 'shipping', 'Shipping & tax' ] ];
const TAB_OF = {
	name: 'basics', slug: 'basics', status: 'basics', category_ids: 'basics', tag_ids: 'basics', short_description: 'basics', description: 'basics', catalog_visibility: 'basics', featured: 'basics', reviews_allowed: 'basics', menu_order: 'basics',
	regular_price: 'price', sale_price: 'price', date_on_sale_from: 'price', date_on_sale_to: 'price',
	sku: 'inventory', manage_stock: 'inventory', stock_quantity: 'inventory', stock_status: 'inventory', low_stock_amount: 'inventory', backorders: 'inventory', sold_individually: 'inventory',
	image_id: 'images', gallery_image_ids: 'images',
	weight: 'shipping', length: 'shipping', width: 'shipping', height: 'shipping', shipping_class_id: 'shipping', tax_status: 'shipping', tax_class: 'shipping',
};
const tabOf = ( key ) => ( 'attributes' === key || 'variations' === key || String( key ).startsWith( 'var:' ) ) ? 'variations' : ( TAB_OF[ key ] || 'basics' );
```
3. In `change()`, after `ed.widgets[ key ]?.setError( '' );` add `if ( varsPanel && 'variations' === tabOf( key ) ) varsPanel.setError( key, '' );`
4. In `apply()`, after the `for ( const [ key, w ] of Object.entries( ed.widgets ) ) { … }` loop add `varsPanel?.render();`
5. Replace `setError()` with:
```js
	function setError( key, msg ) {
		if ( varsPanel && 'variations' === tabOf( key ) ) varsPanel.setError( key, msg );
		else ed.widgets[ key === 'image_id' || key === 'gallery_image_ids' ? 'images' : key ]?.setError( msg );
		markTab( key, !! msg );
	}
```
6. In `clearErrors()` add as its first line `varsPanel?.clearErrors();`
7. In `markTab()` replace `TAB_OF[ key ]` with `tabOf( key )`.
8. In `showErrors()` replace the body of `if ( first ) { … }` with:
```js
			openTab( tabOf( first ) );
			if ( 'variations' === tabOf( first ) ) varsPanel?.focus( first );
			else ed.widgets[ first === 'image_id' || first === 'gallery_image_ids' ? 'images' : first ]?.focus();
```
9. In `renderHead()` replace the read-only note text with `'This kind of product can only be edited in WP admin.'`.
10. Replace `let urlBox = null;` with:
```js
	let urlBox = null;
	let varsPanel = null;
```
11. Replace the whole `function build( d ) { … }` with:
```js
	function build( d ) {
		const v = d.values;
		const L = lookups;
		const W = ed.widgets;
		const sizes = 'variable' === d.type;
		const tabs = sizes ? TABS_SIZES : TABS;
		if ( ! tabs.some( ( [ k ] ) => k === tab ) ) tab = 'basics';
		const on = ( key ) => ( value ) => change( key, value );
		const money = { inputmode: 'decimal', prefix: L.currency };

		W.name = textField( 'Name', v.name, on( 'name' ), { max: 120, placeholder: 'Product name' } );
		W.slug = urlBox = urlField( v.slug );
		W.status = selectField( 'Visibility', v.status, [ [ 'publish', 'Live in the shop' ], [ 'private', 'Hidden (only you can see it)' ] ], on( 'status' ) );
		W.category_ids = categoriesField( 'Categories', v.category_ids, L.categories, on( 'category_ids' ) );
		W.tag_ids = tagsField( 'Tags', v.tag_ids, L.tags, on( 'tag_ids' ), ( name ) => api( 'POST', 'tags', { name } ) );
		W.short_description = richText( 'Short description', v.short_description, on( 'short_description' ), { small: true, help: 'Shown next to the photos and used by Google.' } );
		W.description = richText( 'Full description', v.description, on( 'description' ), { help: 'Shown in the Description tab under the product.' } );
		W.catalog_visibility = selectField( 'Where it shows', v.catalog_visibility, [ [ 'visible', 'Shop and search' ], [ 'catalog', 'Shop only' ], [ 'search', 'Search only' ], [ 'hidden', 'Only by direct link' ] ], on( 'catalog_visibility' ) );
		W.featured = toggleField( 'Featured product', v.featured, on( 'featured' ) );
		W.reviews_allowed = toggleField( 'Allow reviews', v.reviews_allowed, on( 'reviews_allowed' ) );
		const typeField = d.state === 'new'
			? toggleField( 'Comes in sizes or options', sizes, ( yes ) => switchType( yes ? 'variable' : 'simple' ), { help: 'For example a T-shirt in S, M and L. Each size gets its own price and stock.' } )
			: null;

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

		W.weight = textField( 'Weight (kg)', v.weight, on( 'weight' ), { inputmode: 'decimal' } );
		W.length = textField( 'Length (cm)', v.length, on( 'length' ), { inputmode: 'decimal' } );
		W.width = textField( 'Width (cm)', v.width, on( 'width' ), { inputmode: 'decimal' } );
		W.height = textField( 'Height (cm)', v.height, on( 'height' ), { inputmode: 'decimal' } );
		W.shipping_class_id = selectField( 'Shipping class', v.shipping_class_id, [ [ '0', 'Standard' ], ...L.shipping_classes.map( ( s ) => [ String( s.id ), s.name ] ) ], on( 'shipping_class_id' ) );
		W.tax_status = selectField( 'Tax', v.tax_status, [ [ 'taxable', 'Taxable' ], [ 'shipping', 'Shipping only' ], [ 'none', 'No tax' ] ], on( 'tax_status' ) );
		W.tax_class = selectField( 'Tax class', v.tax_class, L.tax_classes.map( ( t ) => [ t.slug, t.name ] ), on( 'tax_class' ) );

		varsPanel = sizes ? variationsPanel( {
			productId: id,
			values: () => ed.data.values,
			payload: () => ed.data,
			change,
			flush,
			apply,
			saved: ( x ) => ed.hooks.saved.forEach( ( f ) => f( x ) ),
			catalog: L.attributes || [],
			currency: L.currency,
			images: () => [ ed.data.values.image_id, ...( ed.data.values.gallery_image_ids || [] ) ].filter( Boolean ).map( ( x ) => ( { id: x } ) ),
		} ) : null;

		const panel = ( key, ...children ) => h( 'section.fs-panel', { role: 'tabpanel', id: 'fs-panel-' + key, 'aria-labelledby': 'fs-tab-' + key, dataset: { tab: key }, hidden: key !== tab }, children );
		tabBar.replaceChildren( ...tabs.map( ( [ key, label ] ) => h( 'button.fs-tab', { type: 'button', role: 'tab', id: 'fs-tab-' + key, 'aria-controls': 'fs-panel-' + key, 'aria-selected': String( key === tab ), dataset: { tab: key }, onclick: () => openTab( key ) }, label ) ) );
		const readOnly = ! d.editable;
		panels.replaceChildren(
			panel( 'basics', W.name.el, typeField?.el, W.slug.el, W.status.el, W.category_ids.el, W.short_description.el, W.description.el, W.tag_ids.el,
				h( 'div.fs-grid-2', {}, W.catalog_visibility.el, h( 'div', {}, W.featured.el, W.reviews_allowed.el ) ),
				readOnly ? null : h( 'div.fs-danger-zone', {}, h( 'button.fs-btn.fs-btn--danger', { type: 'button', onclick: trash }, d.state === 'new' ? 'Delete this draft' : 'Move to trash' ) ) ),
			sizes
				? panel( 'variations', varsPanel.el )
				: panel( 'price', onRequestField.el, h( 'div.fs-grid-2', {}, W.regular_price.el, W.sale_price.el ), h( 'div.fs-grid-2', {}, W.date_on_sale_from.el, W.date_on_sale_to.el ) ),
			sizes
				? panel( 'inventory', W.sku.el, W.sold_individually.el, h( 'p.fs-field__help', {}, 'Stock is set for each size in Sizes & options, or for many products at once on the Inventory page.' ) )
				: panel( 'inventory', h( 'div.fs-stock-actions', {}, soldOut, backIn ), W.manage_stock.el, h( 'div.fs-grid-2', {}, W.stock_quantity.el, W.low_stock_amount.el ), W.stock_status.el, W.backorders.el, W.sold_individually.el, W.sku.el ),
			panel( 'images', W.images.el ),
			panel( 'shipping', h( 'div.fs-grid-4', {}, W.weight.el, W.length.el, W.width.el, W.height.el ), W.shipping_class_id.el, h( 'div.fs-grid-2', {}, W.tax_status.el, W.tax_class.el ) ) );
		if ( readOnly ) panels.querySelectorAll( 'input, select, textarea, button, [contenteditable]' ).forEach( ( x ) => {
			if ( x.hasAttribute( 'contenteditable' ) ) x.setAttribute( 'contenteditable', 'false' );
			else x.disabled = true;
		} );
		refreshDerived();
	}

	/** Rebuilds the whole editor from a payload (after the product type changed). */
	function reload( d ) {
		ed.data = d;
		derived.length = 0;
		for ( const k of Object.keys( ed.widgets ) ) delete ed.widgets[ k ];
		renderHead();
		build( d );
		ed.hooks.saved.forEach( ( f ) => f( d ) );
	}

	async function switchType( type ) {
		if ( ! ( await flush() ) ) {
			reload( ed.data );
			return;
		}
		if ( 'simple' === type && ( ed.data.variations || [] ).length && ! ( await confirmDialog( 'Remove the sizes?', 'This product will be one item again, and the sizes made for it are deleted.', 'Remove sizes', 'danger' ) ) ) {
			reload( ed.data );
			return;
		}
		try {
			reload( await api( 'POST', `products/${ id }/type`, { type } ) );
		} catch ( e ) {
			toast( e.message, 'error' );
			reload( ed.data );
		}
	}
```
12. Replace the returned `destroy()` with:
```js
		destroy() {
			ed.alive = false;
			autosave.cancel();
			// "New product" makes a draft at once; one left completely empty is removed when the owner walks away.
			const v = ed.data?.values;
			const blank = v && ! v.name && ! v.image_id && ! v.short_description && ! v.regular_price && ! ( ed.data.variations || [] ).length;
			if ( ed.data?.state === 'new' && blank && ! Object.keys( ed.pending ).length ) api( 'POST', `products/${ id }/trash` ).catch( () => {} );
		},
```

- [ ] **Step 3: Change `studio/app/js/views/products-list.js`**

1. In `badges()` replace `if ( ! r.editable ) b.push( [ 'Has sizes', 'info' ] );` with:
```js
		if ( r.sizes ) b.push( [ 'Sizes', 'info' ] );
		else if ( ! r.editable ) b.push( [ 'WP admin only', 'info' ] );
```
2. In `row()` replace `const quick = r.editable && r.state === 'live';` with `const quick = r.editable && ! r.sizes && r.state === 'live';`, and replace the start of the `stock` expression `const stock = quick && r.manage_stock` with:
```js
		const stock = r.sizes
			? h( 'span', {}, r.stock_text )
			: quick && r.manage_stock
```
(the rest of that expression stays: `? h( 'input…' ) : h( 'span', … )`).
3. Replace the whole `async function createProduct() { … }` with:
```js
	async function createProduct() {
		try {
			const p = await api( 'POST', 'products', { name: '' } );
			location.hash = '#/products/' + p.id;
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}
```
and remove `modal` from this file's `ui.js` import if it is no longer used.

- [ ] **Step 4: Append to `studio/app/studio.css`** (before the final `@media (pointer: coarse), (max-width: 760px)` block, and add the new small controls to that block)

```css
/* Sizes & options */
.fs-vars { display: grid; gap: 1.5rem; }
.fs-vars h2 { font-size: 1.05rem; margin: 0 0 .3rem; }
.fs-vars__attr { border: 1px solid var(--fs-line); border-radius: 12px; padding: .8rem 1rem; background: #fff; display: grid; gap: .6rem; margin-bottom: .6rem; }
.fs-vars__attr-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
.fs-vars__flags { display: flex; flex-wrap: wrap; gap: .25rem 1rem; }
.fs-chip--toggle { border: 1px solid var(--fs-line); background: #fff; color: var(--fs-ink); padding: .25rem .8rem; min-height: 36px; cursor: pointer; }
.fs-chip--toggle[aria-pressed="true"] { background: var(--fs-ink); border-color: var(--fs-ink); color: #fff; }
.fs-vars__bulk { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; margin-bottom: .6rem; }
.fs-vars__bulk .fs-input--small { width: 7rem; }
.fs-vars__rows { display: grid; gap: .4rem; margin-bottom: .8rem; }
.fs-vars__row { display: grid; grid-template-columns: minmax(9rem, 1.2fr) repeat(5, minmax(6rem, 1fr)) auto; align-items: center; gap: .5rem; padding: .5rem .6rem; border: 1px solid var(--fs-line); border-radius: 10px; background: #fff; }
.fs-vars__row.has-error { border-color: var(--fs-red); }
.fs-vars__name { display: flex; align-items: center; gap: .5rem; }
.fs-vars__cell { display: flex; align-items: center; gap: .35rem; min-width: 0; }
.fs-vars__cell .fs-inline, .fs-vars__cell .fs-input--small { width: 100%; }
.fs-vars__cap { color: var(--fs-muted); font-weight: 700; }
.fs-vars__err { grid-column: 1 / -1; }
@media (max-width: 900px) {
	.fs-vars__row { grid-template-columns: 1fr 1fr; }
	.fs-vars__name { grid-column: 1 / -1; }
}
```
In the existing `@media (pointer: coarse), (max-width: 760px)` block add `.fs-chip--toggle` to the `min-height: 44px` selector list.

- [ ] **Step 5: Check in the browser (test owner, `docker exec fisha-wordpress-1 apachectl graceful` first)**

1. Products → "+ New product" → the full editor opens at once (no popup): Basics shows Name (empty, placeholder "Product name"), "Comes in sizes or options", address, categories, descriptions; tabs are Basics, Price, Inventory, Photos, Shipping & tax (no SEO, no Fisha design).
2. Leave right away (← All products) without typing → the empty draft is gone from the list (Status: Drafts filter shows nothing new).
3. "+ New product" again → name "Test Studio Tee" → switch on "Comes in sizes or options" → the editor rebuilds with a "Sizes & options" tab instead of Price.
4. Sizes & options → "+ Add an option…" → Size → tap S, M, L → "Create missing sizes" → three rows appear; "Set every price" 119 → each row shows 119; turn "Count" on for M and type 3.
5. Add a value "XXL" in the Size card (Enter) → toast "XXL added to Size", chip selected → "Create missing sizes" → a fourth row; delete it (Delete) → gone.
6. Photo, category Originals or T-shirts, short description → preview shows the product page with the size picker and "119" → Publish to the shop → the public page offers S, M, L at 119, M with stock 3.
7. On a live product with sizes (e.g. "Big Fish Tee - Black"): change M's price to 125 → preview shows 125 for M and the range; the public page (private window) still shows the old price → Publish → public page shows 125. Put it back to the original price and publish again.
8. Switch L off on that product → preview no longer offers L → Discard changes → L is back.
9. In a terminal, change a size's stock while its stock edit is unpublished (`wc_update_product_stock( <size id>, 1, "set" )` via the WP-CLI form in context.md) → Publish → the conflict dialog names the size ("M: Stock").
10. Product list: variable rows show "Sizes" and a stock summary ("S 3 · M 0" or "4 of 4 in stock"), no quick-edit boxes; simple rows still quick-edit.
11. Phone width (375×812): size rows stack into two columns, no horizontal scroll; reset to desktop.
12. Trash "Test Studio Tee". `read_console_messages` (errors only) → none.

- [ ] **Step 6: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/app
git commit -m "Studio UI: sizes & options tab, full new-product editor, SEO and design tabs removed

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Inventory screen

**Files:**
- Create: `studio/app/js/views/inventory.js`
- Modify: `studio/app/js/app.js` (route + nav), `studio/app/studio.css` (small additions)

**Interfaces:**
- Consumes: `GET /inventory`, `POST /variations/{vid}/quick`, `POST /products/{id}/quick` (phase 1, returns a product row; the screen reloads after it), `GET /lookups`.
- Produces: `inventoryView( main ): { destroy() }`; route `#/inventory`; nav item "Inventory".

- [ ] **Step 1: `studio/app/js/views/inventory.js`**

```js
/**
 * Fisha Studio Inventory: every product and every size in one table, for a stock count or price update. A change
 * goes live as soon as it is saved, and is refused if it changed on the site since the list loaded (an order).
 */
import { h, toast, debounce } from '../ui.js';
import { api } from '../api.js';

const STATUS = { instock: 'In stock', outofstock: 'Sold out', onbackorder: 'Made to order' };

export function inventoryView( main ) {
	const state = { search: '', category: '', stock: 'all', sort: 'name', page: 1 };
	try { Object.assign( state, JSON.parse( sessionStorage.getItem( 'fs-inv' ) || '{}' ) ); } catch {}
	let alive = true;
	let seq = 0;
	const list = h( 'div.fs-list' );
	const pager = h( 'div.fs-pager' );
	const select = ( key, label, options ) => h( 'select.fs-input', { 'aria-label': label, onchange: ( e ) => update( { [ key ]: e.target.value } ) },
		options.map( ( [ v, t ] ) => h( 'option', { value: v, selected: state[ key ] === v }, t ) ) );
	const catSelect = h( 'select.fs-input', { 'aria-label': 'Category', onchange: ( e ) => update( { category: e.target.value } ) }, h( 'option', { value: '' }, 'All categories' ) );
	const search = debounce( ( value ) => update( { search: value } ), 300 );

	main.append(
		h( 'div.fs-page-head', {}, h( 'h1', {}, 'Inventory' ) ),
		h( 'p.fs-field__help', {}, 'Change a number and press Enter or Save. It goes live straight away.' ),
		h( 'div.fs-filters', {},
			h( 'input.fs-input.fs-search', { type: 'search', placeholder: 'Search by name, size or SKU', value: state.search, 'aria-label': 'Search', oninput: ( e ) => search( e.target.value ) } ),
			catSelect,
			select( 'stock', 'Stock', [ [ 'all', 'Any stock' ], [ 'low', 'Low stock' ], [ 'outofstock', 'Sold out' ], [ 'instock', 'In stock' ] ] ),
			select( 'sort', 'Sort by', [ [ 'name', 'Name' ], [ 'stock', 'Lowest stock first' ], [ 'sku', 'SKU' ], [ 'price', 'Price' ] ] ) ),
		list,
		pager );

	api( 'GET', 'lookups' ).then( ( l ) => {
		for ( const c of l.categories ) catSelect.append( h( 'option', { value: c.id, selected: String( state.category ) === String( c.id ) }, c.path ) );
	} ).catch( () => {} );

	function update( patch ) {
		Object.assign( state, { page: 1 }, patch );
		try { sessionStorage.setItem( 'fs-inv', JSON.stringify( state ) ); } catch {}
		load();
	}

	async function load() {
		const my = ++seq;
		list.classList.add( 'is-loading' );
		const q = new URLSearchParams( { search: state.search, category: state.category, stock: state.stock, sort: state.sort, page: state.page, per_page: 100 } );
		try {
			const d = await api( 'GET', 'inventory?' + q );
			if ( alive && my === seq ) render( d );
		} catch ( e ) {
			if ( alive && my === seq ) list.replaceChildren( h( 'p.fs-empty', {}, e.message ) );
		} finally {
			if ( my === seq ) list.classList.remove( 'is-loading' );
		}
	}

	function render( d ) {
		if ( ! d.items.length ) {
			list.replaceChildren( h( 'p.fs-empty', {}, 'Nothing matches. Try another search or filter.' ) );
			pager.replaceChildren();
			return;
		}
		list.replaceChildren( h( 'table.fs-table.fs-inv', {},
			h( 'thead', {}, h( 'tr', {},
				h( 'th', { scope: 'col' }, h( 'span.fs-sr', {}, 'Photo' ) ),
				h( 'th', { scope: 'col' }, 'Product' ),
				h( 'th', { scope: 'col' }, 'Stock' ),
				h( 'th', { scope: 'col' }, 'Price' ),
				h( 'th', { scope: 'col' }, h( 'span.fs-sr', {}, 'Actions' ) ) ) ),
			h( 'tbody', {}, d.items.map( row ) ) ) );
		pager.replaceChildren( ...( d.pages > 1 ? [
			h( 'button.fs-btn.fs-btn--small', { type: 'button', disabled: state.page <= 1, onclick: () => update( { page: state.page - 1 } ) }, '← Previous' ),
			h( 'span', {}, `Page ${ state.page } of ${ d.pages } · ${ d.total } rows` ),
			h( 'button.fs-btn.fs-btn--small', { type: 'button', disabled: state.page >= d.pages, onclick: () => update( { page: state.page + 1 } ) }, 'Next →' ),
		] : [ h( 'span', {}, `${ d.total } rows` ) ] ) );
	}

	function row( r ) {
		const title = r.option ? `${ r.name } · ${ r.option }` : r.name;
		const open = h( 'a.fs-btn.fs-btn--small', { href: '#/products/' + r.product_id }, 'Open' );
		const product = h( 'td', {},
			h( 'a.fs-table__name', { href: '#/products/' + r.product_id }, r.name ),
			r.option ? h( 'span.fs-inv__option', {}, r.option ) : null,
			h( 'div.fs-table__meta', {}, [ r.sku, r.enabled ? '' : 'Switched off' ].filter( Boolean ).join( ' · ' ) ),
			h( 'div.fs-badges', {},
				'outofstock' === r.stock_status ? h( 'span.fs-badge.fs-badge--out', {}, 'Sold out' ) : r.low ? h( 'span.fs-badge.fs-badge--low', {}, 'Low stock' ) : null,
				r.locked ? h( 'span.fs-badge.fs-badge--changed', {}, r.locked ) : null ) );
		const thumb = h( 'td.fs-table__thumb', {}, r.thumb ? h( 'img', { src: r.thumb, alt: '', width: 48, height: 48, loading: 'lazy' } ) : h( 'span.fs-thumb-empty' ) );
		if ( r.locked ) {
			return h( 'tr', {}, thumb, product,
				h( 'td', { 'data-label': 'Stock' }, r.manage_stock ? String( r.stock_quantity ?? 0 ) : STATUS[ r.stock_status ] ),
				h( 'td', { 'data-label': 'Price' }, r.regular_price ),
				h( 'td.fs-table__actions', {}, open ) );
		}
		const stock = r.manage_stock
			? h( 'input.fs-input.fs-inline.fs-inline--short', { inputmode: 'numeric', value: r.stock_quantity ?? '', 'aria-label': `Stock of ${ title }` } )
			: h( 'select.fs-input.fs-input--small', { 'aria-label': `Stock status of ${ title }` }, Object.entries( STATUS ).map( ( [ v, t ] ) => h( 'option', { value: v, selected: v === r.stock_status }, t ) ) );
		const price = h( 'input.fs-input.fs-inline', { inputmode: 'decimal', value: r.regular_price, 'aria-label': `Price of ${ title }` } );
		const save = h( 'button.fs-btn.fs-btn--small.fs-btn--primary', { type: 'button', hidden: true, onclick: () => commit() }, 'Save' );
		const stockKey = r.manage_stock ? 'stock_quantity' : 'stock_status';
		const was = { stock_quantity: r.stock_quantity, stock_status: r.stock_status, regular_price: r.regular_price };
		const fieldsNow = () => {
			const f = {};
			if ( stock.value !== String( was[ stockKey ] ?? '' ) ) f[ stockKey ] = stock.value;
			if ( price.value !== r.regular_price ) f.regular_price = price.value;
			return f;
		};
		for ( const input of [ stock, price ] ) {
			input.addEventListener( 'input', () => { save.hidden = ! Object.keys( fieldsNow() ).length; } );
			input.addEventListener( 'change', () => { save.hidden = ! Object.keys( fieldsNow() ).length; } );
			input.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Enter' && ! save.hidden && ! save.disabled ) commit(); } );
		}
		async function commit() {
			const fields = fieldsNow();
			if ( ! Object.keys( fields ).length ) return;
			const seen = {};
			for ( const k of Object.keys( fields ) ) seen[ k ] = was[ k ];
			save.disabled = true;
			try {
				await api( 'POST', r.kind === 'variation' ? `variations/${ r.id }/quick` : `products/${ r.id }/quick`, { fields, seen } );
				toast( `${ title } is updated on the site.` );
				load();
			} catch ( e ) {
				save.disabled = false;
				if ( e.code === 'fisha_studio_conflict' ) {
					toast( `${ title } changed on the site since this page loaded (probably an order). The list now shows the current values.`, 'error' );
					load();
					return;
				}
				toast( e.message, 'error' );
			}
		}
		return h( 'tr', {}, thumb, product,
			h( 'td', { 'data-label': 'Stock' }, stock ),
			h( 'td', { 'data-label': 'Price' }, price ),
			h( 'td.fs-table__actions', {}, save, open ) );
	}

	load();
	return { destroy() { alive = false; search.cancel(); } };
}
```

- [ ] **Step 2: Route and nav in `studio/app/js/app.js`**

Add the import `import { inventoryView } from './views/inventory.js';`, add a nav item after Products:
```js
	h( 'a.fs-nav__item', { href: '#/inventory', dataset: { section: 'inventory' } }, 'Inventory' ),
```
(the `nav` is built with `h( 'nav.fs-nav', { … }, <items> )`; add it as another child), and add to `routes`:
```js
	[ /^#\/inventory$/, () => inventoryView( main ) ],
```

- [ ] **Step 3: CSS** — append to `studio/app/studio.css` (before the final touch block):
```css
.fs-inv__option { display: inline-block; margin-left: .4rem; padding: .05rem .5rem; border-radius: 999px; background: var(--fs-orange-soft); font-weight: 800; font-size: .85rem; }
```

- [ ] **Step 4: Check in the browser**

1. Nav → Inventory → every simple product and every size (T-shirts show one row per size with an orange size label), default order by name with sizes in S, M, L order.
2. "Lowest stock first" → sold-out rows at the top. Filter Sold out → only sold-out rows. Search a size SKU → that row.
3. On a simple product with counted stock: change stock, Enter → toast, row updates; set it back.
4. On a size that does not count stock: change the status select to Sold out → Save → public product page shows that size unavailable; set it back.
5. Stale value: load the page, change a size's stock via WP-CLI, then edit it in the list → error toast and the row shows the new site value; nothing overwritten.
6. A product with unpublished changes shows "Unpublished changes" and only an Open button.
7. Phone width → cards, no horizontal scroll. Console → no errors.

- [ ] **Step 5: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/app
git commit -m "Studio UI: inventory screen for every product and size

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Options screen (the shop-wide size lists)

**Files:**
- Create: `studio/app/js/views/options.js`
- Modify: `studio/app/js/app.js` (route + nav), `studio/app/studio.css`

**Interfaces:**
- Consumes: Task 7 routes.
- Produces: `optionsView( main ): { destroy() }`; route `#/options`; nav item "Size lists".

- [ ] **Step 1: `studio/app/js/views/options.js`**

```js
/**
 * Fisha Studio "Size lists": the shop-wide options (Size, Shoe size…) and their values. Changes apply straight away
 * to every product that uses them; a value can only be deleted when no product uses it.
 */
import { h, toast, modal, confirmDialog } from '../ui.js';
import { api } from '../api.js';

export function optionsView( main ) {
	let alive = true;
	const box = h( 'div.fs-opts' );
	main.append(
		h( 'div.fs-page-head', {}, h( 'h1', {}, 'Size lists' ), h( 'button.fs-btn.fs-btn--primary', { type: 'button', onclick: addOption }, '+ New list' ) ),
		h( 'p.fs-field__help', {}, 'The values shoppers choose from, shared by all products. Renaming a value updates every product that uses it.' ),
		box );

	async function load() {
		try {
			const list = await api( 'GET', 'attributes' );
			if ( alive ) box.replaceChildren( ...list.map( card ) );
		} catch ( e ) {
			if ( alive ) box.replaceChildren( h( 'p.fs-empty', {}, e.message ) );
		}
	}

	async function ask( title, label, value = '', max = 40 ) {
		return modal( {
			title,
			body: h( 'label.fs-field__label', {}, label, h( 'input.fs-input', { name: 'v', value, required: true, maxlength: max } ) ),
			actions: [ { label: 'Cancel', value: null }, { label: 'Save', kind: 'primary', submit: true, value: ( f ) => f.elements.v.value.trim() } ],
		} );
	}

	async function addOption() {
		const label = await ask( 'New list', 'Name, for example Ring size', '', 28 );
		if ( ! label ) return;
		try {
			await api( 'POST', 'attributes', { label } );
			load();
		} catch ( e ) { toast( e.message, 'error' ); }
	}

	function card( a ) {
		const add = h( 'input.fs-input.fs-input--small', { placeholder: 'New value', 'aria-label': `New value for ${ a.label }` } );
		add.addEventListener( 'keydown', async ( e ) => {
			if ( e.key !== 'Enter' ) return;
			e.preventDefault();
			const name = add.value.trim();
			if ( ! name ) return;
			try {
				await api( 'POST', `attributes/${ a.id }/terms`, { name } );
				load();
			} catch ( err ) { toast( err.message, 'error' ); }
		} );
		const move = async ( i, by ) => {
			const ids = a.terms.map( ( t ) => t.id );
			const j = i + by;
			if ( j < 0 || j >= ids.length ) return;
			[ ids[ i ], ids[ j ] ] = [ ids[ j ], ids[ i ] ];
			try {
				await api( 'POST', `attributes/${ a.id }/order`, { term_ids: ids } );
				load();
			} catch ( err ) { toast( err.message, 'error' ); }
		};
		const rows = a.terms.map( ( t, i ) => h( 'li.fs-opts__value', {},
			h( 'strong', {}, t.name ),
			h( 'span.fs-table__meta', {}, t.sizes ? `${ t.sizes } size${ t.sizes > 1 ? 's' : '' }` : 'Not used' ),
			h( 'div.fs-opts__tools', {},
				h( 'button.fs-btn.fs-btn--tiny', { type: 'button', 'aria-label': `Move ${ t.name } up`, disabled: i === 0, onclick: () => move( i, -1 ) }, '↑' ),
				h( 'button.fs-btn.fs-btn--tiny', { type: 'button', 'aria-label': `Move ${ t.name } down`, disabled: i === a.terms.length - 1, onclick: () => move( i, 1 ) }, '↓' ),
				h( 'button.fs-btn.fs-btn--tiny', { type: 'button', onclick: async () => {
					const name = await ask( `Rename ${ t.name }`, 'Name', t.name );
					if ( ! name || name === t.name ) return;
					try { await api( 'PUT', `attributes/terms/${ t.id }`, { name } ); load(); } catch ( err ) { toast( err.message, 'error' ); }
				} }, 'Rename' ),
				h( 'button.fs-btn.fs-btn--tiny.fs-btn--danger', { type: 'button', onclick: async () => {
					if ( ! ( await confirmDialog( `Delete ${ t.name }?`, 'It is removed from this list. Values that products use cannot be deleted.', 'Delete', 'danger' ) ) ) return;
					try { await api( 'DELETE', `attributes/terms/${ t.id }` ); load(); } catch ( err ) { toast( err.message, 'error' ); }
				} }, 'Delete' ) ) ) );
		return h( 'section.fs-opts__card', {},
			h( 'div.fs-vars__attr-head', {},
				h( 'h2', {}, a.label ),
				h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: async () => {
					const label = await ask( `Rename ${ a.label }`, 'Name', a.label, 28 );
					if ( ! label || label === a.label ) return;
					try { await api( 'PUT', `attributes/${ a.id }`, { label } ); load(); } catch ( err ) { toast( err.message, 'error' ); }
				} }, 'Rename list' ) ),
			h( 'ul.fs-opts__values', {}, rows ),
			add );
	}

	load();
	return { destroy() { alive = false; } };
}
```

- [ ] **Step 2: Route and nav** — in `app.js` import `optionsView` from `./views/options.js`, add nav item `h( 'a.fs-nav__item', { href: '#/options', dataset: { section: 'options' } }, 'Size lists' )` after Inventory, and route `[ /^#\/options$/, () => optionsView( main ) ]`.

- [ ] **Step 3: CSS** — append (before the final touch block):
```css
.fs-opts { display: grid; gap: 1rem; max-width: 760px; }
.fs-opts__card { background: var(--fs-card); border-radius: var(--fs-radius); box-shadow: var(--fs-shadow); padding: 1rem; display: grid; gap: .6rem; }
.fs-opts__values { list-style: none; margin: 0; padding: 0; display: grid; gap: .3rem; }
.fs-opts__value { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: .6rem; padding: .35rem .5rem; border: 1px solid var(--fs-line); border-radius: 10px; }
.fs-opts__tools { display: flex; gap: .25rem; }
@media (max-width: 760px) { .fs-opts__value { grid-template-columns: 1fr; } }
```

- [ ] **Step 4: Check in the browser**

1. Nav → Size lists → "Size" with S, M, L, XL and how many sizes use each; "Shoe size" with its values.
2. "+ New list" → "zz Ring size" → appears; add values 5, 6, 7; move 7 up; rename 6 → "6.5"; delete 5 → gone.
3. Delete "S" in Size → error toast naming products that use it; nothing deleted.
4. Open a product with sizes → Sizes & options → "+ Add an option…" lists "zz Ring size" (reload the Studio first: lookups are cached per session).
5. Clean up: delete the "zz Ring size" values (it has no products), then remove the list in WP admin (Products → Attributes) or leave it for the owner to see. Note which in the report.
6. Console → no errors.

- [ ] **Step 5: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/app
git commit -m "Studio UI: size lists screen for the shop-wide options

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Verification

**Files:** none unless a check fails (fix it in the owning task's files).

- [ ] **Step 1:** `bash tests/studio/run.sh` → all pass, no PHP warnings. Leftover check (same query as phase 1 Task 16 step 2) → `0 posts, 0 terms, 0 users`; plus `wp eval 'foreach ( wc_get_attribute_taxonomies() as $a ) echo $a->attribute_label, "\n";'` → no `zz-test` lists.
- [ ] **Step 2:** Visible-site parity: `bash tests/studio/baseline.sh > /tmp/after.txt; diff tests/studio/baseline-before.txt /tmp/after.txt` → identical (restore any product changed during browser checks first; run `docker exec fisha-wordpress-1 apachectl graceful`).
- [ ] **Step 3:** Product 21 unchanged: run the exact command from Task 1 Step 0 again → output identical to "Product 21 before" in the Task 1 report.
- [ ] **Step 4:** Do not push. The controller runs the final whole-branch review; the user pushes `develop` (GitHub credentials).

---

## Self-review notes (already applied)

- Spec coverage: §5.2 Variations tab (attributes, create all combinations, per-size enabled/price/sale/SKU/stock/image, bulk set price/±stock/enable) → Tasks 1–6, 9; §5.4 Attributes (add, rename, reorder, delete blocked while used) → Tasks 7, 11; §5.5 Inventory (every simple product and variation, inline stock, filters) → Tasks 8, 10; §6.1 variations in the parent draft, new variations private until publish → Tasks 2–3; conflicts per size → Tasks 2–3; preview → Task 5. Owner direction (2026-10-13): full editor on "New product" → Tasks 2, 6, 9; SEO and Fisha design tabs removed → Task 9; categories → next plan.
- Deliberately not built: per-size sale dates, weights, descriptions (not used by the shop); deleting live sizes (switch off instead); converting a live product's type; product list sorting (Inventory has sort).
- Type consistency: draft keys `attributes` / `var:{id}:{field}` everywhere; `fisha_studio_product_values()` replaces `fisha_studio_product_read()` wherever all keys are needed (drafts, conflicts, publish, payload, preview); `fisha_studio_product_read()` stays for the product fields only (used by `product_values` itself and by trash).
