<?php

declare(strict_types=1);

namespace InternalAppMcp\Http;

use InternalAppMcp\Config\InternalAppDefinition;
use InternalAppMcp\Config\InternalAppEndpointDefinition;
use InternalAppMcp\Config\InternalAppServerConfig;
use Psr\Log\LoggerInterface;

final class InternalAppApiClient
{
    /**
     * @var int[]
     */
    private const RETRYABLE_STATUS_CODES = [408, 425, 429, 500, 502, 503, 504];

    /**
     * @var int[]
     */
    private const RETRYABLE_CURL_ERRORS = [
        \CURLE_COULDNT_CONNECT,
        \CURLE_COULDNT_RESOLVE_HOST,
        \CURLE_GOT_NOTHING,
        \CURLE_OPERATION_TIMEDOUT,
        \CURLE_RECV_ERROR,
        \CURLE_SEND_ERROR,
        \CURLE_SSL_CONNECT_ERROR,
    ];

    public function __construct(
        private readonly InternalAppServerConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{statusCode: int, requestId: string|null, headers: array<string, string>, data: array<string, mixed>}
     */
    public function requestEndpoint(InternalAppDefinition $app, InternalAppEndpointDefinition $endpoint, array $payload = []): array
    {
        [$url, $body, $requestContentType] = $this->buildRequest($app, $endpoint, $payload);

        return $this->request(
            app: $app,
            endpoint: $endpoint,
            url: $url,
            body: $body,
            requestContentType: $requestContentType,
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function buildRequest(InternalAppDefinition $app, InternalAppEndpointDefinition $endpoint, array $payload): array
    {
        $url = $app->apiUrl($endpoint->path);
        $body = null;
        $requestContentType = null;

        if ('query' === $endpoint->parameterMode) {
            if ([] !== $payload) {
                $query = http_build_query($payload, '', '&', \PHP_QUERY_RFC3986);
                $url .= (str_contains($url, '?') ? '&' : '?').$query;
            }

            return [$url, null, null];
        }

        if ('form' === $endpoint->parameterMode) {
            $body = http_build_query($payload, '', '&', \PHP_QUERY_RFC3986);
            $requestContentType = 'application/x-www-form-urlencoded';

            return [$url, $body, $requestContentType];
        }

        $body = json_encode(
            [] === $payload ? new \stdClass() : $payload,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
        );
        $requestContentType = 'application/json';

        return [$url, $body, $requestContentType];
    }

    /**
     * @return array{statusCode: int, requestId: string|null, headers: array<string, string>, data: array<string, mixed>}
     */
    private function request(
        InternalAppDefinition $app,
        InternalAppEndpointDefinition $endpoint,
        string $url,
        ?string $body = null,
        ?string $requestContentType = null,
    ): array {
        $attempts = $this->config->maxRetries + 1;
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $responseHeaders = [];
            $startedAt = microtime(true);
            $handle = curl_init();

            if (false === $handle) {
                throw new ApiException('Unable to initialize cURL for internal app API request.');
            }

            $headers = [
                'Accept: application/json, text/plain;q=0.9, */*;q=0.8',
                'User-Agent: '.$app->userAgent,
            ];

            $authHeader = $app->authHeaderValue();
            if (null !== $authHeader) {
                $headers[] = $app->authHeader.': '.$authHeader;
            }

            if (null !== $body && null !== $requestContentType) {
                $headers[] = 'Content-Type: '.$requestContentType;
            }

            curl_setopt_array($handle, [
                \CURLOPT_URL => $url,
                \CURLOPT_CUSTOMREQUEST => $endpoint->method,
                \CURLOPT_HTTPHEADER => $headers,
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_CONNECTTIMEOUT => $this->config->connectTimeoutSeconds,
                \CURLOPT_TIMEOUT => $this->config->timeoutSeconds,
                \CURLOPT_SSL_VERIFYPEER => $this->config->verifyTls,
                \CURLOPT_SSL_VERIFYHOST => $this->config->verifyTls ? 2 : 0,
                \CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                    $length = strlen($headerLine);
                    $header = trim($headerLine);

                    if ('' === $header || !str_contains($header, ':')) {
                        return $length;
                    }

                    [$name, $value] = explode(':', $header, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);

                    return $length;
                },
            ]);

            if (null !== $body) {
                curl_setopt($handle, \CURLOPT_POSTFIELDS, $body);
            }

            $rawResponse = curl_exec($handle);
            $curlErrorNumber = curl_errno($handle);
            $curlError = curl_error($handle);
            $statusCode = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
            curl_close($handle);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $requestId = $responseHeaders['x-request-id'] ?? $responseHeaders['x-correlation-id'] ?? null;

            if (false === $rawResponse) {
                $this->logger->warning('Internal app API request failed before receiving a response.', [
                    'app' => $app->slug,
                    'endpoint' => $endpoint->toolName,
                    'method' => $endpoint->method,
                    'url' => $url,
                    'attempt' => $attempt,
                    'durationMs' => $durationMs,
                    'curlError' => $curlError,
                    'curlErrorNumber' => $curlErrorNumber,
                ]);

                if ($attempt < $attempts && \in_array($curlErrorNumber, self::RETRYABLE_CURL_ERRORS, true)) {
                    $this->backoff($attempt);
                    continue;
                }

                throw new ApiException(
                    sprintf('Internal app API request failed: %s', $curlError ?: 'unknown cURL error'),
                    requestId: $requestId
                );
            }

            $contentType = $responseHeaders['content-type'] ?? null;
            $decoded = $this->decodeResponseBody($rawResponse, $contentType, $statusCode, $requestId);

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Internal app API request completed.', [
                    'app' => $app->slug,
                    'endpoint' => $endpoint->toolName,
                    'method' => $endpoint->method,
                    'url' => $url,
                    'statusCode' => $statusCode,
                    'attempt' => $attempt,
                    'durationMs' => $durationMs,
                    'requestId' => $requestId,
                ]);

                return [
                    'statusCode' => $statusCode,
                    'requestId' => $requestId,
                    'headers' => $responseHeaders,
                    'data' => [
                        'app' => $app->slug,
                        'endpoint' => $endpoint->name,
                        'method' => $endpoint->method,
                        'parameterMode' => $endpoint->parameterMode,
                        'url' => $url,
                        'contentType' => $decoded['contentType'],
                        'responseType' => $decoded['responseType'],
                        'payload' => $decoded['payload'],
                    ],
                ];
            }

            $message = $this->extractErrorMessage($decoded, $statusCode);

            $this->logger->warning('Internal app API returned an error response.', [
                'app' => $app->slug,
                'endpoint' => $endpoint->toolName,
                'method' => $endpoint->method,
                'url' => $url,
                'statusCode' => $statusCode,
                'attempt' => $attempt,
                'durationMs' => $durationMs,
                'requestId' => $requestId,
                'response' => $decoded,
            ]);

            if ($attempt < $attempts && \in_array($statusCode, self::RETRYABLE_STATUS_CODES, true)) {
                $this->backoff($attempt);
                continue;
            }

            throw new ApiException(
                $message,
                statusCode: $statusCode,
                requestId: $requestId,
                responseData: $decoded,
                responseBody: $rawResponse
            );
        }

        throw new ApiException('Internal app API request failed after exhausting retries.');
    }

    /**
     * @return array{contentType: string|null, responseType: string, payload: mixed}
     */
    private function decodeResponseBody(
        string $rawResponse,
        ?string $contentType,
        int $statusCode,
        ?string $requestId,
    ): array {
        $trimmed = trim($rawResponse);
        if ('' === $trimmed) {
            return [
                'contentType' => $contentType,
                'responseType' => 'empty',
                'payload' => null,
            ];
        }

        if ($this->shouldAttemptJsonDecode($trimmed, $contentType)) {
            try {
                $decoded = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new ApiException(
                    sprintf('Internal app API returned invalid JSON (HTTP %d).', $statusCode),
                    statusCode: $statusCode,
                    requestId: $requestId,
                    responseBody: $trimmed,
                    previous: $exception
                );
            }

            $responseType = match (true) {
                \is_array($decoded) && array_is_list($decoded) => 'json_array',
                \is_array($decoded) => 'json_object',
                default => 'json_scalar',
            };

            return [
                'contentType' => $contentType,
                'responseType' => $responseType,
                'payload' => $decoded,
            ];
        }

        return [
            'contentType' => $contentType,
            'responseType' => 'text',
            'payload' => $trimmed,
        ];
    }

    private function shouldAttemptJsonDecode(string $body, ?string $contentType): bool
    {
        if (null !== $contentType && str_contains(strtolower($contentType), 'json')) {
            return true;
        }

        return str_starts_with($body, '{') || str_starts_with($body, '[');
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractErrorMessage(array $decoded, int $statusCode): string
    {
        $payload = $decoded['payload'] ?? null;

        if (\is_array($payload)) {
            $nested = [
                $payload['error']['message'] ?? null,
                $payload['message'] ?? null,
                $payload['Message'] ?? null,
                $payload['error_description'] ?? null,
                $payload['detail'] ?? null,
            ];

            foreach ($nested as $candidate) {
                if (\is_string($candidate) && '' !== trim($candidate)) {
                    return sprintf('Internal app API request failed with HTTP %d: %s', $statusCode, trim($candidate));
                }
            }
        }

        if (\is_string($payload) && '' !== trim($payload)) {
            return sprintf(
                'Internal app API request failed with HTTP %d: %s',
                $statusCode,
                $this->truncate(trim($payload))
            );
        }

        return sprintf('Internal app API request failed with HTTP %d.', $statusCode);
    }

    private function truncate(string $value, int $maxLength = 240): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength - 3).'...';
    }

    private function backoff(int $attempt): void
    {
        $delayMs = (250 * $attempt) + random_int(0, 150);
        usleep($delayMs * 1000);
    }
}
