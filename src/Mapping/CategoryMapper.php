<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Mapping;

use JijOnline\SkwirrelGavilar\Cpt\CategoryTaxonomy;

final class CategoryMapper
{
    public const META_KEY = '_skwirrel_category_id';

    /**
     * Upsert a Skwirrel category (and ancestors) and return the WP term ID.
     *
     * @param array<string, mixed> $category Expected keys: category_id, name, parent_id
     * @param array<int, array<string, mixed>> $allById Map of skwirrel category_id => category payload, used to resolve parents.
     */
    public function upsert(array $category, array $allById = []): int
    {
        $skwirrelId = (int) ($category['category_id'] ?? 0);
        if ($skwirrelId <= 0) {
            return 0;
        }

        $existing = $this->findTermBySkwirrelId($skwirrelId);
        $name = (string) ($category['name'] ?? sprintf('Category %d', $skwirrelId));
        $parentSkwirrelId = (int) ($category['parent_id'] ?? 0);

        $parentTermId = 0;
        if ($parentSkwirrelId > 0 && isset($allById[$parentSkwirrelId])) {
            $parentTermId = $this->upsert($allById[$parentSkwirrelId], $allById);
        }

        if ($existing) {
            wp_update_term($existing->term_id, CategoryTaxonomy::SLUG, [
                'name' => $name,
                'parent' => $parentTermId,
            ]);
            return (int) $existing->term_id;
        }

        $result = wp_insert_term($name, CategoryTaxonomy::SLUG, ['parent' => $parentTermId]);
        if (is_wp_error($result)) {
            // Term may exist with a different stored skwirrel_id — fall back to lookup by name + parent.
            $term = get_term_by('name', $name, CategoryTaxonomy::SLUG);
            $termId = $term ? (int) $term->term_id : 0;
        } else {
            $termId = (int) $result['term_id'];
        }

        if ($termId > 0) {
            update_term_meta($termId, self::META_KEY, $skwirrelId);
        }
        return $termId;
    }

    private function findTermBySkwirrelId(int $skwirrelId): ?\WP_Term
    {
        $terms = get_terms([
            'taxonomy' => CategoryTaxonomy::SLUG,
            'hide_empty' => false,
            'number' => 1,
            'meta_query' => [
                [
                    'key' => self::META_KEY,
                    'value' => $skwirrelId,
                ],
            ],
        ]);
        if (is_array($terms) && !empty($terms) && $terms[0] instanceof \WP_Term) {
            return $terms[0];
        }
        return null;
    }
}
