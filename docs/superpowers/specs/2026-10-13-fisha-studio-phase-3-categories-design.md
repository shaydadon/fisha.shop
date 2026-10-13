# Fisha Studio — Phase 3: Categories (design addendum)

Date: 2026-10-13 · Status: for owner review
Parent spec: `docs/superpowers/specs/2026-10-12-fisha-studio-design.md` (binding unless this addendum says
otherwise). Phases 1–2 (products, sizes, inventory) are built; this phase adds categories.

## 1. Goal and owner decisions

The site owner adds and edits product categories in the Studio. A new category page comes out exactly like the
existing ones (same template `templates/product-cat.php`, all design fields filled), and keeps the URL
structure `/product-category/{slug}/` and `/product-category/{parent}/{slug}/`.

Owner decisions (2026-10-13), which narrow the parent spec's §5.3 category editor:

1. **Minimal editor.** The owner edits only: name, parent, intro, hero photos, and "Show on site". Every other
   design field is filled automatically (§4) and is not shown in the Studio.
2. **Show on site switch.** Every category is either *Shown* or *Hidden*.
   - Shown top-level category → header "Shop" dropdown, footer Shop column, Home "Pick your Fisha" section, and
     the Shop page (shelf + filter chip).
   - Shown sub-category → shelf on its parent's category page and inside its parent's shelf/chips on the Shop
     page (today's behaviour). Not added to the header dropdown (it lists top-level categories only today).
3. **Empty categories are never displayed.** A Shown category with no published products (counting its
   sub-categories, and counting sold-out products) is left out of the header, footer, Home, Shop and its
   parent's page until it has one.
4. **New categories stay invisible until first Publish** (parent spec §6.1); "Show on site" takes effect on
   Publish.

Not in this phase (site keeps today's values, no Studio UI): heading/eyebrow/SEO fields, Happy clients,
One-of-a-kind, Drop sign-up, Home card photo override, Shop hero categories, a Settings screen.

## 2. Where categories show today (all hardcoded)

| Place | Source today | Phase 3 source |
|---|---|---|
| Header "Shop" dropdown | `wp_navigation` post 201 (`header-menu`), 3 static `navigation-link` items (Wearables, Collectibles, Originals) inside the "Shop" submenu | Generated at render time (§5.2) |
| Footer Shop column | `wp_navigation` post 204 (`footer-shop`), same 3 static links | Generated at render time (§5.2) |
| Home "Pick your Fisha" | `[fisha_home_cats]` in `home.php`: `fisha_home_cat_list()` (slug → tagline), photo per slug from shortcode attributes, header text "Three ways to take the little fish home." | `fisha_home_cat_list()` reads the shown list (§5.3) |
| Shop page shelves + chips | `templates/shop-home.php`: `fisha_shop_top_categories` filter default `array('wearables','collectibles','originals')`; sub-category shelves from `get_terms(parent)` | Filter default = shown list; empty/hidden sub-categories skipped (§5.4) |
| Parent category page shelves | `templates/product-cat.php`: one shelf per child (`get_terms(parent)`) | Hidden/empty children skipped (§5.4) |

## 3. Data

| Data | Storage | Notes |
|---|---|---|
| Show on site | termmeta `fisha_shown` = `'1'` / `'0'` | Missing = Hidden (a category later created in WP admin stays off the site until the owner shows it) |
| Not published yet | termmeta `fisha_studio_hidden` = `'1'` (parent spec §6.1) | Removed on first Publish |
| Created by Studio | termmeta `_fisha_studio_created` = `'1'` | Like products; used for "never published" checks |
| Draft of a published category | termmeta `_fisha_studio_draft` = `{fields, base, user, updated}` | Same shape and conflict rules as products |
| Home tagline | termmeta `fisha_home_line` | Seeded with today's 3 taglines; new categories use the intro (not editable) |
| Order among siblings | termmeta `order` (WooCommerce) | Drag reorder in the tree; order is used by header, footer, Home, Shop, chips |
| Old URLs | termmeta `fisha_old_paths` (parent spec §4.3) | 301 handler |
| Design fields | `fisha_h1`, `fisha_eyebrow`, `fisha_lead`, `fisha_seo_title`, `fisha_seo_desc`, `fisha_hero_ids`, `term.description` | `fisha_lead` = Intro; `fisha_hero_ids` = Hero photos |

**Migration** (once, idempotent, `fisha_studio_db_version` bump):

- `fisha_shown = 1` on Wearables, Collectibles, Originals and every category below them.
- `fisha_shown = 0` on every other category: today `fisha-wear`, `made-for-city-life`, `shop-fisha`,
  `street-goods`, `uncategorized`.
- `fisha_home_line` set to today's 3 taglines.
- Top-level `order` set to today's list order (Wearables 1, Collectibles 2, Originals 3) where it is missing or
  conflicts.

Result: header, footer, Home and Shop render byte-identical HTML to today (verified with
`tests/studio/baseline.sh`).

## 4. Automatic fields (new categories)

Written when a category is created and kept up to date while it has never been published. After that they are
only rewritten on rename if they still equal the automatic value for the old name; hand-written values on the
existing categories are never touched.

| Field | Automatic value |
|---|---|
| Slug | from the name per parent spec §4.3 (unique, `[a-z0-9-]`, ≤ 60, reserved words refused) |
| Heading `fisha_h1` | name with the last word wrapped in `<span>` (e.g. `Winter <span>Scarves</span>`); a one-word name is wrapped whole |
| Eyebrow `fisha_eyebrow` | parent name, else "Fisha Shop" |
| SEO title `fisha_seo_title` | `"{name} \| {first sentence of intro}"`, trimmed to 60 chars on a word boundary |
| SEO description `fisha_seo_desc` | the intro (meta description; `seo.php` already prefers `fisha_lead`) |
| Description `term.description` | left empty (the "About" section only shows when it is set) |
| Order | last among its siblings |
| Home tagline | the intro |

## 5. Site behaviour

### 5.1 One helper decides visibility
`fisha_cat_is_displayed( WP_Term $t ): bool` (in `shop.php`, next to `fisha_cat_products`), true only when:

- the term is not `fisha_studio_hidden`;
- `fisha_shown` is `'1'`;
- its parent chain is displayed (a sub-category of a hidden parent is not displayed); and
- it has at least one published, catalog-visible product, including products in its sub-categories (stock
  status ignored).

Product presence is checked with one `wc_get_products( limit 1, return ids )` query, cached per request.
`fisha_displayed_top_categories(): WP_Term[]` returns displayed top-level categories ordered by `order`.

### 5.2 Header dropdown and footer column
A `render_block_data` / `render_block` filter on the `core/navigation-submenu` labelled "Shop" inside
navigation 201, and on navigation 204, replaces their category links with one `navigation-link` per displayed
top-level category (label = name, URL = term link), in order. Page links (River, Contact…) are untouched. The
stored `wp_navigation` posts are not modified, so turning the filter off restores today's menus.

### 5.3 Home "Pick your Fisha"
- `fisha_home_cat_list()` defaults to the displayed top-level categories (slug → `fisha_home_line`, falling back
  to the intro).
- Photo: the shortcode attribute for that slug if present (existing three), else the first hero photo (today's
  fallback).
- Header text: when the number of cards is not 3, "Three ways…" becomes "{Two|Four|Five|Six} ways to take the
  little fish home." (3 cards = today's text). The card row already wraps; the 4th+ card uses the
  `fh-cat--N` colour cycle.

### 5.4 Shop page and category pages
- `fisha_shop_top_categories` default = slugs of the displayed top-level categories (filter kept).
- Sub-category shelves and filter chips (Shop page) and child shelves (`product-cat.php`) skip children that are
  not displayed.
- A hidden category's own page still opens by URL (product breadcrumbs keep working) but sends
  `X-Robots-Tag: noindex` and `<meta name="robots" content="noindex">`, and is left out of the WordPress
  sitemap. A not-published-yet category behaves as in parent spec §6.1 (invisible; preview only).

### 5.5 Caching
Publishing a category, changing "Show on site", reordering, and publishing/trashing a product whose categories
change the displayed set purge LiteSpeed for Home, Shop, the category, its parent and its old/new URLs; header
and footer are on every page, so a change to the displayed **top-level** set purges all (`litespeed_purge_all`).

## 6. Studio

### 6.1 Categories list (new nav item "Categories")
- Tree (parent → children), each row: name, product count, status pill (Live · Unpublished changes · Not
  published yet), Shown/Hidden switch, and "Not on site: no products" note when shown but empty.
- Drag to reorder among siblings (saved straight away, audit-logged; it is ordering only, no draft).
- "New category" button → editor for a new category (created immediately, Not published yet).
- The Shown/Hidden switch in the list publishes that one field immediately for a published category (for a
  not-published-yet one it only changes the draft); confirm dialog when hiding a live
  category: "Hide {name} from the menu, Home and Shop?").

### 6.2 Category editor (preview on the right, same layout as products)
| Field | Rules |
|---|---|
| Name | required, ≤ 60 chars; URL shown underneath (`fisha.shop/product-category/{parent}/{slug}/`), slug editable until first publish |
| Parent | "Top level" or any other category except itself and its descendants |
| Intro | required to publish, 1–2 sentences, counter with 160-char hint |
| Hero photos | 0–2 images (media picker from phase 1); required to publish unless the category already has a product with an image |
| Show on site | switch, default on for new categories |

Header actions: Publish, Discard changes, Delete. Preview targets: category page, its parent page, Shop, Home.
Publish, conflict check, audit snapshot and transaction follow parent spec §6.3.

### 6.3 Changing a live URL
Changing the parent of a published category (or its slug via "Change URL") asks:
"The address changes from … to …. Old links will be redirected." On publish, the old path (and every
descendant's old path) is added to `fisha_old_paths`; a `template_redirect` handler answers old paths with
301 to the current term link.

### 6.4 Delete
- Confirm dialog. If the category (or its children) holds products: "Move its N products to:" category picker
  (required). Products that would be left with only this category get the chosen one; children move up to its
  parent.
- `uncategorized` (WooCommerce default) cannot be deleted.
- A never-published Studio-created category with no products is deleted without the move step.
- On delete: snapshot to the audit log, `wp_delete_term`, its path and its children's old paths redirect (301)
  to the parent page (or Shop for a top-level category) via an option `fisha_deleted_cat_paths`, purge.

### 6.5 Product editor
The category picker lists not-published-yet categories as "{name} (not published yet)". Publishing a product
whose only categories are not published yet shows the warning from parent spec §6.1 (product stays published;
it just has no category page yet).

## 7. REST (`fisha-studio/v1`, cap `fisha_studio`)
`GET /categories` (tree), `POST /categories` (create), `GET /categories/{id}`, `PUT /categories/{id}/draft`,
`POST /categories/{id}/publish`, `POST /categories/{id}/discard`, `POST /categories/{id}/shown`,
`POST /categories/order` (`{parent, ids[]}`), `DELETE /categories/{id}` (`{move_to}`), `GET
/categories/{id}/preview-token`. Same nonce, error shape and 409 conflict format as products.

## 8. Testing
- Server tests (`tests/studio/test-categories*.php`): automatic fields; slug rules; hidden-until-publish;
  draft/publish/conflict/rollback; shown/hidden; empty rule incl. sold-out and products only in children;
  parent hidden hides children; header/footer/Home/Shop lists; reorder; URL change → 301 incl. children;
  delete with product move and redirects; `uncategorized` refused; migration idempotent; Home header text by
  count.
- Parity: `tests/studio/baseline.sh` before/after = identical (Home, Shop, every category page, header, footer).
- Browser (test owner, localhost): create "Test Scarves" under Wearables, preview, publish with no products →
  not on Shop/parent page; give it a product → appears; new top-level "Test Top" with a product → in header
  dropdown, footer, Home (4 cards, "Four ways…"), Shop; hide → gone; delete both, moving products back;
  restore any real product touched.

## 9. Rollout
Localhost → push `develop` → dev.fisha.shop (owner review) → `main`. Migration runs on first load; expected
visible change on deploy: none.
