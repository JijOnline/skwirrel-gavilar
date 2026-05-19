<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Cpt;

final class ProductPostType
{
    public const SLUG = 'pim_product';

    public function register(): void
    {
        add_action('init', [$this, 'registerPostType'], 5);
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
