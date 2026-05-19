<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Mapping;

use JijOnline\SkwirrelGavilar\Cpt\CategoryTaxonomy;
use JijOnline\SkwirrelGavilar\I18n\Polylang;

final class CategoryMapper
{
    public const META_KEY = '_skwirrel_category_id';

    public function __construct(private readonly Polylang $polylang) {}

    /**
     * Upsert a Skwirrel category (and its ancestors) as one term per Polylang language.
     * Returns the term IDs by language slug.
     *
     * @param array<string, mixed> $category Skwirrel category payload (expects category_id, name, parent_id, optionally translations).
     * @param array<int, array<string, mixed>> $allById Map of skwirrel category_id => category payload (for parent resolution).
     * @return array<string, int> language slug => WP term ID
     */
    public function upsert(array $category, array $allById = []): array
    {
        $skwirrelId = (int) ($category['category_id'] ?? 0);
        if ($skwirrelId <= 0) {
            return [];
        }

        $parentSkwirrelId = (int) ($category['parent_id'] ?? 0);
        $parentByLang = [];
        if ($parentSkwirrelId > 0 && isset($allById[$parentSkwirrelId])) {
            $parentByLang = $this->upsert($allById[$parentSkwirrelId], $allById);
        }

        $namesByLang = $this->resolveNamesByLang($category);
        $existingByLang = $this->findExistingTermsBySkwirrelId($skwirrelId);

        $termIdsByLang = [];
        foreach ($this->polylang->languages() as $slug) {
            $name = $namesByLang[$slug] ?? $namesByLang[$this->polylang->defaultLanguage()] ?? sprintf('Category %d', $skwirrelId);
            $parentTermId = $parentByLang[$slug] ?? 0;

            $termIdsByLang[$slug] = $this->upsertOneTerm(
                $skwirrelId,
                $slug,
                $name,
                $parentTermId,
                $existingByLang[$slug] ?? null,
            );
        }

        if (count($termIdsByLang) > 1) {
            $this->polylang->saveTermTranslations(array_filter($termIdsByLang, static fn ($id) => $id > 0));
        }

        return $termIdsByLang;
    }

    private function upsertOneTerm(int $skwirrelId, string $langSlug, string $name, int $parentTermId, ?int $existingTermId): int
    {
        if ($existingTermId !== null) {
            wp_update_term($existingTermId, CategoryTaxonomy::SLUG, [
                'name' => $name,
                'parent' => $parentTermId,
            ]);
            return $existingTermId;
        }

        // Avoid the "term already exists" collision Polylang relies on slug uniqueness across languages,
        // so we append a language suffix to the slug for non-default languages.
        $slug = sanitize_title($name);
        if ($langSlug !== $this->polylang->defaultLanguage()) {
            $slug .= '-' . $langSlug;
        }

        $result = wp_insert_term($name, CategoryTaxonomy::SLUG, [
            'parent' => $parentTermId,
            'slug' => $slug,
        ]);

        if (is_wp_error($result)) {
            // If a term with this slug already exists (e.g. created manually), reuse it.
            $existing = get_term_by('slug', $slug, CategoryTaxonomy::SLUG);
            $termId = $existing ? (int) $existing->term_id : 0;
        } else {
            $termId = (int) $result['term_id'];
        }

        if ($termId > 0) {
            update_term_meta($termId, self::META_KEY, $skwirrelId);
            $this->polylang->setTermLanguage($termId, $langSlug);
        }

        return $termId;
    }

    /**
     * @param array<string, mixed> $category
     * @return array<string, string> language slug => name
     */
    private function resolveNamesByLang(array $category): array
    {
        $defaultName = (string) ($category['name'] ?? '');
        $defaultLang = $this->polylang->defaultLanguage();
        $namesByLang = [$defaultLang => $defaultName];

        $translations = $category['translations'] ?? [];
        if (is_array($translations)) {
            foreach ($translations as $localeKey => $translation) {
                $localeCode = is_array($translation) ? (string) ($translation['locale'] ?? $localeKey) : (string) $localeKey;
                $slug = $this->polylang->resolveSlug($localeCode);
                if ($slug === null) {
                    continue;
                }
                $name = is_array($translation) ? (string) ($translation['name'] ?? '') : (string) $translation;
                if ($name !== '') {
                    $namesByLang[$slug] = $name;
                }
            }
        }
        return $namesByLang;
    }

    /**
     * @return array<string, int> language slug => term ID
     */
    private function findExistingTermsBySkwirrelId(int $skwirrelId): array
    {
        $terms = get_terms([
            'taxonomy' => CategoryTaxonomy::SLUG,
            'hide_empty' => false,
            'meta_query' => [
                ['key' => self::META_KEY, 'value' => $skwirrelId],
            ],
        ]);

        if (!is_array($terms) || empty($terms)) {
            return [];
        }

        $byLang = [];
        foreach ($terms as $term) {
            if (!($term instanceof \WP_Term)) {
                continue;
            }
            $lang = function_exists('pll_get_term_language')
                ? (pll_get_term_language($term->term_id, 'slug') ?: $this->polylang->defaultLanguage())
                : $this->polylang->defaultLanguage();
            $byLang[$lang] = (int) $term->term_id;
        }
        return $byLang;
    }
}
