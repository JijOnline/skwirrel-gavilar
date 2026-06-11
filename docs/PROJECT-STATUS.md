# Project status & handoff

Last updated: 2026-06-02.

This document captures the **non-code knowledge** behind the plugin —
decisions and their rationale, Skwirrel API gotchas discovered through
debugging, open items, and operational notes. Code-level documentation
lives in the README and in inline comments. The git log records what
changed; this file records *why* and *what's still in someone's inbox*.

If you walked into this project cold, read this file first.

---

## TL;DR — current state

Plugin is **functionally complete** for the scope as it stands. Daily
sync runs, all 435 products (390 with status `available`) come across
with images, categories, structured meta, and ETIM technical features.
Front-end has a basic specifications block; the proper theme template
is intentionally deferred.

Nobody is blocked on plugin work. Progress now depends on **content
being filled in Skwirrel** (Gavilar) and **a theme template being
built**, plus a handful of clarifying questions waiting with Rob and
the Skwirrel developers.

---

## What the plugin does (and does not do)

**Does:**

- One-way sync from Skwirrel PIM to WP, daily delta + on-demand full
  resync, plus a WP-CLI command.
- Stores products as a custom post type `pim_product`, with category
  taxonomy `pim_category` and per-locale Polylang posts.
- Pulls and dedupes attachments (images + documents) into the WP
  media library.
- Normalises ETIM features (filtering NVT, formatting by type) into
  meta.
- Read-only admin metabox showing all synced fields including
  thumbnails, gallery, document links and ETIM block.
- Fallback front-end render — a "Specifications" + "ETIM technical
  features" block appended to `the_content` for `pim_product`. Meant
  to be replaced by the eventual theme template.

**Does not:**

- Authoring (data lives in Skwirrel; WP is read-only by design).
- Writes back to Skwirrel.
- Product variants / pricing / trade items (deliberately out of scope).
- Pretty design (theme job).
- Faceted ETIM filtering (waiting on Rob's curated list of which
  features become filters).

---

## Locked decisions (and why)

These are the choices that aren't obvious from the code and that
shouldn't be re-litigated without good reason.

### CPT, not WooCommerce
Client display-only catalogue, not a shop. No price, no checkout. Going
WC would have pulled in product variants, tax/stock infrastructure and
a heavy admin UI for no benefit.

### Polylang (Free *or* Pro), not WPML
Only the free `pll_*` PHP API is used: `pll_languages_list`,
`pll_set_post_language`, `pll_save_post_translations`, etc. Staging
runs Polylang Free, production runs Pro because the client already has
a licence — but the plugin doesn't care which.

### One post per Polylang language, even when Skwirrel data is monolingual
Gavilar currently only fills Dutch translations. With three Polylang
languages active that produces 1170 posts (390 × 3) with the Dutch name
falling through as the fallback for `en`/`fr`. Justified because:

1. The site is multilingual at WP level; per-language URLs need their
   own posts.
2. When Skwirrel eventually carries more translations, no schema change.

Open question to the client: ship at launch with just `nl` configured
in Polylang until they fill other locales? See *Open items* below.

### `dynamic_selection_id` is optional, status filter `available` is the gate
Client confirmed the website filter is product status, not a Skwirrel
selection. Plugin keeps `dynamic_selection_id` as an optional belt-and-
suspenders param.

### Name-based slugs with the Skwirrel product id appended
`ophangbgl-kort-ga4-220-71993` instead of the bare manufacturer code.
SEO-friendly *and* guaranteed unique. Slugs only re-generate on Full
resync (delta touches changed products only).

### Inline attachment downloads, no job queue
435 products doesn't justify Action Scheduler. AttachmentMapper dedupes
by `_skwirrel_attachment_id` so subsequent syncs are no-ops on
unchanged media. PAGE_SIZE intentionally small (15) so each sync step
finishes inside PHP execution limits even with image downloads.

### ETIM NVT = "don't show on website"
The Skwirrel API does **not** expose a separate "show on web" flag for
ETIM features (we checked the docs, then verified against a real
response). What Rob's admin UI looks like as a green checkbox per
feature maps to `not_applicable: true` in the API. The plugin
implements the rule: skip a feature when `not_applicable === true` OR
when no value is present. Confirmed working against product 27613.

### Display layer is part of the plugin
Originally the plan was "WP is presentation, theme owns rendering".
But underscore-prefixed meta is invisible in the standard Custom
Fields UI, so internal verification of the sync would have required
WP-CLI or DB queries. The admin metabox and the basic front-end
content filter exist so editors can see what's synced *now*; the
theme can override both later.

---

## Skwirrel API gotchas — read before touching the API layer

These cost real time to discover. Don't relearn them.

### 1. OAuth token endpoint MUST send `Accept: */*`
Skwirrel content-negotiates. **Any** request with
`Accept: application/json` is dispatched to the JSON-RPC handler,
regardless of URL path. So `POST /oauth2/token` with
`Accept: application/json` returns a JSON-RPC `-32003 "URL not found"`
404 because the dispatcher doesn't know that path as an RPC method.

The fix lives in `OAuthTokenStore::fetchAndCache` with an explicit
comment. JSON-RPC API calls keep `Accept: application/json` — they
*should* hit the dispatcher.

### 2. `include_languages` is `string[]`, NOT a boolean
Sending `true` → `-32602 "Invalid value for 'include_languages'"`.
Must be e.g. `["nl", "en"]`. Required by every `include_*_translations`
and `include_*_seo` flag.

### 3. Response field names are underscore-prefixed
`_product_translations`, `_product_seo`, `_categories`, `_attachments`,
`_etim`, `_category_translations`, `_etim_features`, etc. Easy to miss
when guessing from method names alone.

### 4. Canonical product key is `product_id`
Skwirrel confirmed in writing. GTIN / manufacturer code / external id
may not be unique or always populated.

### 5. Attachment URLs are public and stable
Plain HTTPS GET to `https://<tenant>.skwirrel.eu/file/download/{uuid}`.
No signing, no expiry. Stable as long as the file record exists.

### 6. Deletion is soft via `product_trashed_on`
Trashed products vanish from `getProducts` results. No "deleted" flag.
The plugin handles this by trashing WP posts that don't carry the
current run's `_skwirrel_last_run_id` at the end of a Full resync.
Delta sync **cannot** detect deletions (we only see what changed) —
deletions only propagate via Full resync. Currently fine because the
WP-Cron daily event runs delta only.

### 7. `getCategories` returns no name field
You need `include_category_translations: true` AND `include_languages`.
Names then live in `_category_translations[].category_name`.

### 8. Category hierarchy parent = `parent_category_id`, NOT `super_category_id`
`super_category_id` is a root container (constant 1 in Gavilar). Using
it as the parent flattens everything under one node.

### 9. ETIM "show on website" = NVT flag inverse (hypothesis)
The API has no documented visibility flag for ETIM features. The
plugin treats `not_applicable: true` as "hidden" — matching Gavilar's
admin workflow. Confirmed against the data; not confirmed by Skwirrel
in writing yet.

### 10. ETIM translations only return `"language": "en"` in Gavilar's tenant
Even with `include_languages: ["nl","en","fr"]`. Either ETIM-NL labels
aren't in their datapool yet or they come via a flag we haven't found.
Open question to Skwirrel devs.

### 11. The `code` filter on `getProducts` — exact syntax unknown
Sent `'code' => ["27613"]` and `getProducts` returned `product_id: 1`
instead of filtering. Behavior suggests the filter was silently ignored
or the format is different. Workaround for now: page through. Open
follow-up: ask Skwirrel devs.

### 12. WP `absint` sanitizer turns "" into 0
For the `dynamic_selection_id` field this meant `0` was sent to
`getProducts` → `-32602 "Invalid value for 'dynamic_selection_id'"`.
Settings now stores empty as `''` and treats both `''` and `0` as null
in `Settings::dynamicSelectionId()`.

### 13. `esc_url_raw` does not strip invisible Unicode or trailing whitespace
A stray space in a pasted URL becomes `%20` and silently breaks the
endpoint. `Settings::cleanUrl()` strips everything outside printable
ASCII as a defensive sanitiser.

### 14. `getDynamicSelections` / `getSelections` / `listSelections` etc. do not exist
We tried 8 plausible names; all returned `-32601 "Method not found"`.
There's no API path to discover selection IDs — must come from Skwirrel
admin UI or PIM admin (Paul).

### 15. `include_custom_features` is not a documented parameter
I invented it. Returns invalid-param if you send it. Custom features
come from separate methods (`getCustomClasses` and friends), not from
a `getProducts` include flag.

### 16. PAGE_SIZE > ~25 risks timeouts on shared hosting
With inline image downloads, processing 500 products in one
AJAX request blew through PHP's `max_execution_time` and returned the
WordPress critical-error HTML page (which the front-end JS then tried
to parse as JSON and crashed with `Unexpected token '<'`). Currently
15; bump cautiously.

---

## WordPress / Polylang gotchas

### Polylang ignores CPTs you don't register with it
Without the `pll_get_post_types` filter, Polylang's admin language
filter doesn't apply to `pim_product` — every language shows every
post. Polylang is hooked in `Polylang::register()`.

### `get_terms()` is filtered to the current Polylang language by default
If you query terms in admin without `'lang' => ''`, you'll only see
terms for the active admin language. The sync ran into this:
`CategoryMapper::findExistingTermsBySkwirrelId` returned only the NL
term per `_skwirrel_category_id`, and the sync created fresh EN/FR
terms on each run — multiplying duplicates fast. Fixed; the cleanup
button merges already-created duplicates.

### Block editor hides protected meta from Custom Fields UI
Any meta key starting with `_` (which all of ours do) is hidden from
the default Custom Fields panel — even when enabled. That's why the
plugin ships a dedicated metabox.

### Adding a Polylang language → flush rewrites
When a new language is added in Polylang after the CPT was registered
(or when Polylang's "Custom post types" setting is changed), the
`/en/product/...` / `/fr/product/...` permalinks return 404 until
rewrite rules are regenerated.

Fix: WP-admin → Settings → Permalinks → Save (no fields need to
change). This rebuilds rewrites and the per-language CPT URLs work.
Common after onboarding a new translation locale.

---

## Open items — who is waiting on whom

### Wait on Skwirrel developers

- **NL ETIM translations.** The `_etim_*_translations` arrays only
  return `"language": "en"` in Gavilar's tenant. Is there a flag or
  configuration to get Dutch labels from the 2ba datapool?
- **`code` filter syntax on `getProducts`.** What's the correct format
  for filtering by external code / SKU / GTIN? The plain string and
  array variants returned unfiltered results.

### Wait on Rob

- **Filterable attributes list.** Which ETIM (or custom) features
  should appear as filters on category / search pages? Without this
  list nothing becomes a taxonomy.
- **Categorisation of the 45 non-`available` products.** Confirm
  whether they're intentionally hidden or whether the status filter
  should include other statuses too.

### Wait on the client (Gavilar)

- **Product translations.** `_product_translations` is currently empty
  for most products → product titles fall back to ERP descriptions
  (`OPHANGBGL KORT GA4-220`). For a public catalogue this is rough.
- **SEO content.** `_product_seo` and `_category_seo` are empty. Yoast
  meta won't populate until they do.
- **At-launch language strategy.** Right now Polylang has 3 languages
  → 3 posts per product → 1170 posts, content identical because only
  NL is filled. Either:
  - Ship at launch with only NL active in Polylang (drop to 390 posts),
    *or*
  - Wait for translations to land before launch,
  - Accept Dutch-as-fallback on `en`/`fr` URLs.

### Wait on theme developer

- `single-pim_product.php`, `archive-pim_product.php`,
  `taxonomy-pim_category.php`. The plugin's `the_content` filter is
  a placeholder.
- Once a real template renders the synced data the front-end content
  filter in `ProductDisplay::appendSpecTable` can be made optional or
  removed.

---

## Operational notes

### Where things live

| | |
|---|---|
| Repo | https://github.com/JijOnline/skwirrel-gavilar |
| Staging WP root | `/home/wijonline/domains/wijonline.nu/public_html/gavilarsync` |
| Plugin folder on staging | `…/wp-content/plugins/skwirrel-gavilar` (a git clone of the repo) |
| Staging SSH | `wijonline@s219.webhostingserver.nl` (port 22) |
| Antagonist account | `wijonline` (not `jijonline` — separate account) |

### Deploy

```bash
ssh wijonline@s219.webhostingserver.nl
cd /home/wijonline/domains/wijonline.nu/public_html/gavilarsync/wp-content/plugins/skwirrel-gavilar
git pull
```

A `skwpull` alias may be configured in `~/.bashrc` for one-command updates.

### Antagonist SSH whitelist
Per-account, per-IP, with an expiry (we set 365 days). Re-add the IP
via the **wijonline** Antagonist panel if it changes — refusal on
TCP-level connect is the signal.

### Plugin configuration lives in the database
Deleting and re-cloning the plugin folder is safe: OAuth credentials,
locale map, selection ID, last-synced-at and the run log all live in
WP options/tables. Activation hooks recreate the cron event and ensure
the log table exists.

### Test fixtures

- `product_id: 1` / external code `13729` — OPHANGBGL bracket, has
  full ETIM block. Use for verifying ETIM rendering.
- `product_id: 39` / external code `27613` — gas pressure control
  valve. Test fixture from Rob with 2 PDFs and 2 (identical) images.
  Use for verifying attachment + document pipeline.

### Common admin actions

- **Force a full re-sync of everything** — Settings → Skwirrel Sync →
  *Full resync*.
- **Reset delta cursor** (next delta pulls everything updated in the
  last 24h) — same screen.
- **Merge duplicate category terms** — same screen, *Cleanup duplicate
  categories*. Safe to run repeatedly.
- **Inspect a raw API response** — *Show sample product*, optionally
  with a code filter. Dumps `getProducts`, `getCategories`,
  `getDenormalizedEtimData`.
- **CLI** — `wp skwirrel sync [--full] [--since=...]`,
  `wp skwirrel status`, `wp skwirrel reset_cursor`.

---

## Conventions / things to know before editing the code

### Meta key prefixes
- `_skwirrel_*` — provenance & cursor data (product id, run id,
  updated-on timestamp).
- `_pim_*` — synced product fields surfaced for display.
- `_yoast_wpseo_*` — Yoast SEO meta the plugin writes through.

### Adding a new synced flat field
1. Add the source field name to `ProductMapper::PRODUCT_META_MAP`
   (`_pim_…` → `skwirrel_field_name`).
2. Add the label/key to `ProductDisplay::fields()` (the `$simple`
   array) so it shows in the metabox and front-end.
3. No DB migration needed; meta keys are flexible.

### Adding a new include_* flag to the sync
- Edit `SyncCoordinator::fetchAndApplyPage()`. Remember
  `include_*_translations` and `include_*_seo` require
  `include_languages` to be present.

### Adding ETIM behaviour
- Parsing: `EtimMapper::build()` / `buildFeature()`.
- Display: `ProductDisplay::renderEtimHtml()` + `EtimMapper::format()`.
- Language fallback chain is centralised in `EtimMapper::pickLabel()`.

### Localisation
The plugin's own UI strings are wrapped in `__('...', 'skwirrel-gavilar')`
but no `.po` files exist yet. Skwirrel content is *not* localised by
the plugin — Skwirrel returns the translations, plugin picks the
right one per Polylang language.

---

## Open architectural debts (small)

- **`FeatureMapper` is unused.** Originally for Skwirrel "custom
  classes/features" (separate from ETIM). Still injected into
  `ProductMapper` because we'll likely wire it once Rob provides the
  curated filterable-attributes list.
- **No tests.** Decision was deliberate (scope, single-tenant project)
  but worth revisiting if behaviour gets richer.
- **Front-end CSS.** The render uses inline styles and `widefat
  striped` admin classes. A theme will style these properly.

---

## Working with Claude Code on this project

This project was substantially built in Claude Code sessions, and the
expectation is that future maintenance happens the same way. The
patterns below cost real time to discover; following them keeps a new
session productive and avoids re-litigating things that are already
settled.

### Open a fresh session for each new piece of work

Don't keep a single session running across days. Token cost grows with
the transcript and the chance of confusion grows with it. Frontend
work, plugin tweaks, debugging — each gets its own fresh session.

### Standard opening prompt

For any session that touches the plugin:

```
Read docs/PROJECT-STATUS.md, docs/THEME-INTEGRATION.md, and README.md
in this repo. Wait for instructions.
```

That loads all the durable knowledge — locked decisions, API gotchas,
data shapes — for the price of a few KB of context. Then state the
actual task.

### Treat the gotcha list as load-bearing

The numbered gotchas in [Skwirrel API gotchas](#skwirrel-api-gotchas-—-read-before-touching-the-api-layer)
and [WordPress / Polylang gotchas](#wordpress--polylang-gotchas) are
settled. Each one cost a debug cycle to find. If Claude starts a new
theory that contradicts one of them ("maybe `include_languages: true`
will work this time"), interrupt and point at the gotcha. Don't
re-litigate; they were verified against real behaviour.

### "Check before guess" — the docs rule

Skwirrel's API documentation has thin response-shape coverage. Before
writing or editing a mapper, get a real response sample. The
**Show sample product** admin button on
*Settings → Skwirrel Sync* dumps:

- `getProducts` (optionally filtered by code) with all the include
  flags the sync uses,
- `getCategories` with translations,
- `getDenormalizedEtimData`.

Ask Claude to wait for the dump before writing code that touches the
shape of a response. Multiple wrong-turn fixes earlier in the project
came from guessing field names instead of inspecting first.

### After schema or mapper changes — Full resync

The daily delta sync only touches products whose `product_updated_on`
moved. If you change a mapper, add a meta field, or start requesting
new include flags, *existing* posts won't get the new data on the next
delta — only changed Skwirrel records would. **Always Full resync** in
*Settings → Skwirrel Sync* after a behaviour change that affects all
products. 390 products with images takes ~10–15 minutes; safe to run
repeatedly.

### Bump the effort slider for unfamiliar work

Routine edits (rename a meta key, add a field to the metabox) work fine
at default effort. Bump higher when:

- Debugging an unfamiliar Skwirrel response.
- Architectural changes touching multiple mappers or the sync flow.
- A bug that doesn't yield to the first round of fixes — that's the
  signal to think harder, not to keep retrying.

### Keep this file alive

When you discover a new gotcha, lock a new decision, or close a "wait
on X" item, update the relevant section in this file. The doc is the
durable knowledge base; this conversation is not.

### When a session does get too long

1. Update this file with anything new the session settled.
2. Commit + push.
3. Either `/compact` (built into Claude Code — summarises older turns
   while keeping the recent ones) or start fresh with the standard
   opening prompt above.
