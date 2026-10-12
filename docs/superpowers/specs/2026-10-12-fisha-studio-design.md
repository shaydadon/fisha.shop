# Fisha Studio — design spec

**Date:** 2026-10-12 · **Status:** awaiting review · **Repo:** fisha.shop (`develop`)

## 1. Purpose

Give the site owner (the artist, non-technical) a simple, branded panel to manage the shop catalog —
products, variations, prices, stock, categories and attributes — with a live preview of the real page
and a Publish button, **without access to WP admin**.

Two problems it solves:

1. **WP admin is too complex and too risky** for the owner (Elementor, plugins, settings are one click away).
2. **Items created in WP admin don't get the Fisha design.** The page *templates* are already shared by every
   product and category, but the *design data* those templates read (headings, intro, hero photos, card colour,
   meta line, Home/Shop placement, SEO text…) lives in hidden meta or is hardcoded in PHP. WP admin cannot set
   any of it, so a new category or product comes out half-designed.

**Success criteria**

- The owner can do every catalog task listed in §5 from `/studio`, on desktop and phone.
- Any product or category created in the Studio renders exactly like the existing ones — same template, all
  design fields filled (by the owner or by a defined default, §4).
- Every change is previewed on the real page before it is live; nothing reaches customers until Publish.
- The owner's account cannot reach wp-admin, plugins, themes, users, settings, Elementor, orders, or any REST
  route outside the Studio's own.
- Deploying the Studio changes nothing visible on the live site.

**Decisions taken** (from brainstorming): approach 1 (standalone app inside the `fisha-design` mu-plugin);
publish **per item**; access **panel only** (no orders); UI in **English**.

## 2. What exists today (inventory)

### 2.1 Templates — already shared

| Page | Rendered by | Notes |
|---|---|---|
| Product page | Theme block template `hostinger-ai-theme//single-product` + Fisha hooks/CSS (`ux.php`, `woo.css`, `shop.css`) | Global for every product; no product has its own Elementor layout. Blocks: breadcrumbs, gallery, title, rating, price, excerpt (short description), add-to-cart form, meta (SKU, categories, **tags**, brands), product-details tabs (description, additional info = attributes / weight / dimensions, reviews), related products (replaced by a Fisha shelf in `ux.php`). Hooks add: series picker (`shop.php`, priority 25), sold-out drop sign-up (`leads.php`, 32). |
| Category page | `fisha-design/templates/product-cat.php` (via `template_include`, `shop.php`) | Global for every `product_cat`. Parent → one shelf per child + "More …" shelf; leaf → grid; empty → "Fresh pieces are on their way". |
| Shop page `/shop-2/` | `fisha-design/templates/shop-home.php` | Shelves per **hardcoded** top category list. |
| Home "Pick your Fisha" | `[fisha_home_cats]` in `home.php` | **Hardcoded** category list + tagline per category. |

### 2.2 Category — every field the site reads

| Field (UI name) | Storage | Read by | Fallback today |
|---|---|---|---|
| Name | `term.name` | crumbs, chips, shelf titles, Home card, Shop shelf, alt texts | — |
| Slug / URL | `term.slug` | URL, filter data, anchors, hardcoded lists | — |
| Parent | `term.parent` | crumbs, eyebrow fallback, shelves vs grid, chips, drop interest | — |
| Description ("About" text) | `term.description` | "About {name}" section (only when Intro is also set), shelf subtitle when shown as a child shelf, SEO fallback | — |
| Heading (H1) | termmeta `fisha_h1` (allows `<span>` for the orange word) | H1, JSON-LD name | name |
| Eyebrow | termmeta `fisha_eyebrow` | above H1 | parent name, else "Fisha Shop" |
| Intro | termmeta `fisha_lead` | under H1, meta description, JSON-LD | plain description |
| SEO title | termmeta `fisha_seo_title` | `<title>` | WP default |
| SEO description | **hardcoded** `fisha_seo_known()['product_cat:<slug>']` in `seo.php` | meta description when no Intro/description | description, else "{name} by Fisha, hand-drawn in Tel Aviv." |
| Hero photos (1–2) | termmeta `fisha_hero_ids` (CSV attachment ids) | hero collage, og:image, Home card photo fallback | `thumbnail_id`, else first 2 products' images |
| Thumbnail | termmeta `thumbnail_id` (WooCommerce) | hero fallback | — |
| Happy clients gallery | termmeta `fisha_happy_ids` (CSV; caption = attachment excerpt) | "Happy clients" section (heading/body copy hardcoded, Originals-flavoured) | section hidden |
| Order among siblings | termmeta `order` (WooCommerce) | chips, shelves, Shop shelves | 0 |
| Show on Home + tagline | **hardcoded** `fisha_home_cat_list()` (`home.php`) | Home card | not shown |
| Home card photo | shortcode attribute per slug (`wearables="<id>"`) in the Home page content | Home card | first hero photo |
| Show on Shop page | **hardcoded** `fisha_shop_top_categories` (`shop-home.php`) | Shop shelves + filter chips | not shown |
| One-of-a-kind | **hardcoded** `'originals' === $term->slug` (`home.php`) | Home facts: "4 available · one of a kind" vs "12 pieces · from ₪89" | normal wording |
| Drop sign-up + its text | **hardcoded** `fisha_drop_cats()` + texts (`leads.php`) | sign-up box under shelf; sold-out product pages | not shown |
| Display type, `wooletor_selectcategory_layout`, `product_count_product_cat` | WooCommerce / WooLentor internals | not used by Fisha templates | — leave untouched |

### 2.3 Product — every field the site reads

| Field (UI name) | Storage | Where it shows |
|---|---|---|
| Name | `post_title` | page H1, card title, alt texts, crumbs |
| Slug | `post_name` | URL |
| Status | `post_status` (publish / draft / private) | visibility |
| Catalog visibility | `product_visibility` terms | card lists (`visibility => catalog`) |
| Featured | `product_visibility` `featured` | (WooCommerce only; kept for completeness) |
| Type | `product_type` (simple / variable) | add-to-cart form, card "+" vs "→" button |
| Categories | `product_cat` | crumbs, category pages, Shop/Home counts, filter data, drop interest, related |
| Tags | `product_tag` | product page meta "Tags:" |
| Short description | `post_excerpt` | product page summary, SEO description |
| Description | `post_content` | Description tab, SEO fallback |
| Regular price | `_regular_price` | everywhere prices show |
| Sale price + schedule | `_sale_price`, `_sale_price_dates_from/_to` | price, "Sale" badge |
| Price on request | no price set | card shows "Price on request" (`fisha_product_card`) |
| SKU | `_sku` | product page meta |
| Manage stock / quantity / status | `_manage_stock`, `_stock`, `_stock_status` | "Sold out" badge, add-to-cart, drop sign-up on product page, Home/Shop counts |
| Low-stock threshold | `_low_stock_amount` | Studio dashboard (low stock) |
| Backorders | `_backorders` | add-to-cart |
| Sold individually | `_sold_individually` | qty selector (one-of-a-kind) |
| Main image | `_thumbnail_id` | gallery, card, Shop hero collage |
| Gallery | `_product_image_gallery` | gallery; first gallery image = card hover image |
| Image alt text | attachment `_wp_attachment_image_alt` | accessibility |
| Attributes | `_product_attributes` (taxonomy `pa_size`, `pa_shoe-size`; or local, e.g. product 21 "Size"/"Color") | variation pickers, card `data-sizes`, Additional information tab |
| Weight / dimensions / shipping class | `_weight`, `_length`…, `product_shipping_class` | Additional information tab, shipping |
| Tax status / class | `_tax_status`, `_tax_class` | checkout |
| Reviews allowed | `comment_status` | Reviews tab |
| Menu order | `menu_order` | sort order in every list |
| **Card background** | postmeta `_fisha_card_bg` (hex) | product card |
| **Meta line** | postmeta `_fisha_colour` ("Oil on wood · 33 × 33 cm", "4 colours") | under card title |
| **Design series** | postmeta `_fisha_series` (e.g. `clipper`) | "Pick your design" picker on product page |
| **Skip in Shop hero** | postmeta `_fisha_hero_skip` | Shop page hero collage |
| SEO title / description | none per product today (title = name; description from short description) | `<title>`, meta description |
| Sample (mock) product flag | postmeta `_fisha_mock` (27 products) | internal; marks generated sample products |

**Variation:** attribute values, `_regular_price`, `_sale_price` (+ dates), `_sku`, `_manage_stock`, `_stock`,
`_stock_status`, enabled (`post_status` publish/private), `_thumbnail_id`, `_variation_description`,
`_weight`/dimensions, `menu_order`.

### 2.4 Other hardcoded, category-dependent bits

- Shop hero collage takes one photo each from `hoodies`, `tshirts`, `hats` (`shop-home.php`).
- "Happy clients" heading/body copy is fixed text written for Originals.
- Orphan empty categories exist: `fisha-wear`, `made-for-city-life`, `shop-fisha`, `street-goods`,
  `uncategorized` (0 products). The Studio shows them; the owner can delete them (`uncategorized` is the
  WooCommerce default and cannot be deleted).

## 3. Architecture

New module `wp-content/mu-plugins/fisha-design/studio/`, loaded from `fisha-design.php` like the others:

| File | Responsibility |
|---|---|
| `studio.php` | Bootstraps the module; registers role, routes (`/studio`, `/studio/login`), access gates |
| `studio-role.php` | `fisha_owner` role + capabilities; `wp fisha-studio create-owner` WP-CLI command |
| `studio-fields.php` | **Single source of truth**: the field schema for product, variation, category (type, storage, sanitizer, validator, default) — used by API, validation, preview overlay and the UI |
| `studio-settings.php` | Site settings that replace hardcoded lists (§4.4) + one-time migration |
| `studio-drafts.php` | Draft storage, base snapshot, conflict detection |
| `studio-preview.php` | Preview token + draft overlay filters |
| `studio-publish.php` | Validate → snapshot → apply (transaction) → purge cache |
| `studio-audit.php` | Audit log + version snapshots + restore |
| `api/*.php` | REST controllers under `fisha-studio/v1`: `products`, `variations`, `categories`, `attributes`, `media`, `settings`, `inventory`, `audit`, `session` |
| `app/` | UI: `index.php` shell, ES-module JS (`app.js`, `views/*.js`, `lib/*.js`), `studio.css`. No build step. |

Integration tests live outside the deployed folder in `/tests/studio/` (added to `.gitignore` allow-list;
the deploy only rsyncs `wp-content/mu-plugins`).

All writes go through WooCommerce/WordPress APIs (`WC_Product_Simple`, `WC_Product_Variable`,
`WC_Product_Variation`, `WC_Product_Attribute`, `wp_insert_term`/`wp_update_term`, `update_term_meta`,
`media_handle_upload`). No direct SQL writes.

## 4. "Same design for everything new"

### 4.1 Templates
No change needed: product and category pages are already rendered by one shared template each (§2.1).
The Studio never creates per-item layouts.

### 4.2 Design data is required, with defaults
Creating an item in the Studio fills every design field the template reads, either from the owner or from a
default shown in the form (so the owner sees exactly what will be used):

| Item | Field | Default |
|---|---|---|
| Category | Heading | name, with the last word wrapped in `<span>` (orange) — matches existing "Fisha <span>Originals</span>" style |
| Category | Eyebrow | parent name, else "Fisha Shop" |
| Category | Intro | **required** (1–2 sentences, ≤ 160 chars recommended — also used as meta description) |
| Category | SEO title | "{Heading text} \| {first sentence of intro}", trimmed to 60 chars, editable |
| Category | Hero photos | **required for publish** unless the category has products with images (template falls back to them) |
| Category | Order | last among siblings |
| Category | One-of-a-kind / Drop sign-up / Home / Shop | inherited from parent; top-level defaults off |
| Product | Card background | palette colour matching its category's existing products (most common `_fisha_card_bg` among siblings) |
| Product | Meta line | variable: "{n} sizes" / "{n} colours"; simple: empty |
| Product | Short description | **required** (used on the page and as meta description) |
| Product | Main image | **required for publish** |
| Product | Image alt text | "{Product name} by Fisha" |
| Product | Menu order | first in its category |
| Product | Stock | category is one-of-a-kind → manage stock on, qty 1, sold individually on |

**Duplicate** (product or category) copies every field incl. design data; the copy starts as a draft named
"{name} (copy)".

### 4.3 URLs keep the site's structure

Current structure (WordPress `permalink_structure = /%postname%/`, WooCommerce permalinks):

| Item | Pattern | Example |
|---|---|---|
| Product | `/product/{slug}/` | `/product/out-to-the-river/` |
| Top category | `/product-category/{slug}/` | `/product-category/originals/` |
| Sub-category | `/product-category/{parent}/{slug}/` | `/product-category/collectibles/clippers/` |
| Product tag | `/product-tag/{slug}/` | — |
| Shop | `/shop-2/` | — |

Rules:
- The Studio **never changes permalink settings** (`permalink_structure`, `woocommerce_permalinks`); it only
  sets slugs. New items therefore always get the same pattern as existing ones.
- **New item slug** is generated from the name: lowercase, ASCII (transliterated via `sanitize_title`),
  words joined by `-`, no stop-word stripping, ≤ 60 chars, made unique with `-2`, `-3`… (WordPress rules).
  Shown live under the name as the full URL ("fisha.shop/product/blue-reed-fisha/"), editable until the first
  publish.
- **Existing slugs are never regenerated.** Renaming a product or category does not touch its slug (many
  current slugs intentionally differ from the name).
- **Changing a published URL** (slug edit or moving a category to another parent) needs an explicit
  "Change URL" action with a warning, and creates a **301 redirect** from the old URL:
  products use WordPress's `_wp_old_slug` (built in); categories store old paths in termmeta
  `fisha_old_paths`, served by a `template_redirect` 301 handler. Moving a parent category also redirects
  every child category URL beneath it.
- Slug validation: unique within its type, not a reserved word (`page`, `feed`, `product`, `shop-2`,
  `studio`, any existing page slug), `[a-z0-9-]` only.

### 4.4 Hardcoded lists become settings
Stored in one option `fisha_studio_settings` (autoload). The existing filters stay; their default values come
from the option, falling back to today's hardcoded arrays if the option is empty:

| Setting | Replaces | Edited in Studio at |
|---|---|---|
| Home categories (ordered) + tagline + card photo | `fisha_home_cat_list()` + shortcode photo attributes | Category → "Show on Home" |
| Shop top categories (ordered) | `fisha_shop_top_categories` default | Category → "Show on Shop page" |
| Shop hero categories (3) | `array('hoodies','tshirts','hats')` | Settings → Shop hero |
| One-of-a-kind categories | `'originals' === $term->slug` | Category → "One of a kind" |
| Drop sign-up categories + text | `fisha_drop_cats()` + texts in `leads.php` | Category → "Drop sign-up" |
| SEO descriptions for categories | `fisha_seo_known()['product_cat:*']` | Category → SEO description (stored as termmeta `fisha_seo_desc`, which wins over the hardcoded map) |
| Happy clients heading + text | fixed copy in `product-cat.php` | Category → Happy clients (termmeta `fisha_happy_title`, `fisha_happy_text`; defaults = today's copy) |

Migration (runs once on first load, idempotent, versioned by `fisha_studio_db_version`) seeds the option and
termmeta with **exactly today's values** → no visible change on deploy. Home card photo ids are read from the
Home page's `[fisha_home_cats]` shortcode attributes.

Per-product SEO fields are added as postmeta `fisha_seo_title` / `fisha_seo_desc`, read by `seo.php` before its
existing fallbacks.

## 5. Screens

Layout: left nav · editor · live preview (right, collapsible, desktop/phone toggle). On phones the panel
stacks and Preview is a tab.

1. **Dashboard** — counts (live, sold out, low stock ≤ threshold or 2, drafts), "Unpublished changes" list,
   recently edited, latest audit entries.
2. **Products** — list with search, filters (category, status, stock), sort; inline price/stock edit per row
   (simple products) with a per-row Publish. Editor tabs:
   - *Basics*: name, slug, status, categories, tags, short description, description (rich text: bold, italic,
     links, lists, headings H2/H3), featured, catalog visibility, reviews on/off.
   - *Price*: regular, sale + optional dates, "price on request".
   - *Inventory*: SKU, manage stock, quantity, low-stock threshold, backorders, sold individually,
     "Mark sold out" / "Back in stock".
   - *Images*: main + gallery (drag order; first gallery image is the card hover image — labelled), upload,
     alt text, choose from media already uploaded.
   - *Variations* (variable only): attributes (global `pa_*` or product-only), "create all combinations",
     grid of variations: enabled, price, sale, SKU, stock, image; bulk actions (set price, set sale, ±stock,
     enable/disable).
   - *Fisha design*: card background (palette + custom), meta line, design series (pick existing or new),
     skip in Shop hero.
   - *SEO*: title, description (with Google-style snippet preview and length hints).
   - *Shipping & tax* (collapsed): weight, dimensions, shipping class, tax status/class.
   - Actions: New simple / New variable, Duplicate, Move to trash (restorable 30 days; never permanent).
     Converting simple ↔ variable is allowed only before first publish.
3. **Categories** — tree with drag reorder/re-parent. Editor: name, slug, parent, heading (with "orange
   word" picker instead of raw `<span>`), eyebrow, intro, description, hero photos (1–2), thumbnail,
   happy clients (photos + captions + heading/text), SEO title/description, one-of-a-kind, drop sign-up
   (+ text), show on Home (+ tagline, card photo, order), show on Shop (+ order). Delete: if it has products,
   ask where to move them; children move to its parent.
4. **Attributes** — global attributes and their values: add, rename, reorder, delete (blocked while used by a
   variation, with a list of where).
5. **Inventory** — every simple product and variation in one table: SKU, stock, status; inline edit, filters
   (low, sold out, category). Each row change is its own item publish.
6. **Settings** — Shop hero categories; owner's own password change.
7. **History** — audit log, filter by item; "Restore this version" opens it as a draft.

Unsaved/draft states always visible in the editor header: **Live** · **Unpublished changes** · **Draft
(never published)**, with **Discard changes** and **Publish**.

## 6. Drafts, preview, publish

### 6.1 Drafts
- **Existing item**: autosave (600 ms debounce) to postmeta/termmeta `_fisha_studio_draft` =
  `{fields, base, user, updated}` where `base` = live values of the edited fields at the moment editing began.
  Live data is untouched.
- **New product**: created immediately as a WooCommerce product with `post_status = draft`; draft edits apply
  directly to it until first publish.
- **New category**: created immediately as a real term with termmeta `fisha_studio_hidden = 1`. A
  `get_terms`/`get_term`/`wp_get_object_terms` filter (front end only, not in preview of that term) hides it
  from menus, chips, shelves, sitemaps, filters, Home and Shop until published. Products cannot be published
  into a hidden category without a warning.
- **Variations** of an existing product: part of the parent product's draft (`fields.variations`); new
  variations are created as `private` (disabled) until publish.

### 6.2 Preview
- iframe loads the real URL + `?fisha_preview=<token>`. Token = HMAC-SHA256(user id, item type, item id,
  expiry 30 min) with `wp_salt('auth')`; valid only for the logged-in user it was minted for. Invalid/expired
  token → normal live page, no error leak.
- On a valid preview request only:
  - product fields → `woocommerce_product_get_{prop}` / `woocommerce_product_variation_get_{prop}` filters;
  - meta (design fields, term meta) → `get_post_metadata` / `get_term_metadata` filters;
  - term fields → `get_term` filter;
  - draft product / hidden category made queryable for this request only.
- Overlay is driven by `studio-fields.php`, so every field has a defined preview path (tested, §8).
- Preview responses: `nocache_headers()`, `do_action('litespeed_control_set_nocache')`,
  `X-Robots-Tag: noindex`.
- After each autosave the iframe reloads and restores scroll position (same origin).
- "Preview on" selector — product: product page / its category / Shop / Home; category: category page / its
  parent / Shop / Home.

### 6.3 Publish
1. **Validate** whole draft against the schema (required fields, numeric prices ≥ 0, sale < regular,
   unique slug/SKU, variable product has ≥ 1 enabled variation with a price, category hero rule §4.2).
   Any error → nothing written; errors map to fields.
2. **Conflict check**: for each edited field, compare `base` with current live value. Untouched fields always
   keep live values. Edited field also changed live (typically stock after an order) → return a conflict list;
   UI asks per field ("Stock changed 3 → 2 since you started (an order came in). Keep 2 or use 5?").
3. **Snapshot** current live state of the item into the audit log.
4. **Apply** inside `START TRANSACTION … COMMIT` (rollback on any exception), via WC/WP APIs. Stock is
   written with `wc_update_product_stock()` so stock events fire.
5. Clear draft and hidden flag; purge LiteSpeed for the product, its categories, Shop, Home
   (`litespeed_purge_url`); clear `wc_delete_product_transients`.
6. Preview switches to the live page; toast "Published · View on site".

Restore = load a snapshot into a draft → preview → Publish (same flow).

## 7. Security

- Role `fisha_owner` capabilities: `read`, `upload_files`, `edit_products`, `edit_others_products`,
  `edit_published_products`, `publish_products`, `delete_products`, `delete_published_products`,
  `read_private_products`, `manage_product_terms`, `edit_product_terms`, `delete_product_terms`,
  `assign_product_terms`, plus custom `fisha_studio`. Nothing else.
- Admins get `fisha_studio` too (can open the Studio).
- `fisha_owner` requests to `/wp-admin/*` (except `admin-ajax.php`, `admin-post.php`, `async-upload.php`)
  → redirect to `/studio`. Admin bar off. `/wp-login.php` → redirect to `/studio/login` for the role
  (password reset links still work).
- `rest_pre_dispatch`: `fisha_owner` may only call `fisha-studio/v1/*` (+ `wc/store/*` used by the public site).
- Every Studio endpoint: `permission_callback` checks `fisha_studio` + the specific capability;
  `X-WP-Nonce` required; inputs sanitized/validated by the field schema; outputs escaped in the UI
  (text via `textContent`, rich text via an allow-list sanitizer server-side `wp_kses_post`).
- Uploads: images only (jpg, png, webp), MIME sniffed, ≤ 15 MB, via `media_handle_upload`.
- Login: own form posting to `wp_signon`; 5 failures per account or IP in 15 min → 15 min lock; generic error
  message; session cookie 12 h (`auth_cookie_expiration` for the role).
- Studio pages: `Content-Security-Policy` (self only, `frame-src` self), `X-Frame-Options: SAMEORIGIN`,
  `noindex`.
- Audit log: custom table `{prefix}fisha_studio_log` (id, time, user, item type, item id, action, before JSON,
  after JSON). Kept 365 days.
- Owner accounts are created only with `wp fisha-studio create-owner <email>` (sends a set-password link).

## 8. Error handling

- API errors: `WP_Error` → `{code, message, field?}`; UI shows them inline at the field, in plain words.
- Offline / 5xx: autosave retries with backoff; banner "Offline — your changes are kept"; Publish disabled.
- 401 (session expired): login modal over the editor; draft kept; retry the failed request after login.
- Upload failure: per-file message + retry.
- Preview render failure: pane shows "Preview couldn't load" + reload; server error logged.
- Server-side unexpected errors logged to `wp-content/fisha-studio.log` (not web-served on Hostinger's
  LiteSpeed: `.htaccess` deny rule added for `*.log` in `wp-content`), never shown to the owner.

## 9. Testing

- **Integration tests** `/tests/studio/*.php`, run with
  `docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp eval-file tests/studio/run.php`.
  Fixtures prefixed `zz-test-`, removed after each test. Cover:
  - field schema: every row of §2.2/§2.3 round-trips (draft → preview overlay → publish → read back);
  - defaults of §4.2; duplicate copies all design fields;
  - validation rules; conflict merge; transaction rollback on a forced failure;
  - URLs: new product → `/product/{slug}/`, new sub-category → `/product-category/{parent}/{slug}/`;
    rename keeps slug; slug change / category move → old URL 301s to the new one (incl. child categories);
    permalink options unchanged after every operation;
  - settings migration reproduces today's hardcoded values exactly (Home list, Shop list, drop cats + texts,
    one-of-a-kind, SEO map, Home card photos);
  - security: `fisha_owner` blocked from `/wp-admin`, core REST, other users' preview tokens; forged/expired
    token → live page; logged-out → 401; non-image upload rejected; login lockout;
  - isolation: hidden category / draft product absent from Shop, Home, chips, menus, sitemap; preview
    responses carry no-cache headers.
- **Visual parity**: before/after screenshots of Home, Shop, every category page and 3 product pages on
  localhost after the migration — must be identical.
- **End-to-end** per phase in the browser on localhost with a test owner account: create category + product,
  preview, publish, check public pages.

## 10. Rollout

Each phase: localhost → push `develop` → dev.fisha.shop for owner review → merge to `main`.

1. **Foundation + simple products**: role, login, gates, app shell, field schema, drafts/preview/publish,
   audit log, product editor (all tabs except Variations), product list.
2. **Variations, attributes, inventory table.**
3. **Categories + settings**: category editor/tree, hidden-until-publish, settings + migration, `seo.php` /
   `home.php` / `shop-home.php` / `leads.php` / `product-cat.php` reading the new settings.
4. **Dashboard, history/restore, duplicate, mobile polish.**

## 11. Out of scope

Orders, customers, coupons, shipping zones/rates, tax rates, reviews moderation, pages/Elementor content,
menus, product brands (unused), downloadable/virtual/grouped/external product types (unused).
