# Theme integration guide

This document is for the front-end / theme developer. It describes
**what data the Skwirrel sync plugin makes available** and **how to
render it** in a WordPress theme.

For background on *why* the plugin behaves the way it does — gotchas,
Skwirrel API quirks, locked decisions — see
[`PROJECT-STATUS.md`](./PROJECT-STATUS.md). For installation and
admin-level usage see the [README](../README.md).

---

## Scope boundary

The plugin owns **data ingestion and storage**: pulling from Skwirrel
PIM into the WordPress database. The theme owns **presentation**: how
that data is rendered to visitors.

If data is missing on the front-end, the question is almost always
*"is it in Skwirrel?"* — not *"is the plugin broken?"*. Confirm in the
**Skwirrel PIM data** metabox on the post; if the metabox shows it,
the data is there to render.

A few content gaps the client (Gavilar) needs to fill before the
catalogue looks finished:

- `_product_translations` is empty for most products — titles fall
  back to the ERP description (`OPHANGBGL KORT GA4-220`).
- `_product_seo` is empty — Yoast meta only gets populated once they
  fill it in Skwirrel.
- ETIM feature/value labels currently only come back in English from
  Gavilar's tenant; Dutch labels are waiting on Skwirrel-devs.

These aren't theme issues. Render whatever's there; the rest fills in
over time without any code change.

---

## Custom post type & taxonomy

- Post type: **`pim_product`** (registered in `src/Cpt/ProductPostType.php`)
- Taxonomy: **`pim_category`**, hierarchical (registered in
  `src/Cpt/CategoryTaxonomy.php`)
- Both are public + REST-enabled.
- Permalinks: `/product/{slug}/` and `/product-category/{slug}/`.

### Template hierarchy

WordPress will look for these files in your theme (in order):

| Page | Template files (first found wins) |
|---|---|
| Single product | `single-pim_product.php` → `singular.php` → `index.php` |
| Product archive | `archive-pim_product.php` → `archive.php` → `index.php` |
| Category page | `taxonomy-pim_category.php` → `taxonomy.php` → `archive.php` |

Until a `single-pim_product.php` exists, the plugin falls back to a
generic block that appends a specifications table to `the_content`.
See [Disabling the placeholder render](#disabling-the-placeholder-render).

### Polylang

`pim_product` and `pim_category` are registered with Polylang via the
`pll_get_post_types` / `pll_get_taxonomies` filters. That means:

- One post per Polylang language (linked as translations).
- `pll_current_language()` and `pll_get_post_translations()` work
  normally.
- The language switcher widget includes product pages by default.

---

## Data inventory

Every field below is stored as **post meta** on the `pim_product` post,
unless noted otherwise. Read via `get_post_meta($post_id, 'KEY', true)`.

### Identification & provenance

| Meta key | Type | Example | Notes |
|---|---|---|---|
| `_skwirrel_product_id` | int | `39` | Canonical Skwirrel id. Stable. |
| `_skwirrel_external_product_id` | string | `"27613"` | External / SKU code. Not guaranteed unique. |
| `_skwirrel_updated_on` | datetime ISO | `2026-06-02T11:40:47+02:00` | Last change in Skwirrel. |

### Display fields (the bulk of what a theme renders)

| Meta key | Type | Example | Notes |
|---|---|---|---|
| `_pim_erp_description` | string | `GASDR REG WMRG10-W-3/4+` | Used as `post_title` fallback when translations are empty. |
| `_pim_manufacturer` | string | `gAvilar` | |
| `_pim_brand` | string | `gAvilar` | |
| `_pim_gtin` | string | `08718558276137` | |
| `_pim_cbs_number` | string | `90328900` | Dutch tax/customs code. |
| `_pim_internal_code` | string | `27613` | |
| `_pim_manufacturer_code` | string | `27613` | |
| `_pim_weight` | number-as-string | `0.516` | Combine with `_pim_weight_uom`. |
| `_pim_weight_uom` | string | `KGM` | UN/CEFACT unit code, raw. |
| `_pim_product_url` | URL | `https://www.gavilar.nl/...` | Optional external product page. |

### Media

| Meta key | Type | Notes |
|---|---|---|
| `_thumbnail_id` | int (attachment ID) | Standard WP featured image. Use `get_the_post_thumbnail()` / `has_post_thumbnail()`. |
| `_pim_gallery_ids` | `int[]` | Additional image attachment IDs. May be empty. |
| `_pim_documents` | `array<{id:int,label:string}>` | PDFs and other non-image files. May be empty. |

Featured + gallery images both live in the standard WP media library.
Get an image URL with `wp_get_attachment_image_url($id, 'large')` etc.

For documents, use `wp_get_attachment_url($id)` to get the file URL.

### Yoast SEO (when populated)

Standard Yoast meta keys, written automatically per-locale by the sync:

- `_yoast_wpseo_title`
- `_yoast_wpseo_metadesc`
- `_yoast_wpseo_focuskw`
- `_yoast_wpseo_opengraph-title`
- `_yoast_wpseo_opengraph-description`

You usually don't read these directly; Yoast outputs them via its own
filters into `<head>`. Just make sure Yoast is active.

### Categories

Standard WP taxonomy. Get terms via
`get_the_terms($post_id, 'pim_category')`. Each term carries:

| Term meta | Type | Notes |
|---|---|---|
| `_skwirrel_category_id` | int | Canonical Skwirrel id. |

Category names are pulled from Skwirrel's `_category_translations[]`
and stored as the WP term name — already localised per Polylang
language. Just call `$term->name`.

### ETIM technical features

Stored on the product as `_pim_etim`. Pre-filtered (NVT features
removed) and structured for direct rendering.

```php
$etim = get_post_meta($post_id, '_pim_etim', true);
```

Structure (PHP array):

```php
[
  [
    'class_code' => 'EC003039',
    'class_label_by_lang' => ['en' => 'Gas meter bracket'],
    'group_code' => 'EG020077',
    'group_label_by_lang' => ['en' => 'Mounting material...'],
    'features' => [
      [
        'code' => 'EF010016',
        'type' => 'A',              // A=picklist, N=numeric, L=boolean, R=range
        'order' => 1,
        'label_by_lang' => ['en' => 'Model gas meter'],
        'value' => [
          // EXACTLY ONE of these will be set, matching `type`:
          'code' => 'EV012357',                              // A
          'label_by_lang' => ['en' => 'G 4/G 6'],            // A
          // 'numeric' => 220,                                // N
          // 'logical' => false,                              // L
          // 'range' => ['min' => -20, 'max' => 60],          // R
        ],
        'unit_code' => null,                                 // N, R
        'unit_label_by_lang' => [],
        'unit_abbr_by_lang' => ['en' => 'mm'],               // for N/R when applicable
      ],
      // ... more features, sorted by `order`
    ],
  ],
  // ... a product can theoretically have multiple ETIM classes
]
```

**The plugin provides a helper for rendering.** Don't recreate this
logic — call `EtimMapper::format()`:

```php
use JijOnline\SkwirrelGavilar\Mapping\EtimMapper;

$lang = function_exists('pll_current_language') ? pll_current_language('slug') : 'en';

foreach ($etim as $class) {
    foreach ($class['features'] as $feature) {
        $formatted = EtimMapper::format($feature, $lang); // ['label' => '...', 'value' => '...']
        // Render $formatted['label'] and $formatted['value']
    }
}
```

`format()` handles all four feature types, range collapsing (when
min == max), unit suffixing, and language fallback (display lang → WP
default lang → `en` → any).

---

## Render recipes

Copy-pastable starters. Adjust HTML / classes / styling to taste.

### Featured image + gallery

```php
<div class="product-media">
    <?php if (has_post_thumbnail()): ?>
        <div class="product-media__featured">
            <?php the_post_thumbnail('large'); ?>
        </div>
    <?php endif; ?>

    <?php
    $gallery_ids = (array) get_post_meta(get_the_ID(), '_pim_gallery_ids', true);
    if (!empty($gallery_ids)):
    ?>
        <ul class="product-media__gallery">
            <?php foreach ($gallery_ids as $att_id): ?>
                <li>
                    <a href="<?php echo esc_url(wp_get_attachment_image_url((int) $att_id, 'large')); ?>">
                        <?php echo wp_get_attachment_image((int) $att_id, 'medium'); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
```

### Documents (PDFs etc.)

```php
<?php
$documents = (array) get_post_meta(get_the_ID(), '_pim_documents', true);
if (!empty($documents)):
?>
    <section class="product-documents">
        <h2><?php esc_html_e('Documentation', 'gavilar-theme'); ?></h2>
        <ul>
            <?php foreach ($documents as $doc):
                $url = wp_get_attachment_url((int) ($doc['id'] ?? 0));
                if (!$url) continue;
                $label = $doc['label'] !== '' ? $doc['label'] : basename($url);
            ?>
                <li>
                    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">
                        <?php echo esc_html($label); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
```

### Specifications table (flat fields)

```php
<?php
$specs = [
    __('Manufacturer', 'gavilar-theme')    => get_post_meta(get_the_ID(), '_pim_manufacturer', true),
    __('Brand', 'gavilar-theme')           => get_post_meta(get_the_ID(), '_pim_brand', true),
    __('GTIN', 'gavilar-theme')            => get_post_meta(get_the_ID(), '_pim_gtin', true),
    __('CBS number', 'gavilar-theme')      => get_post_meta(get_the_ID(), '_pim_cbs_number', true),
];

$weight = trim((string) get_post_meta(get_the_ID(), '_pim_weight', true));
if ($weight !== '') {
    $uom = trim((string) get_post_meta(get_the_ID(), '_pim_weight_uom', true));
    $specs[__('Weight', 'gavilar-theme')] = $uom !== '' ? "{$weight} {$uom}" : $weight;
}

$specs = array_filter($specs, static fn ($v) => $v !== '' && $v !== null);

if (!empty($specs)):
?>
    <table class="product-specs">
        <?php foreach ($specs as $label => $value): ?>
            <tr><th><?php echo esc_html($label); ?></th><td><?php echo esc_html($value); ?></td></tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
```

### ETIM features

```php
<?php
use JijOnline\SkwirrelGavilar\Mapping\EtimMapper;

$etim = get_post_meta(get_the_ID(), '_pim_etim', true);
if (is_array($etim) && !empty($etim)):
    $lang = function_exists('pll_current_language') ? pll_current_language('slug') : 'en';
?>
    <section class="product-etim">
        <h2><?php esc_html_e('Technical specifications', 'gavilar-theme'); ?></h2>
        <?php foreach ($etim as $class):
            $class_label = EtimMapper::pickLabel(
                (array) ($class['class_label_by_lang'] ?? []),
                $lang
            );
        ?>
            <div class="product-etim__class">
                <?php if ($class_label !== ''): ?>
                    <h3><?php echo esc_html($class_label); ?></h3>
                <?php endif; ?>
                <table>
                    <?php foreach ((array) $class['features'] as $feature):
                        $f = EtimMapper::format($feature, $lang);
                        if ($f['label'] === '' && $f['value'] === '') continue;
                    ?>
                        <tr>
                            <th><?php echo esc_html($f['label']); ?></th>
                            <td><?php echo esc_html($f['value']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
```

### Categories on a product

```php
<?php
$terms = get_the_terms(get_the_ID(), 'pim_category');
if (!empty($terms) && !is_wp_error($terms)):
?>
    <ul class="product-categories">
        <?php foreach ($terms as $term): ?>
            <li>
                <a href="<?php echo esc_url(get_term_link($term)); ?>">
                    <?php echo esc_html($term->name); ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
```

### Polylang language switcher (per product)

Use Polylang's standard widget, or:

```php
<?php if (function_exists('pll_the_languages')): ?>
    <ul class="language-switcher">
        <?php pll_the_languages(['raw' => 0, 'show_flags' => 1, 'show_names' => 1]); ?>
    </ul>
<?php endif; ?>
```

The switcher automatically points each language item to the linked
translation of the current product (set via `pll_save_post_translations`
by the sync).

### Detecting an empty product (graceful degradation)

Many products currently have no description / SEO / extra translations.
Avoid rendering empty sections:

```php
$has_description = trim(get_the_content()) !== '';
$has_gallery     = !empty(get_post_meta(get_the_ID(), '_pim_gallery_ids', true));
$has_docs        = !empty(get_post_meta(get_the_ID(), '_pim_documents', true));
$has_etim        = !empty(get_post_meta(get_the_ID(), '_pim_etim', true));
```

---

## Disabling the placeholder render

Until your theme has `single-pim_product.php`, the plugin appends a
generic "Specifications" + "ETIM technical features" block to
`the_content()` for the product. Once your template renders these
properly, you don't want both.

Two options:

**Option 1 — Remove the filter from your `functions.php`** (simplest):

```php
add_action('after_setup_theme', function () {
    if (class_exists(\JijOnline\SkwirrelGavilar\Plugin::class)) {
        // Removes the the_content filter that appends the placeholder.
        // (We register the display class freshly on each page load, but
        // the filter has the same priority so this removes it.)
        remove_filter('the_content', [
            new \JijOnline\SkwirrelGavilar\Display\ProductDisplay(),
            'appendSpecTable',
        ]);
    }
}, 20);
```

Note: removing a filter added with a freshly instantiated object isn't
100% reliable. If it doesn't take, the cleaner path is option 2.

**Option 2 — Ask the plugin to be quiet on single-product pages**

Add to `functions.php`:

```php
add_filter('the_content', function ($content) {
    if (is_singular(\JijOnline\SkwirrelGavilar\Cpt\ProductPostType::SLUG)) {
        // Strip everything after a marker the plugin's HTML emits.
        $pos = strpos($content, '<div class="pim-product-specs">');
        if ($pos !== false) {
            $content = substr($content, 0, $pos);
        }
    }
    return $content;
}, 99);
```

A future plugin version may expose a clean filter/option for this. For
now, either works.

---

## Test fixtures

Use these specific products to verify your rendering covers the
interesting cases:

| Skwirrel `product_id` | External code | What's interesting |
|---|---|---|
| 1 | 13729 | Full ETIM block (gas meter bracket, 6 features). Use to verify ETIM rendering of A and N types. |
| 39 | 27613 | Gas pressure control valve with 2 PDFs + 2 (identical) images + range-type ETIM features (working temperature -20—60 °C, outlet pressure 27.5 mbar). Use to verify documents, gallery, range rendering. |

Find them in `PIM products` via the admin search; the plugin's
enhanced search matches Skwirrel codes too (typing `27613` finds the
product).

---

## Local development

Two options to work locally without Skwirrel credentials:

### Option A — DB dump + plugin (recommended)

1. SSH to staging (`wijonline@s219.webhostingserver.nl`).
2. `wp db export ~/staging-dump.sql` from the WP root, transfer the
   file home.
3. Locally: import the dump into your dev environment.
4. The plugin folder: `git clone https://github.com/JijOnline/skwirrel-gavilar.git`
   into your local `wp-content/plugins/`.
5. Polylang and Yoast: install + activate (Free editions are fine for
   dev).
6. Don't configure Skwirrel credentials — you have all the data
   already; the daily cron will silently no-op.

You can edit templates against fully-populated PIM data without ever
hitting the Skwirrel API.

### Option B — Develop directly on staging

If you prefer working on staging, you'll need an Antagonist SSH
allowlist entry (ask Sebas before he leaves) plus admin access. Then
SFTP / git pull your theme changes in.

---

## Working with Claude Code on plugin changes

If you need a plugin tweak — a new meta field surfaced, a new include
flag, a behaviour change — Claude Code is set up to work on this
project effectively. Open a fresh session and start with:

```
Read docs/PROJECT-STATUS.md and docs/THEME-INTEGRATION.md, then check
the README for codebase orientation. Wait for instructions.
```

That gives Claude all the locked decisions, API gotchas, and structure
without burning tokens replaying months of conversation. Then describe
what you need.

A few patterns to lean on, all documented at length in
`PROJECT-STATUS.md`:

- Before changing a mapper, ask Claude to fetch a real sample via the
  *Show sample product* button (or write the equivalent inspection
  code) so you both work from real response shapes, not guesses.
- Trust the gotcha list (Accept header, `include_languages` as
  `string[]`, NVT semantics, etc.) — Claude knows about them but
  should be reminded if it starts re-litigating any.
- After any schema or mapper change, **run a Full resync** to
  re-populate existing posts. The daily delta only touches changed
  Skwirrel products.

---

## Known content / data gaps

The plugin can render only what Skwirrel returns. Several fields are
currently empty in Gavilar's tenant and aren't theme bugs:

- Product names + descriptions (most products fall back to ERP code)
- SEO fields (Yoast meta only filled when Skwirrel SEO is)
- Dutch ETIM feature/value labels (only English comes back; waiting
  on Skwirrel devs)
- Category descriptions (`category_web_text` is empty)

These are content tasks for the client and waiting answers from
Skwirrel — out of scope for the theme.
