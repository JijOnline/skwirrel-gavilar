<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Mapping;

use JijOnline\SkwirrelGavilar\Support\Logger;

final class AttachmentMapper
{
    public const META_SKWIRREL_ID = '_skwirrel_attachment_id';
    public const META_SOURCE_URL = '_skwirrel_attachment_url';

    public const ROLE_FEATURED = 'featured';
    public const ROLE_GALLERY = 'gallery';
    public const ROLE_DOCUMENT = 'document';

    public function __construct(private readonly Logger $logger) {}

    /**
     * Sync all attachments of a product. Returns the attachment IDs by role.
     *
     * @param array<int, array<string, mixed>> $attachments Skwirrel attachment payloads.
     * @return array{featured: int|null, gallery: int[], documents: array<int, array{id:int,label:string}>}
     */
    public function sync(int $productPostId, array $attachments): array
    {
        $featuredId = null;
        $galleryIds = [];
        $documents = [];

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $skwirrelId = (int) ($attachment['product_attachment_id'] ?? $attachment['attachment_id'] ?? $attachment['id'] ?? 0);
            $url = (string) ($attachment['source_url'] ?? $attachment['url'] ?? $attachment['download_url'] ?? '');
            $mime = (string) ($attachment['file_mimetype'] ?? $attachment['mime_type'] ?? '');
            $fileName = (string) ($attachment['file_name'] ?? $attachment['name'] ?? '');
            $label = (string) ($attachment['file_name'] ?? $attachment['label'] ?? $attachment['name'] ?? '');
            $role = $this->resolveRole($attachment, $mime);

            if ($skwirrelId <= 0 || $url === '') {
                continue;
            }

            $attachmentId = $this->upsertOne($productPostId, $skwirrelId, $url, $fileName, $label, $mime);
            if ($attachmentId === null) {
                continue;
            }

            switch ($role) {
                case self::ROLE_FEATURED:
                    $featuredId ??= $attachmentId;
                    break;
                case self::ROLE_DOCUMENT:
                    $documents[] = ['id' => $attachmentId, 'label' => $label];
                    break;
                case self::ROLE_GALLERY:
                default:
                    $galleryIds[] = $attachmentId;
                    if ($featuredId === null && $this->isImage($mime)) {
                        $featuredId = $attachmentId;
                    }
                    break;
            }
        }

        if ($featuredId !== null) {
            set_post_thumbnail($productPostId, $featuredId);
        }
        update_post_meta($productPostId, '_pim_gallery_ids', array_values(array_unique($galleryIds)));
        update_post_meta($productPostId, '_pim_documents', $documents);

        return ['featured' => $featuredId, 'gallery' => $galleryIds, 'documents' => $documents];
    }

    private function upsertOne(int $productPostId, int $skwirrelId, string $url, string $fileName, string $label, string $mime): ?int
    {
        $existing = $this->findBySkwirrelId($skwirrelId);
        if ($existing !== null) {
            $storedUrl = (string) get_post_meta($existing, self::META_SOURCE_URL, true);
            if ($storedUrl === $url) {
                return $existing;
            }
            // URL changed — delete the old WP attachment and re-download.
            wp_delete_attachment($existing, true);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) {
            $this->logger->error('Attachment download failed', [
                'skwirrel_id' => $skwirrelId,
                'url' => $url,
                'error' => $tmp->get_error_message(),
            ]);
            return null;
        }

        $filename = $this->resolveFilename($fileName, $url, $mime);
        $fileArray = ['name' => $filename, 'tmp_name' => $tmp];

        $attachmentId = media_handle_sideload($fileArray, $productPostId, $label !== '' ? $label : null);
        if (is_wp_error($attachmentId)) {
            @unlink($tmp);
            $this->logger->error('media_handle_sideload failed', [
                'skwirrel_id' => $skwirrelId,
                'error' => $attachmentId->get_error_message(),
            ]);
            return null;
        }

        update_post_meta($attachmentId, self::META_SKWIRREL_ID, $skwirrelId);
        update_post_meta($attachmentId, self::META_SOURCE_URL, $url);
        return (int) $attachmentId;
    }

    private function findBySkwirrelId(int $skwirrelId): ?int
    {
        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                ['key' => self::META_SKWIRREL_ID, 'value' => $skwirrelId],
            ],
        ]);
        $ids = $query->posts;
        return !empty($ids) ? (int) $ids[0] : null;
    }

    private function resolveRole(array $attachment, string $mime): string
    {
        $type = strtolower((string) ($attachment['product_attachment_type_code'] ?? $attachment['type'] ?? $attachment['role'] ?? ''));
        // Known Skwirrel image type codes (PPI = product picture).
        if (in_array($type, ['featured', 'main', 'primary'], true)) {
            return self::ROLE_FEATURED;
        }
        if (str_starts_with($mime, 'image/')) {
            return self::ROLE_GALLERY;
        }
        return self::ROLE_DOCUMENT;
    }

    private function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    /** Prefer Skwirrel's own file_name; fall back to the URL basename, then a hash. */
    private function resolveFilename(string $fileName, string $url, string $mime): string
    {
        $name = $fileName;
        if ($name === '' || !str_contains($name, '.')) {
            $path = parse_url($url, PHP_URL_PATH);
            $name = is_string($path) ? basename($path) : '';
        }
        if ($name === '' || !str_contains($name, '.')) {
            $ext = $this->extensionForMime($mime);
            $name = 'skwirrel-' . substr(md5($url), 0, 10) . ($ext !== '' ? '.' . $ext : '');
        }
        return sanitize_file_name($name);
    }

    private function extensionForMime(string $mime): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
        ];
        return $map[strtolower($mime)] ?? '';
    }
}
