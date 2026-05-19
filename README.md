# Skwirrel Sync for Gavilar

One-way WordPress plugin that mirrors the Gavilar product catalogue from the
client's Skwirrel PIM into a custom post type. The PIM is the source of truth;
WordPress is a read-only presentation layer.

## What it does

- Pulls products, categories, custom features and attachments from the Skwirrel
  v2 JSON-RPC API, gated by a configurable **dynamic selection** (so editors
  control which products appear on the public site from inside Skwirrel).
- Stores each product as a `pim_product` custom post type with a hierarchical
  `pim_category` taxonomy.
- Multilingual via **Polylang** (Free or Pro): each Skwirrel translation lands
  as a separate WP post per language, linked together as a translation group.
  Pro is not required — every `pll_*` function the plugin calls is in the
  free API.
- Writes Yoast SEO meta (title / description / focus keyword / OG fields) on
  each locale's post.
- Downloads attachments into the WP media library inline; dedups by Skwirrel
  attachment ID so an unchanged image is a no-op on the next sync.
- Runs a daily delta via WP-Cron (using Skwirrel's `updated_on` filter).
- Manual **Full resync** from the admin (AJAX page-loop) or the CLI:
  `wp skwirrel sync --full`. End-of-full-run pass soft-deletes any
  `pim_product` no longer in the dynamic selection.

## Requirements

| | |
|---|---|
| WordPress | 6.4+ |
| PHP | 8.1+ |
| Polylang (Free or Pro) | active, with site languages configured (NL/EN/FR/…). Pro adds translated URL bases but isn't required. |
| Yoast SEO | optional but recommended (meta keys hard-coded for Yoast) |

## Installation

1. Clone or unzip into `wp-content/plugins/skwirrel-gavilar/`.
2. Activate **Skwirrel Sync for Gavilar** under Plugins.
3. Configure Polylang Pro first — add every language the PIM exports.
4. Open **Settings → Skwirrel Sync** and fill in the OAuth credentials from
   Skwirrel → Data → Web Services.

No Composer install is needed for production — the plugin uses a PSR-4
fallback autoloader. Run `composer install` only if you want the dev tooling
(WP stubs etc.).

## Configuration

| Setting | Where to find it |
|---|---|
| OAuth2 token URL | Skwirrel → Data → Web Services → Edit your OAuth2 client. |
| API URL | Same screen. JSON-RPC endpoint, e.g. `https://example.skwirrel.eu/jsonrpc`. |
| Client ID / secret | Same screen. The secret is encrypted at rest with AES-256-GCM keyed off `AUTH_KEY`. |
| Dynamic selection ID | The Skwirrel selection that gates which products sync. Ask the PIM admin which one. |
| Locale mapping | Click **Auto-detect locales** after credentials are saved — it pulls one product and reads its `translations`. Adjust the Skwirrel-code → Polylang-slug dropdowns if the auto-mapping is wrong. |

After saving, click **Test connection** to verify, then **Sync now (delta)**
or **Full resync** for the first load.

## Usage

### Daily sync (automatic)

A WP-Cron event `skwirrel_gavilar_daily_sync` fires once every 24 hours and
calls `SyncCoordinator::run()`. It pulls only products whose `updated_on` is
newer than the cursor `skwirrel_gavilar_last_synced_at`. With Gavilar's catalogue
of ~1000 products and a low mutation rate, a daily tick typically processes
under 10 changed products in well under a minute.

### Manual sync

- **Sync now (delta)** — runs the same logic immediately and refreshes the
  cursor on success.
- **Full resync** — opens a status panel and AJAX-polls one page (500 products)
  at a time until exhaustion, then soft-deletes any `pim_product` whose
  `_skwirrel_last_run_id` doesn't match this run.

### WP-CLI

```bash
wp skwirrel sync                                # delta sync
wp skwirrel sync --full                         # full resync + soft-delete pass
wp skwirrel sync --since="2026-01-01 00:00:00"  # delta from a specific UTC datetime
wp skwirrel status                              # cursor + last log row
wp skwirrel reset_cursor                        # clear the delta cursor
```

## Architecture

```
src/
├── Plugin.php              # DI wiring, activation hook, daily cron registration
├── Api/
│   ├── Client.php          # JSON-RPC client, retries on 5xx, refresh on 401
│   ├── OAuthTokenStore.php # transient-cached bearer token
│   └── Exceptions.php
├── Sync/
│   ├── SyncCoordinator.php # delta sync + full resync + soft-delete
│   └── FullResyncState.php # option-backed cursor for the AJAX page loop
├── Mapping/
│   ├── ProductMapper.php   # one post per Polylang language, attachments synced once
│   ├── CategoryMapper.php  # one term per language, translated
│   ├── FeatureMapper.php   # Skwirrel custom classes → structured meta
│   └── AttachmentMapper.php
├── Cpt/                    # pim_product + pim_category registration
├── I18n/Polylang.php       # thin wrapper around pll_* functions
├── Admin/
│   ├── SettingsPage.php    # configuration + log viewer
│   └── FullResyncController.php  # admin-ajax endpoints driving the page loop
├── Cli/SkwirrelCommand.php
└── Support/                # Settings, Logger, Encryption
```

### Stored data

| Type | Key | Purpose |
|---|---|---|
| Post meta | `_skwirrel_product_id` | Canonical link to Skwirrel; lookup key for upserts. |
| Post meta | `_skwirrel_external_product_id` | External code (if Skwirrel supplies one). |
| Post meta | `_skwirrel_updated_on` | Last `updated_on` from Skwirrel, for diagnostics. |
| Post meta | `_skwirrel_last_run_id` | Run that last touched this post — used to detect orphans. |
| Post meta | `_pim_feature_<class>_<feature>` | Each Skwirrel custom feature value. |
| Post meta | `_pim_features_index` | Newline-joined flat search index of all feature labels. |
| Post meta | `_pim_gallery_ids` | Array of attachment IDs. |
| Post meta | `_pim_documents` | Array of `{id, label}` for non-image attachments (PDFs etc.). |
| Term meta | `_skwirrel_category_id` | Canonical Skwirrel category link. |
| Attachment meta | `_skwirrel_attachment_id` / `_skwirrel_attachment_url` | Dedup keys. |
| Option | `skwirrel_gavilar_last_synced_at` | Delta cursor (UTC). |
| Option | `skwirrel_gavilar_dynamic_selection_id` | Required gating filter. |
| Option | `skwirrel_gavilar_locale_map` | Skwirrel locale code → Polylang slug. |
| Option | `skwirrel_gavilar_full_resync_state` | JSON-encoded cursor for the AJAX full resync. |
| Table | `wp_skwirrel_sync_log` | Run history (last 20 shown on the settings page). |

## Troubleshooting

**"Polylang is required" notice.** Activate Polylang (Free or Pro) and add
at least one site language. The plugin will degrade to single-language sync
until then.

**Auto-detect locales returns nothing.** Skwirrel may not expose
`getLanguages`. The button then falls back to inspecting one product's
`translations` array — if the catalogue currently has no translations, you'll
get no codes. Add a translation in Skwirrel and retry, or enter the codes
manually.

**Daily cron not running.** Check `wp cron event list | grep skwirrel`. If the
event is missing, deactivate and reactivate the plugin. If it's present but
not firing, check that `DISABLE_WP_CRON` isn't set without a replacement
trigger.

**Images not appearing.** Look at **Settings → Skwirrel Sync → Recent runs**
for the run summary. Image download failures are logged with the Skwirrel
attachment ID via `error_log()` — set `WP_DEBUG_LOG=true` to capture them.

**Products appearing as Trash unexpectedly.** A Full resync soft-deletes
anything not seen during the run. If you removed a product from the dynamic
selection and want it back on the site, re-add it in Skwirrel and run another
sync.

## Handoff: Phase 0 questions still outstanding

Pin these down with the client and Skwirrel before going live:

**For the client / business owner**

1. Locales at launch — confirm Polylang languages match the Skwirrel
   contexts in use.
2. Source-of-truth: confirm WP is read-only (no editorial in WP).
3. Filterable attributes vs display-only meta.
4. Whether prices ever appear on the public site (currently out of scope).
5. Discontinued-product behaviour: Trash (default), 410, or 301?
6. Dynamic selection ID — the actual numeric ID for the gating selection.

**For Skwirrel (technical)**

1. Attachment URL stability — public-stable, signed, or RPC-only?
2. Canonical unique product field — `product_id`, `external_product_id`,
   GTIN, or SKU?
3. Deletion semantics when a product leaves the dynamic selection.

## Development

```bash
composer install   # dev deps only — production runs without composer
```

Code style: PSR-12, PHP 8.1 strict types, namespaced `JijOnline\SkwirrelGavilar\`.

## License

GPL-2.0-or-later (same as WordPress).
