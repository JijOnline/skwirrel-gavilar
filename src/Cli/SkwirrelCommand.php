<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Cli;

use JijOnline\SkwirrelGavilar\Sync\SyncCoordinator;
use JijOnline\SkwirrelGavilar\Support\Settings;

/**
 * `wp skwirrel ...` commands. Registered only when WP_CLI is loaded.
 */
final class SkwirrelCommand
{
    public function __construct(private readonly SyncCoordinator $coordinator) {}

    /**
     * Run a delta or full sync against Skwirrel.
     *
     * ## OPTIONS
     *
     * [--full]
     * : Ignore the delta cursor and re-sync every product, then soft-delete obsolete posts.
     *
     * [--since=<datetime>]
     * : Override the delta cursor with a specific UTC datetime (e.g. 2026-01-01 00:00:00).
     *
     * ## EXAMPLES
     *
     *   wp skwirrel sync
     *   wp skwirrel sync --full
     *   wp skwirrel sync --since="2026-01-01 00:00:00"
     *
     * @when after_wp_load
     *
     * @param array<int, string>     $args
     * @param array<string, string>  $assoc_args
     */
    public function sync(array $args, array $assoc_args): void
    {
        $full = isset($assoc_args['full']);
        $since = $assoc_args['since'] ?? null;

        if ($full) {
            \WP_CLI::log('Starting full resync…');
            $totals = $this->coordinator->runFull();
        } else {
            if (is_string($since) && $since !== '') {
                (new Settings())->setLastSyncedAt($since);
                \WP_CLI::log("Cursor overridden to {$since}.");
            }
            \WP_CLI::log('Starting delta sync…');
            $totals = $this->coordinator->run();
        }

        \WP_CLI::success(sprintf(
            'Run %s — processed %d (created %d, updated %d, errors %d%s).',
            $totals['run_id'] ?: '(none)',
            $totals['processed'],
            $totals['created'],
            $totals['updated'],
            $totals['errors'],
            isset($totals['trashed']) ? ", trashed {$totals['trashed']}" : ''
        ));
    }

    /**
     * Show the current sync cursor and the last log entry.
     *
     * @when after_wp_load
     */
    public function status(): void
    {
        $settings = new Settings();
        $cursor = $settings->lastSyncedAt() ?? '(none)';
        \WP_CLI::log("Last synced at: {$cursor}");
        \WP_CLI::log('Dynamic selection ID: ' . ($settings->dynamicSelectionId() ?? '(unset)'));

        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}skwirrel_sync_log ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );
        if ($row) {
            \WP_CLI::log('Last run:');
            foreach ($row as $k => $v) {
                \WP_CLI::log(sprintf('  %-20s %s', $k, (string) $v));
            }
        } else {
            \WP_CLI::log('No previous runs.');
        }
    }

    /**
     * Clear the delta cursor so the next `sync` command pulls everything since the
     * dawn of time (effectively a full sync without the soft-delete pass).
     *
     * @when after_wp_load
     */
    public function reset_cursor(): void
    {
        delete_option(Settings::OPT_LAST_SYNCED_AT);
        \WP_CLI::success('Cursor cleared.');
    }
}
