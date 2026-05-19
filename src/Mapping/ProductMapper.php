<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Mapping;

use JijOnline\SkwirrelGavilar\Cpt\ProductPostType;
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
        private readonly Logger $logger,
    ) {}

    /**
     * Upsert one product (single-locale Phase 1 — first translation only).
     *
     * @param array<string, mixed> $product Skwirrel product payload.
     * @param array<int, array<string, mixed>> $categoriesById Skwirrel categories indexed by category_id.
     * @return array{id:int, created:bool}
     */
    public function upsert(array $product, string $runId, array $categoriesById = []): array
    {
        $skwirrelId = (int) ($product['product_id'] ?? 0);
        if ($skwirrelId <= 0) {
            throw new \InvalidArgumentException('Product is missing product_id.');
        }

        $translation = $this->pickPrimaryTranslation($product);
        $title = (string) ($translation['name'] ?? $product['name'] ?? sprintf('Product %d', $skwirrelId));
        $description = (string) ($translation['description'] ?? '');
        $excerpt = (string) ($translation['short_description'] ?? '');
        $slug = sanitize_title((string) ($translation['seo_url'] ?? $title));

        $existingId = $this->findPostBySkwirrelId($skwirrelId);
        $created = ($existingId === null);

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
            throw new \RuntimeException("Failed to upsert product {$skwirrelId}: {$msg}");
        }

        update_post_meta($postId, self::META_SKWIRREL_ID, $skwirrelId);
        if (!empty($product['external_product_id'])) {
            update_post_meta($postId, self::META_EXTERNAL_ID, (string) $product['external_product_id']);
        }
        if (!empty($product['updated_on'])) {
            update_post_meta($postId, self::META_UPDATED_ON, (string) $product['updated_on']);
        }
        update_post_meta($postId, self::META_LAST_RUN_ID, $runId);

        $this->applyCategories($postId, $product, $categoriesById);
        $this->featureMapper->apply($postId, (array) ($product['custom_classes'] ?? []));
        $this->writeSeoMeta($postId, $translation);

        try {
            $this->attachmentMapper->sync($postId, (array) ($product['attachments'] ?? []));
        } catch (\Throwable $e) {
            $this->logger->error('Attachment sync failed', ['product_id' => $skwirrelId, 'error' => $e->getMessage()]);
        }

        return ['id' => $postId, 'created' => $created];
    }

    private function findPostBySkwirrelId(int $skwirrelId): ?int
    {
        $query = new \WP_Query([
            'post_type' => ProductPostType::SLUG,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                ['key' => self::META_SKWIRREL_ID, 'value' => $skwirrelId],
            ],
        ]);
        return !empty($query->posts) ? (int) $query->posts[0] : null;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function pickPrimaryTranslation(array $product): array
    {
        $translations = (array) ($product['translations'] ?? []);
        if (empty($translations)) {
            return $product;
        }
        $first = reset($translations);
        return is_array($first) ? $first : $product;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $categoriesById
     */
    private function applyCategories(int $postId, array $product, array $categoriesById): void
    {
        $categories = (array) ($product['categories'] ?? []);
        $termIds = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }
            $termId = $this->categoryMapper->upsert($category, $categoriesById);
            if ($termId > 0) {
                $termIds[] = $termId;
            }
        }
        wp_set_object_terms($postId, array_values(array_unique($termIds)), \JijOnline\SkwirrelGavilar\Cpt\CategoryTaxonomy::SLUG);
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
