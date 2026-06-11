<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Mapping;

use JijOnline\SkwirrelGavilar\Cpt\CategoryTaxonomy;
use JijOnline\SkwirrelGavilar\Cpt\ProductPostType;
use JijOnline\SkwirrelGavilar\I18n\Polylang;
use JijOnline\SkwirrelGavilar\Support\Logger;

final class ProductMapper
{
    public const META_SKWIRREL_ID = '_skwirrel_product_id';
    public const META_EXTERNAL_ID = '_skwirrel_external_product_id';
    public const META_UPDATED_ON = '_skwirrel_updated_on';
    public const META_LAST_RUN_ID = '_skwirrel_last_run_id';

    /** Flat product meta -> Skwirrel field name. */
    private const PRODUCT_META_MAP = [
        '_pim_manufacturer' => 'manufacturer_name',
        '_pim_brand' => 'brand_name',
        '_pim_gtin' => 'product_gtin',
        '_pim_weight' => 'product_weight',
        '_pim_weight_uom' => 'product_weight_uom',
        '_pim_cbs_number' => 'cbs_number',
        '_pim_internal_code' => 'internal_product_code',
        '_pim_manufacturer_code' => 'manufacturer_product_code',
        '_pim_product_url' => 'product_url',
        '_pim_erp_description' => 'product_erp_description',
    ];

    public function __construct(
        private readonly CategoryMapper $categoryMapper,
        private readonly FeatureMapper $featureMapper,
        private readonly AttachmentMapper $attachmentMapper,
        private readonly EtimMapper $etimMapper,
        private readonly Polylang $polylang,
        private readonly Logger $logger,
    ) {}

    /**
     * Upsert one Skwirrel product as N posts (one per Polylang language), linked as translations.
     * Gavilar currently has no per-locale data, so in practice this is one post per product.
     *
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $categoriesById getCategories index, keyed by category id.
     * @return array{created:int, updated:int}
     */
    public function upsert(array $product, string $runId, array $categoriesById = []): array
    {
        $skwirrelId = (int) ($product['product_id'] ?? 0);
        if ($skwirrelId <= 0) {
            throw new \InvalidArgumentException('Product is missing product_id.');
        }

        $translationsByLang = $this->resolveTranslationsByLang($product);
        $existingByLang = $this->findExistingPostsBySkwirrelId($skwirrelId);

        // Media is language-agnostic in WP — sync attachments once, reuse on every locale post.
        $attachments = (array) ($product['_attachments'] ?? []);
        $sharedAttachmentTargets = null;

        $postIdsByLang = [];
        $created = $updated = 0;

        foreach ($this->polylang->languages() as $slug) {
            $translation = $translationsByLang[$slug]
                ?? $translationsByLang[$this->polylang->defaultLanguage()]
                ?? [];

            $existingId = $existingByLang[$slug] ?? null;
            $isCreate = ($existingId === null);

            $postId = $this->writePost($skwirrelId, $slug, $product, $translation, $runId, $existingId);
            $postIdsByLang[$slug] = $postId;

            if ($isCreate) {
                $created++;
                $this->polylang->setPostLanguage($postId, $slug);
            } else {
                $updated++;
                if ($this->polylang->getPostLanguage($postId) === null) {
                    $this->polylang->setPostLanguage($postId, $slug);
                }
            }

            $this->applyCategoriesForLang($postId, $slug, $product, $categoriesById);
            $this->writeSeoMeta($postId, $translation);

            if ($sharedAttachmentTargets === null && !empty($attachments)) {
                try {
                    $sharedAttachmentTargets = $this->attachmentMapper->sync($postId, $attachments);
                } catch (\Throwable $e) {
                    $this->logger->error('Attachment sync failed', ['product_id' => $skwirrelId, 'error' => $e->getMessage()]);
                    $sharedAttachmentTargets = ['featured' => null, 'gallery' => [], 'documents' => []];
                }
            } elseif ($sharedAttachmentTargets !== null) {
                $this->reuseAttachmentTargets($postId, $sharedAttachmentTargets);
            }
        }

        if (count($postIdsByLang) > 1) {
            $this->polylang->savePostTranslations(array_filter($postIdsByLang, static fn ($id) => $id > 0));
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $translation
     */
    private function writePost(int $skwirrelId, string $langSlug, array $product, array $translation, string $runId, ?int $existingId): int
    {
        $title = $this->resolveTitle($product, $translation);
        // Skwirrel content translation fields, confirmed against a real getProducts
        // response: product_long_description is the full description (→ body) and
        // product_description is the short summary line (→ excerpt). Sanitised as
        // post HTML so safe markup survives but scripts are stripped.
        $description = (string) ($translation['product_long_description'] ?? '');
        $excerpt = (string) ($translation['product_description'] ?? '');
        $description = $description !== '' ? wp_kses_post($description) : '';
        $excerpt = $excerpt !== '' ? wp_kses_post($excerpt) : '';
        $slug = $this->buildSlug($product, $translation, $title, $langSlug);

        $postData = [
            'post_type' => ProductPostType::SLUG,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => $description,
            'post_excerpt' => $excerpt,
        ];

        if ($existingId !== null) {
            $postData['ID'] = $existingId;
            $postId = wp_update_post($postData, true);
        } else {
            $postId = wp_insert_post($postData, true);
        }

        if (is_wp_error($postId) || !is_int($postId) || $postId <= 0) {
            $msg = is_wp_error($postId) ? $postId->get_error_message() : 'unknown';
            throw new \RuntimeException("Failed to upsert product {$skwirrelId} ({$langSlug}): {$msg}");
        }

        update_post_meta($postId, self::META_SKWIRREL_ID, $skwirrelId);
        if (!empty($product['external_product_id'])) {
            update_post_meta($postId, self::META_EXTERNAL_ID, (string) $product['external_product_id']);
        }
        $updatedOn = (string) ($product['product_updated_on'] ?? $product['updated_on'] ?? '');
        if ($updatedOn !== '') {
            update_post_meta($postId, self::META_UPDATED_ON, $updatedOn);
        }
        update_post_meta($postId, self::META_LAST_RUN_ID, $runId);
        $this->writeProductMeta($postId, $product);

        $etim = $this->etimMapper->build($product['_etim'] ?? []);
        if (!empty($etim)) {
            update_post_meta($postId, '_pim_etim', $etim);
        } else {
            delete_post_meta($postId, '_pim_etim');
        }

        return $postId;
    }

    /**
     * Skwirrel has no single "name" field. Prefer a translated name when present,
     * otherwise fall back to the ERP description, then a product code.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $translation
     */
    private function resolveTitle(array $product, array $translation): string
    {
        foreach (['name', 'product_name', 'title'] as $key) {
            if (!empty($translation[$key])) {
                return (string) $translation[$key];
            }
        }
        foreach (['product_erp_description', 'manufacturer_product_code', 'internal_product_code', 'external_product_id'] as $key) {
            if (!empty($product[$key])) {
                return (string) $product[$key];
            }
        }
        return sprintf('Product %d', (int) ($product['product_id'] ?? 0));
    }

    /** @param array<string, mixed> $product */
    private function writeProductMeta(int $postId, array $product): void
    {
        foreach (self::PRODUCT_META_MAP as $metaKey => $srcKey) {
            $value = $product[$srcKey] ?? null;
            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                delete_post_meta($postId, $metaKey);
                continue;
            }
            update_post_meta($postId, $metaKey, is_scalar($value) ? $value : wp_json_encode($value));
        }
    }

    /**
     * Resolve Skwirrel translations + SEO to a map keyed by Polylang language slug.
     *
     * Skwirrel keeps content translations (name, description) in
     * _product_translations and SEO (seo_title, seo_description, seo_url) in a
     * separate _product_seo array. Both are per-language. We merge them into
     * one object per language so writePost / writeSeoMeta can read everything
     * from a single $translation.
     *
     * @param array<string, mixed> $product
     * @return array<string, array<string, mixed>> slug => merged translation payload
     */
    private function resolveTranslationsByLang(array $product): array
    {
        $byLang = [];
        $merge = static function (array &$byLang, mixed $entries, callable $slugFor): void {
            if (!is_array($entries)) {
                return;
            }
            foreach ($entries as $key => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $slug = $slugFor($entry, $key);
                if ($slug === null) {
                    continue;
                }
                $byLang[$slug] = array_merge($byLang[$slug] ?? [], $entry);
            }
        };

        $slugFor = fn (array $entry, mixed $key): ?string => $this->slugForEntry($entry, $key);
        $merge($byLang, $product['_product_translations'] ?? $product['translations'] ?? null, $slugFor);
        $merge($byLang, $product['_product_seo'] ?? null, $slugFor);

        // No usable translations (Gavilar's current state) — one post in the default language.
        if (empty($byLang)) {
            $byLang[$this->polylang->defaultLanguage()] = [];
        }

        return $byLang;
    }

    /**
     * Map a per-language Skwirrel record (translation/SEO) to a Polylang slug.
     * Skwirrel may carry the language as a code string, as a numeric context_id,
     * or fall back to the array key from the parent payload.
     *
     * @param array<string, mixed> $entry
     */
    private function slugForEntry(array $entry, mixed $key): ?string
    {
        foreach (['language', 'language_code', 'locale', 'code'] as $field) {
            if (!empty($entry[$field])) {
                $slug = $this->polylang->resolveSlug((string) $entry[$field]);
                if ($slug !== null) {
                    return $slug;
                }
            }
        }
        if (isset($entry['context_id'])) {
            $slug = $this->polylang->resolveSlug((string) $entry['context_id']);
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
     * @return array<string, int> language slug => post ID
     */
    private function findExistingPostsBySkwirrelId(int $skwirrelId): array
    {
        $query = new \WP_Query([
            'post_type' => ProductPostType::SLUG,
            'post_status' => 'any',
            'posts_per_page' => 50,
            'fields' => 'ids',
            'no_found_rows' => true,
            'lang' => '', // Polylang: return posts in all languages.
            'meta_query' => [
                ['key' => self::META_SKWIRREL_ID, 'value' => $skwirrelId],
            ],
        ]);

        $byLang = [];
        foreach ($query->posts as $id) {
            $postId = (int) $id;
            $lang = $this->polylang->getPostLanguage($postId) ?? $this->polylang->defaultLanguage();
            if (!isset($byLang[$lang])) {
                $byLang[$lang] = $postId;
            }
        }
        return $byLang;
    }

    /**
     * The product's _categories array only references categories by id; names and
     * hierarchy come from the getCategories index.
     *
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $categoriesById
     */
    private function applyCategoriesForLang(int $postId, string $langSlug, array $product, array $categoriesById): void
    {
        $categoryRefs = (array) ($product['_categories'] ?? $product['categories'] ?? []);
        $termIds = [];

        foreach ($categoryRefs as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $catId = (int) ($ref['product_category_id'] ?? $ref['category_id'] ?? $ref['id'] ?? 0);
            if ($catId <= 0 || !isset($categoriesById[$catId])) {
                continue;
            }
            $idsByLang = $this->categoryMapper->upsert($categoriesById[$catId], $categoriesById);
            $termId = $idsByLang[$langSlug] ?? $idsByLang[$this->polylang->defaultLanguage()] ?? 0;
            if ($termId > 0) {
                $termIds[] = $termId;
            }
        }

        wp_set_object_terms($postId, array_values(array_unique($termIds)), CategoryTaxonomy::SLUG);
    }

    /**
     * @param array{featured:int|null,gallery:int[],documents:array<int,array{id:int,label:string}>} $shared
     */
    private function reuseAttachmentTargets(int $postId, array $shared): void
    {
        if (!empty($shared['featured'])) {
            set_post_thumbnail($postId, $shared['featured']);
        }
        update_post_meta($postId, '_pim_gallery_ids', $shared['gallery']);
        update_post_meta($postId, '_pim_documents', $shared['documents']);
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $translation
     */
    private function buildSlug(array $product, array $translation, string $title, string $langSlug): string
    {
        $source = (string) ($translation['seo_url'] ?? $translation['slug'] ?? '');
        if ($source === '') {
            // Name-based slug for SEO, with the Skwirrel product id appended so
            // products with an identical (ERP) name still get a unique URL.
            $productId = (int) ($product['product_id'] ?? 0);
            $base = sanitize_title($title);
            $source = $base !== '' ? $base . '-' . $productId : (string) $productId;
        }
        $slug = sanitize_title($source);
        // Avoid cross-language slug collisions when Polylang shared slugs is off.
        if ($langSlug !== $this->polylang->defaultLanguage()) {
            $slug .= '-' . $langSlug;
        }
        return $slug;
    }

    /** @param array<string, mixed> $translation */
    private function writeSeoMeta(int $postId, array $translation): void
    {
        $seoTitle = (string) ($translation['seo_title'] ?? '');
        $seoDesc = (string) ($translation['seo_description'] ?? '');
        $seoKeywords = (string) ($translation['seo_keywords'] ?? '');

        if ($seoTitle !== '') {
            update_post_meta($postId, '_yoast_wpseo_title', $seoTitle);
            update_post_meta($postId, '_yoast_wpseo_opengraph-title', $seoTitle);
        }
        if ($seoDesc !== '') {
            update_post_meta($postId, '_yoast_wpseo_metadesc', $seoDesc);
            update_post_meta($postId, '_yoast_wpseo_opengraph-description', $seoDesc);
        }
        if ($seoKeywords !== '') {
            $first = trim((string) (explode(',', $seoKeywords)[0] ?? ''));
            if ($first !== '') {
                update_post_meta($postId, '_yoast_wpseo_focuskw', $first);
            }
        }
    }
}
