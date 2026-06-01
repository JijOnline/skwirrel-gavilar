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
        $skwirrelId = (int) ($category['product_category_id'] ?? $category['category_id'] ?? $category['id'] ?? 0);
        if ($skwirrelId <= 0) {
            return [];
        }

        // parent_category_id is the real hierarchy parent (null = top-level).
        // super_category_id is a root container (always 1 here) — not a parent.
        $parentSkwirrelId = (int) ($category['parent_category_id'] ?? $category['parent_id'] ?? 0);
        $parentByLang = [];
        if ($parentSkwirrelId > 0 && $parentSkwirrelId !== $skwirrelId && isset($allById[$parentSkwirrelId])) {
            $parentByLang = $this->upsert($allById[$parentSkwirrelId], $allById);
        }

        $namesByLang = $this->resolveNamesByLang($category);
        $existingByLang = $this->findExistingTermsBySkwirrelId($skwirrelId);

        $termIdsByLang = [];
        foreach ($this->polylang->languages() as $slug) {
            // Prefer the matching translation, then the default language, then
            // any translation that exists, before the generic placeholder. So
            // when Skwirrel only has Dutch filled in, English/French posts
            // still get the Dutch name instead of "Category 18".
            $name = $namesByLang[$slug] ?? '';
            if ($name === '') {
                $name = $namesByLang[$this->polylang->defaultLanguage()] ?? '';
            }
            if ($name === '') {
                foreach ($namesByLang as $candidate) {
                    if ($candidate !== '') {
                        $name = $candidate;
                        break;
                    }
                }
            }
            if ($name === '') {
                $name = sprintf('Category %d', $skwirrelId);
            }
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
        // Slug is name-based (plus a language suffix for non-default langs to avoid
        // cross-language collisions when Polylang's shared slugs is off).
        $slug = sanitize_title($name);
        if ($langSlug !== $this->polylang->defaultLanguage()) {
            $slug .= '-' . $langSlug;
        }

        if ($existingTermId !== null) {
            // Update the slug too — early runs created terms with the "Category {id}"
            // placeholder name and got slugs like "category-16". Now that we have
            // the real name we want the URL to reflect it.
            wp_update_term($existingTermId, CategoryTaxonomy::SLUG, [
                'name' => $name,
                'slug' => $slug,
                'parent' => $parentTermId,
            ]);
            return $existingTermId;
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
        $defaultLang = $this->polylang->defaultLanguage();
        $defaultName = '';
        foreach (['name', 'category_name', 'product_category_name', 'category_description', 'product_category_description', 'description', 'title'] as $key) {
            if (!empty($category[$key]) && is_scalar($category[$key])) {
                $defaultName = (string) $category[$key];
                break;
            }
        }
        $namesByLang = $defaultName !== '' ? [$defaultLang => $defaultName] : [];

        $translations = $category['_category_translations'] ?? $category['translations'] ?? [];
        if (is_array($translations)) {
            foreach ($translations as $localeKey => $translation) {
                if (!is_array($translation)) {
                    continue;
                }
                $slug = $this->slugForTranslation($translation, $localeKey);
                if ($slug === null) {
                    continue;
                }
                $name = '';
                foreach (['name', 'category_name', 'product_category_name', 'category_description', 'product_category_description', 'description', 'title'] as $field) {
                    if (!empty($translation[$field]) && is_scalar($translation[$field])) {
                        $name = (string) $translation[$field];
                        break;
                    }
                }
                if ($name !== '') {
                    $namesByLang[$slug] = $name;
                }
            }
        }
        return $namesByLang;
    }

    /**
     * @param array<string, mixed> $translation
     */
    private function slugForTranslation(array $translation, mixed $key): ?string
    {
        foreach (['language', 'language_code', 'locale', 'code'] as $field) {
            if (!empty($translation[$field])) {
                $slug = $this->polylang->resolveSlug((string) $translation[$field]);
                if ($slug !== null) {
                    return $slug;
                }
            }
        }
        if (isset($translation['context_id'])) {
            $slug = $this->polylang->resolveSlug((string) $translation['context_id']);
            if ($slug !== null) {
                return $slug;
            }
        }
        if (is_string($key) && $key !== '') {
            return $this->polylang->resolveSlug($key);
        }
        return null;
    }

    /**
     * @return array<string, int> language slug => term ID
     */
    private function findExistingTermsBySkwirrelId(int $skwirrelId): array
    {
        $terms = get_terms([
            'taxonomy' => CategoryTaxonomy::SLUG,
            'hide_empty' => false,
            // Without lang=>'' Polylang restricts get_terms to the current
            // admin language, so the sync only finds one of the N translated
            // terms per category and (mistakenly) creates the others fresh on
            // every run, multiplying duplicates.
            'lang' => '',
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
            // Keep the lowest-id term per language — older terms are typically
            // the "real" ones; later duplicates from the bug above can be
            // cleaned up separately.
            if (!isset($byLang[$lang]) || $term->term_id < $byLang[$lang]) {
                $byLang[$lang] = (int) $term->term_id;
            }
        }
        return $byLang;
    }
}
