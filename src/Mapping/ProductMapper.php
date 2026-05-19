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

    public function __construct(
        private readonly CategoryMapper $categoryMapper,
        private readonly FeatureMapper $featureMapper,
        private readonly AttachmentMapper $attachmentMapper,
        private readonly Polylang $polylang,
        private readonly Logger $logger,
    ) {}

    /**
     * Upsert one Skwirrel product as N posts (one per Polylang language), linked as translations.
     *
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $categoriesById
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

        // Sync attachments once (media is language-agnostic in WP).
        $attachments = (array) ($product['attachments'] ?? []);
        $sharedAttachmentTargets = null;

        $postIdsByLang = [];
        $created = $updated = 0;

        foreach ($this->polylang->languages() as $slug) {
            $translation = $translationsByLang[$slug]
                ?? $translationsByLang[$this->polylang->defaultLanguage()]
                ?? $product;

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
            $this->featureMapper->apply($postId, (array) ($product['custom_classes'] ?? []));
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
        $title = (string) ($translation['name'] ?? $product['name'] ?? sprintf('Product %d', $skwirrelId));
        $description = (string) ($translation['description'] ?? '');
        $excerpt = (string) ($translation['short_description'] ?? '');
        $slug = $this->buildSlug($translation, $title, $langSlug);

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
        if (!empty($product['updated_on'])) {
            update_post_meta($postId, self::META_UPDATED_ON, (string) $product['updated_on']);
        }
        update_post_meta($postId, self::META_LAST_RUN_ID, $runId);

        return $postId;
    }

    /**
     * Resolve Skwirrel translations to a map keyed by Polylang language slug.
     *
     * @param array<string, mixed> $product
     * @return array<string, array<string, mixed>> slug => translation payload
     */
    private function resolveTranslationsByLang(array $product): array
    {
        $byLang = [];
        $translations = $product['translations'] ?? [];

        if (!is_array($translations) || empty($translations)) {
            $byLang[$this->polylang->defaultLanguage()] = $product;
            return $byLang;
        }

        foreach ($translations as $localeKey => $translation) {
            if (!is_array($translation)) {
                continue;
            }
            $localeCode = (string) ($translation['locale'] ?? $translation['context'] ?? $localeKey);
            $slug = $this->polylang->resolveSlug($localeCode);
            if ($slug === null) {
                continue;
            }
            $byLang[$slug] = $translation;
        }

        if (empty($byLang)) {
            $byLang[$this->polylang->defaultLanguage()] = $product;
        }

        return $byLang;
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
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $categoriesById
     */
    private function applyCategoriesForLang(int $postId, string $langSlug, array $product, array $categoriesById): void
    {
        $categories = (array) ($product['categories'] ?? []);
        $termIds = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }
            $idsByLang = $this->categoryMapper->upsert($category, $categoriesById);
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

    /** @param array<string, mixed> $translation */
    private function buildSlug(array $translation, string $title, string $langSlug): string
    {
        $source = (string) ($translation['seo_url'] ?? $translation['slug'] ?? $title);
        $slug = sanitize_title($source);
        // Avoid cross-language slug collisions when Polylang shared slugs is off.
        if ($langSlug !== $this->polylang->defaultLanguage()) {
            $slug = $slug . '-' . $langSlug;
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
