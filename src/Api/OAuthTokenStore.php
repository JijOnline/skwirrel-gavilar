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
            // An OAuth2 token endpoint never legitimately redirects. Don't follow
            // 3xx silently — a POST->GET downgrade would land on a 404. Surface it.
            'redirection' => 0,
            'headers' => [
                // IMPORTANT: must NOT be "application/json". Skwirrel content-negotiates:
                // an Accept: application/json request is routed to the JSON-RPC dispatcher,
                // which doesn't know /oauth2/token and answers -32003 "URL not found".
                // "*/*" makes the server route to the real OAuth handler.
                'Accept' => '*/*',
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
            throw new AuthException(self::describeFailure($tokenUrl, (int) $code, (string) $body, $response));
        }

        $token = (string) $data['access_token'];
        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        $ttl = max(60, $expiresIn - self::SAFETY_BUFFER_SECONDS);
        set_transient(self::TRANSIENT, $token, $ttl);
        return $token;
    }

    /**
     * Build a self-diagnosing failure message: exact URL byte length, a hex
     * dump if the URL carries non-ASCII, the redirect target on a 3xx, and a
     * body excerpt. Lets us tell a typo / hidden character / redirect apart
     * without server log access.
     *
     * @param array|\WP_Error $response
     */
    private static function describeFailure(string $url, int $code, string $body, $response): string
    {
        $parts = [sprintf('Token endpoint %s returned %d.', $url, $code)];

        $len = strlen($url);
        $hasNonAscii = (bool) preg_match('/[^\x21-\x7E]/', $url);
        $parts[] = sprintf('[url: %d bytes%s]', $len, $hasNonAscii ? ', NON-ASCII present hex=' . bin2hex($url) : '');

        if ($code >= 300 && $code < 400) {
            $location = wp_remote_retrieve_header($response, 'location');
            $parts[] = 'Redirect Location: ' . ($location !== '' ? $location : '(none)');
        }

        if ($body !== '') {
            $parts[] = 'Body: ' . substr($body, 0, 200);
        }

        return implode(' ', $parts);
    }
}
