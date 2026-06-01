<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Sync;

use JijOnline\SkwirrelGavilar\Api\Client;
use JijOnline\SkwirrelGavilar\Cpt\ProductPostType;
use JijOnline\SkwirrelGavilar\I18n\Polylang;
use JijOnline\SkwirrelGavilar\Mapping\ProductMapper;
use JijOnline\SkwirrelGavilar\Support\Logger;
use JijOnline\SkwirrelGavilar\Support\Settings;

final class SyncCoordinator
{
    public const MODE_DELTA = 'delta';
    public const MODE_FULL = 'full';

    // Small page size: each page is processed in one request (an admin AJAX
    // step, or one cron iteration) and includes inline image downloads, so a
    // page must finish well within typical PHP/web-server timeouts.
    private const PAGE_SIZE = 15;

    /** @var array<int, array<string, mixed>>|null Memoised getCategories index for the current run. */
    private ?array $categoryIndex = null;

    public function __construct(
        private readonly Client $client,
        private readonly ProductMapper $productMapper,
        private readonly Settings $settings,
        private readonly Polylang $polylang,
        private readonly Logger $logger,
    ) {}

    /**
     * Daily delta sync. Reads cursor from settings, processes everything updated since.
     *
     * @return array{run_id:string, processed:int, created:int, updated:int, errors:int}
     */
    public function run(): array
    {
        if (!$this->settings->isConfigured()) {
            $this->logger->info('Skipping sync — plugin is not configured.');
            return ['run_id' => '', 'processed' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0];
        }

        $since = $this->settings->lastSyncedAt() ?? $this->initialDelta();
        $runStartedUtc = gmdate('Y-m-d H:i:s');
        $totals = $this->runWithSince($since, self::MODE_DELTA);

        if ($totals['errors'] === 0) {
            $this->settings->setLastSyncedAt($runStartedUtc);
        }
        return $totals;
    }

    /**
     * Run a full resync end-to-end in a single PHP process (suitable for WP-CLI).
     *
     * @return array{run_id:string, processed:int, created:int, updated:int, errors:int}
     */
    public function runFull(): array
    {
        if (!$this->settings->isConfigured()) {
            throw new \RuntimeException('Plugin is not configured.');
        }
        return $this->runWithSince(null, self::MODE_FULL, /* finalize: */ true);
    }

    /**
     * Process one page of a multi-step full resync. Returns the updated state.
     */
    public function runFullPage(string $runId, int $page): array
    {
        if (!$this->settings->isConfigured()) {
            throw new \RuntimeException('Plugin is not configured.');
        }

        return $this->fetchAndApplyPage(null, $page, $runId, self::MODE_FULL);
    }

    /**
     * Soft-delete (trash) any pim_product whose _skwirrel_last_run_id is not the given runId.
     * Only safe to call at the end of a *full* run — delta runs only update changed products.
     *
     * @return int Number of trashed posts.
     */
    public function finalizeFullRun(string $runId): int
    {
        $query = new \WP_Query([
            'post_type' => ProductPostType::SLUG,
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'lang' => '',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => ProductMapper::META_LAST_RUN_ID,
                    'value' => $runId,
                    'compare' => '!=',
                ],
                [
                    'key' => ProductMapper::META_LAST_RUN_ID,
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        $trashed = 0;
        foreach ($query->posts as $id) {
            if (wp_trash_post((int) $id)) {
                $trashed++;
            }
        }
        return $trashed;
    }

    /**
     * @return array{run_id:string, processed:int, created:int, updated:int, errors:int, trashed?:int}
     */
    private function runWithSince(?string $since, string $mode, bool $finalize = false): array
    {
        $runId = wp_generate_uuid4();
        $this->logger->startRun($runId, $mode);

        $page = 1;
        $totals = ['processed' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0];

        do {
            $result = $this->fetchAndApplyPage($since, $page, $runId, $mode);
            $totals['processed'] += $result['processed'];
            $totals['created'] += $result['created'];
            $totals['updated'] += $result['updated'];
            $totals['errors'] += $result['errors'];
            $page = $result['next_page'] ?? 0;
        } while ($page > 0);

        if ($finalize && $mode === self::MODE_FULL && $totals['errors'] === 0) {
            $totals['trashed'] = $this->finalizeFullRun($runId);
        }

        $this->logger->finishRun($runId, $totals['errors'] > 0 ? 'completed_with_errors' : 'completed', $totals);

        return $totals + ['run_id' => $runId];
    }

    /**
     * @return array{run_id:string, processed:int, created:int, updated:int, errors:int, next_page:?int}
     */
    private function fetchAndApplyPage(?string $since, int $page, string $runId, string $mode): array
    {
        // include_languages is a string[] of language codes (e.g. ["nl","en"]),
        // not a boolean — and the translation / SEO flags require it. Send the
        // full Polylang language list so every configured locale comes back.
        $languages = $this->polylang->languages();

        $params = [
            'page' => $page,
            'limit' => self::PAGE_SIZE,
            'include_product_status' => true,
            'include_categories' => true,
            'include_product_translations' => true,
            'include_product_seo' => true,
            'include_attachments' => true,
            'include_languages' => $languages,
        ];

        // Optional gating filter — Gavilar gates by status, not a selection.
        $selectionId = $this->settings->dynamicSelectionId();
        if ($selectionId !== null) {
            $params['dynamic_selection_id'] = $selectionId;
        }

        if ($since !== null) {
            $params['updated_on'] = ['>=' => $since];
        }

        $result = $this->client->call('getProducts', $params);
        $rawProducts = (is_array($result) && isset($result['products']) && is_array($result['products']))
            ? $result['products']
            : [];

        // Pagination from the response's page object (number_of_pages / current_page).
        $pageInfo = (is_array($result) && isset($result['page']) && is_array($result['page'])) ? $result['page'] : [];
        $currentPage = (int) ($pageInfo['current_page'] ?? $page);
        $totalPages = (int) ($pageInfo['number_of_pages'] ?? $currentPage);
        $hasMore = $currentPage < $totalPages;

        $products = $this->filterByStatus($rawProducts);
        $categoriesById = $this->categoryIndex();

        $processed = $created = $updated = $errors = 0;
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            try {
                $r = $this->productMapper->upsert($product, $runId, $categoriesById);
                $processed++;
                $created += (int) ($r['created'] ?? 0);
                $updated += (int) ($r['updated'] ?? 0);
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('Product upsert failed', [
                    'product_id' => $product['product_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'run_id' => $runId,
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
            'next_page' => $hasMore ? $page + 1 : null,
        ];
    }

    /**
     * Keep only products whose status matches the configured value (e.g. "available").
     * Empty setting = no filtering. Matching is tolerant: the configured string is
     * compared case-insensitively against the status id, code, name and label,
     * whether the status is a scalar or a nested object.
     *
     * @param array<mixed> $products
     * @return array<int, mixed>
     */
    private function filterByStatus(array $products): array
    {
        $wanted = strtolower($this->settings->productStatus());
        if ($wanted === '') {
            return array_values($products);
        }

        return array_values(array_filter($products, function ($product) use ($wanted): bool {
            if (!is_array($product)) {
                return false;
            }
            return in_array($wanted, $this->statusCandidates($product), true);
        }));
    }

    /**
     * @param array<string, mixed> $product
     * @return string[] lower-cased status values found on the product
     */
    private function statusCandidates(array $product): array
    {
        $values = [];
        $status = $product['_product_status'] ?? $product['product_status'] ?? $product['status'] ?? null;
        if (is_array($status)) {
            $subKeys = [
                'product_status_id', 'product_status_description', 'product_status_internal',
                'id', 'code', 'name', 'description', 'internal', 'label', 'value',
            ];
            foreach ($subKeys as $sub) {
                if (isset($status[$sub]) && is_scalar($status[$sub])) {
                    $values[] = strtolower((string) $status[$sub]);
                }
            }
        } elseif (is_scalar($status)) {
            $values[] = strtolower((string) $status);
        }
        return $values;
    }

    /**
     * Fetch the full category tree once per run and index it by category id.
     * The getProducts response only references categories by id, so names and
     * hierarchy must come from getCategories.
     *
     * @return array<int, array<string, mixed>>
     */
    private function categoryIndex(): array
    {
        if ($this->categoryIndex !== null) {
            return $this->categoryIndex;
        }

        try {
            $result = $this->client->call('getCategories', [
                'page' => 1,
                'limit' => 1000,
                // Names + SEO live in translations and both flags require
                // include_languages to be present.
                'include_category_translations' => true,
                'include_category_seo' => true,
                'include_languages' => $this->polylang->languages(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('getCategories failed', ['error' => $e->getMessage()]);
            return $this->categoryIndex = [];
        }

        $list = [];
        if (is_array($result)) {
            $list = $result['categories'] ?? $result['_categories'] ?? $result;
        }
        if (!is_array($list)) {
            $list = [];
        }

        $byId = [];
        foreach ($list as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $id = (int) ($cat['product_category_id'] ?? $cat['category_id'] ?? $cat['id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = $cat;
            }
        }
        return $this->categoryIndex = $byId;
    }

    private function initialDelta(): string
    {
        // First-ever delta with no cursor: pull last 24h so we don't accidentally trigger a full resync via the daily hook.
        return gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
    }
}
