# Fisha Studio — Phase 3 (Categories) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The owner adds and edits shop categories in the Studio (name, parent, intro, hero photos, Show on site). A new category comes out exactly like the existing ones. Shown top-level categories appear by themselves in the header Shop menu, the footer, the Home "Pick your Fisha" cards and the Shop page, and empty categories never show.

**Architecture:**
- One small site file, `fisha-design/cat-visibility.php`, decides which categories the site displays. The Shop page, the category pages, the Home cards and the header/footer menus ask it instead of using hardcoded lists. The menus are rewritten at render time; the stored menus are untouched.
- In the Studio, `studio-categories.php` and `studio-categories-publish.php` reuse the product engine for categories, with the data kept in term meta:
  - the draft with a "seen" base and conflicts;
  - the transactional publish and the audit log;
  - the signed preview.
- A never-published category is a real term marked `fisha_studio_hidden = 1`. Its edits apply at once, and the public site cannot see it until Publish.

**Tech Stack:** WordPress 7.1, WooCommerce 11.1, PHP 8.3, MariaDB (InnoDB), vanilla ES modules (no build), WP-CLI tests in Docker.

**Spec:**
- `docs/superpowers/specs/2026-10-13-fisha-studio-phase-3-categories-design.md` (phase 3 addendum, binding).
- Parent spec `docs/superpowers/specs/2026-10-12-fisha-studio-design.md` (§4.3 URL rules, §6 drafts/preview/publish).

**Plan rulings on the spec (small, recorded here):**
- `fisha_seo_desc` is not written. `shop.php` already prints the category's Intro (`fisha_lead`) as the meta description, so a second copy would only drift.
- Preview targets come in the category payload as `previews`, like products, instead of a separate `/preview-token` route.
- Delete is `DELETE /categories/{id}` with JSON `{ move_to }`.

**Code this plan builds on (exact names):**
- Fields: `fisha_studio_clean( $def, $v )`, `fisha_studio_sanitize( $schema, $input )`, `fisha_studio_plain()`, `fisha_studio_same()`.
- Drafts: `FISHA_STUDIO_DRAFT_META` (`_fisha_studio_draft`), `FISHA_STUDIO_CREATED_META` (`_fisha_studio_created`), `fisha_studio_draft_get( int $id )`, `fisha_studio_draft_put( int $id, array $d )`, `fisha_studio_draft_save( WC_Product, $values, $seen )`, `fisha_studio_conflicts( WC_Product, $draft )`, `fisha_studio_value_label( WC_Product, string )`.
- Publish: `fisha_studio_resolution()`, `fisha_studio_open_conflicts()`, `fisha_studio_purge_product( WC_Product, array $extra_cat_ids )`.
- URLs: `fisha_studio_slug_from_name()`, `fisha_studio_slug_error()`, `fisha_studio_reserved_slugs()`.
- Audit: `fisha_studio_log( $type, $id, $action, $before, $after )`, `fisha_studio_log_error()`.
- Preview: `fisha_studio_preview_token( $user, $type, $id )` (already accepts type `category`), `fisha_studio_preview_add( $hook, $fn, $priority, $args )`, `fisha_studio_preview_uninstall()`.
- REST: `fisha_studio_permission( $cap )`, `fisha_studio_body()`, `fisha_studio_image_payload( int )`.
- Site: `fisha_cat_products( $term, $limit )` and `fisha_cat_hero_ids( $term )` (`shop.php`), `fisha_home_cat_list()` and the `[fisha_home_cats]` shortcode (`home.php`), `templates/shop-home.php`, `templates/product-cat.php`.
- JS: `h, toast, modal, confirmDialog, debounce` (ui.js), `api` (api.js), `textField, selectField, toggleField` (fields.js), `imagesField` (images.js), `previewPane` (preview.js), the router in app.js.
- Tests: `fs_*` helpers (`tests/studio/helpers.php`), run with `bash tests/studio/run.sh [filter]` (185 passing at the start).

## Global Constraints

- Site code goes in `wp-content/mu-plugins/fisha-design/` (new file `cat-visibility.php`, loaded from `fisha-design.php` before `shop.php`). Studio code goes in `wp-content/mu-plugins/fisha-design/studio/`, and tests in `tests/studio/`.
- Same conventions as phases 1–2:
  - file doc comment plus `if ( ! defined( 'ABSPATH' ) ) exit;`;
  - WordPress style;
  - prefix `fisha_studio_` (Studio) and `fisha_cat_` (site);
  - REST namespace `fisha-studio/v1`.
- Term meta keys, verbatim:
  - `fisha_shown` (`'1'`/`'0'`; missing = Hidden);
  - `fisha_studio_hidden` (`'1'` = not published yet);
  - `_fisha_studio_created`, `_fisha_studio_draft`, `_fisha_studio_slug_manual`;
  - `fisha_home_line`, `order`, `fisha_old_paths` (one row per old path);
  - design fields `fisha_h1`, `fisha_eyebrow`, `fisha_lead`, `fisha_seo_title`, `fisha_hero_ids` (CSV).
- Option `fisha_deleted_cat_paths` (path → parent term id, 0 = Shop).
- Migration version option: `fisha_cat_visibility_db`.
- **No visible change on deploy:** header, footer, Home, Shop and every category page render the same for visitors. Proven by `tests/studio/baseline.sh` before/after.
- Writes only through WordPress/WooCommerce APIs (`wp_insert_term`, `wp_update_term`, `wp_delete_term`, `update_term_meta`, `wp_set_object_terms`). Publish, delete and reorder of live data run inside `START TRANSACTION … COMMIT`, with ROLLBACK on any error and the COMMIT result checked.
- Existing hand-written design fields (Wearables, Collectibles, Originals, Clippers) are never overwritten.
- Owner-facing copy:
  - plain English, no em or en dashes;
  - touch targets of 44px or more on phones (new small controls join the `@media (pointer: coarse), (max-width: 760px)` block in studio.css).
- Never push; never touch `main`. Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Renaming a live category whose heading was written by hand (Wearables)** → its heading, small line and Google title stay exactly as they were. Pinned by `categories publish: renaming a live category keeps hand-written design fields` (Task 5).
2. **Moving a live category that has sub-categories to another parent** → its old address and every sub-category's old address answer 301 with the new address. Pinned by `categories publish: moving a live category remembers old paths of it and its children` (Task 5) and `cat hiding: an old path redirects to the category's address now` (Task 3).
3. **Deleting a category whose products also sit in other categories:**
   - those products keep their other categories;
   - only products left with no category get the chosen one;
   - nothing lands in Uncategorized.

   Pinned by `categories delete: products keep other categories, orphans move, children move up` (Task 5).
4. **A shown top-level category whose only products are drafts or private** → not displayed anywhere. Pinned by `cat visibility: draft and private products do not count, sold-out ones do` (Task 1).
5. **"New category" twice, or two categories with the same name under one parent:**
   - the second new one is named "New category 2";
   - renaming onto a sibling's name is refused with a message.

   Pinned by `categories: new names never clash, a sibling's name is refused` (Task 4).

---

## File map

| Path | Change |
|---|---|
| `fisha-design.php` | require `fisha-design/cat-visibility.php` before `shop.php` |
| `fisha-design/cat-visibility.php` | **new**: visibility rules, migration, menu link swap, hiding never-published categories, noindex, sitemap, old-address redirects |
| `fisha-design/templates/shop-home.php` | top list and sub-category shelves from the visibility rules |
| `fisha-design/templates/product-cat.php` | child shelves and chips from the visibility rules |
| `fisha-design/home.php`, `home.css` | Home cards from the visibility rules; header text by count; 2- or 4-card rows |
| `studio/studio-drafts.php` | shared `fisha_studio_draft_merge()` and `fisha_studio_draft_conflicts()`; draft get/put take a meta type |
| `studio/studio-categories.php` | **new**: category schema, values, automatic fields, create, edit, draft, validate |
| `studio/studio-categories-publish.php` | **new**: publish, Show on site, reorder, delete, purge |
| `studio/studio-publish.php` | product purge also purges everything when a top-level category may have changed between empty and not |
| `studio/studio.php` | require the two new Studio files |
| `studio/studio-preview.php` | category preview overlay and targets |
| `studio/api/api-categories.php` | **new**: REST for categories |
| `studio/api/api.php` | require it |
| `studio/api/api-lookups.php` | not-published-yet categories labelled |
| `studio/api/api-products.php` | publish response carries `warnings` |
| `studio/app/js/conflicts.js` | **new**: conflict dialog moved out of product-editor.js |
| `studio/app/js/views/categories-list.js` | **new** |
| `studio/app/js/views/category-editor.js` | **new** |
| `studio/app/js/views/product-editor.js` | uses conflicts.js; shows publish warnings |
| `studio/app/js/images.js` | `imagesField` takes options (label, help, max, hover note) |
| `studio/app/js/app.js` | Categories nav item and routes |
| `studio/app/studio.css` | categories list styles, warning toast |
| `tests/studio/helpers.php` | `fs_cat()`, `fs_studio_cat()`, `fs_hiding()` |
| `tests/studio/baseline.sh` | also prints category links and Home card names |
| `tests/studio/test-cat-visibility.php`, `test-cat-lists.php`, `test-cat-hiding.php`, `test-categories.php`, `test-categories-publish.php`, `test-categories-preview.php`, `test-api-categories.php` | **new** tests |

---

### Task 1: Which categories the site displays (rules + migration)

**Files:**
- Create: `wp-content/mu-plugins/fisha-design/cat-visibility.php`
- Modify: `wp-content/mu-plugins/fisha-design.php` (add the require before `shop.php`), `tests/studio/helpers.php`, `tests/studio/baseline.sh`
- Test: `tests/studio/test-cat-visibility.php`

**Interfaces:**
- Produces:
  - Constants `FISHA_CAT_SHOWN` (`'fisha_shown'`), `FISHA_CAT_UNPUBLISHED` (`'fisha_studio_hidden'`), `FISHA_CAT_OLD_PATHS` (`'fisha_old_paths'`).
  - `fisha_cat_is_unpublished( WP_Term $t ): bool`
  - `fisha_cat_is_enabled( WP_Term $t ): bool` (published, shown, parents too)
  - `fisha_cat_has_products( WP_Term $t ): bool`
  - `fisha_cat_is_displayed( WP_Term $t ): bool`
  - `fisha_cat_cache_reset(): void`
  - `fisha_cat_by_order( array $terms ): array`
  - `fisha_cat_displayed_children( int $parent ): WP_Term[]`
  - `fisha_displayed_top_categories(): WP_Term[]`
  - `fisha_cat_visibility_migrate(): void`
  - Test helper `fs_cat( string $name, int $parent = 0, bool $shown = true ): WP_Term`.

- [ ] **Step 1: Record the "before" picture of the site**

Extend `tests/studio/baseline.sh` so it also prints each page's category links (in page order) and the Home card names. Then save the before-file **before any other change**:

```bash
#!/usr/bin/env bash
# Prints what visitors and search engines see on key pages (title, description, H1, card count, category links in page
# order, Home card names), so a before/after diff proves the Studio changed nothing visible. Usage: bash tests/studio/baseline.sh > file
for u in / /shop-2/ /product-category/originals/ /product-category/collectibles/clippers/ /product-category/wearables/ /product-category/wearables/tshirts/ /product/out-to-the-river/ /product/upside-down/; do
	html=$(curl -s "http://localhost:8484$u")
	echo "== $u"
	echo "$html" | grep -o -e '<title>[^<]*' -e '<meta name="description" content="[^"]*' | head -2
	echo "$html" | grep -o '<h1[^>]*>.*</h1>' | sed 's/<[^>]*>//g' | head -1
	echo "cards: $(echo "$html" | grep -o 'class="fs-card' | wc -l)"
	echo "category links: $(echo "$html" | grep -o 'href="[^"]*product-category/[^"]*"' | tr '\n' ' ')"
	echo "home cards: $(echo "$html" | grep -o '<h3 class="fh-cat__name">[^<]*' | sed 's/.*>//' | tr '\n' ',')"
done
```

Run: `bash tests/studio/baseline.sh > tests/studio/baseline-phase3-before.txt`. The file must list Wearables, Collectibles and Originals links on `/`, plus 3 home cards. Commit it with this task. Tasks 2, 3 and 10 compare against it.

- [ ] **Step 2: Test helper**

Append to `tests/studio/helpers.php`:

```php
/* ---------- categories ---------- */

/** A category with "Show on site" set (fs_term() ones have no fisha_shown, so the site treats them as hidden). */
function fs_cat( string $name, int $parent = 0, bool $shown = true ): WP_Term {
	$t = fs_term( $name, $parent );
	update_term_meta( $t->term_id, 'fisha_shown', $shown ? '1' : '0' );
	if ( function_exists( 'fisha_cat_cache_reset' ) ) fisha_cat_cache_reset();
	return $t;
}
```

Also make `fs_term()`'s cleanup remove the category's audit rows. Change its cleanup closure to:

```php
	fs_on_cleanup( function () use ( $r ) {
		global $wpdb;
		$table = $wpdb->prefix . 'fisha_studio_log';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) ) $wpdb->delete( $table, array( 'item_type' => 'category', 'item_id' => $r['term_id'] ) );
		wp_delete_term( $r['term_id'], 'product_cat' );
	} );
```

- [ ] **Step 3: Write the failing tests** — `tests/studio/test-cat-visibility.php`

```php
<?php
fs_test( 'cat visibility: shown with a product is displayed; hidden, empty or never published is not', function () {
	$top = fs_cat( 'vis-top' );
	fs_false( fisha_cat_is_displayed( $top ), 'empty' );
	fs_product( array( 'category_ids' => array( $top->term_id ) ) );
	fisha_cat_cache_reset();
	fs_true( fisha_cat_is_displayed( $top ), 'has a product' );
	update_term_meta( $top->term_id, 'fisha_shown', '0' );
	fisha_cat_cache_reset();
	fs_false( fisha_cat_is_displayed( $top ), 'switched off' );
	update_term_meta( $top->term_id, 'fisha_shown', '1' );
	update_term_meta( $top->term_id, 'fisha_studio_hidden', '1' );
	fs_false( fisha_cat_is_displayed( $top ), 'not published yet' );
	delete_term_meta( $top->term_id, 'fisha_shown' );
	delete_term_meta( $top->term_id, 'fisha_studio_hidden' );
	fs_false( fisha_cat_is_displayed( $top ), 'missing fisha_shown means hidden' );
} );

fs_test( 'cat visibility: draft and private products do not count, sold-out ones do', function () {
	$c = fs_cat( 'vis-count' );
	fs_product( array( 'category_ids' => array( $c->term_id ) ), 'draft' );
	fs_product( array( 'category_ids' => array( $c->term_id ) ), 'private' );
	fisha_cat_cache_reset();
	fs_false( fisha_cat_has_products( $c ), 'draft/private only' );
	fs_product( array( 'category_ids' => array( $c->term_id ), 'stock_status' => 'outofstock' ) );
	fisha_cat_cache_reset();
	fs_true( fisha_cat_has_products( $c ), 'sold out counts' );
} );

fs_test( 'cat visibility: products in a sub-category count for the parent; a hidden parent hides its children', function () {
	$top = fs_cat( 'vis-parent' );
	$kid = fs_cat( 'vis-kid', $top->term_id );
	fs_product( array( 'category_ids' => array( $kid->term_id ) ) );
	fisha_cat_cache_reset();
	fs_true( fisha_cat_is_displayed( $top ), 'parent counts the child product' );
	fs_true( fisha_cat_is_displayed( $kid ) );
	update_term_meta( $top->term_id, 'fisha_shown', '0' );
	fisha_cat_cache_reset();
	fs_false( fisha_cat_is_displayed( $kid ), 'parent hidden' );
} );

fs_test( 'cat visibility: displayed children come in the owner order, then by name', function () {
	$top = fs_cat( 'vis-order' );
	$b = fs_cat( 'vis-b', $top->term_id );
	$a = fs_cat( 'vis-a', $top->term_id );
	$c = fs_cat( 'vis-c', $top->term_id );
	$empty = fs_cat( 'vis-empty', $top->term_id );
	foreach ( array( $a, $b, $c ) as $t ) fs_product( array( 'category_ids' => array( $t->term_id ) ) );
	update_term_meta( $c->term_id, 'order', 1 );
	update_term_meta( $a->term_id, 'order', 2 );
	update_term_meta( $b->term_id, 'order', 2 );
	fisha_cat_cache_reset();
	fs_eq( array( $c->term_id, $a->term_id, $b->term_id ), wp_list_pluck( fisha_cat_displayed_children( $top->term_id ), 'term_id' ) );
	fs_false( in_array( $empty->term_id, wp_list_pluck( fisha_cat_displayed_children( $top->term_id ), 'term_id' ), true ), 'empty child left out' );
} );

fs_test( 'cat visibility: today\'s three top categories are displayed, in today\'s order', function () {
	fisha_cat_cache_reset();
	$slugs = wp_list_pluck( fisha_displayed_top_categories(), 'slug' );
	fs_eq( array( 'wearables', 'collectibles', 'originals' ), array_values( array_intersect( $slugs, array( 'wearables', 'collectibles', 'originals' ) ) ) );
	foreach ( array( 'fisha-wear', 'made-for-city-life', 'shop-fisha', 'street-goods', 'uncategorized' ) as $s ) fs_false( in_array( $s, $slugs, true ), "$s hidden" );
} );

fs_test( 'cat visibility: migration shows the three shop branches, hides the rest, keeps existing values', function () {
	$loose = fs_term( 'vis-migrate-loose' );
	$under = fs_term( 'vis-migrate-under', (int) get_term_by( 'slug', 'wearables', 'product_cat' )->term_id );
	$kept  = fs_term( 'vis-migrate-kept' );
	update_term_meta( $kept->term_id, 'fisha_shown', '1' );
	$version = get_option( 'fisha_cat_visibility_db' );
	fs_on_cleanup( function () use ( $version ) { update_option( 'fisha_cat_visibility_db', $version ); } );
	delete_option( 'fisha_cat_visibility_db' );
	fisha_cat_visibility_migrate();
	fs_eq( '0', get_term_meta( $loose->term_id, 'fisha_shown', true ), 'outside the shop branches' );
	fs_eq( '1', get_term_meta( $under->term_id, 'fisha_shown', true ), 'below Wearables' );
	fs_eq( '1', get_term_meta( $kept->term_id, 'fisha_shown', true ), 'existing value kept' );
	$w = get_term_by( 'slug', 'wearables', 'product_cat' );
	fs_eq( 'T-shirts, hoodies, caps and socks carrying the hand-drawn fish.', get_term_meta( $w->term_id, 'fisha_home_line', true ) );
	fs_eq( '1', (string) get_term_meta( $w->term_id, 'order', true ) );
	fs_eq( '3', (string) get_term_meta( get_term_by( 'slug', 'originals', 'product_cat' )->term_id, 'order', true ) );
	fs_eq( 1, (int) get_option( 'fisha_cat_visibility_db' ), 'version stored' );
} );
```

- [ ] **Step 4: Run them to see them fail**

Run: `bash tests/studio/run.sh "cat visibility"`
Expected: FAIL. The functions are undefined, which shows as a PHP fatal "Call to undefined function fisha_cat_cache_reset()" printed as FAIL lines.

- [ ] **Step 5: Implement** — create `wp-content/mu-plugins/fisha-design/cat-visibility.php`

```php
<?php
/**
 * Which product categories the site displays (spec docs/superpowers/specs/2026-10-13-fisha-studio-phase-3-categories-design.md).
 * A category is displayed (header Shop menu, footer, Home cards, Shop page, its parent's shelves) only when it is published
 * (no fisha_studio_hidden), switched on (fisha_shown = 1, and so is every parent) and has a published, catalog-visible
 * product (its sub-categories count, sold-out products count).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_CAT_SHOWN', 'fisha_shown' );
define( 'FISHA_CAT_UNPUBLISHED', 'fisha_studio_hidden' );
define( 'FISHA_CAT_OLD_PATHS', 'fisha_old_paths' );
define( 'FISHA_CAT_VIS_DB', 1 );

/** Created in the Studio and not published yet. */
function fisha_cat_is_unpublished( WP_Term $t ): bool {
	return '1' === (string) get_term_meta( $t->term_id, FISHA_CAT_UNPUBLISHED, true );
}

/** Published and switched on, and so is every parent (products are not checked). */
function fisha_cat_is_enabled( WP_Term $t ): bool {
	for ( $depth = 0; $depth < 20; $depth++ ) {
		if ( fisha_cat_is_unpublished( $t ) || '1' !== (string) get_term_meta( $t->term_id, FISHA_CAT_SHOWN, true ) ) return false;
		if ( ! $t->parent ) return true;
		$t = get_term( (int) $t->parent, 'product_cat' );
		if ( ! $t instanceof WP_Term ) return false;
	}
	return false;
}

/** Whether the category, with its sub-categories, has a published, catalog-visible product (sold-out ones count). */
function fisha_cat_has_products( WP_Term $t ): bool {
	$cache = &$GLOBALS['fisha_cat_products_cache'];
	if ( ! is_array( $cache ) ) $cache = array();
	if ( ! isset( $cache[ $t->term_id ] ) ) {
		$ids = wc_get_products( array( 'status' => 'publish', 'limit' => 1, 'return' => 'ids', 'visibility' => 'catalog', 'category' => array( $t->slug ) ) );
		$cache[ $t->term_id ] = ! empty( $ids );
	}
	return $cache[ $t->term_id ];
}

function fisha_cat_is_displayed( WP_Term $t ): bool {
	return fisha_cat_is_enabled( $t ) && fisha_cat_has_products( $t );
}

/** Forgets what this request learned about categories (after the Studio changes one, and in tests). */
function fisha_cat_cache_reset(): void {
	$GLOBALS['fisha_cat_products_cache']    = array();
	$GLOBALS['fisha_cat_unpublished_cache'] = null;
}

/** The owner's order (term meta "order"), then by name. */
function fisha_cat_by_order( array $terms ): array {
	usort( $terms, function ( $a, $b ) {
		return array( (int) get_term_meta( $a->term_id, 'order', true ), $a->name ) <=> array( (int) get_term_meta( $b->term_id, 'order', true ), $b->name );
	} );
	return $terms;
}

/** Displayed categories directly below $parent (0 = top level), in the owner's order. @return WP_Term[] */
function fisha_cat_displayed_children( int $parent ): array {
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $parent, 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) ) return array();
	return fisha_cat_by_order( array_values( array_filter( $terms, 'fisha_cat_is_displayed' ) ) );
}

/** @return WP_Term[] */
function fisha_displayed_top_categories(): array {
	return fisha_cat_displayed_children( 0 );
}

/**
 * Once: Wearables, Collectibles, Originals and everything below them are shown, every other category is hidden (only where
 * no value is stored yet); the three get today's order and Home taglines. Result: the site looks exactly as before.
 */
function fisha_cat_visibility_migrate(): void {
	if ( (int) get_option( 'fisha_cat_visibility_db', 0 ) >= FISHA_CAT_VIS_DB || ! taxonomy_exists( 'product_cat' ) ) return;
	$roots = array( 'wearables' => 1, 'collectibles' => 2, 'originals' => 3 );
	$lines = array(
		'wearables'    => 'T-shirts, hoodies, caps and socks carrying the hand-drawn fish.',
		'collectibles' => 'Hand-painted Clippers and small objects, each one made once.',
		'originals'    => 'Ink drawings and paintings, signed and framed by hand.',
	);
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) ) return;
	$root_ids = array();
	foreach ( $terms as $t ) {
		if ( isset( $roots[ $t->slug ] ) && 0 === (int) $t->parent ) $root_ids[ (int) $t->term_id ] = $t->slug;
	}
	foreach ( $terms as $t ) {
		if ( '' !== (string) get_term_meta( $t->term_id, FISHA_CAT_SHOWN, true ) ) continue;
		$chain = array_merge( array( (int) $t->term_id ), array_map( 'intval', get_ancestors( $t->term_id, 'product_cat' ) ) );
		update_term_meta( $t->term_id, FISHA_CAT_SHOWN, array_intersect( $chain, array_keys( $root_ids ) ) ? '1' : '0' );
	}
	foreach ( $root_ids as $id => $slug ) {
		update_term_meta( $id, 'order', $roots[ $slug ] );
		if ( '' === (string) get_term_meta( $id, 'fisha_home_line', true ) ) update_term_meta( $id, 'fisha_home_line', $lines[ $slug ] );
	}
	update_option( 'fisha_cat_visibility_db', FISHA_CAT_VIS_DB );
	fisha_cat_cache_reset();
}
add_action( 'init', 'fisha_cat_visibility_migrate', 20 );
```

In `wp-content/mu-plugins/fisha-design.php`, add `require_once FISHA_DESIGN_DIR . 'cat-visibility.php';` on the line before `require_once FISHA_DESIGN_DIR . 'shop.php';`.

- [ ] **Step 6: Run the tests to see them pass**

Run: `bash tests/studio/run.sh "cat visibility"` → all PASS. Then run `bash tests/studio/run.sh` → 191 passed, 0 failed, no PHP warnings.

- [ ] **Step 7: Nothing visible changed**

Run: `bash tests/studio/baseline.sh > /tmp/p3-t1.txt; diff tests/studio/baseline-phase3-before.txt /tmp/p3-t1.txt` → no output.

- [ ] **Step 8: Commit**

```bash
git add wp-content/mu-plugins/fisha-design.php wp-content/mu-plugins/fisha-design/cat-visibility.php tests/studio/helpers.php tests/studio/baseline.sh tests/studio/baseline-phase3-before.txt tests/studio/test-cat-visibility.php
git commit -m "Categories: which categories the site displays (shown, published, has products) + one-time migration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The site lists categories from the rules (Shop, category pages, Home, header and footer menus)

**Files:**
- Modify:
  - `wp-content/mu-plugins/fisha-design/cat-visibility.php` (menu swap)
  - `templates/shop-home.php` lines 9 and 16-18
  - `templates/product-cat.php` lines 8-11, 22-28 and 34-35
  - `home.php` (`fisha_home_cat_list`, the header text)
  - `home.css`
- Test: `tests/studio/test-cat-lists.php`

**Interfaces:**
- Consumes: Task 1 functions.
- Produces:
  - `fisha_nav_cat_link( WP_Term $t ): array`
  - `fisha_nav_is_top_cat_link( array $block ): bool`
  - `fisha_nav_swap_cat_links( array $blocks, ?array $tops = null ): array`
  - `fisha_home_cats_text( string $text, int $count ): string`

- [ ] **Step 1: Write the failing tests** — `tests/studio/test-cat-lists.php`

```php
<?php
function fs_nav_link( WP_Term $t ): array {
	return array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'label' => $t->name, 'url' => get_term_link( $t ), 'type' => 'product_cat', 'id' => $t->term_id, 'kind' => 'taxonomy' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() );
}
function fs_page_link( string $label ): array {
	return array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'label' => $label, 'url' => home_url( '/' . sanitize_title( $label ) . '/' ), 'kind' => 'post-type', 'type' => 'page' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() );
}

fs_test( 'cat lists: a run of top-level category links is replaced by the displayed ones, pages stay', function () {
	$a = fs_cat( 'nav-a' ); $b = fs_cat( 'nav-b' ); $c = fs_cat( 'nav-c' );
	$blocks = array( fs_page_link( 'River' ), fs_nav_link( $a ), fs_nav_link( $b ), fs_page_link( 'Contact' ) );
	$out = fisha_nav_swap_cat_links( $blocks, array( $c, $a ) );
	fs_eq( array( 'River', $c->name, $a->name, 'Contact' ), array_map( function ( $x ) { return $x['attrs']['label']; }, $out ) );
	fs_eq( $c->term_id, $out[1]['attrs']['id'] );
	fs_eq( 'product_cat', $out[1]['attrs']['type'] );
} );

fs_test( 'cat lists: category links inside the Shop submenu are replaced and the submenu stays whole', function () {
	$a = fs_cat( 'nav-sub-a' ); $b = fs_cat( 'nav-sub-b' );
	$sub = array( 'blockName' => 'core/navigation-submenu', 'attrs' => array( 'label' => 'Shop' ), 'innerBlocks' => array( fs_nav_link( $a ) ), 'innerHTML' => '', 'innerContent' => array( null ) );
	$out = fisha_nav_swap_cat_links( array( $sub, fs_page_link( 'River' ) ), array( $a, $b ) );
	fs_eq( 2, count( $out[0]['innerBlocks'] ) );
	fs_eq( array( null, null ), $out[0]['innerContent'], 'one placeholder per link' );
	fs_eq( 'River', $out[1]['attrs']['label'] );
} );

fs_test( 'cat lists: sub-category links and lists without category links are left alone', function () {
	$top = fs_cat( 'nav-top' );
	$kid = fs_cat( 'nav-kid', $top->term_id );
	$blocks = array( fs_nav_link( $kid ), fs_page_link( 'River' ) );
	fs_eq( $blocks, fisha_nav_swap_cat_links( $blocks, array( $top ) ) );
	$pages = array( fs_page_link( 'River' ) );
	fs_eq( $pages, fisha_nav_swap_cat_links( $pages, array( $top ) ) );
} );

fs_test( 'cat lists: a rendered menu shows a newly shown top category with a product', function () {
	$new = fs_cat( 'nav-render' );
	update_term_meta( $new->term_id, 'order', 99 );
	fs_product( array( 'category_ids' => array( $new->term_id ) ) );
	fisha_cat_cache_reset();
	$w = get_term_by( 'slug', 'wearables', 'product_cat' );
	$content = serialize_block( array( 'blockName' => 'core/navigation-submenu', 'attrs' => array( 'label' => 'Shop', 'url' => home_url( '/shop-2/' ), 'kind' => 'post-type', 'type' => 'page' ), 'innerBlocks' => array( fs_nav_link( $w ) ), 'innerHTML' => '', 'innerContent' => array( null ) ) );
	$nav = wp_insert_post( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'zz-test nav', 'post_content' => $content ) );
	fs_on_cleanup( function () use ( $nav ) { wp_delete_post( $nav, true ); } );
	$html = do_blocks( '<!-- wp:navigation {"ref":' . $nav . '} /-->' );
	fs_true( false !== strpos( $html, get_term_link( $new ) ), 'new category linked' );
	fs_true( strpos( $html, get_term_link( $w ) ) < strpos( $html, get_term_link( $new ) ), 'after Wearables (order)' );
	update_term_meta( $new->term_id, 'fisha_shown', '0' );
	fisha_cat_cache_reset();
	fs_false( strpos( do_blocks( '<!-- wp:navigation {"ref":' . $nav . '} /-->' ), get_term_link( $new ) ), 'hidden: gone' );
} );

fs_test( 'cat lists: Home list follows the displayed top categories, taglines from meta or the intro', function () {
	$new = fs_cat( 'home-list' );
	update_term_meta( $new->term_id, 'fisha_lead', 'A short intro.' );
	update_term_meta( $new->term_id, 'order', 99 );
	fs_product( array( 'category_ids' => array( $new->term_id ) ) );
	fisha_cat_cache_reset();
	$list = fisha_home_cat_list();
	fs_eq( array( 'wearables', 'collectibles', 'originals' ), array_slice( array_keys( $list ), 0, 3 ) );
	fs_eq( 'Ink drawings and paintings, signed and framed by hand.', $list['originals'] );
	fs_eq( 'A short intro.', $list[ $new->slug ] );
} );

fs_test( 'cat lists: Home header text follows the number of cards', function () {
	$d = 'Three ways to take the little fish home.';
	fs_eq( $d, fisha_home_cats_text( $d, 3 ) );
	fs_eq( 'Four ways to take the little fish home.', fisha_home_cats_text( $d, 4 ) );
	fs_eq( 'One way to take the little fish home.', fisha_home_cats_text( $d, 1 ) );
	fs_eq( 'Our own words.', fisha_home_cats_text( 'Our own words.', 4 ), 'custom text untouched' );
} );
```

- [ ] **Step 2: Run them to see them fail**

Run: `bash tests/studio/run.sh "cat lists"` → FAIL (undefined `fisha_nav_swap_cat_links`, `fisha_home_cats_text`, and the old hardcoded Home list).

- [ ] **Step 3: Menu swap** — append to `cat-visibility.php`

```php
/* ---------- header and footer menus ---------- */

/** A menu link block for a category (same attributes the block editor saves). */
function fisha_nav_cat_link( WP_Term $t ): array {
	return array(
		'blockName'    => 'core/navigation-link',
		'attrs'        => array( 'label' => $t->name, 'url' => get_term_link( $t ), 'type' => 'product_cat', 'id' => (int) $t->term_id, 'kind' => 'taxonomy' ),
		'innerBlocks'  => array(),
		'innerHTML'    => '',
		'innerContent' => array(),
	);
}

/** A menu link to a top-level category (or to one that no longer exists). */
function fisha_nav_is_top_cat_link( array $b ): bool {
	if ( 'core/navigation-link' !== ( $b['blockName'] ?? '' ) || 'product_cat' !== ( $b['attrs']['type'] ?? '' ) ) return false;
	$t = get_term( (int) ( $b['attrs']['id'] ?? 0 ), 'product_cat' );
	return ! $t instanceof WP_Term || 0 === (int) $t->parent;
}

/**
 * Replaces the first run of top-level category links in a list of parsed menu blocks (or, when the list has none, inside
 * its first submenu that has them) with links to the displayed top-level categories, in the owner's order.
 */
function fisha_nav_swap_cat_links( array $blocks, ?array $tops = null ): array {
	$blocks = array_values( $blocks );
	$start  = null;
	$end    = null;
	foreach ( $blocks as $i => $b ) {
		if ( fisha_nav_is_top_cat_link( $b ) ) {
			if ( null === $start ) $start = $i;
			$end = $i;
		} elseif ( null !== $start ) {
			break;
		}
	}
	$tops = $tops ?? fisha_displayed_top_categories();
	if ( null !== $start ) {
		array_splice( $blocks, $start, $end - $start + 1, array_map( 'fisha_nav_cat_link', $tops ) );
		return $blocks;
	}
	foreach ( $blocks as $i => $b ) {
		if ( 'core/navigation-submenu' !== ( $b['blockName'] ?? '' ) || empty( $b['innerBlocks'] ) ) continue;
		$inner = fisha_nav_swap_cat_links( $b['innerBlocks'], $tops );
		if ( $inner === array_values( $b['innerBlocks'] ) ) continue;
		$b['innerBlocks']  = $inner;
		$b['innerContent'] = array_fill( 0, count( $inner ), null );
		$b['innerHTML']    = '';
		$blocks[ $i ]      = $b;
		return $blocks;
	}
	return $blocks;
}

// The header "Shop" submenu and the footer Shop column list the displayed top-level categories; the saved menus are not changed.
add_filter( 'block_core_navigation_render_inner_blocks', function ( $inner ) {
	if ( ! $inner instanceof WP_Block_List || is_admin() ) return $inner;
	$parsed = array();
	foreach ( $inner as $b ) $parsed[] = $b->parsed_block;
	$swapped = fisha_nav_swap_cat_links( $parsed );
	if ( $swapped === $parsed ) return $inner;
	$n = count( $inner );
	foreach ( $swapped as $i => $pb ) $inner[ $i ] = $pb;
	for ( $i = count( $swapped ); $i < $n; $i++ ) unset( $inner[ $i ] );
	return $inner;
} );
```

- [ ] **Step 4: Shop page** — `templates/shop-home.php`

Replace line 9 with:
```php
$top_slugs = apply_filters( 'fisha_shop_top_categories', wp_list_pluck( fisha_displayed_top_categories(), 'slug' ) );
```
Replace these lines (16-18):
```php
	$children = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $term->term_id, 'hide_empty' => false ) );
	$children = is_wp_error( $children ) ? array() : $children;
	usort( $children, fn( $a, $b ) => (int) get_term_meta( $a->term_id, 'order', true ) <=> (int) get_term_meta( $b->term_id, 'order', true ) );
```
with:
```php
	$children = fisha_cat_displayed_children( (int) $term->term_id );
```

- [ ] **Step 5: Category pages** — `templates/product-cat.php`

Replace lines 8-11 (`$children = get_terms(…)` through `usort( $children, $by_order );`) with:
```php
$children = fisha_cat_displayed_children( (int) $term->term_id );
// every sub-category (shown or not): products sitting in a hidden one do not move into the "More" shelf
$all_kids = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $term->term_id, 'hide_empty' => false, 'fields' => 'ids' ) );
$all_kids = is_wp_error( $all_kids ) ? array() : array_map( 'intval', $all_kids );
```
In the "direct" filter, replace `wp_list_pluck( $children, 'term_id' )` with `$all_kids`. Replace lines 34-35:
```php
$chip_terms = $children ? $children : ( $parent ? get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $parent->term_id, 'hide_empty' => false ) ) : array() );
if ( ! $children && $chip_terms ) usort( $chip_terms, $by_order );
```
with:
```php
$chip_terms = $children ? $children : ( $parent ? fisha_cat_displayed_children( (int) $parent->term_id ) : array() );
```
`$by_order` is no longer used; check with `grep -n by_order templates/product-cat.php` (expect no output).

- [ ] **Step 6: Home** — `home.php`

Replace `fisha_home_cat_list()` with:
```php
/** Displayed top-level categories (slug => tagline): the stored Home tagline, else the category's intro. */
function fisha_home_cat_list() {
	$list = array();
	foreach ( fisha_displayed_top_categories() as $t ) {
		$line = (string) get_term_meta( $t->term_id, 'fisha_home_line', true );
		$list[ $t->slug ] = '' !== $line ? $line : wp_strip_all_tags( (string) get_term_meta( $t->term_id, 'fisha_lead', true ) );
	}
	return apply_filters( 'fisha_home_cats', $list );
}

/** "Three ways to take the little fish home." follows the number of cards; text the page sets itself is kept. */
function fisha_home_cats_text( string $text, int $count ): string {
	if ( 'Three ways to take the little fish home.' !== $text || 3 === $count ) return $text;
	$words = array( 1 => 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten' );
	if ( ! isset( $words[ $count ] ) ) return 'Ways to take the little fish home.';
	return $words[ $count ] . ( 1 === $count ? ' way' : ' ways' ) . ' to take the little fish home.';
}
```
In the shortcode, change the header text line to `<p class="fh-cats__text"><?php echo esc_html( fisha_home_cats_text( $a['text'], count( $cards ) ) ); ?></p>`. Change the row to `<ul class="fh-cats__row<?php echo 3 !== count( $cards ) ? ' fh-cats__row--n' . (int) min( count( $cards ), 4 ) : ''; ?>">`, so 3 cards keep the exact markup. Update the file doc comment's first line to: `Home page — "Pick your Fisha": the displayed top-level shop categories as taped photos.`

- [ ] **Step 7: Home layout for 1, 2 or 4+ cards** — append to `home.css` before the `/* ---------- phones` block:

```css
/* other than three cards (a category was added or hidden in the Studio) */
.fh-cats__row--n1 { grid-template-columns: minmax(0, 26rem); justify-content: center; }
.fh-cats__row--n2 { grid-template-columns: repeat(2, minmax(0, 1fr)); max-width: 54rem; margin-inline: auto; }
.fh-cats__row--n4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
@media (max-width: 1100px) { .fh-cats__row--n4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
```
(The phones block already turns any row into a swipe row.)

- [ ] **Step 8: Run tests and the parity check**

Run: `bash tests/studio/run.sh "cat lists"` → PASS. Then `bash tests/studio/run.sh` → 197 passed, 0 failed. Then:
```bash
docker exec fisha-wordpress-1 apachectl graceful
bash tests/studio/baseline.sh > /tmp/p3-t2.txt; diff tests/studio/baseline-phase3-before.txt /tmp/p3-t2.txt
```
Expected: no output (same links in the same order, same Home cards).

- [ ] **Step 9: Live check with a real request** (fixture made and removed by WP-CLI)

```bash
W='MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp'
eval $W eval "'\$r = wp_insert_term( \"zz-test Live Top\", \"product_cat\", array( \"slug\" => \"zz-test-live-top\" ) ); update_term_meta( \$r[\"term_id\"], \"fisha_shown\", \"1\" ); \$p = new WC_Product_Simple(); \$p->set_name( \"zz-test live\" ); \$p->set_status( \"publish\" ); \$p->set_regular_price( \"10\" ); \$p->set_category_ids( array( \$r[\"term_id\"] ) ); echo \$p->save(), \" \", \$r[\"term_id\"];'"
curl -s http://localhost:8484/ | grep -c 'zz-test-live-top'
curl -s http://localhost:8484/shop-2/ | grep -c 'zz-test-live-top'
```
Expected: both counts ≥ 1 (header menu, footer, Home card on `/`; shelf and chip on Shop). The Home header text reads "Four ways to take the little fish home." Then remove the fixture with the two ids printed: `eval $W eval "'wp_delete_post( <product id>, true ); wp_delete_term( <term id>, \"product_cat\" );'"`. Re-run the baseline diff → no output.

- [ ] **Step 10: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/cat-visibility.php wp-content/mu-plugins/fisha-design/templates/shop-home.php wp-content/mu-plugins/fisha-design/templates/product-cat.php wp-content/mu-plugins/fisha-design/home.php wp-content/mu-plugins/fisha-design/home.css tests/studio/test-cat-lists.php
git commit -m "Categories: Shop, category pages, Home cards and the header/footer menus list the displayed categories

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Never-published categories stay invisible; hidden ones are not indexed; old addresses redirect

**Files:**
- Modify: `wp-content/mu-plugins/fisha-design/cat-visibility.php`, `tests/studio/helpers.php`
- Test: `tests/studio/test-cat-hiding.php`

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `fisha_cat_unpublished_ids(): int[]`
  - `fisha_cat_hiding_active(): bool` (filter `fisha_cat_hiding_active`)
  - `fisha_cat_hidden_now(): int[]` (respects `$GLOBALS['fisha_studio_preview_term']`)
  - `fisha_cat_page_status( WP_Term $t ): string` (`'ok' | 'noindex' | '404'`)
  - `fisha_cat_path( WP_Term $t ): string` (e.g. `product-category/wearables/tshirts`)
  - `fisha_cat_redirect_target( string $path ): ?string`
  - Option `fisha_deleted_cat_paths` (array path → parent term id).
  - Test helper `fs_hiding(): void`.

- [ ] **Step 1: Test helper** — append to `tests/studio/helpers.php`

```php
/** Makes this test act like a public page view: never-published categories are hidden (undone after the test). */
function fs_hiding(): void {
	add_filter( 'fisha_cat_hiding_active', '__return_true' );
	fisha_cat_cache_reset();
	fs_on_cleanup( function () {
		remove_filter( 'fisha_cat_hiding_active', '__return_true' );
		unset( $GLOBALS['fisha_studio_preview_term'] );
		fisha_cat_cache_reset();
	} );
}
```

- [ ] **Step 2: Write the failing tests** — `tests/studio/test-cat-hiding.php`

```php
<?php
fs_test( 'cat hiding: a never-published category is invisible to the public site only', function () {
	$t = fs_cat( 'hide-new' );
	update_term_meta( $t->term_id, 'fisha_studio_hidden', '1' );
	$p = fs_product( array( 'category_ids' => array( $t->term_id ) ) );
	fisha_cat_cache_reset();
	$ids = function () { return get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) ); };
	fs_true( in_array( $t->term_id, array_map( 'intval', $ids() ), true ), 'Studio/CLI still sees it' );
	fs_hiding();
	fs_false( in_array( $t->term_id, array_map( 'intval', $ids() ), true ), 'public lists skip it' );
	fs_eq( array(), (array) get_the_terms( $p->get_id(), 'product_cat' ), 'product page meta skips it' );
	$GLOBALS['fisha_studio_preview_term'] = $t->term_id;
	fs_true( in_array( $t->term_id, array_map( 'intval', $ids() ), true ), 'its own preview sees it' );
} );

fs_test( 'cat hiding: page status is 404 for never published, noindex for hidden, ok for shown', function () {
	$shown = fs_cat( 'status-shown' );
	$off   = fs_cat( 'status-off', 0, false );
	$kid   = fs_cat( 'status-kid', $off->term_id );
	$new   = fs_cat( 'status-new' );
	update_term_meta( $new->term_id, 'fisha_studio_hidden', '1' );
	fs_eq( 'ok', fisha_cat_page_status( $shown ) );
	fs_eq( 'noindex', fisha_cat_page_status( $off ) );
	fs_eq( 'noindex', fisha_cat_page_status( $kid ), 'parent hidden' );
	fs_eq( '404', fisha_cat_page_status( $new ) );
} );

fs_test( 'cat hiding: hidden categories are left out of the sitemap', function () {
	$on  = fs_cat( 'map-on' );
	$off = fs_cat( 'map-off', 0, false );
	$args = apply_filters( 'wp_sitemaps_taxonomies_query_args', array( 'taxonomy' => 'product_cat' ), 'product_cat' );
	fs_true( in_array( $off->term_id, (array) ( $args['exclude'] ?? array() ), true ), 'hidden excluded' );
	fs_false( in_array( $on->term_id, (array) ( $args['exclude'] ?? array() ), true ), 'shown kept' );
} );

fs_test( 'cat hiding: an old path redirects to the category\'s address now', function () {
	$t = fs_cat( 'redir' );
	add_term_meta( $t->term_id, 'fisha_old_paths', 'product-category/zz-test-old-place' );
	fs_eq( get_term_link( $t ), fisha_cat_redirect_target( '/product-category/zz-test-old-place/' ) );
	fs_eq( null, fisha_cat_redirect_target( fisha_cat_path( $t ) ), 'current address never redirects' );
	fs_eq( null, fisha_cat_redirect_target( 'product-category/zz-test-nothing' ) );
} );

fs_test( 'cat hiding: a deleted category\'s path goes to its parent, or the Shop', function () {
	$parent = fs_cat( 'redir-parent' );
	$before = get_option( 'fisha_deleted_cat_paths', null );
	fs_on_cleanup( function () use ( $before ) { null === $before ? delete_option( 'fisha_deleted_cat_paths' ) : update_option( 'fisha_deleted_cat_paths', $before, false ); } );
	update_option( 'fisha_deleted_cat_paths', array( 'product-category/zz-test-gone' => $parent->term_id, 'product-category/zz-test-gone-top' => 0 ), false );
	fs_eq( get_term_link( $parent ), fisha_cat_redirect_target( 'product-category/zz-test-gone' ) );
	fs_eq( get_permalink( wc_get_page_id( 'shop' ) ), fisha_cat_redirect_target( 'product-category/zz-test-gone-top' ) );
} );
```

- [ ] **Step 3: Run them to see them fail**

Run: `bash tests/studio/run.sh "cat hiding"` → FAIL (undefined functions; the never-published term is still listed).

- [ ] **Step 4: Implement** — append to `cat-visibility.php`

```php
/* ---------- never-published categories, hidden ones, old addresses ---------- */

/** Ids of categories made in the Studio and not published yet. @return int[] */
function fisha_cat_unpublished_ids(): array {
	global $wpdb;
	$cache = &$GLOBALS['fisha_cat_unpublished_cache'];
	if ( ! is_array( $cache ) ) {
		$cache = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = '1'", FISHA_CAT_UNPUBLISHED ) ) );
	}
	return $cache;
}

/** Whether this request hides never-published categories: public pages, not WP admin, the REST API, WP-CLI or cron. */
function fisha_cat_hiding_active(): bool {
	$public = ! is_admin() && ! wp_doing_cron() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! ( defined( 'WP_CLI' ) && WP_CLI );
	return (bool) apply_filters( 'fisha_cat_hiding_active', $public );
}

/** The never-published categories to hide right now (the one in the Studio preview stays visible). @return int[] */
function fisha_cat_hidden_now(): array {
	if ( ! fisha_cat_hiding_active() ) return array();
	$exempt = (int) ( $GLOBALS['fisha_studio_preview_term'] ?? 0 );
	return array_values( array_diff( fisha_cat_unpublished_ids(), array( $exempt ) ) );
}

// Every category list (menus, chips, shelves, widgets, breadcrumbs, a product's categories) skips them. The clause is part of
// the term query's SQL, so WordPress caches public and Studio results separately.
add_filter( 'terms_clauses', function ( $clauses, $taxonomies ) {
	if ( ! in_array( 'product_cat', (array) $taxonomies, true ) ) return $clauses;
	$ids = fisha_cat_hidden_now();
	if ( $ids ) $clauses['where'] .= ' AND t.term_id NOT IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
	return $clauses;
}, 10, 2 );
add_filter( 'get_the_terms', function ( $terms, $post_id, $taxonomy ) {
	if ( 'product_cat' !== $taxonomy || ! is_array( $terms ) ) return $terms;
	$ids = fisha_cat_hidden_now();
	if ( ! $ids ) return $terms;
	return array_values( array_filter( $terms, function ( $t ) use ( $ids ) { return ! in_array( (int) $t->term_id, $ids, true ); } ) );
}, 10, 3 );

/** 'ok', 'noindex' (switched off, or below a switched-off category) or '404' (never published) for a category page. */
function fisha_cat_page_status( WP_Term $t ): string {
	if ( fisha_cat_is_unpublished( $t ) ) return '404';
	return fisha_cat_is_enabled( $t ) ? 'ok' : 'noindex';
}

add_action( 'template_redirect', function () {
	if ( ! is_tax( 'product_cat' ) ) return;
	$t = get_queried_object();
	if ( ! $t instanceof WP_Term ) return;
	$status = fisha_cat_page_status( $t );
	if ( '404' === $status ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	} elseif ( 'noindex' === $status ) {
		header( 'X-Robots-Tag: noindex' );
	}
}, 5 );
add_filter( 'wp_robots', function ( $robots ) {
	$t = is_tax( 'product_cat' ) ? get_queried_object() : null;
	if ( $t instanceof WP_Term && 'ok' !== fisha_cat_page_status( $t ) ) $robots['noindex'] = true;
	return $robots;
} );
add_filter( 'wp_sitemaps_taxonomies_query_args', function ( $args, $taxonomy ) {
	if ( 'product_cat' !== $taxonomy ) return $args;
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	$off   = array();
	foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
		if ( ! fisha_cat_is_enabled( $t ) ) $off[] = (int) $t->term_id;
	}
	if ( $off ) $args['exclude'] = array_merge( (array) ( $args['exclude'] ?? array() ), $off );
	return $args;
}, 10, 2 );

/** A category's address without the site and the slashes around it: "product-category/wearables/tshirts". */
function fisha_cat_path( WP_Term $t ): string {
	$link = get_term_link( $t );
	return is_wp_error( $link ) ? '' : trim( (string) wp_parse_url( $link, PHP_URL_PATH ), '/' );
}

/** Where an old category address lives now (the moved category, or the parent page / Shop after a delete), or null. */
function fisha_cat_redirect_target( string $path ): ?string {
	$path = trim( $path, '/' );
	if ( '' === $path ) return null;
	$found = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1, 'meta_query' => array( array( 'key' => FISHA_CAT_OLD_PATHS, 'value' => $path ) ) ) );
	if ( ! is_wp_error( $found ) && $found ) {
		return fisha_cat_path( $found[0] ) === $path ? null : get_term_link( $found[0] );
	}
	$deleted = get_option( 'fisha_deleted_cat_paths', array() );
	if ( ! is_array( $deleted ) || ! array_key_exists( $path, $deleted ) ) return null;
	$to = $deleted[ $path ] ? get_term( (int) $deleted[ $path ], 'product_cat' ) : null;
	$url = $to instanceof WP_Term ? get_term_link( $to ) : get_permalink( wc_get_page_id( 'shop' ) );
	return is_wp_error( $url ) || ! $url ? null : $url;
}

// Old addresses answer with a permanent redirect. A moved category's old address can still open its page (WordPress reads
// only the last part), so category pages are checked too, not only 404s.
add_action( 'template_redirect', function () {
	if ( ! is_404() && ! is_tax( 'product_cat' ) ) return;
	$path = rawurldecode( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) );
	$to   = fisha_cat_redirect_target( $path );
	if ( $to ) {
		wp_safe_redirect( $to, 301, 'Fisha' );
		exit;
	}
}, 1 );
```

- [ ] **Step 5: Run the tests to see them pass**

Run: `bash tests/studio/run.sh "cat hiding"` → PASS. Run `bash tests/studio/run.sh` → 202 passed, 0 failed.

- [ ] **Step 6: Parity**

Run `docker exec fisha-wordpress-1 apachectl graceful`, then the baseline diff from Task 2 Step 8 → no output.

- [ ] **Step 7: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/cat-visibility.php tests/studio/helpers.php tests/studio/test-cat-hiding.php
git commit -m "Categories: never-published ones stay invisible, hidden ones are not indexed, old addresses redirect

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Studio category model (fields, automatic design fields, create, edit, draft)

**Files:**
- Create: `wp-content/mu-plugins/fisha-design/studio/studio-categories.php`
- Modify: `studio/studio-drafts.php` (shared draft helpers), `studio/studio.php` (require after `studio-publish.php`), `tests/studio/helpers.php`
- Test: `tests/studio/test-categories.php`

**Interfaces:**
- Consumes: Task 1 (`FISHA_CAT_SHOWN`, `FISHA_CAT_UNPUBLISHED`, `fisha_cat_is_unpublished`, `fisha_cat_cache_reset`).
- Produces (studio-drafts.php):
  - `fisha_studio_draft_get( int $id, string $meta_type = 'post' ): array`
  - `fisha_studio_draft_put( int $id, array $d, string $meta_type = 'post' ): array`
  - `fisha_studio_draft_merge( array $d, array $live, array $values, array $seen ): array`
  - `fisha_studio_draft_conflicts( array $draft, array $live, callable $label ): array`
- Produces (studio-categories.php):
  - `fisha_studio_category( int $id ): ?WP_Term`
  - `fisha_studio_category_fields(): array` (keys `name, slug, parent, lead, hero_ids, shown`)
  - `fisha_studio_category_values( WP_Term $t ): array`
  - `fisha_studio_category_state( WP_Term $t ): string` (`new|changed|live`)
  - `fisha_studio_category_auto( array $values ): array` (`fisha_h1, fisha_eyebrow, fisha_seo_title, fisha_home_line`)
  - `fisha_studio_category_write_auto( WP_Term $t, array $new, ?array $old ): void`
  - `fisha_studio_category_set_meta( int $id, string $key, string $value ): void`
  - `fisha_studio_category_slug_taken( string $slug, int $id ): bool`
  - `fisha_studio_unique_cat_slug( string $slug, int $id ): string`
  - `fisha_studio_category_name_taken( string $name, int $parent, int $id ): bool`
  - `fisha_studio_category_parent_error( WP_Term $t, int $parent ): ?string`
  - `fisha_studio_category_has_photo_products( WP_Term $t ): bool`
  - `fisha_studio_category_validate( array $v, WP_Term $t ): array`
  - `fisha_studio_category_next_order( int $parent, int $except ): int`
  - `fisha_studio_category_create( string $name = '', int $parent = 0 ): WP_Term|WP_Error`
  - `fisha_studio_category_edit( WP_Term $t, array $values, array $seen = array() ): array` (field errors)
  - `fisha_studio_category_draft_save( WP_Term $t, array $values, array $seen = array() ): array`
  - `fisha_studio_category_conflicts( WP_Term $t, array $draft ): array`
  - Test helper `fs_studio_cat( string $name = '', int $parent = 0 ): WP_Term`.

- [ ] **Step 1: Test helper** — append to `tests/studio/helpers.php`

```php
/** A never-published category made by the Studio (removed with its audit rows after the test). */
function fs_studio_cat( string $name = '', int $parent = 0 ): WP_Term {
	$t = fisha_studio_category_create( $name, $parent );
	if ( is_wp_error( $t ) ) throw new Fs_Fail( $t->get_error_message() );
	$id = $t->term_id;
	fs_on_cleanup( function () use ( $id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'fisha_studio_log', array( 'item_type' => 'category', 'item_id' => $id ) );
		wp_delete_term( $id, 'product_cat' );
	} );
	return $t;
}
```

- [ ] **Step 2: Write the failing tests** — `tests/studio/test-categories.php`

```php
<?php
fs_test( 'categories: create makes a hidden, shown-when-published category with automatic design fields', function () {
	$w = get_term_by( 'slug', 'wearables', 'product_cat' );
	$t = fs_studio_cat( 'zz-test Winter Scarves', $w->term_id );
	fs_eq( '1', get_term_meta( $t->term_id, 'fisha_studio_hidden', true ), 'not published yet' );
	fs_eq( '1', get_term_meta( $t->term_id, '_fisha_studio_created', true ) );
	fs_eq( '1', get_term_meta( $t->term_id, 'fisha_shown', true ), 'Show on site defaults on' );
	fs_eq( 'zz-test-winter-scarves', $t->slug );
	fs_eq( 'zz-test Winter <span>Scarves</span>', get_term_meta( $t->term_id, 'fisha_h1', true ) );
	fs_eq( 'Wearables', get_term_meta( $t->term_id, 'fisha_eyebrow', true ) );
	fs_eq( 'new', fisha_studio_category_state( $t ) );
	$v = fisha_studio_category_values( $t );
	fs_eq( array( 'name', 'slug', 'parent', 'lead', 'hero_ids', 'shown' ), array_keys( $v ) );
	fs_eq( (int) $w->term_id, $v['parent'] );
	fs_true( (int) get_term_meta( $t->term_id, 'order', true ) > 0, 'last among its siblings' );
} );

fs_test( 'categories: new names never clash, a sibling\'s name is refused', function () {
	$a = fs_studio_cat( 'zz-test Clash' );
	$b = fs_studio_cat( 'zz-test Clash' );
	fs_eq( 'zz-test Clash 2', $b->name );
	$errors = fisha_studio_category_edit( $b, array( 'name' => 'zz-test Clash' ) );
	fs_true( isset( $errors['name'] ), 'refused' );
	fs_eq( 'zz-test Clash 2', get_term( $b->term_id, 'product_cat' )->name, 'name unchanged' );
	fs_true( isset( fisha_studio_category_validate( array_merge( fisha_studio_category_values( $b ), array( 'name' => 'ZZ-TEST clash' ) ), $b )['name'] ), 'case-insensitive' );
} );

fs_test( 'categories: automatic fields follow the name, parent and intro', function () {
	$a = fisha_studio_category_auto( array( 'name' => 'Hats & Caps', 'parent' => 0, 'lead' => 'Caps with the fish. Embroidered by hand.' ) );
	fs_eq( 'Hats &amp; <span>Caps</span>', $a['fisha_h1'] );
	fs_eq( 'Fisha Shop', $a['fisha_eyebrow'] );
	fs_eq( 'Hats & Caps | Caps with the fish.', $a['fisha_seo_title'] );
	fs_eq( 'Caps with the fish. Embroidered by hand.', $a['fisha_home_line'] );
	fs_eq( '<span>Socks</span>', fisha_studio_category_auto( array( 'name' => 'Socks', 'parent' => 0, 'lead' => '' ) )['fisha_h1'] );
	$long = fisha_studio_category_auto( array( 'name' => 'Collectibles', 'parent' => 0, 'lead' => 'Hand-painted lighters and small objects carrying the little hand-drawn fish everywhere.' ) );
	fs_true( mb_strlen( $long['fisha_seo_title'] ) <= 60, 'SEO title at most 60' );
	fs_false( '|' === substr( rtrim( $long['fisha_seo_title'] ), -1 ), 'no dangling separator' );
} );

fs_test( 'categories: edits to a never-published category apply at once; the address follows the name until typed', function () {
	$t = fs_studio_cat( 'zz-test Edit Me' );
	$img = fs_image();
	$errors = fisha_studio_category_edit( $t, array( 'name' => 'zz-test Edited', 'lead' => 'A short intro.', 'hero_ids' => array( $img ), 'shown' => false ) );
	fs_eq( array(), $errors );
	$t = get_term( $t->term_id, 'product_cat' );
	fs_eq( 'zz-test Edited', $t->name );
	fs_eq( 'zz-test-edited', $t->slug, 'slug follows the name' );
	fs_eq( 'A short intro.', get_term_meta( $t->term_id, 'fisha_lead', true ) );
	fs_eq( (string) $img, get_term_meta( $t->term_id, 'fisha_hero_ids', true ) );
	fs_eq( '0', get_term_meta( $t->term_id, 'fisha_shown', true ) );
	fs_eq( 'zz-test <span>Edited</span>', get_term_meta( $t->term_id, 'fisha_h1', true ) );
	fs_eq( 'zz-test Edited | A short intro.', get_term_meta( $t->term_id, 'fisha_seo_title', true ) );
	fisha_studio_category_edit( $t, array( 'slug' => 'zz-test-my-own' ) );
	fisha_studio_category_edit( get_term( $t->term_id, 'product_cat' ), array( 'name' => 'zz-test Renamed' ) );
	fs_eq( 'zz-test-my-own', get_term( $t->term_id, 'product_cat' )->slug, 'typed address kept' );
	fs_eq( array(), fisha_studio_draft_get( $t->term_id, 'term' )['fields'], 'no draft for a never-published one' );
} );

fs_test( 'categories: a category cannot move inside itself', function () {
	$top = fs_studio_cat( 'zz-test Loop Top' );
	$kid = fs_studio_cat( 'zz-test Loop Kid', $top->term_id );
	$errors = fisha_studio_category_edit( $top, array( 'parent' => $kid->term_id ) );
	fs_true( isset( $errors['parent'] ) );
	fs_eq( 0, (int) get_term( $top->term_id, 'product_cat' )->parent );
	fs_true( isset( fisha_studio_category_edit( $top, array( 'parent' => $top->term_id ) )['parent'] ), 'not its own parent' );
} );

fs_test( 'categories: edits to a published category wait in a draft with the seen base', function () {
	$t = fs_cat( 'draft-live' );
	update_term_meta( $t->term_id, 'fisha_lead', 'Live intro.' );
	fisha_studio_category_edit( $t, array( 'lead' => 'Draft intro.', 'name' => 'zz-test draft-live renamed' ), array( 'lead' => 'Live intro.' ) );
	fs_eq( 'Live intro.', get_term_meta( $t->term_id, 'fisha_lead', true ), 'live untouched' );
	fs_eq( 'zz-test draft-live', get_term( $t->term_id, 'product_cat' )->name, 'live name untouched' );
	$d = fisha_studio_draft_get( $t->term_id, 'term' );
	fs_eq( 'Draft intro.', $d['fields']['lead'] );
	fs_eq( 'Live intro.', $d['base']['lead'] );
	fs_eq( 'changed', fisha_studio_category_state( $t ) );
	update_term_meta( $t->term_id, 'fisha_lead', 'Changed elsewhere.' );
	$c = fisha_studio_category_conflicts( $t, $d );
	fs_eq( array( 'lead' ), wp_list_pluck( $c, 'field' ) );
	fs_eq( 'Intro', $c[0]['label'] );
	fisha_studio_category_edit( $t, array( 'name' => 'zz-test draft-live' ) );
	fs_false( isset( fisha_studio_draft_get( $t->term_id, 'term' )['fields']['name'] ), 'back to live value leaves the draft' );
} );

fs_test( 'categories: validation needs a name, an intro and a hero photo unless products have photos', function () {
	$t = fs_studio_cat( 'zz-test Validate' );
	$v = fisha_studio_category_values( $t );
	$e = fisha_studio_category_validate( array_merge( $v, array( 'name' => '' ) ), $t );
	fs_true( isset( $e['name'], $e['lead'], $e['hero_ids'] ), 'name, intro and photo' );
	fs_product( array( 'category_ids' => array( $t->term_id ), 'image_id' => fs_image() ) );
	$e = fisha_studio_category_validate( array_merge( $v, array( 'lead' => 'Intro.' ) ), $t );
	fs_false( isset( $e['hero_ids'] ), 'product photo is enough' );
	fs_true( isset( fisha_studio_category_validate( array_merge( $v, array( 'lead' => 'x', 'hero_ids' => array( fs_image(), fs_image(), fs_image() ) ) ), $t )['hero_ids'] ), 'at most two' );
	fs_true( isset( fisha_studio_category_validate( array_merge( $v, array( 'lead' => 'x', 'slug' => 'studio' ) ), $t )['slug'] ), 'reserved address' );
} );
```

- [ ] **Step 3: Run them to see them fail**

Run: `bash tests/studio/run.sh "categories:"` → FAIL (undefined `fisha_studio_category_create` etc.).

- [ ] **Step 4: Shared draft helpers** — `studio/studio-drafts.php`

Replace `fisha_studio_draft_get`, `fisha_studio_draft_put`, `fisha_studio_draft_save` and `fisha_studio_conflicts` with:

```php
/** The draft of a product (meta type 'post') or a category ('term'). */
function fisha_studio_draft_get( int $id, string $meta_type = 'post' ): array {
	$d = get_metadata( $meta_type, $id, FISHA_STUDIO_DRAFT_META, true );
	$empty = array( 'fields' => array(), 'base' => array(), 'user' => 0, 'updated' => 0 );
	return ( is_array( $d ) && isset( $d['fields'] ) && is_array( $d['fields'] ) ) ? array_merge( $empty, $d ) : $empty;
}

/** Stores a draft (or removes it when it has no fields). */
function fisha_studio_draft_put( int $id, array $d, string $meta_type = 'post' ): array {
	if ( empty( $d['fields'] ) ) {
		delete_metadata( $meta_type, $id, FISHA_STUDIO_DRAFT_META );
		return fisha_studio_draft_get( $id, $meta_type );
	}
	update_metadata( $meta_type, $id, FISHA_STUDIO_DRAFT_META, wp_slash( $d ) );
	return $d;
}

/**
 * Puts edited values into a draft. Each field keeps the value the owner saw when it first entered the draft (its base:
 * $seen, else the live value); a field set back to its live value while live did not move leaves the draft.
 */
function fisha_studio_draft_merge( array $d, array $live, array $values, array $seen ): array {
	foreach ( $values as $k => $v ) {
		if ( ! array_key_exists( $k, $live ) ) continue;
		if ( ! array_key_exists( $k, $d['base'] ) ) $d['base'][ $k ] = array_key_exists( $k, $seen ) ? $seen[ $k ] : $live[ $k ];
		$d['fields'][ $k ] = $v;
		if ( fisha_studio_same( $v, $live[ $k ] ) && fisha_studio_same( $d['base'][ $k ], $live[ $k ] ) ) {
			unset( $d['fields'][ $k ], $d['base'][ $k ] );
		}
	}
	$d['user']    = get_current_user_id();
	$d['updated'] = time();
	return $d;
}

/** Draft fields whose live value moved since the owner saw it and differs from theirs; $label names a field for the owner. */
function fisha_studio_draft_conflicts( array $draft, array $live, callable $label ): array {
	$out = array();
	foreach ( $draft['fields'] as $k => $mine ) {
		if ( ! array_key_exists( $k, $draft['base'] ) || ! array_key_exists( $k, $live ) ) continue;
		if ( fisha_studio_same( $draft['base'][ $k ], $live[ $k ] ) || fisha_studio_same( $mine, $live[ $k ] ) ) continue;
		$out[] = array( 'field' => $k, 'label' => $label( (string) $k ), 'base' => $draft['base'][ $k ], 'live' => $live[ $k ], 'mine' => $mine );
	}
	return $out;
}

/** $seen: cleaned values the owner had on screen, keyed by field; the base of a field entering the draft. */
function fisha_studio_draft_save( WC_Product $p, array $values, array $seen = array() ): array {
	return fisha_studio_draft_put( $p->get_id(), fisha_studio_draft_merge( fisha_studio_draft_get( $p->get_id() ), fisha_studio_product_values( $p ), $values, $seen ) );
}

function fisha_studio_conflicts( WC_Product $p, array $draft ): array {
	return fisha_studio_draft_conflicts( $draft, fisha_studio_product_values( $p ), function ( string $k ) use ( $p ) { return fisha_studio_value_label( $p, $k ); } );
}
```

Run `bash tests/studio/run.sh draft` and `bash tests/studio/run.sh publish`. Both stay green: product behaviour is unchanged.

- [ ] **Step 5: Implement** — create `studio/studio-categories.php`

```php
<?php
/**
 * Fisha Studio categories: the fields the owner edits (name, address, parent, intro, hero photos, Show on site), the design
 * fields filled automatically (heading, small line above it, Google title, Home tagline), creating a category, and edits.
 * A never-published category (term meta fisha_studio_hidden = 1) takes edits at once and the public site cannot see it;
 * a published one keeps edits in a draft (term meta _fisha_studio_draft) until Publish (studio-categories-publish.php).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_category( int $id ): ?WP_Term {
	$t = $id ? get_term( $id, 'product_cat' ) : null;
	return $t instanceof WP_Term ? $t : null;
}

function fisha_studio_category_fields(): array {
	static $fields = null;
	if ( null !== $fields ) return $fields;
	$meta = function ( string $key ) {
		return function ( WP_Term $t ) use ( $key ) { return (string) get_term_meta( $t->term_id, $key, true ); };
	};
	$fields = array(
		'name'     => array( 'type' => 'text', 'label' => 'Name', 'max' => 60, 'get' => function ( WP_Term $t ) { return $t->name; } ),
		'slug'     => array( 'type' => 'slug', 'label' => 'Address', 'get' => function ( WP_Term $t ) { return $t->slug; } ),
		'parent'   => array( 'type' => 'int', 'label' => 'Parent', 'min' => 0, 'get' => function ( WP_Term $t ) { return (int) $t->parent; } ),
		'lead'     => array( 'type' => 'text', 'label' => 'Intro', 'max' => 300, 'get' => $meta( 'fisha_lead' ) ),
		'hero_ids' => array( 'type' => 'attachments', 'label' => 'Hero photos', 'get' => $meta( 'fisha_hero_ids' ) ),
		'shown'    => array( 'type' => 'bool', 'label' => 'Show on site', 'get' => function ( WP_Term $t ) { return '1' === (string) get_term_meta( $t->term_id, FISHA_CAT_SHOWN, true ); } ),
	);
	return $fields;
}

/** Live values of every field, cleaned to the shapes the Studio sends. */
function fisha_studio_category_values( WP_Term $t ): array {
	$out = array();
	foreach ( fisha_studio_category_fields() as $key => $def ) $out[ $key ] = fisha_studio_clean( $def, call_user_func( $def['get'], $t ) )[0];
	return $out;
}

function fisha_studio_category_state( WP_Term $t ): string {
	if ( fisha_cat_is_unpublished( $t ) ) return 'new';
	return fisha_studio_draft_get( $t->term_id, 'term' )['fields'] ? 'changed' : 'live';
}

/** The design fields the Studio fills for a category from its name, parent and intro (spec §4). */
function fisha_studio_category_auto( array $v ): array {
	$name  = trim( (string) $v['name'] );
	$words = preg_split( '/\s+/u', $name );
	$last  = (string) array_pop( $words );
	$h1    = ( $words ? esc_html( implode( ' ', $words ) ) . ' ' : '' ) . '<span>' . esc_html( $last ) . '</span>';
	$parent = ! empty( $v['parent'] ) ? fisha_studio_category( (int) $v['parent'] ) : null;
	$lead   = trim( (string) $v['lead'] );
	$first  = preg_match( '/^.*?[.!?](?=\s|$)/u', $lead, $m ) ? $m[0] : $lead;
	$seo    = '' !== $first ? $name . ' | ' . $first : $name;
	if ( mb_strlen( $seo ) > 60 ) {
		$seo = mb_substr( $seo, 0, 60 );
		$cut = mb_strrpos( $seo, ' ' );
		if ( false !== $cut && $cut > 20 ) $seo = mb_substr( $seo, 0, $cut );
		$seo = rtrim( $seo, ' ,.;:-|' );
	}
	return array(
		'fisha_h1'        => $h1,
		'fisha_eyebrow'   => $parent ? fisha_studio_plain( $parent->name ) : 'Fisha Shop',
		'fisha_seo_title' => $seo,
		'fisha_home_line' => $lead,
	);
}

/**
 * Writes the automatic design fields. With no $old (never published) all of them; otherwise only a field whose stored
 * value still equals the automatic value for $old, so hand-written headings and taglines are never overwritten.
 */
function fisha_studio_category_write_auto( WP_Term $t, array $new, ?array $old ): void {
	$next = fisha_studio_category_auto( $new );
	$prev = null === $old ? null : fisha_studio_category_auto( $old );
	foreach ( $next as $key => $val ) {
		if ( null !== $prev && (string) get_term_meta( $t->term_id, $key, true ) !== $prev[ $key ] ) continue;
		fisha_studio_category_set_meta( $t->term_id, $key, $val );
	}
}

/** Stores a text meta value, or removes it when empty. */
function fisha_studio_category_set_meta( int $id, string $key, string $value ): void {
	if ( '' === $value ) delete_term_meta( $id, $key );
	else update_term_meta( $id, $key, wp_slash( $value ) );
}

function fisha_studio_category_slug_taken( string $slug, int $id ): bool {
	$other = get_term_by( 'slug', $slug, 'product_cat' );
	return $other instanceof WP_Term && (int) $other->term_id !== $id;
}

/** $slug, or $slug-2, -3… when another category (or the site) uses it. */
function fisha_studio_unique_cat_slug( string $slug, int $id ): string {
	$base = in_array( $slug, fisha_studio_reserved_slugs(), true ) ? $slug . '-2' : $slug;
	$try  = $base;
	for ( $n = 2; fisha_studio_category_slug_taken( $try, $id ); $n++ ) $try = $base . '-' . $n;
	return $try;
}

/** Whether another category under the same parent has this name (case does not matter). */
function fisha_studio_category_name_taken( string $name, int $parent, int $id ): bool {
	$siblings = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $parent, 'hide_empty' => false ) );
	foreach ( is_wp_error( $siblings ) ? array() : $siblings as $s ) {
		if ( (int) $s->term_id !== $id && 0 === strcasecmp( fisha_studio_plain( $s->name ), trim( $name ) ) ) return true;
	}
	return false;
}

function fisha_studio_category_parent_error( WP_Term $t, int $parent ): ?string {
	if ( ! $parent ) return null;
	if ( ! fisha_studio_category( $parent ) ) return 'That parent category no longer exists.';
	if ( $parent === (int) $t->term_id || in_array( (int) $t->term_id, array_map( 'intval', get_ancestors( $parent, 'product_cat' ) ), true ) ) {
		return 'A category cannot sit inside itself.';
	}
	return null;
}

/** Whether a published product of this category (or below it) has a photo, which the page uses when there is no hero photo. */
function fisha_studio_category_has_photo_products( WP_Term $t ): bool {
	foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 20, 'category' => array( $t->slug ) ) ) as $p ) {
		if ( $p->get_image_id() ) return true;
	}
	return false;
}

/** Errors for a complete set of values (live merged with the draft), keyed by field. */
function fisha_studio_category_validate( array $v, WP_Term $t ): array {
	$e = array();
	if ( '' === trim( (string) $v['name'] ) ) $e['name'] = 'Give the category a name.';
	elseif ( fisha_studio_category_name_taken( (string) $v['name'], (int) $v['parent'], (int) $t->term_id ) ) $e['name'] = 'Another category in the same place already has this name.';
	if ( '' === trim( (string) $v['lead'] ) ) $e['lead'] = 'Write a short intro (one or two sentences).';
	if ( count( (array) $v['hero_ids'] ) > 2 ) $e['hero_ids'] = 'Use one or two hero photos.';
	elseif ( ! $v['hero_ids'] && ! fisha_studio_category_has_photo_products( $t ) ) $e['hero_ids'] = 'Add a hero photo. A category without product photos needs one.';
	$parent = fisha_studio_category_parent_error( $t, (int) $v['parent'] );
	if ( $parent ) $e['parent'] = $parent;
	$slug = fisha_studio_slug_error( (string) $v['slug'] );
	if ( $slug ) $e['slug'] = $slug;
	elseif ( '' !== (string) $v['slug'] && fisha_studio_category_slug_taken( (string) $v['slug'], (int) $t->term_id ) ) $e['slug'] = 'Another category already uses this address.';
	return $e;
}

/** One more than the highest "order" among the categories under $parent (so a category lands last). */
function fisha_studio_category_next_order( int $parent, int $except ): int {
	$max      = 0;
	$siblings = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $parent, 'hide_empty' => false, 'exclude' => array( $except ) ) );
	foreach ( is_wp_error( $siblings ) ? array() : $siblings as $s ) $max = max( $max, (int) get_term_meta( $s->term_id, 'order', true ) );
	return $max + 1;
}

/** @return WP_Term|WP_Error A never-published category, last among its siblings ("New category", "New category 2"… when no name is given). */
function fisha_studio_category_create( string $name = '', int $parent = 0 ) {
	$name = trim( sanitize_text_field( $name ) );
	$base = '' !== $name ? $name : 'New category';
	$try  = $base;
	for ( $n = 2; fisha_studio_category_name_taken( $try, $parent, 0 ); $n++ ) $try = $base . ' ' . $n;
	$r = wp_insert_term( $try, 'product_cat', array( 'parent' => $parent, 'slug' => fisha_studio_unique_cat_slug( fisha_studio_slug_from_name( $try ), 0 ) ) );
	if ( is_wp_error( $r ) ) return $r;
	$id = (int) $r['term_id'];
	update_term_meta( $id, FISHA_CAT_UNPUBLISHED, '1' );
	update_term_meta( $id, FISHA_STUDIO_CREATED_META, '1' );
	update_term_meta( $id, FISHA_CAT_SHOWN, '1' );
	update_term_meta( $id, 'order', fisha_studio_category_next_order( $parent, $id ) );
	fisha_cat_cache_reset();
	$t = get_term( $id, 'product_cat' );
	fisha_studio_category_write_auto( $t, fisha_studio_category_values( $t ), null );
	return $t;
}

function fisha_studio_category_draft_save( WP_Term $t, array $values, array $seen = array() ): array {
	$id = (int) $t->term_id;
	return fisha_studio_draft_put( $id, fisha_studio_draft_merge( fisha_studio_draft_get( $id, 'term' ), fisha_studio_category_values( $t ), $values, $seen ), 'term' );
}

function fisha_studio_category_conflicts( WP_Term $t, array $draft ): array {
	$labels = wp_list_pluck( fisha_studio_category_fields(), 'label' );
	return fisha_studio_draft_conflicts( $draft, fisha_studio_category_values( $t ), function ( string $k ) use ( $labels ) { return $labels[ $k ] ?? $k; } );
}

/**
 * The one entry point for an edit from the Studio ($values and $seen already cleaned). A move inside itself is refused
 * in both cases. A published category keeps the rest in its draft; a never-published one takes it at once (its address
 * follows its name until the owner types one). @return array Field errors for values that were not applied.
 */
function fisha_studio_category_edit( WP_Term $t, array $values, array $seen = array() ): array {
	$errors = array();
	if ( array_key_exists( 'parent', $values ) && ( $err = fisha_studio_category_parent_error( $t, (int) $values['parent'] ) ) ) {
		$errors['parent'] = $err;
		unset( $values['parent'] );
	}
	if ( ! fisha_cat_is_unpublished( $t ) ) {
		if ( $values ) fisha_studio_category_draft_save( $t, $values, $seen );
		return $errors;
	}

	$id  = (int) $t->term_id;
	$old = fisha_studio_category_values( $t );
	$new = array_merge( $old, $values );
	if ( array_key_exists( 'name', $values ) ) {
		if ( '' === trim( (string) $values['name'] ) ) $errors['name'] = 'Give the category a name.';
		elseif ( fisha_studio_category_name_taken( (string) $values['name'], (int) $new['parent'], $id ) ) $errors['name'] = 'Another category in the same place already has this name.';
		if ( isset( $errors['name'] ) ) $new['name'] = $old['name'];
	}
	if ( array_key_exists( 'slug', $values ) ) {
		$err = fisha_studio_slug_error( (string) $values['slug'] );
		if ( $err ) {
			$errors['slug'] = $err;
			$new['slug']    = $old['slug'];
		} else {
			update_term_meta( $id, '_fisha_studio_slug_manual', '' !== $values['slug'] ? '1' : '' );
		}
	}
	if ( ! get_term_meta( $id, '_fisha_studio_slug_manual', true ) ) $new['slug'] = fisha_studio_slug_from_name( (string) $new['name'] );

	$args = array();
	if ( $new['name'] !== $old['name'] ) $args['name'] = $new['name'];
	if ( (int) $new['parent'] !== (int) $old['parent'] ) $args['parent'] = (int) $new['parent'];
	if ( $new['slug'] !== $old['slug'] ) $args['slug'] = fisha_studio_unique_cat_slug( (string) $new['slug'], $id );
	if ( $args ) {
		$r = wp_update_term( $id, 'product_cat', $args );
		if ( is_wp_error( $r ) ) $errors[ isset( $args['name'] ) ? 'name' : 'slug' ] = $r->get_error_message();
		elseif ( isset( $args['parent'] ) ) update_term_meta( $id, 'order', fisha_studio_category_next_order( (int) $args['parent'], $id ) );
	}
	if ( array_key_exists( 'lead', $values ) ) fisha_studio_category_set_meta( $id, 'fisha_lead', (string) $values['lead'] );
	if ( array_key_exists( 'hero_ids', $values ) ) fisha_studio_category_set_meta( $id, 'fisha_hero_ids', implode( ',', array_map( 'intval', (array) $values['hero_ids'] ) ) );
	if ( array_key_exists( 'shown', $values ) ) update_term_meta( $id, FISHA_CAT_SHOWN, $values['shown'] ? '1' : '0' );
	clean_term_cache( $id, 'product_cat' );
	fisha_cat_cache_reset();
	$fresh = get_term( $id, 'product_cat' );
	fisha_studio_category_write_auto( $fresh, fisha_studio_category_values( $fresh ), null );
	return $errors;
}
```

In `studio/studio.php`, add `require_once FISHA_STUDIO_DIR . 'studio-categories.php';` after the `studio-publish.php` require.

- [ ] **Step 6: Run the tests to see them pass**

Run: `bash tests/studio/run.sh "categories:"` → PASS. Run `bash tests/studio/run.sh` → 209 passed, 0 failed.

- [ ] **Step 7: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/studio-drafts.php wp-content/mu-plugins/fisha-design/studio/studio-categories.php wp-content/mu-plugins/fisha-design/studio/studio.php tests/studio/helpers.php tests/studio/test-categories.php
git commit -m "Studio: category fields, automatic design fields, create and edit (direct before publish, draft after)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Publish, Show on site, reorder and delete categories

**Files:**
- Create: `wp-content/mu-plugins/fisha-design/studio/studio-categories-publish.php`
- Modify: `studio/studio.php` (require after `studio-categories.php`), `studio/studio-publish.php` (`fisha_studio_purge_product`)
- Test: `tests/studio/test-categories-publish.php`

**Interfaces:**
- Consumes: Task 3 (`fisha_cat_path`, `FISHA_CAT_OLD_PATHS`, option `fisha_deleted_cat_paths`) and Task 4.
- Produces:
  - `fisha_studio_publish_category( int $id, array $resolutions = array(), bool $change_url = false ): array|WP_Error` (`{id, url}`)
  - `fisha_studio_category_discard( int $id ): void`
  - `fisha_studio_category_paths_below( WP_Term $t ): array`
  - `fisha_studio_category_remember_paths( array $old ): void`
  - `fisha_studio_purge_category( WP_Term $t, array $old_paths = array(), bool $everywhere = false ): void`
  - `fisha_studio_category_set_shown( int $id, bool $shown ): true|WP_Error`
  - `fisha_studio_category_reorder( int $parent, array $ids ): true|WP_Error`
  - `fisha_studio_category_direct_products( int $id ): int[]`
  - `fisha_studio_category_orphans( int $id ): int[]`
  - `fisha_studio_category_delete( int $id, int $move_to = 0 ): true|WP_Error`
  - Action `fisha_studio_before_category_commit( int $id )`.

- [ ] **Step 1: Write the failing tests** — `tests/studio/test-categories-publish.php`

```php
<?php
function fs_ready_cat( string $name, int $parent = 0 ): WP_Term {
	$t = fs_studio_cat( $name, $parent );
	fisha_studio_category_edit( $t, array( 'lead' => 'A short intro. Second sentence.', 'hero_ids' => array( fs_image() ) ) );
	return get_term( $t->term_id, 'product_cat' );
}
function fs_keep_deleted_paths(): void {
	$before = get_option( 'fisha_deleted_cat_paths', null );
	fs_on_cleanup( function () use ( $before ) { null === $before ? delete_option( 'fisha_deleted_cat_paths' ) : update_option( 'fisha_deleted_cat_paths', $before, false ); } );
}

fs_test( 'categories publish: a new category goes live and is logged', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t = fs_ready_cat( 'zz-test Pub New' );
	$r = fisha_studio_publish_category( $t->term_id );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
	fs_eq( '', (string) get_term_meta( $t->term_id, 'fisha_studio_hidden', true ), 'published' );
	fs_eq( home_url( '/product-category/zz-test-pub-new/' ), $r['url'] );
	fs_eq( 'live', fisha_studio_category_state( get_term( $t->term_id, 'product_cat' ) ) );
	fs_eq( 'create', fisha_studio_log_for( 'category', $t->term_id, 1 )[0]['action'] );
} );

fs_test( 'categories publish: missing intro or photo is refused with field errors, nothing written', function () {
	$t = fs_studio_cat( 'zz-test Pub Bad' );
	$r = fisha_studio_publish_category( $t->term_id );
	fs_true( is_wp_error( $r ) );
	fs_eq( 422, $r->get_error_data()['status'] );
	fs_true( isset( $r->get_error_data()['fields']['lead'], $r->get_error_data()['fields']['hero_ids'] ) );
	fs_eq( '1', get_term_meta( $t->term_id, 'fisha_studio_hidden', true ), 'still not published' );
} );

fs_test( 'categories publish: draft changes go live; a moved value asks first', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t = fs_ready_cat( 'zz-test Pub Draft' );
	fisha_studio_publish_category( $t->term_id );
	$t = get_term( $t->term_id, 'product_cat' );
	fisha_studio_category_edit( $t, array( 'lead' => 'New intro.' ), array( 'lead' => 'A short intro. Second sentence.' ) );
	update_term_meta( $t->term_id, 'fisha_lead', 'Someone else.' );
	$r = fisha_studio_publish_category( $t->term_id );
	fs_eq( 409, $r->get_error_data()['status'] );
	$r = fisha_studio_publish_category( $t->term_id, array( 'lead' => array( 'choice' => 'mine', 'live' => 'Someone else.' ) ) );
	fs_false( is_wp_error( $r ) );
	fs_eq( 'New intro.', get_term_meta( $t->term_id, 'fisha_lead', true ) );
	fs_eq( array(), fisha_studio_draft_get( $t->term_id, 'term' )['fields'], 'draft cleared' );
	fs_eq( 400, fisha_studio_publish_category( $t->term_id )->get_error_data()['status'], 'nothing left to publish' );
} );

fs_test( 'categories publish: renaming a live category keeps hand-written design fields', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t = fs_cat( 'pub-hand' );
	update_term_meta( $t->term_id, 'fisha_lead', 'Hand intro.' );
	update_term_meta( $t->term_id, 'fisha_hero_ids', (string) fs_image() );
	update_term_meta( $t->term_id, 'fisha_h1', 'Fisha <span>Hand</span>' );
	update_term_meta( $t->term_id, 'fisha_seo_title', 'Hand written title' );
	fisha_studio_category_edit( $t, array( 'name' => 'zz-test pub-hand renamed' ) );
	fs_false( is_wp_error( fisha_studio_publish_category( $t->term_id ) ) );
	fs_eq( 'zz-test pub-hand renamed', get_term( $t->term_id, 'product_cat' )->name );
	fs_eq( 'Fisha <span>Hand</span>', get_term_meta( $t->term_id, 'fisha_h1', true ) );
	fs_eq( 'Hand written title', get_term_meta( $t->term_id, 'fisha_seo_title', true ) );
	fs_eq( 'zz-test-pub-hand', get_term( $t->term_id, 'product_cat' )->slug, 'address unchanged by a rename' );
} );

fs_test( 'categories publish: an automatic heading follows a rename', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t = fs_ready_cat( 'zz-test Auto Old' );
	fisha_studio_publish_category( $t->term_id );
	fisha_studio_category_edit( get_term( $t->term_id, 'product_cat' ), array( 'name' => 'zz-test Auto New' ) );
	fisha_studio_publish_category( $t->term_id );
	fs_eq( 'zz-test Auto <span>New</span>', get_term_meta( $t->term_id, 'fisha_h1', true ) );
} );

fs_test( 'categories publish: moving a live category remembers old paths of it and its children', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$a = fs_ready_cat( 'zz-test Move A' );   fisha_studio_publish_category( $a->term_id );
	$b = fs_ready_cat( 'zz-test Move B' );   fisha_studio_publish_category( $b->term_id );
	$k = fs_ready_cat( 'zz-test Move Kid', $a->term_id ); fisha_studio_publish_category( $k->term_id );
	$old_a = fisha_cat_path( get_term( $a->term_id, 'product_cat' ) );
	$old_k = fisha_cat_path( get_term( $k->term_id, 'product_cat' ) );
	fisha_studio_category_edit( get_term( $a->term_id, 'product_cat' ), array( 'parent' => $b->term_id ) );
	$r = fisha_studio_publish_category( $a->term_id );
	fs_eq( 422, $r->get_error_data()['status'], 'needs the owner to confirm the new address' );
	fs_true( isset( $r->get_error_data()['fields']['parent'] ) );
	fs_false( is_wp_error( fisha_studio_publish_category( $a->term_id, array(), true ) ) );
	fs_eq( 'product-category/zz-test-move-b/zz-test-move-a', fisha_cat_path( get_term( $a->term_id, 'product_cat' ) ) );
	fs_eq( array( $old_a ), get_term_meta( $a->term_id, 'fisha_old_paths' ) );
	fs_eq( array( $old_k ), get_term_meta( $k->term_id, 'fisha_old_paths' ), 'child too' );
	fs_eq( get_term_link( $k->term_id, 'product_cat' ), fisha_cat_redirect_target( $old_k ) );
} );

fs_test( 'categories publish: a failure rolls everything back', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t = fs_ready_cat( 'zz-test Pub Fail' );
	$boom = function () { throw new RuntimeException( 'forced' ); };
	add_action( 'fisha_studio_before_category_commit', $boom );
	fs_on_cleanup( function () use ( $boom ) { remove_action( 'fisha_studio_before_category_commit', $boom ); } );
	$r = fisha_studio_publish_category( $t->term_id );
	fs_eq( 500, $r->get_error_data()['status'] );
	clean_term_cache( $t->term_id, 'product_cat' );
	wp_cache_delete( $t->term_id, 'term_meta' );
	fs_eq( '1', get_term_meta( $t->term_id, 'fisha_studio_hidden', true ), 'still not published' );
} );

fs_test( 'categories: Show on site switches a live category at once and drops it from the draft', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t = fs_cat( 'shown-switch' );
	fisha_studio_category_edit( $t, array( 'shown' => false, 'lead' => 'Draft intro.' ) );
	fs_true( true === fisha_studio_category_set_shown( $t->term_id, false ) );
	fs_eq( '0', get_term_meta( $t->term_id, 'fisha_shown', true ) );
	$d = fisha_studio_draft_get( $t->term_id, 'term' );
	fs_false( isset( $d['fields']['shown'] ), 'shown left the draft' );
	fs_true( isset( $d['fields']['lead'] ), 'other edits kept' );
	fs_eq( 'shown', fisha_studio_log_for( 'category', $t->term_id, 1 )[0]['action'] );
} );

fs_test( 'categories: reorder needs every child and numbers them from 1', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$top = fs_cat( 'order-top' );
	$a = fs_cat( 'order-a', $top->term_id ); $b = fs_cat( 'order-b', $top->term_id ); $c = fs_cat( 'order-c', $top->term_id );
	fs_eq( 409, fisha_studio_category_reorder( $top->term_id, array( $a->term_id, $b->term_id ) )->get_error_data()['status'], 'missing one' );
	fs_true( true === fisha_studio_category_reorder( $top->term_id, array( $c->term_id, $a->term_id, $b->term_id ) ) );
	fs_eq( array( '1', '2', '3' ), array( get_term_meta( $c->term_id, 'order', true ), get_term_meta( $a->term_id, 'order', true ), get_term_meta( $b->term_id, 'order', true ) ) );
} );

fs_test( 'categories delete: products keep other categories, orphans move, children move up', function () {
	fs_as( FISHA_STUDIO_ROLE );
	fs_keep_deleted_paths();
	$top   = fs_cat( 'del-top' );
	$gone  = fs_cat( 'del-gone', $top->term_id );
	$kid   = fs_cat( 'del-kid', $gone->term_id );
	$other = fs_cat( 'del-other' );
	$to    = fs_cat( 'del-to' );
	$only  = fs_product( array( 'category_ids' => array( $gone->term_id ) ) );
	$both  = fs_product( array( 'category_ids' => array( $gone->term_id, $other->term_id ) ) );
	$inkid = fs_product( array( 'category_ids' => array( $kid->term_id ) ) );
	$path  = fisha_cat_path( $gone );
	fs_eq( 422, fisha_studio_category_delete( $gone->term_id )->get_error_data()['status'], 'must choose where products go' );
	fs_eq( array( $only->get_id() ), fisha_studio_category_orphans( $gone->term_id ) );
	fs_true( true === fisha_studio_category_delete( $gone->term_id, $to->term_id ) );
	fs_false( (bool) get_term( $gone->term_id, 'product_cat' ), 'deleted' );
	fs_eq( array( $to->term_id ), wp_get_object_terms( $only->get_id(), 'product_cat', array( 'fields' => 'ids' ) ), 'orphan moved' );
	fs_eq( array( $other->term_id ), wp_get_object_terms( $both->get_id(), 'product_cat', array( 'fields' => 'ids' ) ), 'other category kept, no Uncategorized' );
	fs_eq( array( $kid->term_id ), wp_get_object_terms( $inkid->get_id(), 'product_cat', array( 'fields' => 'ids' ) ) );
	fs_eq( (int) $top->term_id, (int) get_term( $kid->term_id, 'product_cat' )->parent, 'child moved up' );
	fs_eq( get_term_link( $top ), fisha_cat_redirect_target( $path ), 'old address goes to the parent page' );
	fs_eq( 'delete', fisha_studio_log_for( 'category', $gone->term_id, 1 )[0]['action'] );
} );

fs_test( 'categories delete: the default category is refused; an empty never-published one leaves no redirect', function () {
	fs_as( FISHA_STUDIO_ROLE );
	fs_keep_deleted_paths();
	fs_eq( 400, fisha_studio_category_delete( (int) get_option( 'default_product_cat' ) )->get_error_data()['status'] );
	$t = fs_studio_cat( 'zz-test Del New' );
	$path = fisha_cat_path( $t );
	fs_true( true === fisha_studio_category_delete( $t->term_id ) );
	fs_false( array_key_exists( $path, (array) get_option( 'fisha_deleted_cat_paths', array() ) ) );
} );
```

- [ ] **Step 2: Run them to see them fail**

Run: `bash tests/studio/run.sh "categories publish"` and `bash tests/studio/run.sh "categories delete"` → FAIL (undefined functions).

- [ ] **Step 3: Implement** — create `studio/studio-categories-publish.php`

```php
<?php
/**
 * Fisha Studio category publish, Show on site, order and delete. Publish checks the whole category, asks about values that
 * changed on the site meanwhile, then writes inside one database transaction and logs before/after. Moving a live category
 * keeps its old address (and its sub-categories' old addresses) so they redirect.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_category_missing(): WP_Error {
	return new WP_Error( 'fisha_studio_not_found', 'This category no longer exists.', array( 'status' => 404 ) );
}

/** @return array{id:int,url:string}|WP_Error */
function fisha_studio_publish_category( int $id, array $resolutions = array(), bool $change_url = false ) {
	$t = fisha_studio_category( $id );
	if ( ! $t ) return fisha_studio_category_missing();
	$is_new  = fisha_cat_is_unpublished( $t );
	$draft   = fisha_studio_draft_get( $id, 'term' );
	$before  = fisha_studio_category_values( $t );
	$changes = $draft['fields'];

	if ( ! $is_new ) {
		$conflicts = fisha_studio_category_conflicts( $t, $draft );
		$open      = fisha_studio_open_conflicts( $conflicts, $resolutions );
		if ( $open ) return new WP_Error( 'fisha_studio_conflict', 'Some details changed on the site while you were editing.', array( 'status' => 409, 'conflicts' => $open ) );
		foreach ( $conflicts as $c ) {
			if ( 'live' === fisha_studio_resolution( $c, $resolutions ) ) unset( $changes[ $c['field'] ] );
		}
		$moved_slug   = isset( $changes['slug'] ) && $changes['slug'] !== $before['slug'];
		$moved_parent = isset( $changes['parent'] ) && (int) $changes['parent'] !== (int) $before['parent'];
		if ( ( $moved_slug || $moved_parent ) && ! $change_url ) {
			return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => array( $moved_slug ? 'slug' : 'parent' => 'This category is live. Confirm the new address to move it.' ) ) );
		}
		if ( ! $changes ) return new WP_Error( 'fisha_studio_nothing', 'There are no changes to publish.', array( 'status' => 400 ) );
	}

	$after  = array_merge( $before, $changes );
	$errors = fisha_studio_category_validate( $after, $t );
	if ( $errors ) return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => $errors ) );

	$old_paths = $is_new ? array() : fisha_studio_category_paths_below( $t );
	$was_top   = 0 === (int) $before['parent'];

	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		$args = array();
		if ( $after['name'] !== $before['name'] ) $args['name'] = $after['name'];
		if ( (int) $after['parent'] !== (int) $before['parent'] ) $args['parent'] = (int) $after['parent'];
		if ( $after['slug'] !== $before['slug'] ) $args['slug'] = fisha_studio_unique_cat_slug( (string) $after['slug'], $id );
		if ( $args ) {
			$r = wp_update_term( $id, 'product_cat', $args );
			if ( is_wp_error( $r ) ) throw new RuntimeException( $r->get_error_message() );
			if ( isset( $args['parent'] ) ) update_term_meta( $id, 'order', fisha_studio_category_next_order( (int) $args['parent'], $id ) );
		}
		if ( $after['lead'] !== $before['lead'] ) fisha_studio_category_set_meta( $id, 'fisha_lead', (string) $after['lead'] );
		if ( $after['hero_ids'] !== $before['hero_ids'] ) fisha_studio_category_set_meta( $id, 'fisha_hero_ids', implode( ',', array_map( 'intval', $after['hero_ids'] ) ) );
		if ( $after['shown'] !== $before['shown'] ) update_term_meta( $id, FISHA_CAT_SHOWN, $after['shown'] ? '1' : '0' );
		clean_term_cache( $id, 'product_cat' );
		fisha_studio_category_write_auto( get_term( $id, 'product_cat' ), $after, $is_new ? null : $before );
		if ( $is_new ) {
			delete_term_meta( $id, FISHA_CAT_UNPUBLISHED );
			delete_term_meta( $id, '_fisha_studio_slug_manual' );
		} else {
			fisha_studio_category_remember_paths( $old_paths );
		}
		delete_term_meta( $id, FISHA_STUDIO_DRAFT_META );
		do_action( 'fisha_studio_before_category_commit', $id );
		if ( ! fisha_studio_log( 'category', $id, $is_new ? 'create' : 'publish', $is_new ? null : $before, fisha_studio_category_values( get_term( $id, 'product_cat' ) ) ) ) {
			throw new RuntimeException( 'audit log write failed: ' . $wpdb->last_error );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'commit failed: ' . $wpdb->last_error );
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		clean_term_cache( array_keys( $old_paths ) ?: array( $id ), 'product_cat' );
		wp_cache_delete( $id, 'term_meta' );
		fisha_cat_cache_reset();
		fisha_studio_log_error( 'publish category ' . $id, $e );
		return new WP_Error( 'fisha_studio_publish_failed', 'Publishing failed, so nothing was changed. Please try again.', array( 'status' => 500 ) );
	}

	fisha_cat_cache_reset();
	$fresh = get_term( $id, 'product_cat' );
	fisha_studio_purge_category( $fresh, array_values( $old_paths ), $was_top || 0 === (int) $fresh->parent || $after['shown'] !== $before['shown'] );
	return array( 'id' => $id, 'url' => get_term_link( $fresh ) );
}

function fisha_studio_category_discard( int $id ): void {
	delete_term_meta( $id, FISHA_STUDIO_DRAFT_META );
}

/** [term id => address path] of a category and every category below it. */
function fisha_studio_category_paths_below( WP_Term $t ): array {
	$out = array( (int) $t->term_id => fisha_cat_path( $t ) );
	foreach ( get_term_children( $t->term_id, 'product_cat' ) as $cid ) {
		$c = fisha_studio_category( (int) $cid );
		if ( $c ) $out[ (int) $cid ] = fisha_cat_path( $c );
	}
	return $out;
}

/** After a move: each category whose address changed keeps its previous one as an old path, which redirects. */
function fisha_studio_category_remember_paths( array $old ): void {
	foreach ( $old as $tid => $path ) {
		clean_term_cache( (int) $tid, 'product_cat' );
		$t = fisha_studio_category( (int) $tid );
		if ( ! $t || '' === $path || fisha_cat_path( $t ) === $path ) continue;
		if ( ! in_array( $path, get_term_meta( (int) $tid, FISHA_CAT_OLD_PATHS ), true ) ) add_term_meta( (int) $tid, FISHA_CAT_OLD_PATHS, $path );
	}
}

/**
 * Clears the page cache for a category: its page, its parents, Shop, Home and any old addresses. $everywhere when the
 * header and footer menus may change (they are on every page).
 */
function fisha_studio_purge_category( WP_Term $t, array $old_paths = array(), bool $everywhere = false ): void {
	if ( $everywhere ) {
		do_action( 'litespeed_purge_all' );
		return;
	}
	$urls = array( get_permalink( wc_get_page_id( 'shop' ) ), home_url( '/' ) );
	foreach ( array_merge( array( (int) $t->term_id ), get_ancestors( $t->term_id, 'product_cat' ) ) as $tid ) {
		$link = get_term_link( (int) $tid, 'product_cat' );
		if ( ! is_wp_error( $link ) ) $urls[] = $link;
	}
	foreach ( $old_paths as $p ) $urls[] = home_url( '/' . $p . '/' );
	foreach ( array_unique( array_filter( $urls ) ) as $url ) do_action( 'litespeed_purge_url', $url );
}

/** Show on site, at once for a published category (and out of its draft); a never-published one only changes what Publish does. @return true|WP_Error */
function fisha_studio_category_set_shown( int $id, bool $shown ) {
	$t = fisha_studio_category( $id );
	if ( ! $t ) return fisha_studio_category_missing();
	if ( fisha_cat_is_unpublished( $t ) ) {
		fisha_studio_category_edit( $t, array( 'shown' => $shown ) );
		return true;
	}
	$was = '1' === (string) get_term_meta( $id, FISHA_CAT_SHOWN, true );
	update_term_meta( $id, FISHA_CAT_SHOWN, $shown ? '1' : '0' );
	$d = fisha_studio_draft_get( $id, 'term' );
	unset( $d['fields']['shown'], $d['base']['shown'] );
	fisha_studio_draft_put( $id, $d, 'term' );
	fisha_studio_log( 'category', $id, 'shown', array( 'shown' => $was ), array( 'shown' => $shown ) );
	fisha_cat_cache_reset();
	fisha_studio_purge_category( $t, array(), true );
	return true;
}

/** Sets the order of the categories under $parent; $ids must be all of them, in the new order. @return true|WP_Error */
function fisha_studio_category_reorder( int $parent, array $ids ) {
	$kids = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $parent, 'hide_empty' => false, 'fields' => 'ids' ) );
	$kids = is_wp_error( $kids ) ? array() : array_map( 'intval', $kids );
	$ids  = array_values( array_map( 'intval', $ids ) );
	$a = $kids;
	$b = $ids;
	sort( $a );
	sort( $b );
	if ( $a !== $b ) return new WP_Error( 'fisha_studio_stale', 'The categories changed meanwhile. Please reload and try again.', array( 'status' => 409 ) );
	$before = array();
	$after  = array();
	foreach ( $ids as $i => $cid ) {
		$before[ $cid ] = (int) get_term_meta( $cid, 'order', true );
		$after[ $cid ]  = $i + 1;
		update_term_meta( $cid, 'order', $i + 1 );
	}
	fisha_studio_log( 'category', $parent, 'reorder', $before, $after );
	do_action( 'litespeed_purge_all' );
	return true;
}

/** Products filed directly in this category (any status). @return int[] */
function fisha_studio_category_direct_products( int $id ): array {
	$ids = get_objects_in_term( $id, 'product_cat' );
	return is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
}

/** Its products that have no other category. @return int[] */
function fisha_studio_category_orphans( int $id ): array {
	return array_values( array_filter( fisha_studio_category_direct_products( $id ), function ( $pid ) use ( $id ) {
		return array( $id ) === array_map( 'intval', (array) wp_get_object_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) ) );
	} ) );
}

/**
 * Deletes a category. Products that were only in it move to $move_to (required then); its sub-categories move up to its
 * parent; its address and old addresses redirect to the parent's page, or the Shop. @return true|WP_Error
 */
function fisha_studio_category_delete( int $id, int $move_to = 0 ) {
	$t = fisha_studio_category( $id );
	if ( ! $t ) return fisha_studio_category_missing();
	if ( (int) get_option( 'default_product_cat' ) === $id ) {
		return new WP_Error( 'fisha_studio_default_cat', 'This is the shop\'s default category, so it cannot be deleted.', array( 'status' => 400 ) );
	}
	$orphans = fisha_studio_category_orphans( $id );
	if ( $orphans ) {
		$target = fisha_studio_category( $move_to );
		if ( ! $target || $move_to === $id || in_array( $id, array_map( 'intval', get_ancestors( $move_to, 'product_cat' ) ), true ) ) {
			return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => array( 'move_to' => 'Choose where its products go.' ) ) );
		}
	}
	$was_new = fisha_cat_is_unpublished( $t );
	$before  = fisha_studio_category_values( $t );
	$paths   = fisha_studio_category_paths_below( $t );
	$own     = array_merge( array( $paths[ $id ] ), get_term_meta( $id, FISHA_CAT_OLD_PATHS ) );
	unset( $paths[ $id ] );
	$parent = (int) $t->parent;

	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		foreach ( $orphans as $pid ) {
			$r = wp_set_object_terms( $pid, array( $move_to ), 'product_cat', true );
			if ( is_wp_error( $r ) ) throw new RuntimeException( $r->get_error_message() );
		}
		$kids = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $id, 'hide_empty' => false, 'fields' => 'ids' ) );
		foreach ( is_wp_error( $kids ) ? array() : $kids as $cid ) {
			$r = wp_update_term( (int) $cid, 'product_cat', array( 'parent' => $parent ) );
			if ( is_wp_error( $r ) ) throw new RuntimeException( $r->get_error_message() );
		}
		$r = wp_delete_term( $id, 'product_cat' );
		if ( true !== $r ) throw new RuntimeException( is_wp_error( $r ) ? $r->get_error_message() : 'delete failed' );
		fisha_studio_category_remember_paths( $paths );
		if ( ! $was_new ) {
			$deleted = get_option( 'fisha_deleted_cat_paths', array() );
			if ( ! is_array( $deleted ) ) $deleted = array();
			foreach ( $own as $p ) {
				if ( '' !== $p ) $deleted[ $p ] = $parent;
			}
			update_option( 'fisha_deleted_cat_paths', $deleted, false );
		}
		if ( ! fisha_studio_log( 'category', $id, 'delete', array_merge( $before, array( 'products_moved' => $orphans, 'move_to' => $move_to ) ), null ) ) {
			throw new RuntimeException( 'audit log write failed: ' . $wpdb->last_error );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'commit failed: ' . $wpdb->last_error );
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		clean_term_cache( array_merge( array( $id ), array_keys( $paths ) ), 'product_cat' );
		foreach ( $orphans as $pid ) clean_object_term_cache( $pid, 'product' );
		wp_cache_delete( 'fisha_deleted_cat_paths', 'options' );
		fisha_cat_cache_reset();
		fisha_studio_log_error( 'delete category ' . $id, $e );
		return new WP_Error( 'fisha_studio_delete_failed', 'Deleting failed, so nothing was changed. Please try again.', array( 'status' => 500 ) );
	}
	fisha_cat_cache_reset();
	do_action( 'litespeed_purge_all' );
	return true;
}
```

In `studio/studio.php`, add `require_once FISHA_STUDIO_DIR . 'studio-categories-publish.php';` after the `studio-categories.php` require.

- [ ] **Step 4: Product publishes can change which top categories are shown** — `studio/studio-publish.php`

At the end of `fisha_studio_purge_product()` (after `wc_delete_product_transients(...)`), add:

```php
	// A top-level category with no other product may have just appeared or disappeared from the menus on every page.
	if ( function_exists( 'fisha_cat_is_enabled' ) ) {
		fisha_cat_cache_reset();
		$tops = array();
		foreach ( array_unique( array_merge( $p->get_category_ids(), $extra_cat_ids ) ) as $cid ) {
			$chain = array_merge( array( (int) $cid ), array_map( 'intval', get_ancestors( (int) $cid, 'product_cat' ) ) );
			$tops[ end( $chain ) ] = true;
		}
		foreach ( array_keys( $tops ) as $tid ) {
			$top = get_term( (int) $tid, 'product_cat' );
			if ( ! $top instanceof WP_Term || ! fisha_cat_is_enabled( $top ) ) continue;
			if ( count( wc_get_products( array( 'status' => 'publish', 'limit' => 2, 'return' => 'ids', 'visibility' => 'catalog', 'category' => array( $top->slug ) ) ) ) < 2 ) {
				do_action( 'litespeed_purge_all' );
				break;
			}
		}
	}
```

- [ ] **Step 5: Run the tests to see them pass**

Run: `bash tests/studio/run.sh "categories"` → all PASS. Run `bash tests/studio/run.sh` → 220 passed, 0 failed.

- [ ] **Step 6: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/studio-categories-publish.php wp-content/mu-plugins/fisha-design/studio/studio.php wp-content/mu-plugins/fisha-design/studio/studio-publish.php tests/studio/test-categories-publish.php
git commit -m "Studio: publish categories (transaction, conflicts, old addresses), Show on site, reorder, delete with product move

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Category preview

**Files:**
- Modify: `wp-content/mu-plugins/fisha-design/studio/studio-preview.php`
- Test: `tests/studio/test-categories-preview.php`

**Interfaces:**
- Consumes: Tasks 1, 3, 4.
- Produces:
  - `fisha_studio_preview_install_category( int $id, array $fields ): void`
  - `fisha_studio_category_preview_targets( WP_Term $t ): array` (`[{key,label,url}]`, keys `item`, `parent`, `shop`, `home`)
  - `fisha_studio_preview_uninstall()` also clears `$GLOBALS['fisha_studio_preview_term']`.

- [ ] **Step 1: Write the failing tests** — `tests/studio/test-categories-preview.php`

```php
<?php
fs_test( 'categories preview: the draft is laid over name, intro, photos, Show on site and automatic fields', function () {
	$t = fs_cat( 'prev-live' );
	update_term_meta( $t->term_id, 'fisha_lead', 'Live intro.' );
	$img = fs_image();
	fs_product( array( 'category_ids' => array( $t->term_id ) ) );
	fisha_studio_preview_install_category( $t->term_id, array( 'name' => 'zz-test Preview Name', 'lead' => 'Draft intro.', 'hero_ids' => array( $img ), 'shown' => false ) );
	fs_eq( 'zz-test Preview Name', get_term( $t->term_id, 'product_cat' )->name );
	fs_eq( 'Draft intro.', get_term_meta( $t->term_id, 'fisha_lead', true ) );
	fs_eq( (string) $img, get_term_meta( $t->term_id, 'fisha_hero_ids', true ) );
	fs_false( fisha_cat_is_displayed( get_term( $t->term_id, 'product_cat' ) ), 'switched off in the draft' );
	fisha_studio_preview_uninstall();
	fs_eq( 'zz-test prev-live', get_term( $t->term_id, 'product_cat' )->name, 'gone after uninstall' );
	fs_eq( 'Live intro.', get_term_meta( $t->term_id, 'fisha_lead', true ) );
} );

fs_test( 'categories preview: a never-published category shows only in its own preview', function () {
	$t = fs_studio_cat( 'zz-test Prev New' );
	fs_hiding();
	$listed = function () use ( $t ) { return in_array( $t->term_id, array_map( 'intval', get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) ) ), true ); };
	fs_false( $listed(), 'public' );
	fisha_studio_preview_install_category( $t->term_id, array() );
	fs_true( $listed(), 'in its preview' );
	fs_eq( 'ok', fisha_cat_page_status( get_term( $t->term_id, 'product_cat' ) ), 'page opens in the preview' );
	fisha_studio_preview_uninstall();
	fisha_cat_cache_reset();
	fs_false( $listed(), 'public again' );
} );

fs_test( 'categories preview: a hand-written heading stays, an automatic one follows the draft name', function () {
	$hand = fs_cat( 'prev-hand' );
	update_term_meta( $hand->term_id, 'fisha_h1', 'Fisha <span>Hand</span>' );
	fisha_studio_preview_install_category( $hand->term_id, array( 'name' => 'zz-test Other' ) );
	fs_eq( 'Fisha <span>Hand</span>', get_term_meta( $hand->term_id, 'fisha_h1', true ) );
	fisha_studio_preview_uninstall();
	$auto = fs_studio_cat( 'zz-test Prev Auto' );
	fisha_studio_preview_install_category( $auto->term_id, array( 'name' => 'zz-test Prev Changed' ) );
	fs_eq( 'zz-test Prev <span>Changed</span>', get_term_meta( $auto->term_id, 'fisha_h1', true ) );
} );

fs_test( 'categories preview: targets are the category, its parent, Shop and Home, each with a token', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$top = fs_cat( 'prev-top' );
	$kid = fs_cat( 'prev-kid', $top->term_id );
	$targets = fisha_studio_category_preview_targets( $kid );
	fs_eq( array( 'item', 'parent', 'shop', 'home' ), wp_list_pluck( $targets, 'key' ) );
	parse_str( (string) wp_parse_url( $targets[0]['url'], PHP_URL_QUERY ), $q );
	fs_eq( array( 'type' => 'category', 'id' => $kid->term_id ), fisha_studio_preview_verify( $q['fisha_preview'] ) );
} );
```

- [ ] **Step 2: Run them to see them fail**

Run: `bash tests/studio/run.sh "categories preview"` → FAIL (undefined `fisha_studio_preview_install_category`).

- [ ] **Step 3: Implement** — `studio/studio-preview.php`

In `fisha_studio_preview_uninstall()`, add `unset( $GLOBALS['fisha_studio_preview_term'] );` after `unset( $GLOBALS['fisha_studio_preview'] );`, then call `if ( function_exists( 'fisha_cat_cache_reset' ) ) fisha_cat_cache_reset();`.

Replace the `init` hook that installs the preview with:

```php
add_action( 'init', function () {
	if ( empty( $_GET['fisha_preview'] ) || is_admin() || wp_doing_ajax() ) return;
	$item = fisha_studio_preview_verify( sanitize_text_field( wp_unslash( $_GET['fisha_preview'] ) ) );
	if ( ! $item ) return;
	if ( 'product' === $item['type'] ) fisha_studio_preview_install_product( $item['id'], fisha_studio_draft_get( $item['id'] )['fields'] );
	else fisha_studio_preview_install_category( $item['id'], fisha_studio_draft_get( $item['id'], 'term' )['fields'] );
}, 20 );
```

Append:

```php
/**
 * Lays a category's draft over the live data for this request: name, parent, intro, hero photos, Show on site, and the
 * automatic design fields exactly as Publish would write them. A never-published category is visible in its own preview.
 */
function fisha_studio_preview_install_category( int $id, array $fields ): void {
	fisha_studio_preview_uninstall();
	$t = fisha_studio_category( $id );
	if ( ! $t ) return;
	$live = fisha_studio_category_values( $t );
	$v    = array_merge( $live, $fields );
	$prev = fisha_cat_is_unpublished( $t ) ? null : fisha_studio_category_auto( $live );
	$meta = array(
		'fisha_lead'          => (string) $v['lead'],
		'fisha_hero_ids'      => implode( ',', array_map( 'intval', (array) $v['hero_ids'] ) ),
		FISHA_CAT_SHOWN       => $v['shown'] ? '1' : '0',
		FISHA_CAT_UNPUBLISHED => '',
	);
	foreach ( fisha_studio_category_auto( $v ) as $key => $val ) {
		if ( null === $prev || (string) get_term_meta( $id, $key, true ) === $prev[ $key ] ) $meta[ $key ] = $val;
	}
	$GLOBALS['fisha_studio_preview']      = true;
	$GLOBALS['fisha_studio_preview_term'] = $id;
	fisha_studio_preview_add( 'get_term_metadata', function ( $value, $object_id, $key ) use ( $id, $meta ) {
		if ( (int) $object_id !== $id || ! is_string( $key ) || ! array_key_exists( $key, $meta ) ) return $value;
		return array( $meta[ $key ] );
	}, 99, 3 );
	fisha_studio_preview_add( 'get_term', function ( $term ) use ( $id, $v ) {
		if ( ! $term instanceof WP_Term || (int) $term->term_id !== $id ) return $term;
		$term         = clone $term;
		$term->name   = (string) $v['name'];
		$term->parent = (int) $v['parent'];
		return $term;
	}, 99, 1 );
	fisha_cat_cache_reset();
}

/** Pages the editor can preview this category on, each with a fresh token. */
function fisha_studio_category_preview_targets( WP_Term $t ): array {
	$token   = fisha_studio_preview_token( get_current_user_id(), 'category', (int) $t->term_id );
	$targets = array( array( 'key' => 'item', 'label' => 'Category page', 'url' => get_term_link( $t ) ) );
	$parent  = $t->parent ? fisha_studio_category( (int) $t->parent ) : null;
	if ( $parent ) $targets[] = array( 'key' => 'parent', 'label' => fisha_studio_plain( $parent->name ) . ' page', 'url' => get_term_link( $parent ) );
	$targets[] = array( 'key' => 'shop', 'label' => 'Shop', 'url' => get_permalink( wc_get_page_id( 'shop' ) ) );
	$targets[] = array( 'key' => 'home', 'label' => 'Home', 'url' => home_url( '/' ) );
	foreach ( $targets as &$x ) $x['url'] = add_query_arg( 'fisha_preview', $token, $x['url'] );
	return $targets;
}
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `bash tests/studio/run.sh "categories preview"` → PASS. Then `bash tests/studio/run.sh preview` → every product preview test still PASSes. Then `bash tests/studio/run.sh` → 224 passed, 0 failed.

- [ ] **Step 5: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/studio-preview.php tests/studio/test-categories-preview.php
git commit -m "Studio: live preview of a category draft (and of a never-published category)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: REST for categories; labels and a publish warning for products

**Files:**
- Create: `wp-content/mu-plugins/fisha-design/studio/api/api-categories.php`
- Modify: `studio/api/api.php` (require it), `studio/api/api-lookups.php`, `studio/api/api-products.php` (`fisha_studio_rest_products_publish`)
- Test: `tests/studio/test-api-categories.php`

**Interfaces:**
- Consumes: Tasks 4–6.
- Produces the routes (all under `fisha-studio/v1`; capability `manage_product_terms`, delete `delete_product_terms`):

  | Route | Body | Returns |
  |---|---|---|
  | `GET /categories` | | `{ items: row[] }` |
  | `POST /categories` | `{name?, parent?}` | 201 payload |
  | `GET /categories/{id}` | | payload |
  | `PUT /categories/{id}/draft` | `{fields, seen}` | payload + `field_errors` |
  | `DELETE /categories/{id}/draft` | | payload |
  | `POST /categories/{id}/publish` | `{resolutions, change_url}` | payload + `published_url` |
  | `POST /categories/{id}/shown` | `{shown}` | row |
  | `POST /categories/order` | `{parent, ids}` | `{ items }` |
  | `DELETE /categories/{id}` | `{move_to}` | `{ deleted: true }` |

- The shapes the JS consumes:
  - Row: `{ id, name, parent, slug, order, state, shown, off_reason ('' | 'hidden' | 'parent' | 'empty' | 'new'), count, url|null }`.
  - Payload: `{ id, state, values{name,slug,parent,lead,hero_ids,shown}, live, images{id: img}, url|null, base_url, product_count, displayed, has_photo_products, direct_products, orphan_products, deletable, previews }`.
  - Product publish response gains `warnings: string[]`.
  - Lookups label never-published categories "(not published yet)".

- [ ] **Step 1: Write the failing tests** — `tests/studio/test-api-categories.php`

```php
<?php
fs_test( 'api categories: create, edit, publish', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'POST', '/categories', array() );
	fs_eq( 201, $s );
	$id = $d['id'];
	fs_on_cleanup( function () use ( $id ) { global $wpdb; $wpdb->delete( $wpdb->prefix . 'fisha_studio_log', array( 'item_type' => 'category', 'item_id' => $id ) ); wp_delete_term( $id, 'product_cat' ); } );
	fs_eq( 'new', $d['state'] );
	fs_eq( 'New category', substr( $d['values']['name'], 0, 12 ) );
	fs_eq( null, $d['url'] );
	fs_eq( array( 'item', 'shop', 'home' ), wp_list_pluck( $d['previews'], 'key' ) );
	list( $s, $d ) = fs_api( 'PUT', "/categories/$id/draft", array( 'fields' => array( 'name' => 'zz-test Api Cat', 'lead' => 'Intro here.', 'hero_ids' => array( fs_image() ), 'parent' => 'abc' ) ) );
	fs_eq( 200, $s );
	fs_eq( 'zz-test Api Cat', $d['values']['name'] );
	fs_true( isset( $d['field_errors']['parent'] ), 'bad parent reported' );
	fs_eq( home_url( '/product-category/' ), $d['base_url'] );
	list( $s, $d ) = fs_api( 'POST', "/categories/$id/publish" );
	fs_eq( 200, $s, wp_json_encode( $d ) );
	fs_eq( 'live', $d['state'] );
	fs_eq( home_url( '/product-category/zz-test-api-cat/' ), $d['published_url'] );
} );

fs_test( 'api categories: list rows say why a category is not on the site', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$on    = fs_cat( 'row-on' );
	fs_product( array( 'category_ids' => array( $on->term_id ) ) );
	$empty = fs_cat( 'row-empty' );
	$off   = fs_cat( 'row-off', 0, false );
	$kid   = fs_cat( 'row-kid', $off->term_id );
	$new   = fs_studio_cat( 'zz-test Row New' );
	fisha_cat_cache_reset();
	list( $s, $d ) = fs_api( 'GET', '/categories' );
	fs_eq( 200, $s );
	$by = array_column( $d['items'], null, 'id' );
	fs_eq( '', $by[ $on->term_id ]['off_reason'] );
	fs_eq( 1, $by[ $on->term_id ]['count'] );
	fs_eq( 'empty', $by[ $empty->term_id ]['off_reason'] );
	fs_eq( 'hidden', $by[ $off->term_id ]['off_reason'] );
	fs_eq( 'parent', $by[ $kid->term_id ]['off_reason'] );
	fs_eq( 'new', $by[ $new->term_id ]['off_reason'] );
} );

fs_test( 'api categories: shown, order and delete', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$top = fs_cat( 'api-top' );
	$a = fs_cat( 'api-a', $top->term_id );
	$b = fs_cat( 'api-b', $top->term_id );
	list( $s, $d ) = fs_api( 'POST', "/categories/{$a->term_id}/shown", array( 'shown' => false ) );
	fs_eq( 200, $s );
	fs_false( $d['shown'] );
	list( $s ) = fs_api( 'POST', '/categories/order', array( 'parent' => $top->term_id, 'ids' => array( $b->term_id, $a->term_id ) ) );
	fs_eq( 200, $s );
	fs_eq( '1', get_term_meta( $b->term_id, 'order', true ) );
	$before = get_option( 'fisha_deleted_cat_paths', null );
	fs_on_cleanup( function () use ( $before ) { null === $before ? delete_option( 'fisha_deleted_cat_paths' ) : update_option( 'fisha_deleted_cat_paths', $before, false ); } );
	list( $s, $d ) = fs_api( 'DELETE', "/categories/{$a->term_id}", array( 'move_to' => 0 ) );
	fs_eq( 200, $s, wp_json_encode( $d ) );
	fs_false( (bool) get_term( $a->term_id, 'product_cat' ) );
} );

fs_test( 'api categories: someone without the Studio role is refused', function () {
	fs_as( 'customer' );
	list( $s ) = fs_api( 'GET', '/categories' );
	fs_eq( 403, $s );
} );

fs_test( 'api categories: lookups label never-published categories; a product published into one gets a warning', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$new = fs_studio_cat( 'zz-test Lookup New' );
	list( , $l ) = fs_api( 'GET', '/lookups' );
	$by = array_column( $l['categories'], null, 'id' );
	fs_eq( 'zz-test Lookup New (not published yet)', $by[ $new->term_id ]['name'] );
	$p = fs_product( array( 'category_ids' => array( $new->term_id ), 'image_id' => fs_image() ) );
	fisha_studio_product_edit( $p, array( 'regular_price' => '120' ) );
	list( $s, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/publish' );
	fs_eq( 200, $s, wp_json_encode( $d ) );
	fs_eq( 1, count( $d['warnings'] ) );
} );
```

- [ ] **Step 2: Run them to see them fail**

Run: `bash tests/studio/run.sh "api categories"` → FAIL (404 `rest_no_route`).

- [ ] **Step 3: Implement** — create `studio/api/api-categories.php`

```php
<?php
/**
 * Fisha Studio REST: categories. The tree with product counts and why a category is not on the site, create (a
 * never-published category), read, autosave a draft, discard, publish, Show on site, order among siblings and delete.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	$id   = '/categories/(?P<id>\d+)';
	$edit = fisha_studio_permission( 'manage_product_terms' );
	register_rest_route( FISHA_STUDIO_NS, '/categories', array(
		array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_categories_list', 'permission_callback' => $edit ),
		array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_categories_create', 'permission_callback' => $edit ),
	) );
	register_rest_route( FISHA_STUDIO_NS, '/categories/order', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_categories_order', 'permission_callback' => $edit ) );
	register_rest_route( FISHA_STUDIO_NS, $id, array(
		array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_categories_get', 'permission_callback' => $edit ),
		array( 'methods' => 'DELETE', 'callback' => 'fisha_studio_rest_categories_delete', 'permission_callback' => fisha_studio_permission( 'delete_product_terms' ) ),
	) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/draft', array(
		array( 'methods' => 'PUT', 'callback' => 'fisha_studio_rest_categories_draft', 'permission_callback' => $edit ),
		array( 'methods' => 'DELETE', 'callback' => 'fisha_studio_rest_categories_discard', 'permission_callback' => $edit ),
	) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/publish', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_categories_publish', 'permission_callback' => $edit ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/shown', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_categories_shown', 'permission_callback' => $edit ) );
} );

/** @return WP_Term|WP_Error */
function fisha_studio_rest_category( WP_REST_Request $r ) {
	return fisha_studio_category( (int) $r['id'] ) ?? fisha_studio_category_missing();
}

/** Published products in the category and below it. */
function fisha_studio_category_count( WP_Term $t ): int {
	return count( wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'return' => 'ids', 'category' => array( $t->slug ) ) ) );
}

/** The address up to the category's own part: ".../product-category/" or the parent's address. */
function fisha_studio_category_base_url( int $parent ): string {
	$p = fisha_studio_category( $parent );
	if ( $p ) return trailingslashit( get_term_link( $p ) );
	return home_url( '/' . trim( wc_get_permalink_structure()['category_rewrite_slug'], '/' ) . '/' );
}

/** Why a category is not on the site: '' (it is), 'new', 'hidden', 'parent' (a parent is hidden) or 'empty' (no products). */
function fisha_studio_category_off_reason( WP_Term $t ): string {
	if ( fisha_cat_is_unpublished( $t ) ) return 'new';
	if ( '1' !== (string) get_term_meta( $t->term_id, FISHA_CAT_SHOWN, true ) ) return 'hidden';
	if ( ! fisha_cat_is_enabled( $t ) ) return 'parent';
	return fisha_cat_has_products( $t ) ? '' : 'empty';
}

function fisha_studio_category_row( WP_Term $t ): array {
	return array(
		'id'         => (int) $t->term_id,
		'name'       => fisha_studio_plain( $t->name ),
		'parent'     => (int) $t->parent,
		'slug'       => $t->slug,
		'order'      => (int) get_term_meta( $t->term_id, 'order', true ),
		'state'      => fisha_studio_category_state( $t ),
		'shown'      => '1' === (string) get_term_meta( $t->term_id, FISHA_CAT_SHOWN, true ),
		'off_reason' => fisha_studio_category_off_reason( $t ),
		'count'      => fisha_studio_category_count( $t ),
		'url'        => fisha_cat_is_unpublished( $t ) ? null : get_term_link( $t ),
	);
}

function fisha_studio_category_payload( WP_Term $t ): array {
	$id     = (int) $t->term_id;
	$live   = fisha_studio_category_values( $t );
	$draft  = fisha_studio_draft_get( $id, 'term' );
	$values = array_merge( $live, $draft['fields'] );
	$images = array();
	foreach ( (array) $values['hero_ids'] as $aid ) {
		$img = fisha_studio_image_payload( (int) $aid );
		if ( $img ) $images[ (int) $aid ] = $img;
	}
	$new = fisha_cat_is_unpublished( $t );
	return array(
		'id'                 => $id,
		'state'              => fisha_studio_category_state( $t ),
		'values'             => $values,
		'live'               => $live,
		'images'             => (object) $images,
		'url'                => $new ? null : get_term_link( $t ),
		'base_url'           => fisha_studio_category_base_url( (int) $values['parent'] ),
		'product_count'      => fisha_studio_category_count( $t ),
		'displayed'          => fisha_cat_is_displayed( $t ),
		'has_photo_products' => fisha_studio_category_has_photo_products( $t ),
		'direct_products'    => count( fisha_studio_category_direct_products( $id ) ),
		'orphan_products'    => count( fisha_studio_category_orphans( $id ) ),
		'deletable'          => (int) get_option( 'default_product_cat' ) !== $id,
		'previews'           => fisha_studio_category_preview_targets( $t ),
	);
}

function fisha_studio_rest_categories_list(): array {
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	fisha_cat_cache_reset();
	return array( 'items' => array_map( 'fisha_studio_category_row', fisha_cat_by_order( is_wp_error( $terms ) ? array() : $terms ) ) );
}

function fisha_studio_rest_categories_create( WP_REST_Request $r ) {
	$b      = fisha_studio_body( $r );
	$name   = trim( sanitize_text_field( (string) ( $b['name'] ?? '' ) ) );
	$parent = absint( $b['parent'] ?? 0 );
	if ( mb_strlen( $name ) > 60 ) return new WP_Error( 'fisha_studio_invalid', 'A name can be at most 60 characters.', array( 'status' => 422, 'fields' => array( 'name' => 'A name can be at most 60 characters.' ) ) );
	if ( $parent && ! fisha_studio_category( $parent ) ) return new WP_Error( 'fisha_studio_invalid', 'That parent category no longer exists.', array( 'status' => 422, 'fields' => array( 'parent' => 'That parent category no longer exists.' ) ) );
	$t = fisha_studio_category_create( $name, $parent );
	if ( is_wp_error( $t ) ) return new WP_Error( 'fisha_studio_create_failed', 'The category could not be created. Please try again.', array( 'status' => 500 ) );
	fisha_studio_log( 'category', (int) $t->term_id, 'new', null, fisha_studio_category_values( $t ) );
	return new WP_REST_Response( fisha_studio_category_payload( $t ), 201 );
}

function fisha_studio_rest_categories_get( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	return is_wp_error( $t ) ? $t : fisha_studio_category_payload( $t );
}

function fisha_studio_rest_categories_draft( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	if ( is_wp_error( $t ) ) return $t;
	$b      = fisha_studio_body( $r );
	$schema = fisha_studio_category_fields();
	list( $values, $errors ) = fisha_studio_sanitize( $schema, (array) ( $b['fields'] ?? array() ) );
	$values = array_diff_key( $values, $errors );
	list( $seen, $seen_errors ) = fisha_studio_sanitize( $schema, is_array( $b['seen'] ?? null ) ? $b['seen'] : array() );
	if ( $values ) $errors += fisha_studio_category_edit( $t, $values, array_diff_key( $seen, $seen_errors ) );
	return array_merge( fisha_studio_category_payload( fisha_studio_category( (int) $t->term_id ) ), array( 'field_errors' => (object) $errors ) );
}

function fisha_studio_rest_categories_discard( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	if ( is_wp_error( $t ) ) return $t;
	fisha_studio_category_discard( (int) $t->term_id );
	return fisha_studio_category_payload( fisha_studio_category( (int) $t->term_id ) );
}

function fisha_studio_rest_categories_publish( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	if ( is_wp_error( $t ) ) return $t;
	$b   = fisha_studio_body( $r );
	$res = fisha_studio_publish_category( (int) $t->term_id, is_array( $b['resolutions'] ?? null ) ? $b['resolutions'] : array(), ! empty( $b['change_url'] ) );
	if ( is_wp_error( $res ) ) return $res;
	return array_merge( fisha_studio_category_payload( fisha_studio_category( (int) $t->term_id ) ), array( 'published_url' => $res['url'] ) );
}

function fisha_studio_rest_categories_shown( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	if ( is_wp_error( $t ) ) return $t;
	$res = fisha_studio_category_set_shown( (int) $t->term_id, ! empty( fisha_studio_body( $r )['shown'] ) );
	return is_wp_error( $res ) ? $res : fisha_studio_category_row( fisha_studio_category( (int) $t->term_id ) );
}

function fisha_studio_rest_categories_order( WP_REST_Request $r ) {
	$b   = fisha_studio_body( $r );
	$res = fisha_studio_category_reorder( absint( $b['parent'] ?? 0 ), is_array( $b['ids'] ?? null ) ? $b['ids'] : array() );
	return is_wp_error( $res ) ? $res : fisha_studio_rest_categories_list();
}

function fisha_studio_rest_categories_delete( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	if ( is_wp_error( $t ) ) return $t;
	$res = fisha_studio_category_delete( (int) $t->term_id, absint( fisha_studio_body( $r )['move_to'] ?? 0 ) );
	return is_wp_error( $res ) ? $res : array( 'deleted' => true );
}
```

Add `require_once __DIR__ . '/api-categories.php';` at the end of `studio/api/api.php`.

- [ ] **Step 4: Lookups label** — `studio/api/api-lookups.php`

Replace the `$path` closure's first line and the `$categories[]` line so a never-published category reads "Name (not published yet)":

```php
	$label = function ( WP_Term $t ): string {
		return fisha_studio_plain( $t->name ) . ( fisha_cat_is_unpublished( $t ) ? ' (not published yet)' : '' );
	};
	$path = function ( WP_Term $t ) use ( $by_id, $label ): string {
		$names = array( $label( $t ) );
		while ( $t->parent && isset( $by_id[ $t->parent ] ) ) {
			$t = $by_id[ $t->parent ];
			array_unshift( $names, $label( $t ) );
		}
		return implode( ' › ', $names );
	};
	$categories = array();
	foreach ( $terms as $t ) $categories[] = array( 'id' => $t->term_id, 'name' => $label( $t ), 'parent' => $t->parent, 'slug' => $t->slug, 'path' => $path( $t ) );
```

- [ ] **Step 5: Publish warning** — `studio/api/api-products.php`

In `fisha_studio_rest_products_publish`, replace the final `return` with:

```php
	$fresh    = wc_get_product( $p->get_id() );
	$warnings = array();
	$cats     = $fresh->get_category_ids();
	$public   = array_filter( $cats, function ( $cid ) { $t = fisha_studio_category( (int) $cid ); return $t && ! fisha_cat_is_unpublished( $t ); } );
	if ( $cats && ! $public ) $warnings[] = 'Its category is not published yet, so no category page lists this product until you publish the category.';
	return array_merge( fisha_studio_product_payload( $fresh ), array( 'published_url' => $res['url'], 'warnings' => $warnings ) );
```

- [ ] **Step 6: Run the tests to see them pass**

Run: `bash tests/studio/run.sh "api categories"` → PASS. Run `bash tests/studio/run.sh` → 229 passed, 0 failed.

- [ ] **Step 7: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/api/api-categories.php wp-content/mu-plugins/fisha-design/studio/api/api.php wp-content/mu-plugins/fisha-design/studio/api/api-lookups.php wp-content/mu-plugins/fisha-design/studio/api/api-products.php tests/studio/test-api-categories.php
git commit -m "Studio: REST for categories; pickers label unpublished categories; warning when a product's category is unpublished

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Categories screen (tree, Show on site, order, New category)

**Files:**
- Create: `wp-content/mu-plugins/fisha-design/studio/app/js/views/categories-list.js`
- Modify: `studio/app/js/app.js`, `studio/app/studio.css`

**Interfaces:**
- Consumes: Task 7 routes and row shape.
- Produces:
  - `categoriesList( main ): { destroy() }`.
  - Session marker `sessionStorage['fs-new-cat-' + id] = '1'` for a category made by "New category" (Task 9 removes it if left untouched).
  - Routes `#/categories` and `#/categories/{id}`. The second needs `categoryEditor` from Task 9: until Task 9 lands, app.js imports a stub (Step 2).

- [ ] **Step 1: `views/categories-list.js`**

```js
/**
 * Fisha Studio categories: the shop's categories as a tree with their product counts, whether each is on the site
 * (and why not), a Show on site switch, arrows or drag to change the order among siblings, and "New category".
 */
import { h, toast, confirmDialog } from '../ui.js';
import { api } from '../api.js';

const STATE = { new: [ 'Not published yet', 'draft' ], changed: [ 'Unpublished changes', 'changed' ], live: [ 'Live', 'live' ] };
const WHY = { empty: 'Not on the site yet: no products', parent: 'Not on the site: its parent is hidden' };

export function categoriesList( main ) {
	let items = [];
	let busy = false;
	let alive = true;
	let dragId = null;
	const tree = h( 'div.fs-cats', { 'aria-live': 'polite' } );
	const note = h( 'p.fs-empty', {}, 'Loading categories…' );
	main.append(
		h( 'div.fs-page-head', {},
			h( 'h1', {}, 'Categories' ),
			h( 'button.fs-btn.fs-btn--primary', { type: 'button', onclick: create }, '+ New category' ) ),
		h( 'p.fs-field__help', {}, 'A shown category appears on the site once it has a product. Top-level ones are in the Shop menu, the footer, the Home page and the Shop page, in this order.' ),
		note,
		tree );

	async function load() {
		try {
			const d = await api( 'GET', 'categories' );
			if ( ! alive ) return;
			items = d.items;
			note.hidden = true;
			tree.replaceChildren( branch( 0 ) || h( 'p.fs-empty', {}, 'No categories yet.' ) );
		} catch ( e ) {
			note.hidden = false;
			note.textContent = e.message;
		}
	}

	const kids = ( pid ) => items.filter( ( c ) => c.parent === pid );

	function branch( pid ) {
		const group = kids( pid );
		if ( ! group.length ) return null;
		return h( 'ul.fs-cats__group', {}, group.map( ( c, i ) => row( c, i, group ) ) );
	}

	function row( c, i, group ) {
		const pill = STATE[ c.state ];
		const sw = h( 'input.fs-switch', { type: 'checkbox', role: 'switch', checked: c.shown, 'aria-label': `Show ${ c.name } on the site`, onchange: () => setShown( c, sw ) } );
		const li = h( 'li.fs-cat', { draggable: 'true', dataset: { id: c.id } },
			h( 'div.fs-cat__row', {},
				h( 'span.fs-cat__grip', { 'aria-hidden': 'true' }, '⋮⋮' ),
				h( 'a.fs-cat__name', { href: '#/categories/' + c.id }, c.name || 'Untitled' ),
				h( 'span.fs-pill.fs-pill--' + pill[ 1 ], {}, pill[ 0 ] ),
				h( 'span.fs-cat__count', {}, c.count === 1 ? '1 product' : `${ c.count } products` ),
				WHY[ c.off_reason ] ? h( 'span.fs-badge.fs-badge--info', {}, WHY[ c.off_reason ] ) : null,
				h( 'label.fs-cat__shown', {}, sw, h( 'span', {}, 'Show on site' ) ),
				h( 'span.fs-cat__move', {},
					h( 'button.fs-btn.fs-btn--tiny', { type: 'button', 'aria-label': `Move ${ c.name } up`, disabled: i === 0, onclick: () => reorder( group, i, i - 1 ) }, '↑' ),
					h( 'button.fs-btn.fs-btn--tiny', { type: 'button', 'aria-label': `Move ${ c.name } down`, disabled: i === group.length - 1, onclick: () => reorder( group, i, i + 1 ) }, '↓' ) ) ),
			branch( c.id ) );
		li.addEventListener( 'dragstart', ( e ) => {
			e.stopPropagation();
			dragId = c.id;
			li.classList.add( 'is-dragging' );
		} );
		li.addEventListener( 'dragend', () => li.classList.remove( 'is-dragging' ) );
		li.addEventListener( 'dragover', ( e ) => {
			const d = items.find( ( x ) => x.id === dragId );
			if ( d && d.parent === c.parent ) {
				e.preventDefault();
				e.stopPropagation();
			}
		} );
		li.addEventListener( 'drop', ( e ) => {
			const from = group.findIndex( ( x ) => x.id === dragId );
			if ( from < 0 ) return;
			e.preventDefault();
			e.stopPropagation();
			dragId = null;
			reorder( group, from, i );
		} );
		return li;
	}

	async function setShown( c, sw ) {
		if ( busy ) {
			sw.checked = c.shown;
			return;
		}
		const on = sw.checked;
		if ( ! on && c.state !== 'new' && ! ( await confirmDialog( `Hide ${ c.name }?`, 'It disappears from the menu, the Home page, the Shop page and its parent category. Its own page keeps working for people who have the link.', 'Hide', 'danger' ) ) ) {
			sw.checked = true;
			return;
		}
		busy = true;
		try {
			await api( 'POST', `categories/${ c.id }/shown`, { shown: on } );
			toast( c.state === 'new' ? 'Saved. It applies when you publish the category.' : ( on ? `${ c.name } is shown on the site.` : `${ c.name } is hidden.` ) );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
		busy = false;
		await load();
	}

	async function reorder( group, from, to ) {
		if ( busy || from === to || to < 0 || to >= group.length ) return;
		const ids = group.map( ( c ) => c.id );
		const [ moved ] = ids.splice( from, 1 );
		ids.splice( to, 0, moved );
		busy = true;
		try {
			const d = await api( 'POST', 'categories/order', { parent: group[ 0 ].parent, ids } );
			items = d.items;
			tree.replaceChildren( branch( 0 ) );
			tree.querySelector( `[data-id="${ moved }"] .fs-cat__name` )?.focus();
		} catch ( e ) {
			toast( e.message, 'error' );
			await load();
		}
		busy = false;
	}

	async function create() {
		if ( busy ) return;
		busy = true;
		try {
			const d = await api( 'POST', 'categories', {} );
			try { sessionStorage.setItem( 'fs-new-cat-' + d.id, '1' ); } catch {}
			location.hash = '#/categories/' + d.id;
		} catch ( e ) {
			toast( e.message, 'error' );
		}
		busy = false;
	}

	load();
	return { destroy() { alive = false; } };
}
```

- [ ] **Step 2: Router and nav** — `app/js/app.js`

Add `import { categoriesList } from './views/categories-list.js';` and `import { categoryEditor } from './views/category-editor.js';`. Add the nav link after Products:

```js
	h( 'a.fs-nav__item', { href: '#/categories', dataset: { section: 'categories' } }, 'Categories' ),
```

Add these routes after the products routes:

```js
	[ /^#\/categories$/, () => categoriesList( main ) ],
	[ /^#\/categories\/(\d+)$/, ( m ) => categoryEditor( main, Number( m[ 1 ] ) ) ],
```

Until Task 9, create `app/js/views/category-editor.js` with only:

```js
/** Fisha Studio category editor (built in the next task). */
import { h } from '../ui.js';
export function categoryEditor( main ) {
	main.append( h( 'p.fs-empty', {}, 'The category editor is coming next.' ) );
	return {};
}
```

- [ ] **Step 3: Styles** — append to `app/studio.css`

```css
/* ---------- categories ---------- */
.fs-cats__group { list-style: none; margin: 0; padding: 0; }
.fs-cats__group .fs-cats__group { margin-left: 1.5rem; border-left: 2px solid var(--fs-line, #e6dfd5); padding-left: .75rem; }
.fs-cat { margin: .35rem 0; }
.fs-cat.is-dragging > .fs-cat__row { opacity: .5; }
.fs-cat__row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; padding: .6rem .8rem; background: #fff; border: 1px solid var(--fs-line, #e6dfd5); border-radius: 10px; }
.fs-cat__grip { cursor: grab; color: var(--fs-muted); letter-spacing: -2px; }
.fs-cat__name { font-weight: 800; color: inherit; text-decoration: none; min-width: 8rem; }
.fs-cat__name:hover, .fs-cat__name:focus-visible { text-decoration: underline; }
.fs-cat__count { color: var(--fs-muted); font-size: .9rem; }
.fs-cat__shown { display: inline-flex; align-items: center; gap: .4rem; margin-left: auto; font-size: .9rem; }
.fs-cat__move { display: inline-flex; gap: .25rem; }
.fs-toast--warn { background: #7a5a00; }
```

Add `.fs-cat__move .fs-btn, .fs-cat__shown` to the existing `@media (pointer: coarse), (max-width: 760px)` block so the targets are at least 44px (`min-height: 44px; min-width: 44px;` for the buttons).

- [ ] **Step 4: Browser check**

Run `docker exec fisha-wordpress-1 apachectl graceful`, sign in at `http://localhost:8484/studio/` with the test owner (credentials in `tests/studio/local-owner.txt`; never print them), and open **Categories**. Check:
- The tree shows Wearables (T-shirts, Hoodies & Sweatshirts, Hats & Caps, Socks), Collectibles (Clippers), Originals, and the leftover categories.
- "&" shows as "&".
- Wearables, Collectibles and Originals have the switch on; Fisha Wear, Uncategorized and the others have it off.
- ↓ on Wearables moves it below Collectibles. On `http://localhost:8484/` the header Shop menu now lists Collectibles first. Then ↑ restores the original order, and the baseline diff shows no output.
- No console errors (`read_console_messages`).
- At 375px the rows wrap without sideways scrolling.

- [ ] **Step 5: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/app/js/views/categories-list.js wp-content/mu-plugins/fisha-design/studio/app/js/views/category-editor.js wp-content/mu-plugins/fisha-design/studio/app/js/app.js wp-content/mu-plugins/fisha-design/studio/app/studio.css
git commit -m "Studio: Categories screen with Show on site, order and New category

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Category editor (and the shared conflict dialog, hero photos, product warnings)

**Files:**
- Create: `wp-content/mu-plugins/fisha-design/studio/app/js/conflicts.js`
- Replace: `studio/app/js/views/category-editor.js` (the stub from Task 8)
- Modify: `studio/app/js/views/product-editor.js` (import the conflict dialog; show publish warnings), `studio/app/js/images.js` (options)

**Interfaces:**
- Consumes: Task 7 payload and routes, Task 8 session marker.
- Produces:
  - `resolveConflicts( conflicts ): Promise<object|null>` (conflicts.js)
  - `imagesField( values, images, onChange, opts = { label, help, max, hoverNote } )`
  - `categoryEditor( main, id ): { destroy(), dirty(), canLeave() }`

- [ ] **Step 1: `app/js/conflicts.js`** — move `show()` and `resolveConflicts()` out of product-editor.js unchanged:

```js
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
```

In `product-editor.js`:
- Delete its `show()` and `resolveConflicts()` functions.
- Add `import { resolveConflicts } from '../conflicts.js';`.
- In `publish()`, after the success `toast(...)` line, add `( d.warnings || [] ).forEach( ( w ) => toast( w, 'warn' ) );`.
- Check with `grep -n "show(" app/js/views/product-editor.js` (expect no output).

- [ ] **Step 2: `images.js` options**

Change the signature to `export function imagesField( values, images, onChange, opts = {} )` and:
- The label line: `h( 'p.fs-field__label', {}, opts.label || 'Photos' ),`.
- The help line: `h( 'p.fs-field__help', {}, opts.help || 'JPG, PNG or WebP, up to 15 MB. Square photos look best on cards.' ),`.
- In `tile()`, the hover note: `opts.hoverNote !== false && ! isMain && index === 0 ? h( 'p.fs-img__note', {}, 'Shown when shoppers hover the card' ) : null`.
- At the top of `add( img )`:

```js
		const count = ( main ? 1 : 0 ) + gallery.length;
		if ( opts.max && count >= opts.max && img.id !== main && ! gallery.includes( img.id ) ) {
			toast( opts.max === 1 ? 'You can use one photo here.' : `You can use up to ${ opts.max } photos here.`, 'error' );
			return;
		}
```

Product photos are unchanged (no options passed).

- [ ] **Step 3: `views/category-editor.js`** (replaces the stub)

```js
/**
 * Fisha Studio category editor: name, address, parent, intro, hero photos and Show on site, next to the real category
 * page in the preview. Edits autosave 0.6 s after typing stops: a live category keeps them as a draft until Publish; a
 * new one takes them at once and stays off the site until its first Publish. The heading, the small line above it and
 * the Google title are filled in by the server.
 */
import { h, toast, modal, confirmDialog, debounce } from '../ui.js';
import { api } from '../api.js';
import { textField, selectField, toggleField } from '../fields.js';
import { imagesField } from '../images.js';
import { previewPane } from '../preview.js';
import { resolveConflicts } from '../conflicts.js';

const heroValues = ( ids ) => ( { image_id: ids?.[ 0 ] || 0, gallery_image_ids: ( ids || [] ).slice( 1 ) } );

export function categoryEditor( main, id ) {
	const ed = { data: null, cats: [], pending: {}, saving: null, publishing: false, alive: true, touched: false, changeUrl: false, widgets: {} };
	const status = h( 'span.fs-save-state', { role: 'status', 'aria-live': 'polite' } );
	const head = h( 'div.fs-ed-head' );
	const body = h( 'div.fs-ed-body' );
	const side = h( 'aside.fs-ed-side', { 'aria-label': 'Live preview' } );
	const editor = h( 'div.fs-editor', {}, h( 'div.fs-ed-main', {}, head, body ), side );
	const loading = h( 'p.fs-empty', {}, 'Loading category…' );
	main.classList.add( 'fs-main--editor' );
	main.append( editor, loading );
	let pane = null;
	let urlCode = null;
	const autosave = debounce( () => { if ( ! ed.publishing ) flush(); }, 600 );

	function change( key, value ) {
		ed.touched = true;
		try { sessionStorage.removeItem( 'fs-new-cat-' + id ); } catch {}
		ed.pending[ key ] = value;
		ed.data.values[ key ] = value;
		ed.widgets[ key ]?.setError( '' );
		status.textContent = 'Saving…';
		const pub = head.querySelector( '.fs-publish' );
		if ( pub && ! ed.publishing ) pub.disabled = false;
		paintUrl();
		autosave();
	}

	/** Saves pending edits, waiting for any save already in flight. Resolves true only when everything on screen is saved. */
	async function flush() {
		autosave.cancel();
		let ok = true;
		for ( ;; ) {
			while ( ed.saving ) {
				if ( ! ( await ed.saving ) ) ok = false;
			}
			if ( ! Object.keys( ed.pending ).length ) return ok;
			if ( ! ok ) return false;
			ed.saving = saveOnce();
			if ( ! ( await ed.saving ) ) ok = false;
		}
	}

	async function saveOnce() {
		const fields = ed.pending;
		ed.pending = {};
		const seen = {};
		for ( const k of Object.keys( fields ) ) if ( ed.data.live && k in ed.data.live ) seen[ k ] = ed.data.live[ k ];
		let ok = true;
		try {
			const d = await api( 'PUT', `categories/${ id }/draft`, { fields, seen } );
			if ( ! ed.alive ) return ok;
			apply( d );
			const errs = d.field_errors || {};
			for ( const [ k, msg ] of Object.entries( errs ) ) ed.widgets[ k ]?.setError( msg );
			if ( Object.keys( errs ).length ) ok = false;
			status.textContent = Object.keys( errs ).length ? 'Some fields need a look' : ( d.state === 'new' ? 'Saved (not on the site yet)' : 'Saved as draft' );
			refreshPreview( d );
		} catch ( e ) {
			ok = false;
			ed.pending = { ...fields, ...ed.pending };
			status.textContent = 'Not saved yet';
			toast( e.message, 'error' );
		} finally {
			ed.saving = null;
		}
		return ok;
	}

	/** Takes a payload from the server, keeping edits typed since the request left. */
	function apply( d ) {
		const local = { ...ed.pending };
		ed.data = d;
		Object.assign( ed.data.values, local );
		renderHead();
		for ( const [ key, w ] of Object.entries( ed.widgets ) ) {
			if ( key === 'hero_ids' ) w.set( heroValues( ed.data.values.hero_ids ), ed.data.images );
			else if ( key in ed.data.values ) w.set( ed.data.values[ key ] );
		}
		paintUrl();
	}

	function clearErrors() {
		Object.values( ed.widgets ).forEach( ( w ) => w.setError( '' ) );
	}
	function showErrors( e ) {
		const fields = e.data?.fields || {};
		Object.entries( fields ).forEach( ( [ k, m ] ) => ed.widgets[ k ]?.setError( m ) );
		const first = Object.keys( fields )[ 0 ];
		if ( first ) ed.widgets[ first ]?.focus();
		toast( e.message, 'error' );
	}

	/* ---------- header and actions ---------- */

	function renderHead() {
		const d = ed.data;
		const pill = { new: [ 'Not published yet', 'draft' ], changed: [ 'Unpublished changes', 'changed' ], live: [ 'Live', 'live' ] }[ d.state ];
		head.replaceChildren(
			h( 'a.fs-back', { href: '#/categories' }, '← All categories' ),
			h( 'div.fs-ed-title', {}, h( 'h1', {}, d.values.name || 'Untitled' ), h( 'span.fs-pill.fs-pill--' + pill[ 1 ], {}, pill[ 0 ] ), status ),
			h( 'div.fs-ed-actions', {},
				d.state === 'changed' ? h( 'button.fs-btn', { type: 'button', onclick: discard }, 'Discard changes' ) : null,
				d.url ? h( 'a.fs-btn', { href: d.url, target: '_blank', rel: 'noopener' }, 'View on site' ) : null,
				h( 'button.fs-btn.fs-btn--primary.fs-publish', { type: 'button', onclick: () => publish(), disabled: d.state === 'live' && ! Object.keys( ed.pending ).length }, d.state === 'new' ? 'Publish category' : 'Publish changes' ),
				h( 'button.fs-btn.fs-preview-toggle', { type: 'button', onclick: () => editor.classList.toggle( 'is-previewing' ) }, 'Preview' ) ) );
	}

	const newUrl = () => ed.data.base_url + ( ed.data.values.slug || '…' ) + '/';
	function paintUrl() {
		if ( urlCode && ed.data ) urlCode.textContent = newUrl();
	}

	/** A live category whose parent or address changed asks once before it moves; old links will redirect. */
	async function confirmMove() {
		const d = ed.data;
		if ( d.state === 'new' || ed.changeUrl || ( d.values.parent === d.live.parent && d.values.slug === d.live.slug ) ) return true;
		const ok = await confirmDialog( 'Change the address?', `The address changes from ${ d.url } to ${ newUrl() }. Old links, also to its sub-categories, will send visitors to the new address.`, 'Change address' );
		if ( ok ) ed.changeUrl = true;
		return ok;
	}

	async function publish( resolutions = {} ) {
		if ( ed.publishing ) return;
		ed.publishing = true;
		const btn = head.querySelector( '.fs-publish' );
		if ( btn ) btn.disabled = true;
		status.textContent = 'Publishing…';
		try {
			for ( ;; ) {
				try {
					if ( ! ( await flush() ) ) {
						status.textContent = 'Fix the highlighted fields, then publish';
						return;
					}
					if ( ! ( await confirmMove() ) ) {
						status.textContent = 'Not published';
						return;
					}
					const d = await api( 'POST', `categories/${ id }/publish`, { resolutions, change_url: ed.changeUrl } );
					ed.changeUrl = false;
					clearErrors();
					apply( d );
					status.textContent = 'Published';
					const msg = d.displayed ? 'Published. It is on the site now.' : ( d.values.shown ? 'Published. It appears on the site once it has a product.' : 'Published. It stays out of the menu, Home and Shop.' );
					toast( msg, 'ok', d.url ? { href: d.url, label: 'View on site' } : null );
					refreshPreview( d );
					return;
				} catch ( e ) {
					if ( e.code === 'fisha_studio_conflict' ) {
						const chosen = await resolveConflicts( e.data.conflicts );
						if ( chosen ) {
							resolutions = { ...resolutions, ...chosen };
							continue;
						}
						status.textContent = 'Not published';
						return;
					}
					if ( e.code === 'fisha_studio_nothing' && ! Object.keys( ed.pending ).length && ! ed.saving ) {
						try {
							apply( await api( 'DELETE', `categories/${ id }/draft` ) );
							status.textContent = 'Up to date';
							toast( 'Nothing to publish. The site keeps its current values.' );
							return;
						} catch ( e2 ) {
							e = e2;
						}
					}
					status.textContent = 'Not published';
					showErrors( e );
					return;
				}
			}
		} finally {
			ed.publishing = false;
			const b2 = head.querySelector( '.fs-publish' );
			if ( b2 ) b2.disabled = ed.data.state === 'live' && ! Object.keys( ed.pending ).length;
			if ( Object.keys( ed.pending ).length ) autosave();
		}
	}

	async function discard() {
		if ( ! ( await confirmDialog( 'Discard changes?', 'Your unpublished changes to this category will be thrown away. The live category stays exactly as it is.', 'Discard', 'danger' ) ) ) return;
		autosave.cancel();
		ed.pending = {};
		if ( ed.saving ) await ed.saving;
		try {
			const d = await api( 'DELETE', `categories/${ id }/draft` );
			clearErrors();
			apply( d );
			status.textContent = '';
			toast( 'Changes discarded.' );
			refreshPreview( d );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	async function editAddress() {
		const d = ed.data;
		const slug = await modal( {
			title: d.state === 'new' ? 'Edit the address' : 'Change the address',
			body: [
				d.state === 'new' ? null : h( 'p', {}, 'This category is live. Old links keep working and send visitors to the new address, also links to its sub-categories.' ),
				h( 'label.fs-field__label', {}, 'Address', h( 'input.fs-input', { name: 'slug', value: d.values.slug, required: true, pattern: '[a-z0-9]+(-[a-z0-9]+)*', maxlength: 60, title: 'Lowercase letters, numbers and single dashes' } ) ),
				h( 'p.fs-field__help', {}, d.base_url + '…' ),
			],
			actions: [ { label: 'Cancel', value: null }, { label: 'Use this address', kind: 'primary', submit: true, value: ( f ) => f.elements.slug.value.trim() } ],
		} );
		if ( ! slug || slug === d.values.slug ) return;
		if ( d.state !== 'new' ) ed.changeUrl = true;
		change( 'slug', slug );
	}

	/* ---------- delete ---------- */

	const byId = () => new Map( ed.cats.map( ( c ) => [ c.id, c ] ) );
	function pathOf( c ) {
		const m = byId();
		const names = [ c.name ];
		for ( let p = m.get( c.parent ); p; p = m.get( p.parent ) ) names.unshift( p.name );
		return names.join( ' › ' );
	}
	function descendantsOf( root ) {
		const out = new Set();
		for ( let grew = true; grew; ) {
			grew = false;
			for ( const c of ed.cats ) {
				if ( ( c.parent === root || out.has( c.parent ) ) && ! out.has( c.id ) ) {
					out.add( c.id );
					grew = true;
				}
			}
		}
		return out;
	}
	function parentChoices() {
		const below = descendantsOf( id );
		return ed.cats.filter( ( c ) => c.id !== id && ! below.has( c.id ) ).map( ( c ) => [ String( c.id ), pathOf( c ) ] );
	}

	async function remove() {
		const d = ed.data;
		const parent = byId().get( d.live.parent );
		const where = parent ? `the ${ parent.name } page` : 'the Shop page';
		let moveTo = 0;
		if ( d.orphan_products > 0 ) {
			const n = d.orphan_products;
			moveTo = await modal( {
				title: `Delete ${ d.values.name }?`,
				body: [
					h( 'p', {}, `${ n === 1 ? '1 product is' : n + ' products are' } only in this category. Choose where ${ n === 1 ? 'it goes' : 'they go' }:` ),
					h( 'select.fs-input', { name: 'to', required: true, 'aria-label': 'Move products to' }, h( 'option', { value: '' }, 'Choose a category…' ), parentChoices().map( ( [ v, t ] ) => h( 'option', { value: v }, t ) ) ),
					h( 'p.fs-field__help', {}, `Its sub-categories move up one level. Its address sends visitors to ${ where }.` ),
				],
				actions: [ { label: 'Cancel', value: null }, { label: 'Delete category', kind: 'danger', submit: true, value: ( f ) => Number( f.elements.to.value ) } ],
			} );
			if ( ! moveTo ) return;
		} else if ( ! ( await confirmDialog( `Delete ${ d.values.name }?`, d.state === 'new' ? 'This category was never published. It will be removed.' : `Its sub-categories move up one level, and its address sends visitors to ${ where }.`, 'Delete category', 'danger' ) ) ) {
			return;
		}
		autosave.cancel();
		ed.pending = {};
		try {
			await api( 'DELETE', `categories/${ id }`, { move_to: moveTo }, { retry: false } );
			ed.alive = false;
			toast( 'Category deleted.' );
			location.hash = '#/categories';
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	/* ---------- fields ---------- */

	function build( d ) {
		const v = d.values;
		const W = ed.widgets;
		W.name = textField( 'Name', v.name, ( x ) => change( 'name', x ), { max: 60 } );
		urlCode = h( 'code' );
		W.slug = {
			el: h( 'div.fs-field', {}, h( 'p.fs-field__label', {}, 'Address' ),
				h( 'div.fs-url', {}, urlCode, h( 'button.fs-btn.fs-btn--small', { type: 'button', onclick: editAddress }, d.state === 'new' ? 'Edit address' : 'Change address' ) ),
				h( 'p.fs-field__error', { hidden: true } ) ),
			set() { paintUrl(); },
			setError( msg ) {
				const e = W.slug.el.querySelector( '.fs-field__error' );
				e.hidden = ! msg;
				e.textContent = msg || '';
			},
			focus() { W.slug.el.querySelector( 'button' ).focus(); },
		};
		W.parent = selectField( 'Parent', String( v.parent ), [ [ '0', 'Top level' ], ...parentChoices() ], ( x ) => change( 'parent', Number( x ) ), { help: 'Top-level categories are in the Shop menu, the footer, the Home page and the Shop page.' } );
		W.lead = textField( 'Intro', v.lead, ( x ) => change( 'lead', x ), { multiline: true, counter: 160, max: 300, help: 'One or two sentences under the heading. Google shows it too.' } );
		W.hero_ids = imagesField( heroValues( v.hero_ids ), d.images, ( x ) => change( 'hero_ids', [ x.image_id, ...x.gallery_image_ids ].filter( Boolean ) ), { label: 'Hero photos', help: 'One or two photos for the top of the page. Leave it empty to use the first product photos.', max: 2, hoverNote: false } );
		W.shown = toggleField( 'Show on site', v.shown, ( x ) => change( 'shown', x ), { help: 'When on, it appears on the site once it has at least one product.' } );
		body.replaceChildren( W.name.el, W.slug.el, W.parent.el, W.lead.el, W.hero_ids.el, W.shown.el,
			d.deletable ? h( 'div.fs-danger-zone', {}, h( 'button.fs-btn.fs-btn--danger', { type: 'button', onclick: remove }, 'Delete category' ) ) : null );
		paintUrl();
	}

	function refreshPreview( d ) {
		if ( ! pane ) return;
		pane.setTargets( d.previews );
		pane.reload();
	}

	/* ---------- load ---------- */

	( async () => {
		try {
			const [ d, list ] = await Promise.all( [ api( 'GET', `categories/${ id }` ), api( 'GET', 'categories' ) ] );
			if ( ! ed.alive ) return;
			ed.data = d;
			ed.cats = list.items;
			loading.remove();
			renderHead();
			build( d );
			pane = previewPane( d.previews );
			pane.el.querySelector( '.fs-preview__bar' ).prepend( h( 'button.fs-btn.fs-btn--small.fs-preview-close', { type: 'button', onclick: () => editor.classList.remove( 'is-previewing' ) }, 'Back to editing' ) );
			side.append( pane.el );
		} catch ( e ) {
			loading.replaceChildren( e.message, ' ', h( 'a', { href: '#/categories' }, 'Back to categories' ) );
		}
	} )();

	return {
		destroy() {
			ed.alive = false;
			autosave.cancel();
			// "New category" makes one at once; one made in this session and left untouched is removed when the owner walks away.
			let fresh = false;
			try {
				fresh = sessionStorage.getItem( 'fs-new-cat-' + id ) === '1';
				sessionStorage.removeItem( 'fs-new-cat-' + id );
			} catch {}
			const d = ed.data;
			if ( fresh && ! ed.touched && d?.state === 'new' && ! d.direct_products && ! Object.keys( ed.pending ).length ) {
				api( 'DELETE', `categories/${ id }`, { move_to: 0 }, { retry: false } ).catch( () => {} );
			}
		},
		dirty: () => Object.keys( ed.pending ).length > 0 || !! ed.saving || ed.publishing,
		async canLeave() {
			if ( ed.publishing ) return false;
			if ( ! ed.data || ( ! Object.keys( ed.pending ).length && ! ed.saving ) ) return true;
			if ( await flush() ) return true;
			return confirmDialog( 'Leave without saving?', 'Some changes could not be saved yet.', 'Leave', 'danger' );
		},
	};
}
```

If the categories list from Task 8 renders before a just-abandoned new category is deleted, the deleted row can flash. Accept that, or make the list wait as the product list does with `pendingCleanup`; see the ledger for phase 2.

- [ ] **Step 4: Browser check** (test owner; `docker exec fisha-wordpress-1 apachectl graceful` first)

1. Categories → **+ New category** → the editor opens with "New category", pill "Not published yet", address `…/product-category/new-category/`, and a preview of the page.
2. Type the name "zz-test Scarves":
   - the address follows the name;
   - the preview H1 shows "zz-test <span>Scarves</span>" with the last word orange.

   Change Parent to Wearables → the address becomes `…/product-category/wearables/zz-test-scarves/` after the autosave.
3. Click Publish with no intro → "Write a short intro" and "Add a hero photo" show on the fields. Type an intro and pick one photo → Publish → toast "Published. It appears on the site once it has a product."
4. Open `http://localhost:8484/product-category/wearables/` in another tab → no Scarves shelf (empty). In the Studio, edit a draft-only test product and give it this category (or use WP-CLI to add a published zz-test product to it). Refresh: the Scarves shelf and chip appear on Wearables and on `/shop-2/`.
5. Back in the editor, try to add a 3rd hero photo → "You can use up to 2 photos here."
6. Change the intro → pill "Unpublished changes" → Discard changes → back to Live.
7. Delete category:
   - the dialog asks where its product goes (the zz-test product is only there);
   - choose Wearables → deleted;
   - `curl -sI http://localhost:8484/product-category/wearables/zz-test-scarves/` → `301` to `/product-category/wearables/`.

   Remove the zz-test product.
8. New category, then leave at once with ← All categories → it is gone from the list (refresh once if it flashed).
9. A product editor: Categories shows "(not published yet)" next to a never-published category.
10. No console errors. At 375px the editor and the preview toggle work.

Restore anything real you touched. Then the baseline diff must show no output.

- [ ] **Step 5: Run the suite**

Run: `bash tests/studio/run.sh` → 229 passed, 0 failed (JS changes have no server tests; the browser check is the verification).

- [ ] **Step 6: Commit**

```bash
git add wp-content/mu-plugins/fisha-design/studio/app/js/conflicts.js wp-content/mu-plugins/fisha-design/studio/app/js/views/category-editor.js wp-content/mu-plugins/fisha-design/studio/app/js/views/product-editor.js wp-content/mu-plugins/fisha-design/studio/app/js/images.js
git commit -m "Studio: category editor with preview, publish, change address and delete; shared conflict dialog

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Verification

**Files:** none (report only)

- [ ] **Step 1:** `bash tests/studio/run.sh` → all pass (229), no PHP warnings or notices.
- [ ] **Step 2:** `docker exec fisha-wordpress-1 apachectl graceful`, then `bash tests/studio/baseline.sh > /tmp/p3-final.txt; diff tests/studio/baseline-phase3-before.txt /tmp/p3-final.txt` → no output.
- [ ] **Step 3: End to end in the browser** (test owner):
  1. New top-level category "zz-test Top" with intro and photo → Publish. It is not on Home/Shop/menus (no products).
  2. Add a published zz-test product to it:
     - the header Shop menu, the footer Shop column, Home ("Four ways to take the little fish home." and 4 cards in one row at 1280px, swipe row at 375px) and the Shop page all show it, last.
  3. Move it to first place in Categories → it is first everywhere.
  4. Switch Show on site off → gone everywhere. `curl -sI` on its page shows `X-Robots-Tag: noindex`.
  5. Delete it, moving the product to Originals.
  6. Clean up the zz-test product and give Originals back its exact product list.
- [ ] **Step 4: Leftovers:**
  - `MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp term list product_cat --fields=term_id,slug --format=csv | grep zz-test` → no output.
  - The same for products: `wp post list --post_type=product --s=zz-test --post_status=any --format=ids` → empty (or only the trash entries already listed in the phase 2 ledger).
- [ ] **Step 5:** Report the counts. Add to the deploy notes:
  - The migration runs by itself on first load (option `fisha_cat_visibility_db`).
  - On live, check that the header and footer menus still list Wearables, Collectibles and Originals right after deploy.
