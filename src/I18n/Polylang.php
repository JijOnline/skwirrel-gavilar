<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\I18n;

use JijOnline\SkwirrelGavilar\Cpt\CategoryTaxonomy;
use JijOnline\SkwirrelGavilar\Cpt\ProductPostType;
use JijOnline\SkwirrelGavilar\Support\Settings;

/**
 * Thin wrapper around Polylang's PHP API (Free or Pro).
 *
 * If Polylang is not active, isActive() returns false and the rest of the
 * methods degrade gracefully (single "default" language equal to the WP locale).
 */
final class Polylang
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Tell Polylang to manage our CPT + taxonomy. Without this, Polylang
     * ignores them entirely — its admin language filter won't apply and
     * pll_set_post_language has nothing to hang the language on.
     */
    public function register(): void
    {
        add_filter('pll_get_post_types', static function ($types, $is_settings) {
            $types = is_array($types) ? $types : [];
            $types[ProductPostType::SLUG] = ProductPostType::SLUG;
            return $types;
        }, 10, 2);

        add_filter('pll_get_taxonomies', static function ($taxonomies, $is_settings) {
            $taxonomies = is_array($taxonomies) ? $taxonomies : [];
            $taxonomies[CategoryTaxonomy::SLUG] = CategoryTaxonomy::SLUG;
            return $taxonomies;
        }, 10, 2);
    }

    public function isActive(): bool
    {
        return function_exists('pll_languages_list') && function_exists('pll_set_post_language');
    }

    /** @return string[] Polylang language slugs (e.g. ['nl', 'en', 'fr']). */
    public function languages(): array
    {
        if (!$this->isActive()) {
            return [$this->fallbackLanguage()];
        }
        $list = pll_languages_list(['fields' => 'slug']);
        if (!is_array($list) || empty($list)) {
            return [$this->fallbackLanguage()];
        }
        return array_map('strval', $list);
    }

    public function defaultLanguage(): string
    {
        if ($this->isActive() && function_exists('pll_default_language')) {
            $slug = pll_default_language('slug');
            if (is_string($slug) && $slug !== '') {
                return $slug;
            }
        }
        $langs = $this->languages();
        return $langs[0] ?? $this->fallbackLanguage();
    }

    /**
     * Map a Skwirrel locale code (e.g. 'nl_NL', 'en') to a Polylang slug.
     * Order of resolution:
     *   1. Explicit entry in settings locale map
     *   2. Exact slug match
     *   3. First two characters match (e.g. 'nl_NL' -> 'nl')
     *   4. null (no match)
     */
    public function resolveSlug(string $skwirrelLocale): ?string
    {
        $skwirrelLocale = trim($skwirrelLocale);
        if ($skwirrelLocale === '') {
            return null;
        }

        $map = $this->settings->localeMap();
        if (isset($map[$skwirrelLocale]) && $map[$skwirrelLocale] !== '') {
            return $map[$skwirrelLocale];
        }

        $slugs = $this->languages();

        if (in_array($skwirrelLocale, $slugs, true)) {
            return $skwirrelLocale;
        }

        $prefix = strtolower(substr($skwirrelLocale, 0, 2));
        foreach ($slugs as $slug) {
            if (strtolower($slug) === $prefix) {
                return $slug;
            }
        }

        return null;
    }

    public function setPostLanguage(int $postId, string $slug): void
    {
        if ($this->isActive()) {
            pll_set_post_language($postId, $slug);
        }
    }

    public function getPostLanguage(int $postId): ?string
    {
        if (!$this->isActive() || !function_exists('pll_get_post_language')) {
            return null;
        }
        $slug = pll_get_post_language($postId, 'slug');
        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /** @param array<string, int> $translations slug => post_id */
    public function savePostTranslations(array $translations): void
    {
        if ($this->isActive() && function_exists('pll_save_post_translations')) {
            pll_save_post_translations($translations);
        }
    }

    public function setTermLanguage(int $termId, string $slug): void
    {
        if ($this->isActive() && function_exists('pll_set_term_language')) {
            pll_set_term_language($termId, $slug);
        }
    }

    /** @param array<string, int> $translations slug => term_id */
    public function saveTermTranslations(array $translations): void
    {
        if ($this->isActive() && function_exists('pll_save_term_translations')) {
            pll_save_term_translations($translations);
        }
    }

    private function fallbackLanguage(): string
    {
        $locale = get_locale();
        return substr($locale, 0, 2) ?: 'en';
    }
}
