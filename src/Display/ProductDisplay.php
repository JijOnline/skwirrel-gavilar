<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Display;

use JijOnline\SkwirrelGavilar\Cpt\ProductPostType;
use JijOnline\SkwirrelGavilar\Mapping\EtimMapper;
use JijOnline\SkwirrelGavilar\Mapping\ProductMapper;

/**
 * Surfaces the synced PIM data: a read-only metabox in the post editor and a
 * specification table appended to the product on the front-end. Without this
 * the data is in post meta but invisible — _-prefixed meta is hidden from the
 * Custom Fields UI, and no theme knows the pim_product fields.
 */
final class ProductDisplay
{
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
        add_filter('the_content', [$this, 'appendSpecTable']);
    }

    public function addMetaBox(): void
    {
        add_meta_box(
            'skwirrel_gavilar_pim_data',
            __('Skwirrel PIM data', 'skwirrel-gavilar'),
            [$this, 'renderMetaBox'],
            ProductPostType::SLUG,
            'normal',
            'high',
        );
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        $rows = $this->fields($post->ID);
        if (!empty($rows)) {
            echo '<table class="widefat striped"><tbody>';
            foreach ($rows as $label => $value) {
                printf(
                    '<tr><th style="width:220px;text-align:left;">%s</th><td>%s</td></tr>',
                    esc_html($label),
                    wp_kses_post($value),
                );
            }
            echo '</tbody></table>';
        }

        $etimHtml = $this->renderEtimHtml($post->ID, /* heading: */ true);
        if ($etimHtml !== '') {
            echo $etimHtml;
        }

        if (empty($rows) && $etimHtml === '') {
            echo '<p>' . esc_html__('No PIM data synced for this product yet.', 'skwirrel-gavilar') . '</p>';
            return;
        }

        echo '<p class="description">' . esc_html__('Read-only — managed by the Skwirrel sync. Edits here are overwritten on the next sync.', 'skwirrel-gavilar') . '</p>';
    }

    public function appendSpecTable(string $content): string
    {
        if (!is_singular(ProductPostType::SLUG) || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        $postId = (int) get_the_ID();
        $rows = $this->fields($postId);
        $etimHtml = $this->renderEtimHtml($postId, /* heading: */ true);

        if (empty($rows) && $etimHtml === '') {
            return $content;
        }

        $html = '<div class="pim-product-specs">';
        if (!empty($rows)) {
            $html .= '<h2>' . esc_html__('Specifications', 'skwirrel-gavilar') . '</h2>';
            $html .= '<table class="pim-product-specs__table">';
            foreach ($rows as $label => $value) {
                $html .= sprintf(
                    '<tr><th scope="row">%s</th><td>%s</td></tr>',
                    esc_html($label),
                    wp_kses_post($value),
                );
            }
            $html .= '</table>';
        }
        $html .= $etimHtml;
        $html .= '</div>';

        return $content . $html;
    }

    /**
     * Render the stored ETIM blocks. Returns an empty string if there's nothing.
     * Used by both the metabox and the front-end content filter.
     */
    private function renderEtimHtml(int $postId, bool $withHeading): string
    {
        $etim = get_post_meta($postId, '_pim_etim', true);
        if (!is_array($etim) || empty($etim)) {
            return '';
        }

        $displayLang = $this->displayLanguage();
        $defaultLang = function_exists('pll_default_language')
            ? (string) pll_default_language('slug')
            : 'en';

        $html = '';
        if ($withHeading) {
            $html .= '<h3 style="margin-top:1.5em;">' . esc_html__('ETIM technical features', 'skwirrel-gavilar') . '</h3>';
        }

        foreach ($etim as $class) {
            if (!is_array($class) || empty($class['features'])) {
                continue;
            }
            $classLabel = EtimMapper::pickLabel((array) ($class['class_label_by_lang'] ?? []), $displayLang, $defaultLang);
            $classCode = (string) ($class['class_code'] ?? '');

            $html .= '<div class="pim-etim-class" style="margin-bottom:1em;">';
            if ($classLabel !== '' || $classCode !== '') {
                $caption = $classLabel !== '' ? $classLabel : $classCode;
                if ($classLabel !== '' && $classCode !== '') {
                    $caption .= ' (' . $classCode . ')';
                }
                $html .= '<p style="margin:0 0 4px;"><strong>' . esc_html($caption) . '</strong></p>';
            }
            $html .= '<table class="widefat striped pim-etim-class__table"><tbody>';
            foreach ((array) $class['features'] as $feature) {
                if (!is_array($feature)) {
                    continue;
                }
                $formatted = EtimMapper::format($feature, $displayLang, $defaultLang);
                if ($formatted['label'] === '' && $formatted['value'] === '') {
                    continue;
                }
                $html .= sprintf(
                    '<tr><th scope="row" style="width:220px;text-align:left;">%s</th><td>%s</td></tr>',
                    esc_html($formatted['label']),
                    esc_html($formatted['value']),
                );
            }
            $html .= '</tbody></table></div>';
        }
        return $html;
    }

    /** Best-effort current display language slug (Polylang, or WP locale fallback). */
    private function displayLanguage(): string
    {
        if (function_exists('pll_current_language')) {
            $slug = pll_current_language('slug');
            if (is_string($slug) && $slug !== '') {
                return $slug;
            }
        }
        $locale = get_locale();
        return substr($locale, 0, 2) ?: 'en';
    }

    /**
     * Build the label => display-value map from synced meta. Empty values skipped.
     *
     * @return array<string, string>
     */
    private function fields(int $postId): array
    {
        $get = static fn (string $key): string => trim((string) get_post_meta($postId, $key, true));

        $rows = [];
        $simple = [
            '_pim_manufacturer' => __('Manufacturer', 'skwirrel-gavilar'),
            '_pim_brand' => __('Brand', 'skwirrel-gavilar'),
            '_pim_gtin' => __('GTIN', 'skwirrel-gavilar'),
            '_pim_cbs_number' => __('CBS number', 'skwirrel-gavilar'),
            '_pim_internal_code' => __('Internal code', 'skwirrel-gavilar'),
            '_pim_manufacturer_code' => __('Manufacturer code', 'skwirrel-gavilar'),
            '_pim_erp_description' => __('ERP description', 'skwirrel-gavilar'),
            ProductMapper::META_EXTERNAL_ID => __('External product ID', 'skwirrel-gavilar'),
            ProductMapper::META_SKWIRREL_ID => __('Skwirrel product ID', 'skwirrel-gavilar'),
            ProductMapper::META_UPDATED_ON => __('Last changed in Skwirrel', 'skwirrel-gavilar'),
        ];

        // Weight + unit of measure combined.
        $weight = $get('_pim_weight');
        if ($weight !== '') {
            $uom = $get('_pim_weight_uom');
            $rows[(string) __('Weight', 'skwirrel-gavilar')] = $uom !== '' ? "{$weight} {$uom}" : $weight;
        }

        foreach ($simple as $metaKey => $label) {
            $value = $get($metaKey);
            if ($value !== '') {
                $rows[(string) $label] = $value;
            }
        }

        $url = $get('_pim_product_url');
        if ($url !== '') {
            $rows[(string) __('Product page', 'skwirrel-gavilar')] = sprintf(
                '<a href="%1$s" target="_blank" rel="noopener">%1$s</a>',
                esc_url($url),
            );
        }

        // Featured image, gallery and documents — surfaced so the synced
        // media is visible in the editor without diving into the database.
        $featuredId = (int) get_post_thumbnail_id($postId);
        if ($featuredId > 0) {
            $rows[(string) __('Featured image', 'skwirrel-gavilar')] = (string) wp_get_attachment_image(
                $featuredId,
                [80, 80],
                false,
                ['style' => 'border:1px solid #ddd;']
            );
        }

        $galleryIds = get_post_meta($postId, '_pim_gallery_ids', true);
        if (is_array($galleryIds) && !empty($galleryIds)) {
            $thumbs = '';
            foreach ($galleryIds as $attId) {
                $thumbs .= wp_get_attachment_image(
                    (int) $attId,
                    [60, 60],
                    false,
                    ['style' => 'margin:2px;border:1px solid #ddd;']
                );
            }
            $rows[(string) __('Gallery', 'skwirrel-gavilar')] = sprintf(
                '%d %s<br>%s',
                count($galleryIds),
                esc_html__('image(s)', 'skwirrel-gavilar'),
                $thumbs,
            );
        }

        $documents = get_post_meta($postId, '_pim_documents', true);
        if (is_array($documents) && !empty($documents)) {
            $links = [];
            foreach ($documents as $doc) {
                if (!is_array($doc) || empty($doc['id'])) {
                    continue;
                }
                $attUrl = wp_get_attachment_url((int) $doc['id']);
                if (!$attUrl) {
                    continue;
                }
                $label = (string) ($doc['label'] ?? '');
                if ($label === '') {
                    $label = basename($attUrl);
                }
                $links[] = sprintf(
                    '<a href="%s" target="_blank" rel="noopener">%s</a>',
                    esc_url($attUrl),
                    esc_html($label),
                );
            }
            if (!empty($links)) {
                $rows[(string) __('Documents', 'skwirrel-gavilar')] = implode('<br>', $links);
            }
        }

        return $rows;
    }
}
