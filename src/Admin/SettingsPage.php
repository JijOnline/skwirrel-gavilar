<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Admin;

use JijOnline\SkwirrelGavilar\Api\Client;
use JijOnline\SkwirrelGavilar\I18n\Polylang;
use JijOnline\SkwirrelGavilar\Support\Settings;
use JijOnline\SkwirrelGavilar\Sync\FullResyncState;
use JijOnline\SkwirrelGavilar\Sync\SyncCoordinator;

final class SettingsPage
{
    private const PAGE_SLUG = 'skwirrel-gavilar';
    private const OPTION_GROUP = 'skwirrel_gavilar_settings';
    private const NONCE_ACTION = 'skwirrel_gavilar_admin';

    private const SECRET_PLACEHOLDER = '__keep_existing__';

    public function __construct(
        private readonly Client $client,
        private readonly SyncCoordinator $coordinator,
        private readonly Polylang $polylang,
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_notices', [$this, 'maybeRenderNotice']);
        add_action('admin_post_skwirrel_gavilar_test_connection', [$this, 'handleTestConnection']);
        add_action('admin_post_skwirrel_gavilar_sync_now', [$this, 'handleSyncNow']);
        add_action('admin_post_skwirrel_gavilar_detect_locales', [$this, 'handleDetectLocales']);
        add_action('admin_post_skwirrel_gavilar_save_locale_map', [$this, 'handleSaveLocaleMap']);
        add_action('admin_post_skwirrel_gavilar_reset_cursor', [$this, 'handleResetCursor']);
        add_action('admin_post_skwirrel_gavilar_list_selections', [$this, 'handleListSelections']);
    }

    public function maybeRenderNotice(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }
        $notice = get_transient('skwirrel_gavilar_admin_notice');
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient('skwirrel_gavilar_admin_notice');
        $class = $notice['type'] === 'error' ? 'notice notice-error' : 'notice notice-success';
        printf('<div class="%s"><p>%s</p></div>', esc_attr($class), esc_html((string) $notice['message']));
    }

    public function addMenu(): void
    {
        add_options_page(
            __('Skwirrel Sync', 'skwirrel-gavilar'),
            __('Skwirrel Sync', 'skwirrel-gavilar'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render'],
        );
    }

    public function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, Settings::OPT_TOKEN_URL, ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeUrl']]);
        register_setting(self::OPTION_GROUP, Settings::OPT_API_URL, ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeUrl']]);
        register_setting(self::OPTION_GROUP, Settings::OPT_CLIENT_ID, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting(self::OPTION_GROUP, Settings::OPT_DYNAMIC_SELECTION_ID, ['type' => 'integer', 'sanitize_callback' => 'absint']);

        register_setting(self::OPTION_GROUP, Settings::OPT_CLIENT_SECRET, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitizeClientSecret'],
        ]);
    }

    public static function sanitizeUrl(mixed $value): string
    {
        // Trim *before* esc_url_raw so a stray trailing space isn't turned into "%20".
        return esc_url_raw(Settings::cleanUrl((string) $value));
    }

    public static function sanitizeClientSecret(mixed $value): string
    {
        $value = (string) $value;
        if ($value === self::SECRET_PLACEHOLDER) {
            return (string) get_option(Settings::OPT_CLIENT_SECRET, '');
        }
        if ($value === '') {
            return '';
        }
        return \JijOnline\SkwirrelGavilar\Support\Encryption::encrypt($value);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = new Settings();
        $hasSecret = $settings->clientSecret() !== '';
        $lastSynced = $settings->lastSyncedAt();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Skwirrel Sync for Gavilar', 'skwirrel-gavilar'); ?></h1>

            <?php if (!$this->polylang->isActive()): ?>
                <div class="notice notice-warning"><p><strong><?php esc_html_e('Polylang is not active.', 'skwirrel-gavilar'); ?></strong> <?php esc_html_e('The sync will fall back to a single-language mode (default WP locale). Activate Polylang (Free or Pro) and configure your site languages to enable multilingual sync.', 'skwirrel-gavilar'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="<?php echo esc_attr(Settings::OPT_TOKEN_URL); ?>"><?php esc_html_e('OAuth2 token URL', 'skwirrel-gavilar'); ?></label></th>
                        <td><input type="url" class="regular-text code" id="<?php echo esc_attr(Settings::OPT_TOKEN_URL); ?>" name="<?php echo esc_attr(Settings::OPT_TOKEN_URL); ?>" value="<?php echo esc_attr($settings->tokenUrl()); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo esc_attr(Settings::OPT_API_URL); ?>"><?php esc_html_e('API URL', 'skwirrel-gavilar'); ?></label></th>
                        <td>
                            <input type="url" class="regular-text code" id="<?php echo esc_attr(Settings::OPT_API_URL); ?>" name="<?php echo esc_attr(Settings::OPT_API_URL); ?>" value="<?php echo esc_attr($settings->apiUrl()); ?>">
                            <p class="description"><?php esc_html_e('JSON-RPC endpoint, e.g. https://example.skwirrel.eu/jsonrpc', 'skwirrel-gavilar'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo esc_attr(Settings::OPT_CLIENT_ID); ?>"><?php esc_html_e('Client ID', 'skwirrel-gavilar'); ?></label></th>
                        <td><input type="text" class="regular-text" id="<?php echo esc_attr(Settings::OPT_CLIENT_ID); ?>" name="<?php echo esc_attr(Settings::OPT_CLIENT_ID); ?>" value="<?php echo esc_attr($settings->clientId()); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo esc_attr(Settings::OPT_CLIENT_SECRET); ?>"><?php esc_html_e('Client secret', 'skwirrel-gavilar'); ?></label></th>
                        <td>
                            <input type="password" class="regular-text" id="<?php echo esc_attr(Settings::OPT_CLIENT_SECRET); ?>" name="<?php echo esc_attr(Settings::OPT_CLIENT_SECRET); ?>" value="<?php echo $hasSecret ? esc_attr(self::SECRET_PLACEHOLDER) : ''; ?>" autocomplete="new-password">
                            <p class="description"><?php $hasSecret ? esc_html_e('A secret is stored (encrypted at rest). Leave the placeholder to keep it, or paste a new one to replace.', 'skwirrel-gavilar') : esc_html_e('Paste the client secret from Skwirrel → Data → Web Services.', 'skwirrel-gavilar'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo esc_attr(Settings::OPT_DYNAMIC_SELECTION_ID); ?>"><?php esc_html_e('Dynamic selection ID', 'skwirrel-gavilar'); ?></label></th>
                        <td>
                            <input type="number" min="0" id="<?php echo esc_attr(Settings::OPT_DYNAMIC_SELECTION_ID); ?>" name="<?php echo esc_attr(Settings::OPT_DYNAMIC_SELECTION_ID); ?>" value="<?php echo esc_attr((string) $settings->dynamicSelectionId()); ?>">
                            <p class="description"><?php esc_html_e('The Skwirrel selection that gates which products sync to this site. Click "List selections" below after entering credentials to discover the ID.', 'skwirrel-gavilar'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:-1em; margin-bottom:1em;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="skwirrel_gavilar_list_selections">
                <?php submit_button(__('List selections', 'skwirrel-gavilar'), 'secondary small', 'submit', false); ?>
            </form>

            <?php $this->renderSelectionsCache(); ?>

            <hr>
            <h2><?php esc_html_e('Locale mapping', 'skwirrel-gavilar'); ?></h2>
            <p class="description"><?php esc_html_e('Maps Skwirrel locale codes (e.g. nl_NL) to Polylang language slugs (e.g. nl). Use "Auto-detect locales" to pre-fill from a live API call, then adjust if needed.', 'skwirrel-gavilar'); ?></p>

            <?php $this->renderLocaleMap($settings); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:1em;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="skwirrel_gavilar_detect_locales">
                <?php submit_button(__('Auto-detect locales', 'skwirrel-gavilar'), 'secondary', 'submit', false); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Sync', 'skwirrel-gavilar'); ?></h2>
            <p>
                <?php if ($lastSynced): ?>
                    <?php printf(esc_html__('Last synced at %s (UTC).', 'skwirrel-gavilar'), esc_html($lastSynced)); ?>
                <?php else: ?>
                    <?php esc_html_e('No successful sync yet.', 'skwirrel-gavilar'); ?>
                <?php endif; ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:1em;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="skwirrel_gavilar_test_connection">
                <?php submit_button(__('Test connection', 'skwirrel-gavilar'), 'secondary', 'submit', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:1em;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="skwirrel_gavilar_sync_now">
                <?php submit_button(__('Sync now (delta)', 'skwirrel-gavilar'), 'primary', 'submit', false); ?>
            </form>

            <button type="button" class="button button-secondary" id="skwirrel-gavilar-full-resync"><?php esc_html_e('Full resync', 'skwirrel-gavilar'); ?></button>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-left:1em;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="skwirrel_gavilar_reset_cursor">
                <?php submit_button(__('Reset delta cursor', 'skwirrel-gavilar'), 'link-delete', 'submit', false); ?>
            </form>

            <div id="skwirrel-gavilar-resync-status" style="margin-top:1em; display:none; padding:1em; background:#f6f7f7; border-left:4px solid #2271b1;"></div>

            <?php $this->renderResyncScript(); ?>

            <hr>
            <h2><?php esc_html_e('Recent runs', 'skwirrel-gavilar'); ?></h2>
            <?php $this->renderLogTable(); ?>
        </div>
        <?php
    }

    private function renderLogTable(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'skwirrel_sync_log';
        $rows = $wpdb->get_results(
            "SELECT started_at, finished_at, mode, status, products_processed, products_created, products_updated, errors, message
             FROM {$table} ORDER BY id DESC LIMIT 20",
            ARRAY_A
        );

        if (empty($rows)) {
            echo '<p><em>' . esc_html__('No sync runs recorded yet.', 'skwirrel-gavilar') . '</em></p>';
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:1100px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Started (UTC)', 'skwirrel-gavilar'); ?></th>
                    <th><?php esc_html_e('Duration', 'skwirrel-gavilar'); ?></th>
                    <th><?php esc_html_e('Mode', 'skwirrel-gavilar'); ?></th>
                    <th><?php esc_html_e('Status', 'skwirrel-gavilar'); ?></th>
                    <th><?php esc_html_e('Processed', 'skwirrel-gavilar'); ?></th>
                    <th><?php esc_html_e('Created', 'skwirrel-gavilar'); ?></th>
                    <th><?php esc_html_e('Updated', 'skwirrel-gavilar'); ?></th>
                    <th><?php esc_html_e('Errors', 'skwirrel-gavilar'); ?></th>
                    <th><?php esc_html_e('Message', 'skwirrel-gavilar'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html((string) $row['started_at']); ?></td>
                        <td><?php echo esc_html($this->formatDuration((string) $row['started_at'], (string) ($row['finished_at'] ?? ''))); ?></td>
                        <td><?php echo esc_html((string) $row['mode']); ?></td>
                        <td><?php echo $this->statusBadge((string) $row['status']); ?></td>
                        <td><?php echo (int) $row['products_processed']; ?></td>
                        <td><?php echo (int) $row['products_created']; ?></td>
                        <td><?php echo (int) $row['products_updated']; ?></td>
                        <td><?php echo (int) $row['errors']; ?></td>
                        <td><?php echo esc_html((string) ($row['message'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function formatDuration(string $startedAt, string $finishedAt): string
    {
        if ($startedAt === '' || $finishedAt === '') {
            return '—';
        }
        $start = strtotime($startedAt . ' UTC');
        $end = strtotime($finishedAt . ' UTC');
        if (!$start || !$end || $end < $start) {
            return '—';
        }
        $seconds = $end - $start;
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $minutes = intdiv($seconds, 60);
        $remainder = $seconds % 60;
        return sprintf('%dm %ds', $minutes, $remainder);
    }

    private function statusBadge(string $status): string
    {
        $colour = match ($status) {
            'completed' => '#46b450',
            'completed_with_errors' => '#dba617',
            'running' => '#2271b1',
            'failed' => '#d63638',
            default => '#999',
        };
        return sprintf(
            '<span style="display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;background:%s;font-size:11px;">%s</span>',
            esc_attr($colour),
            esc_html($status)
        );
    }

    private function renderResyncScript(): void
    {
        $ajaxUrl = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce(\JijOnline\SkwirrelGavilar\Admin\FullResyncController::NONCE_ACTION);
        $state = FullResyncState::load()->toArray();
        $confirm = __('A full resync re-fetches every product. Trashed posts will be cleaned up at the end. Continue?', 'skwirrel-gavilar');
        $running = __('Running… page %page%, processed %processed% (created %created%, updated %updated%, errors %errors%)', 'skwirrel-gavilar');
        $done = __('Completed. Processed %processed% (created %created%, updated %updated%, errors %errors%). %message%', 'skwirrel-gavilar');
        $failed = __('Failed: %message%', 'skwirrel-gavilar');
        ?>
        <script>
        (function () {
            const ajaxUrl = <?php echo wp_json_encode($ajaxUrl); ?>;
            const nonce = <?php echo wp_json_encode($nonce); ?>;
            const initialState = <?php echo wp_json_encode($state); ?>;
            const i18n = {
                confirm: <?php echo wp_json_encode($confirm); ?>,
                running: <?php echo wp_json_encode($running); ?>,
                done: <?php echo wp_json_encode($done); ?>,
                failed: <?php echo wp_json_encode($failed); ?>,
            };

            const $button = document.getElementById('skwirrel-gavilar-full-resync');
            const $status = document.getElementById('skwirrel-gavilar-resync-status');

            function render(state) {
                if (!state || state.status === 'idle') {
                    $status.style.display = 'none';
                    $button.disabled = false;
                    return;
                }
                let template = i18n.running;
                if (state.status === 'completed') template = i18n.done;
                if (state.status === 'failed') template = i18n.failed;
                const msg = template
                    .replace('%page%', state.page)
                    .replace('%processed%', state.processed)
                    .replace('%created%', state.created)
                    .replace('%updated%', state.updated)
                    .replace('%errors%', state.errors)
                    .replace('%message%', state.message || '');
                $status.textContent = msg;
                $status.style.display = 'block';
                $button.disabled = state.status === 'running';
            }

            async function post(action) {
                const body = new URLSearchParams({ action, nonce });
                const res = await fetch(ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
                const json = await res.json();
                if (!json.success) {
                    throw new Error((json.data && json.data.message) || 'request failed');
                }
                return json.data.state;
            }

            async function stepLoop() {
                try {
                    let state = await post('skwirrel_gavilar_full_resync_step');
                    render(state);
                    if (state.status === 'running') {
                        setTimeout(stepLoop, 250);
                    }
                } catch (e) {
                    render({ status: 'failed', message: e.message });
                }
            }

            $button.addEventListener('click', async function () {
                if (!confirm(i18n.confirm)) return;
                try {
                    const state = await post('skwirrel_gavilar_full_resync_start');
                    render(state);
                    setTimeout(stepLoop, 100);
                } catch (e) {
                    render({ status: 'failed', message: e.message });
                }
            });

            // Resume display if a run was already in flight when the page loaded.
            if (initialState && initialState.status === 'running') {
                render(initialState);
                setTimeout(stepLoop, 100);
            } else if (initialState && initialState.status !== 'idle') {
                render(initialState);
            }
        })();
        </script>
        <?php
    }

    private function renderSelectionsCache(): void
    {
        $cache = get_transient('skwirrel_gavilar_selections_cache');
        if (!is_array($cache) || empty($cache['selections'])) {
            return;
        }
        ?>
        <div style="padding:1em; background:#f6f7f7; border-left:4px solid #2271b1; max-width:600px; margin-bottom:1em;">
            <p style="margin-top:0;"><strong><?php esc_html_e('Selections discovered', 'skwirrel-gavilar'); ?></strong>
                <small style="color:#777;">(<?php echo esc_html((string) ($cache['method'] ?? '')); ?>)</small>
            </p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'skwirrel-gavilar'); ?></th>
                        <th><?php esc_html_e('Name', 'skwirrel-gavilar'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ((array) $cache['selections'] as $sel): ?>
                        <tr>
                            <td><code><?php echo (int) ($sel['id'] ?? 0); ?></code></td>
                            <td><?php echo esc_html((string) ($sel['name'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description" style="margin-bottom:0;"><?php esc_html_e('Paste the ID into "Dynamic selection ID" above and save. Cached for 5 minutes.', 'skwirrel-gavilar'); ?></p>
        </div>
        <?php
    }

    private function renderLocaleMap(Settings $settings): void
    {
        $map = $settings->localeMap();
        $polylangActive = $this->polylang->isActive();
        $polylangSlugs = $polylangActive ? $this->polylang->languages() : [];

        if (empty($map)) {
            echo '<p><em>' . esc_html__('No locale mapping yet. Click "Auto-detect locales" to populate it.', 'skwirrel-gavilar') . '</em></p>';
            return;
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(self::NONCE_ACTION); ?>
            <input type="hidden" name="action" value="skwirrel_gavilar_save_locale_map">
            <table class="widefat striped" style="max-width:600px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Skwirrel locale code', 'skwirrel-gavilar'); ?></th>
                        <th><?php esc_html_e('Polylang language slug', 'skwirrel-gavilar'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($map as $skwirrelCode => $pllSlug): ?>
                        <tr>
                            <td><code><?php echo esc_html($skwirrelCode); ?></code></td>
                            <td>
                                <?php if ($polylangActive): ?>
                                    <select name="locale_map[<?php echo esc_attr($skwirrelCode); ?>]">
                                        <option value="">— <?php esc_html_e('Skip', 'skwirrel-gavilar'); ?> —</option>
                                        <?php foreach ($polylangSlugs as $slug): ?>
                                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($pllSlug, $slug); ?>><?php echo esc_html($slug); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="locale_map[<?php echo esc_attr($skwirrelCode); ?>]" value="<?php echo esc_attr($pllSlug); ?>">
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button(__('Save locale mapping', 'skwirrel-gavilar'), 'secondary'); ?>
        </form>
        <?php
    }

    public function handleTestConnection(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);

        try {
            $result = $this->client->call('getCategories', ['page' => 1, 'limit' => 1]);
            $categories = is_array($result) ? ($result['categories'] ?? $result) : [];
            $count = is_array($categories) ? count($categories) : 0;
            $notice = sprintf(
                /* translators: %d: number of categories returned */
                __('Connection OK. Sample response contains %d category record(s).', 'skwirrel-gavilar'),
                $count
            );
            set_transient('skwirrel_gavilar_admin_notice', ['type' => 'success', 'message' => $notice], 30);
        } catch (\Throwable $e) {
            set_transient('skwirrel_gavilar_admin_notice', ['type' => 'error', 'message' => $e->getMessage()], 30);
        }

        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public function handleSyncNow(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);

        try {
            $totals = $this->coordinator->run();
            set_transient('skwirrel_gavilar_admin_notice', [
                'type' => 'success',
                'message' => sprintf(
                    /* translators: 1: processed, 2: created, 3: updated, 4: errors */
                    __('Sync done. Processed %1$d (created %2$d, updated %3$d, errors %4$d).', 'skwirrel-gavilar'),
                    $totals['processed'], $totals['created'], $totals['updated'], $totals['errors']
                ),
            ], 30);
        } catch (\Throwable $e) {
            set_transient('skwirrel_gavilar_admin_notice', ['type' => 'error', 'message' => $e->getMessage()], 30);
        }

        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public function handleDetectLocales(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);

        try {
            $detected = $this->detectLocales();
            $settings = new Settings();
            $existing = $settings->localeMap();

            // Merge: pre-fill missing entries with auto-resolved Polylang slugs; keep manual overrides.
            $merged = $existing;
            foreach ($detected as $code) {
                if (!array_key_exists($code, $merged)) {
                    $merged[$code] = $this->polylang->resolveSlug($code) ?? '';
                }
            }
            $settings->setLocaleMap($merged);

            $notice = sprintf(
                /* translators: 1: number of detected codes, 2: comma-separated list */
                __('Detected %1$d Skwirrel locale code(s): %2$s. Review the mapping below.', 'skwirrel-gavilar'),
                count($detected),
                implode(', ', $detected)
            );
            set_transient('skwirrel_gavilar_admin_notice', ['type' => 'success', 'message' => $notice], 30);
        } catch (\Throwable $e) {
            set_transient('skwirrel_gavilar_admin_notice', ['type' => 'error', 'message' => $e->getMessage()], 30);
        }

        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public function handleSaveLocaleMap(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);

        $input = $_POST['locale_map'] ?? [];
        $sanitised = [];
        if (is_array($input)) {
            foreach ($input as $code => $slug) {
                $code = sanitize_text_field((string) $code);
                $slug = sanitize_text_field((string) $slug);
                if ($code !== '') {
                    $sanitised[$code] = $slug;
                }
            }
        }

        (new Settings())->setLocaleMap($sanitised);
        set_transient('skwirrel_gavilar_admin_notice', [
            'type' => 'success',
            'message' => __('Locale mapping saved.', 'skwirrel-gavilar'),
        ], 30);

        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public function handleListSelections(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);

        $candidates = [
            'getDynamicSelections',
            'getDynamicSelection',
            'getSelections',
            'getSelection',
            'getDynamicSelectionList',
            'getSelectionList',
            'listDynamicSelections',
            'listSelections',
        ];

        $selections = null;
        $usedMethod = null;
        $lastError = '';

        foreach ($candidates as $method) {
            try {
                $result = $this->client->call($method, []);
                $list = $this->extractSelectionList($result);
                if (!empty($list)) {
                    $selections = $list;
                    $usedMethod = $method;
                    break;
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        if ($selections === null) {
            set_transient('skwirrel_gavilar_admin_notice', [
                'type' => 'error',
                'message' => sprintf(
                    /* translators: %s: last error message from the API */
                    __('Could not discover selections via any known RPC method. Last error: %s. Ask the Skwirrel admin for the numeric ID directly.', 'skwirrel-gavilar'),
                    $lastError
                ),
            ], 60);
        } else {
            set_transient('skwirrel_gavilar_selections_cache', [
                'method' => $usedMethod,
                'selections' => $selections,
            ], 5 * MINUTE_IN_SECONDS);
            set_transient('skwirrel_gavilar_admin_notice', [
                'type' => 'success',
                'message' => sprintf(
                    /* translators: 1: number of selections, 2: RPC method that worked */
                    __('Found %1$d selection(s) via %2$s. See the table below.', 'skwirrel-gavilar'),
                    count($selections),
                    $usedMethod
                ),
            ], 60);
        }

        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /**
     * Normalise the various response shapes Skwirrel might return into a list of
     * `['id' => int, 'name' => string]`. Returns [] if the shape doesn't look right.
     *
     * @return array<int, array{id:int,name:string}>
     */
    private function extractSelectionList(mixed $result): array
    {
        if (!is_array($result)) {
            return [];
        }
        // Try common nested keys first, then fall back to treating the whole result as the list.
        foreach (['selections', 'dynamic_selections', 'items', 'data'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                return $this->mapSelectionEntries($result[$key]);
            }
        }
        return $this->mapSelectionEntries($result);
    }

    /**
     * @param array<mixed> $list
     * @return array<int, array{id:int,name:string}>
     */
    private function mapSelectionEntries(array $list): array
    {
        $out = [];
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = (int) ($entry['id'] ?? $entry['selection_id'] ?? $entry['dynamic_selection_id'] ?? 0);
            $name = (string) ($entry['name'] ?? $entry['title'] ?? $entry['label'] ?? '');
            if ($id > 0) {
                $out[] = ['id' => $id, 'name' => $name !== '' ? $name : sprintf('Selection %d', $id)];
            }
        }
        return $out;
    }

    public function handleResetCursor(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);

        delete_option(Settings::OPT_LAST_SYNCED_AT);
        set_transient('skwirrel_gavilar_admin_notice', [
            'type' => 'success',
            'message' => __('Delta cursor cleared. The next sync will pull every product updated in the last 24h; for a complete refresh, use "Full resync".', 'skwirrel-gavilar'),
        ], 30);

        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /**
     * Discover Skwirrel locale codes by inspecting one product's translations + languages list.
     *
     * @return string[] de-duped locale codes
     */
    private function detectLocales(): array
    {
        $codes = [];

        // First attempt: ask Skwirrel for its language list directly if such a method exists.
        try {
            $langResult = $this->client->call('getLanguages', []);
            $list = is_array($langResult) ? ($langResult['languages'] ?? $langResult) : [];
            if (is_array($list)) {
                foreach ($list as $entry) {
                    $code = is_array($entry)
                        ? (string) ($entry['code'] ?? $entry['locale'] ?? '')
                        : (string) $entry;
                    if ($code !== '') {
                        $codes[$code] = true;
                    }
                }
            }
        } catch (\Throwable) {
            // Endpoint may not exist on this tenant — fall through to product inspection.
        }

        // Fallback: pull one product with translations and inspect.
        if (empty($codes)) {
            $result = $this->client->call('getProducts', [
                'page' => 1,
                'limit' => 1,
                'include_product_translations' => true,
                'include_languages' => true,
            ]);
            $products = is_array($result) ? ($result['products'] ?? $result) : [];
            $first = is_array($products) && !empty($products) ? $products[0] : null;

            if (is_array($first)) {
                foreach ((array) ($first['translations'] ?? []) as $key => $translation) {
                    $code = is_array($translation) ? (string) ($translation['locale'] ?? $translation['context'] ?? $key) : (string) $key;
                    if ($code !== '') {
                        $codes[$code] = true;
                    }
                }
            }
            if (is_array($result) && isset($result['languages']) && is_array($result['languages'])) {
                foreach ($result['languages'] as $entry) {
                    $code = is_array($entry) ? (string) ($entry['code'] ?? $entry['locale'] ?? '') : (string) $entry;
                    if ($code !== '') {
                        $codes[$code] = true;
                    }
                }
            }
        }

        return array_keys($codes);
    }
}
