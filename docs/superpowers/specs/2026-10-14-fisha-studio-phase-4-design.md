# Fisha Studio — Phase 4: Dashboard, History & restore, Duplicate, My account, phone polish (design addendum)

Date: 2026-10-14 · Status: for owner review
Parent spec: `docs/superpowers/specs/2026-10-12-fisha-studio-design.md` (§5 screens 1 and 7, §4.2 Duplicate, §10 phase 4),
binding unless this addendum says otherwise. Phases 1–3 and 2-step sign-in are built.

## 1. Goal and owner decisions (2026-10-14)

The owner gets a start page that shows what needs attention, can see and undo past changes, can copy a product or category
to start a new one, can manage their own password and backup codes, and can do all of it from a phone.

Decisions:
1. Scope = Dashboard, History + restore, Duplicate, My account + phone polish. (Shop hero setting stays hardcoded.)
2. Undelete: **trashed products only** (WordPress keeps them 30 days). Deleted categories are not brought back.
3. Everything that changes the site still goes through draft → preview → Publish (restore and undelete included).

## 2. Dashboard (`#/` and `#/dashboard`, the Studio's new start page)

- **Counts** (each links to the products list with that filter): Live (published products), Sold out, Low stock
  (counted stock ≤ the product's or size's low-stock alert, else 2, and > 0), Drafts (never published). Products with
  sizes count once per product (low/sold out when any size is).
- **Waiting to publish**: products and categories whose state is `changed` or `new`, newest draft first (draft
  `updated`), max 20, each: name, kind, "changed 2 hours ago", Open.
- **Recently edited**: last 10 distinct products/categories with a `create`/`publish`/`convert` audit row, with time.
- **Latest activity**: last 15 audit rows in plain English (§3.3), sign-ins included.
- One REST call `GET /dashboard` returns all four; counts use direct SQL (no per-product object loads).
- Empty states: "Nothing waiting to publish." etc.

## 3. History and restore

### 3.1 Data
The existing audit log (`wp_fisha_studio_log`) is the history; nothing new is stored. Snapshot rows = product actions
`create`, `publish`, `convert` and category `create`, `publish` (their `after_json` holds every field the Studio edits:
`fisha_studio_product_values()` / `fisha_studio_category_values()`). Other rows (`quick` on a size, `shown`, `reorder`,
`trash`, `new`, attribute and user rows) are activity only, never restorable. Changes made outside the Studio (orders
lowering stock, WP admin) are not in History. Retention stays 365 days.

### 3.2 Item History tab (product editor and category editor)
- List of that item's rows, newest first, 20 per page: date ("14 Oct, 10:42"), who (display name, or "WP-CLI"),
  action, and a short diff in plain words between that row's `before` and `after` (max 6 changes listed, "+3 more"):
  `Price 119 → 109`, `Stock 4 → 3`, `Size M: price 60 → 65`, `Photos changed`, `Description changed`.
- A product's tab also lists `quick` rows of its sizes (variation rows whose size belongs to it).
- Each snapshot row has **Restore this version**.

### 3.3 Restore
- `POST /products/{id}/restore {log_id}` / `POST /categories/{id}/restore {log_id}`: take that row's `after` values,
  keep only keys the item still has (fields of the schema; `var:{id}:…` keys only for sizes that still exist; image ids
  only if the attachment still exists; category ids only if the category exists), and put every key whose value
  differs from live into the item's draft through the normal edit entry point (`fisha_studio_product_edit` /
  `fisha_studio_category_edit`) with `seen` = the live value. Response = the editor payload + `skipped`: plain list
  ("Size XS no longer exists", "1 photo no longer exists").
- The editor reloads showing "Unpublished changes"; the owner previews, then Publish or Discard as usual. A row whose
  values all equal live → "This version is the same as the live one."
- Restoring a never-published item applies values directly (same as any edit to a new item).
- The `options`/attributes value of a product is restored only if every option value still exists; otherwise skipped
  with a note.

### 3.4 History screen (`#/history`)
All rows, newest first, 50 per page, filters: Products · Categories · Sign-ins · Everything else, and a date range
(Today / 7 days / 30 days / All). Each row links to its item when the item still exists.

### 3.5 Recently deleted (part of the History screen)
- "Recently deleted products": products in the WordPress trash (any trashed in the last 30 days), name, deleted date,
  **Bring back**.
- `POST /products/{id}/untrash` → `wp_untrash_post` restores it as a **draft** (WordPress's default untrash status;
  force `draft` if it would come back published), logs `untrash`, opens the editor. Its sizes come back with it.
- Products trashed in WP admin show too (it is WordPress's trash).

## 4. Duplicate

- **Product**: "Duplicate" in the editor header (and in the list row menu). Uses WooCommerce's own duplicator
  (`WC_Admin_Duplicate_Product::product_duplicate()`, which copies sizes, attributes, photos, prices, stock settings
  and meta) and then: name `"{name} (copy)"`, status draft, slug from the new name (unique), SKU and every size SKU
  cleared, `_fisha_studio_created` = 1 (so it is "never published" for size delete/type switch rules), no
  `_fisha_studio_published`, no draft meta, sales count 0, "featured" off. Logged `duplicate` with the source id.
  The editor opens on the copy.
- **Category**: "Duplicate" in the category editor: `fisha_studio_category_create( "{name} (copy)", same parent )`
  then copy intro, hero photos, Show on site; automatic fields recomputed; never-published; products not copied.
  Logged `duplicate`.
- The copy takes the source's **live** values plus its current
  draft (what the owner sees), so a duplicate made mid-edit matches the screen.

## 5. My account (`#/account`)

- **Change password**: current password, new password, new again. Rules: new ≥ 12 characters, not equal to the
  current, the two match; the current password is checked with `wp_check_password` (wrong → counts toward the sign-in
  lock). On success: `wp_set_password` (ends every session), then a fresh session for this browser is created through
  the 2-step guard's allowed path (the owner proved the password now and holds a session that passed the code step),
  so the owner stays signed in here and is signed out everywhere else. Audit `password-change`. An email from
  WordPress's normal "password changed" notice goes to the owner.
- **2-step sign-in**: "On since 14 Oct" and "N backup codes left".
- **Make new backup codes**: asks for a current app code (not a backup code); shows the 10 new codes once with
  "I have saved these codes"; the old codes stop working. Audit `backup-codes-new`.
- **Sign out of all other devices**: ends every session but this one (`WP_Session_Tokens::destroy_others`).
- REST under `/account` (session, password, backup-codes, sign-out-others); all require the logged-in owner and the
  REST nonce; password and backup-code calls are rate-limited by the existing lock counters.

## 6. Phone polish

Every Studio screen works at 375 px wide (and 768 px): no sideways page scroll, touch targets ≥ 44 px, text ≥ 14 px,
the preview opens as a full-screen tab with "Back to editing", products/inventory/history tables become stacked cards
below 760 px, modals fit the screen, sticky header with Publish reachable without scrolling. Verified in Chrome at
375 × 812 and 768 × 1024 on: Dashboard, Products, product editor (all tabs), Inventory, Size lists, Categories, category
editor, History, My account, sign-in pages.

## 7. Navigation
Nav order: Dashboard, Products, Categories, Inventory, Size lists, History, My account (the user name in the top bar
also opens My account). The Studio opens on Dashboard.

## 8. Testing
- Server tests: dashboard counts (live/sold out/low/drafts incl. sizes) and lists; history diff text; restore (values to
  draft, skipped sizes/photos/categories/options, same-as-live, never-published direct apply); untrash → draft; product
  duplicate (sizes copied, SKUs cleared, draft, never-published flags, unique slug) and category duplicate; password
  change (wrong current counts to lock, rules, sessions ended except this one, still signed in); backup codes need a
  valid app code, old codes stop working; sign out others.
- Parity: `tests/studio/baseline.sh` unchanged (nothing visible to visitors changes in this phase).
- Browser (owner, Chrome): each screen at desktop, 375 px and 768 px; restore a price change and publish; bring back a
  trashed test product; duplicate a product with sizes; change password and stay signed in; new backup codes.

## 9. Out of scope
Shop hero setting, editable category design fields, deleted-category recovery, history of changes made outside the
Studio, email/SMS notifications.
