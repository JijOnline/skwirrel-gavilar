<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Cpt;

final class ProductPostType
{
    public const SLUG = 'pim_product';

    public function register(): void
    {
        add_action('init', [$this, 'registerPostType'], 5);
        add_action('pre_get_posts', [$this, 'enhanceAdminSearch']);
    }

    /**
     * The default admin search only hits post_title / post_content / post_excerpt.
     * Skwirrel product codes (manufacturer code, internal code, GTIN, external id)
     * live in meta — searching for "27613" in PIM products → 0 results. When the
     * query term looks like a code (no spaces, alnum + dashes only) we redirect
     * the search to the relevant meta keys instead.
     */
    public function enhanceAdminSearch(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        if ($query->get('post_type') !== self::SLUG) {
            return;
        }
        $term = trim((string) $query->get('s'));
        if ($term === '' || !preg_match('/^[A-Z0-9\-_.]+$/i', $term)) {
            return;
        }

        $query->set('s', '');
        $existing = $query->get('meta_query') ?: [];
        $existing[] = [
            'relation' => 'OR',
            ['key' => '_pim_manufacturer_code', 'value' => $term, 'compare' => 'LIKE'],
            ['key' => '_pim_internal_code', 'value' => $term, 'compare' => 'LIKE'],
            ['key' => '_pim_gtin', 'value' => $term, 'compare' => 'LIKE'],
            ['key' => '_skwirrel_external_product_id', 'value' => $term, 'compare' => 'LIKE'],
            ['key' => '_skwirrel_product_id', 'value' => $term, 'compare' => '='],
        ];
        $query->set('meta_query', $existing);
    }

    public function registerPostType(): void
    {
        register_post_type(self::SLUG, [
            'labels' => [
                'name' => __('Products', 'skwirrel-gavilar'),
                'singular_name' => __('Product', 'skwirrel-gavilar'),
                'menu_name' => __('PIM products', 'skwirrel-gavilar'),
                'add_new_item' => __('Add product', 'skwirrel-gavilar'),
                'edit_item' => __('Edit product', 'skwirrel-gavilar'),
                'view_item' => __('View product', 'skwirrel-gavilar'),
                'search_items' => __('Search products', 'skwirrel-gavilar'),
                'not_found' => __('No products found', 'skwirrel-gavilar'),
            ],
            'public' => true,
            'show_in_rest' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-products',
            'menu_position' => 20,
            'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'custom-fields'],
            'has_archive' => true,
            'rewrite' => ['slug' => 'product', 'with_front' => false],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }
}
