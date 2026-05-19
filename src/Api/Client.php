<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Api;

use JijOnline\SkwirrelGavilar\Support\Logger;
use JijOnline\SkwirrelGavilar\Support\Settings;

final class Client
{
    private const MAX_ATTEMPTS = 4;
    private const REQUEST_TIMEOUT = 60;

    public function __construct(
        private readonly Settings $settings,
        private readonly OAuthTokenStore $tokenStore,
        private readonly Logger $logger,
    ) {}

    /**
     * @param array<string, mixed> $params
     * @return array<mixed>|mixed
     */
    public function call(string $method, array $params = [])
    {
        $apiUrl = $this->settings->apiUrl();
        if ($apiUrl === '') {
            throw new ApiException('Skwirrel API URL is not configured.');
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => (object) $params,
            'id' => wp_generate_uuid4(),
        ];
        $body = (string) wp_json_encode($payload);

        $tokenRefreshed = false;
        $attempt = 0;
        $lastError = null;

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;
            $token = $this->tokenStore->getToken();

            $response = wp_remote_post($apiUrl, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                    'X-Skwirrel-Api-Version' => '2',
                ],
                'body' => $body,
            ]);

            if (is_wp_error($response)) {
                $lastError = $response->get_error_message();
                $this->logger->info('RPC transport error', ['method' => $method, 'attempt' => $attempt, 'error' => $lastError]);
                $this->backoff($attempt);
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            $responseBody = wp_remote_retrieve_body($response);

            if ($code === 401 && !$tokenRefreshed) {
                $tokenRefreshed = true;
                $this->tokenStore->invalidate();
                continue;
            }

            if ($code >= 500) {
                $lastError = "HTTP {$code}";
                $this->logger->info('RPC server error', ['method' => $method, 'attempt' => $attempt, 'code' => $code]);
                $this->backoff($attempt);
                continue;
            }

            if ($code !== 200) {
                throw new ApiException("Skwirrel RPC HTTP {$code}: " . substr((string) $responseBody, 0, 300));
            }

            $decoded = json_decode((string) $responseBody, true);
            if (!is_array($decoded)) {
                throw new ApiException('Skwirrel RPC: invalid JSON response.');
            }

            if (isset($decoded['error'])) {
                $err = $decoded['error'];
                throw new RpcException(
                    (string) ($err['message'] ?? 'RPC error'),
                    (int) ($err['code'] ?? 0),
                    $err['data'] ?? null,
                );
            }

            return $decoded['result'] ?? null;
        }

        throw new TransportException("Skwirrel RPC '{$method}' failed after " . self::MAX_ATTEMPTS . " attempts: {$lastError}");
    }

    private function backoff(int $attempt): void
    {
        // 1s, 2s, 4s, 8s
        $delay = (int) min(8, 2 ** ($attempt - 1));
        sleep($delay);
    }
}
