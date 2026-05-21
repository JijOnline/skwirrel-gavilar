<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar;

use JijOnline\SkwirrelGavilar\Admin\FullResyncController;
use JijOnline\SkwirrelGavilar\Admin\SettingsPage;
use JijOnline\SkwirrelGavilar\Cli\SkwirrelCommand;
use JijOnline\SkwirrelGavilar\Api\Client;
use JijOnline\SkwirrelGavilar\Api\OAuthTokenStore;
use JijOnline\SkwirrelGavilar\Cpt\CategoryTaxonomy;
use JijOnline\SkwirrelGavilar\Cpt\ProductPostType;
use JijOnline\SkwirrelGavilar\Display\ProductDisplay;
use JijOnline\SkwirrelGavilar\I18n\Polylang;
use JijOnline\SkwirrelGavilar\Mapping\AttachmentMapper;
use JijOnline\SkwirrelGavilar\Mapping\CategoryMapper;
use JijOnline\SkwirrelGavilar\Mapping\FeatureMapper;
use JijOnline\SkwirrelGavilar\Mapping\ProductMapper;
use JijOnline\SkwirrelGavilar\Support\Logger;
use JijOnline\SkwirrelGavilar\Support\Settings;
use JijOnline\SkwirrelGavilar\Sync\SyncCoordinator;

final class Plugin
{
    private const DAILY_HOOK = 'skwirrel_gavilar_daily_sync';
    private const DB_VERSION = '1';
    private const DB_VERSION_OPTION = 'skwirrel_gavilar_db_version';

    private static ?self $instance = null;

    public static function boot(): void
    {
        self::$instance ??= new self();
        self::$instance->register();
    }

    public static function instance(): self
    {
        self::$instance ??= new self();
        return self::$instance;
    }

    private Client $client;
    private SyncCoordinator $coordinator;
    private Polylang $polylang;

    private function register(): void
    {
        $this->buildServices();

        (new ProductPostType())->register();
        (new CategoryTaxonomy())->register();
        $this->polylang->register();
        (new ProductDisplay())->register();
        (new SettingsPage($this->client, $this->coordinator, $this->polylang))->register();
        (new FullResyncController($this->coordinator))->register();

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('skwirrel', new SkwirrelCommand($this->coordinator));
        }

        add_action(self::DAILY_HOOK, function (): void {
            $this->coordinator->run();
        });
        add_action('init', [$this, 'maybeFlushRewrites'], 20);

        $this->maybeUpgradeSchema();
    }

    private function buildServices(): void
    {
        $settings = new Settings();
        $logger = new Logger();
        $tokenStore = new OAuthTokenStore($settings);
        $this->client = new Client($settings, $tokenStore, $logger);

        $this->polylang = new Polylang($settings);
        $categoryMapper = new CategoryMapper($this->polylang);
        $featureMapper = new FeatureMapper();
        $attachmentMapper = new AttachmentMapper($logger);
        $productMapper = new ProductMapper($categoryMapper, $featureMapper, $attachmentMapper, $this->polylang, $logger);

        $this->coordinator = new SyncCoordinator($this->client, $productMapper, $settings, $logger);
    }

    public function polylang(): Polylang
    {
        return $this->polylang;
    }

    public function coordinator(): SyncCoordinator
    {
        return $this->coordinator;
    }

    public function client(): Client
    {
        return $this->client;
    }

    public static function onActivate(): void
    {
        self::createTables();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);

        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK);
        }

        // CPT/taxonomy register on init; flag a deferred flush so rewrites pick them up on next load.
        update_option('skwirrel_gavilar_flush_rewrites', '1', false);
    }

    public static function onDeactivate(): void
    {
        wp_clear_scheduled_hook(self::DAILY_HOOK);
        flush_rewrite_rules();
    }

    public function maybeFlushRewrites(): void
    {
        if (get_option('skwirrel_gavilar_flush_rewrites') === '1') {
            flush_rewrite_rules(false);
            delete_option('skwirrel_gavilar_flush_rewrites');
        }
    }

    private function maybeUpgradeSchema(): void
    {
        $current = get_option(self::DB_VERSION_OPTION);
        if ($current !== self::DB_VERSION) {
            self::createTables();
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
    }

    private static function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'skwirrel_sync_log';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id VARCHAR(36) NOT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME DEFAULT NULL,
            mode VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            products_processed INT UNSIGNED NOT NULL DEFAULT 0,
            products_created INT UNSIGNED NOT NULL DEFAULT 0,
            products_updated INT UNSIGNED NOT NULL DEFAULT 0,
            errors INT UNSIGNED NOT NULL DEFAULT 0,
            message LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY run_id (run_id),
            KEY started_at (started_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
