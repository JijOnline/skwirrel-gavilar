<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Admin;

use JijOnline\SkwirrelGavilar\Api\Client;
use JijOnline\SkwirrelGavilar\Support\Settings;
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
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_notices', [$this, 'maybeRenderNotice']);
        add_action('admin_post_skwirrel_gavilar_test_connection', [$this, 'handleTestConnection']);
        add_action('admin_post_skwirrel_gavilar_sync_now', [$this, 'handleSyncNow']);
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
        register_setting(self::OPTION_GROUP, Settings::OPT_TOKEN_URL, ['type' => 'string', 'sanitize_callback' => 'esc_url_raw']);
        register_setting(self::OPTION_GROUP, Settings::OPT_API_URL, ['type' => 'string', 'sanitize_callback' => 'esc_url_raw']);
        register_setting(self::OPTION_GROUP, Settings::OPT_CLIENT_ID, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting(self::OPTION_GROUP, Settings::OPT_DYNAMIC_SELECTION_ID, ['type' => 'integer', 'sanitize_callback' => 'absint']);

        register_setting(self::OPTION_GROUP, Settings::OPT_CLIENT_SECRET, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitizeClientSecret'],
        ]);
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

            <?php if (!function_exists('pll_languages_list')): ?>
                <div class="notice notice-warning"><p><?php esc_html_e('Polylang Pro is not active. Multilingual sync will be skipped until it is installed and configured.', 'skwirrel-gavilar'); ?></p></div>
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
                            <p class="description"><?php esc_html_e('The Skwirrel selection that gates which products sync to this site.', 'skwirrel-gavilar'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
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

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="skwirrel_gavilar_sync_now">
                <?php submit_button(__('Sync now (delta)', 'skwirrel-gavilar'), 'primary', 'submit', false); ?>
            </form>
        </div>
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
}
