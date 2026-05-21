<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Admin;

use JijOnline\SkwirrelGavilar\Sync\FullResyncState;
use JijOnline\SkwirrelGavilar\Sync\SyncCoordinator;

/**
 * Drives a full resync as a series of admin-ajax calls so a 1000+ product
 * catalogue doesn't have to complete inside a single HTTP request.
 */
final class FullResyncController
{
    public const NONCE_ACTION = 'skwirrel_gavilar_full_resync';

    public function __construct(private readonly SyncCoordinator $coordinator) {}

    public function register(): void
    {
        add_action('wp_ajax_skwirrel_gavilar_full_resync_start', [$this, 'ajaxStart']);
        add_action('wp_ajax_skwirrel_gavilar_full_resync_step', [$this, 'ajaxStep']);
        add_action('wp_ajax_skwirrel_gavilar_full_resync_state', [$this, 'ajaxState']);
    }

    public function ajaxStart(): void
    {
        $this->guard();

        $existing = FullResyncState::load();
        if ($existing->isRunning()) {
            wp_send_json_error(['state' => $existing->toArray(), 'message' => 'A full resync is already running.']);
        }

        $state = FullResyncState::start();
        $state->persist();
        wp_send_json_success(['state' => $state->toArray()]);
    }

    public function ajaxStep(): void
    {
        $this->guard();

        // One step downloads images for a page of products — give it room.
        @set_time_limit(180);
        ignore_user_abort(true);

        $state = FullResyncState::load();
        if (!$state->isRunning()) {
            wp_send_json_error(['state' => $state->toArray(), 'message' => 'No full resync is running.']);
        }

        try {
            $result = $this->coordinator->runFullPage($state->runId, $state->page);
            $state->processed += (int) ($result['processed'] ?? 0);
            $state->created += (int) ($result['created'] ?? 0);
            $state->updated += (int) ($result['updated'] ?? 0);
            $state->errors += (int) ($result['errors'] ?? 0);

            $nextPage = $result['next_page'] ?? null;
            if ($nextPage === null) {
                $trashed = $this->coordinator->finalizeFullRun($state->runId);
                $state->status = FullResyncState::STATUS_COMPLETED;
                $state->message = sprintf('Soft-deleted %d obsolete product(s).', $trashed);
            } else {
                $state->page = (int) $nextPage;
            }
            $state->persist();
            wp_send_json_success(['state' => $state->toArray()]);
        } catch (\Throwable $e) {
            $state->status = FullResyncState::STATUS_FAILED;
            $state->message = $e->getMessage();
            $state->persist();
            wp_send_json_error(['state' => $state->toArray(), 'message' => $e->getMessage()]);
        }
    }

    public function ajaxState(): void
    {
        $this->guard();
        wp_send_json_success(['state' => FullResyncState::load()->toArray()]);
    }

    private function guard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }
}
