<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Api;

use JijOnline\SkwirrelGavilar\Support\Settings;

final class OAuthTokenStore
{
    private const TRANSIENT = 'skwirrel_gavilar_access_token';
    private const SAFETY_BUFFER_SECONDS = 60;

    public function __construct(private readonly Settings $settings) {}

    public function getToken(bool $forceRefresh = false): string
    {
        if (!$forceRefresh) {
            $cached = get_transient(self::TRANSIENT);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        return $this->fetchAndCache();
    }

    public function invalidate(): void
    {
        delete_transient(self::TRANSIENT);
    }

    private function fetchAndCache(): string
    {
        $tokenUrl = $this->settings->tokenUrl();
        $clientId = $this->settings->clientId();
        $clientSecret = $this->settings->clientSecret();

        if ($tokenUrl === '' || $clientId === '' || $clientSecret === '') {
            throw new AuthException('OAuth credentials are not configured.');
        }

        $response = wp_remote_post($tokenUrl, [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => ['grant_type' => 'client_credentials'],
        ]);

        if (is_wp_error($response)) {
            throw new TransportException(sprintf(
                'Token request to %s failed: %s',
                $tokenUrl,
                $response->get_error_message()
            ));
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
            throw new AuthException(sprintf(
                'Token endpoint %s returned %d: %s',
                $tokenUrl,
                $code,
                is_string($body) ? substr($body, 0, 300) : ''
            ));
        }

        $token = (string) $data['access_token'];
        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        $ttl = max(60, $expiresIn - self::SAFETY_BUFFER_SECONDS);
        set_transient(self::TRANSIENT, $token, $ttl);
        return $token;
    }
}
