<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Cpt;

final class CategoryTaxonomy
{
    public const SLUG = 'pim_category';

    public function register(): void
    {
        add_action('init', [$this, 'registerTaxonomy'], 5);
    }

    public function registerTaxonomy(): void
    {
        register_taxonomy(self::SLUG, [ProductPostType::SLUG], [
            'labels' => [
                'name' => __('Product categories', 'skwirrel-gavilar'),
                'singular_name' => __('Product category', 'skwirrel-gavilar'),
                'menu_name' => __('Categories', 'skwirrel-gavilar'),
                'search_items' => __('Search categories', 'skwirrel-gavilar'),
                'all_items' => __('All categories', 'skwirrel-gavilar'),
                'parent_item' => __('Parent category', 'skwirrel-gavilar'),
                'edit_item' => __('Edit category', 'skwirrel-gavilar'),
            ],
            'hierarchical' => true,
            'public' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'rewrite' => ['slug' => 'product-category', 'with_front' => false],
        ]);
    }
}
