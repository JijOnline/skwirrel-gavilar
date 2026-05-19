<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Sync;

/**
 * Persistent state for a multi-step full resync.
 *
 * Stored in a single option as JSON so an admin tab can be closed and reopened
 * without losing the cursor. Also lets WP-CLI and the admin AJAX flow share state.
 */
final class FullResyncState
{
    public const OPTION = 'skwirrel_gavilar_full_resync_state';

    public const STATUS_IDLE = 'idle';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public string $runId,
        public int $page,
        public string $startedAt,
        public string $status,
        public int $processed = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $errors = 0,
        public string $message = '',
    ) {}

    public static function load(): self
    {
        $raw = get_option(self::OPTION);
        if (is_string($raw) && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return new self(
                    runId: (string) ($data['run_id'] ?? ''),
                    page: (int) ($data['page'] ?? 0),
                    startedAt: (string) ($data['started_at'] ?? ''),
                    status: (string) ($data['status'] ?? self::STATUS_IDLE),
                    processed: (int) ($data['processed'] ?? 0),
                    created: (int) ($data['created'] ?? 0),
                    updated: (int) ($data['updated'] ?? 0),
                    errors: (int) ($data['errors'] ?? 0),
                    message: (string) ($data['message'] ?? ''),
                );
            }
        }
        return self::idle();
    }

    public static function idle(): self
    {
        return new self('', 0, '', self::STATUS_IDLE);
    }

    public static function start(): self
    {
        return new self(
            runId: wp_generate_uuid4(),
            page: 1,
            startedAt: gmdate('Y-m-d H:i:s'),
            status: self::STATUS_RUNNING,
        );
    }

    public function persist(): void
    {
        update_option(self::OPTION, wp_json_encode($this->toArray()), false);
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'page' => $this->page,
            'started_at' => $this->startedAt,
            'status' => $this->status,
            'processed' => $this->processed,
            'created' => $this->created,
            'updated' => $this->updated,
            'errors' => $this->errors,
            'message' => $this->message,
        ];
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }
}
