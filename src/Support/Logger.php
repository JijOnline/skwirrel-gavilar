<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Support;

final class Logger
{
    public function startRun(string $runId, string $mode): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'skwirrel_sync_log', [
            'run_id' => $runId,
            'started_at' => current_time('mysql', true),
            'mode' => $mode,
            'status' => 'running',
        ]);
    }

    /**
     * @param array{processed?:int,created?:int,updated?:int,errors?:int,message?:string} $counts
     */
    public function finishRun(string $runId, string $status, array $counts = []): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'skwirrel_sync_log',
            [
                'finished_at' => current_time('mysql', true),
                'status' => $status,
                'products_processed' => (int) ($counts['processed'] ?? 0),
                'products_created' => (int) ($counts['created'] ?? 0),
                'products_updated' => (int) ($counts['updated'] ?? 0),
                'errors' => (int) ($counts['errors'] ?? 0),
                'message' => (string) ($counts['message'] ?? ''),
            ],
            ['run_id' => $runId]
        );
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        error_log('[skwirrel-gavilar] ' . $message . ' ' . wp_json_encode($context));
        do_action('skwirrel_sync_error', ['message' => $message, 'context' => $context]);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[skwirrel-gavilar] ' . $message . ' ' . wp_json_encode($context));
        }
    }
}
