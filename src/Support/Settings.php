<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Support;

final class Settings
{
    public const OPT_TOKEN_URL = 'skwirrel_gavilar_oauth_token_url';
    public const OPT_API_URL = 'skwirrel_gavilar_api_url';
    public const OPT_CLIENT_ID = 'skwirrel_gavilar_client_id';
    public const OPT_CLIENT_SECRET = 'skwirrel_gavilar_client_secret';
    public const OPT_DYNAMIC_SELECTION_ID = 'skwirrel_gavilar_dynamic_selection_id';
    public const OPT_LOCALE_MAP = 'skwirrel_gavilar_locale_map';
    public const OPT_LAST_SYNCED_AT = 'skwirrel_gavilar_last_synced_at';
    public const OPT_CURRENT_RUN_ID = 'skwirrel_gavilar_current_run_id';

    public function tokenUrl(): string
    {
        return self::cleanUrl((string) get_option(self::OPT_TOKEN_URL, ''));
    }

    public function apiUrl(): string
    {
        return self::cleanUrl((string) get_option(self::OPT_API_URL, ''));
    }

    /**
     * Defensive URL cleanup. esc_url_raw (the field sanitizer) converts a
     * stray trailing space into a literal "%20" instead of stripping it,
     * which silently breaks the endpoint path. Strip whitespace and any
     * trailing encoded-space artefacts here so an already-saved bad value
     * still resolves correctly.
     */
    public static function cleanUrl(string $raw): string
    {
        $url = trim($raw);
        while (str_ends_with($url, '%20') || str_ends_with($url, '%09')) {
            $url = substr($url, 0, -3);
        }
        return trim($url);
    }

    public function clientId(): string
    {
        return (string) get_option(self::OPT_CLIENT_ID, '');
    }

    public function clientSecret(): string
    {
        $stored = (string) get_option(self::OPT_CLIENT_SECRET, '');
        if ($stored === '') {
            return '';
        }
        return Encryption::decrypt($stored);
    }

    public function storeClientSecret(string $plaintext): void
    {
        if ($plaintext === '') {
            delete_option(self::OPT_CLIENT_SECRET);
            return;
        }
        update_option(self::OPT_CLIENT_SECRET, Encryption::encrypt($plaintext), false);
    }

    public function dynamicSelectionId(): ?int
    {
        $raw = get_option(self::OPT_DYNAMIC_SELECTION_ID, '');
        return $raw === '' ? null : (int) $raw;
    }

    public function lastSyncedAt(): ?string
    {
        $val = get_option(self::OPT_LAST_SYNCED_AT, '');
        return $val === '' ? null : (string) $val;
    }

    public function setLastSyncedAt(string $isoUtc): void
    {
        update_option(self::OPT_LAST_SYNCED_AT, $isoUtc, false);
    }

    /** @return array<string, string> Skwirrel locale code => Polylang language slug */
    public function localeMap(): array
    {
        $raw = get_option(self::OPT_LOCALE_MAP, []);
        return is_array($raw) ? $raw : [];
    }

    /** @param array<string, string> $map */
    public function setLocaleMap(array $map): void
    {
        update_option(self::OPT_LOCALE_MAP, $map, false);
    }

    public function isConfigured(): bool
    {
        return $this->tokenUrl() !== ''
            && $this->apiUrl() !== ''
            && $this->clientId() !== ''
            && $this->clientSecret() !== ''
            && $this->dynamicSelectionId() !== null;
    }
}
