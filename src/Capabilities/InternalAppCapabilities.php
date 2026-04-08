<?php

declare(strict_types=1);

namespace InternalAppMcp\Capabilities;

use InternalAppMcp\Config\InternalAppDefinition;
use InternalAppMcp\Config\InternalAppEndpointDefinition;
use InternalAppMcp\Config\InternalAppServerConfig;
use InternalAppMcp\Http\ApiException;
use InternalAppMcp\Http\InternalAppApiClient;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

final class InternalAppCapabilities
{
    public function __construct(
        private readonly InternalAppApiClient $client,
        private readonly InternalAppServerConfig $config,
    ) {
    }

    /**
     * @param string[] $allowedBranches
     *
     * @return array<string, mixed>|CallToolResult
     */
    public function callDashboardFetchGeneral(
        InternalAppDefinition $app,
        InternalAppEndpointDefinition $endpoint,
        array $allowedBranches,
        ?int $daysChange = null,
        ?bool $reportFlag = null,
        bool $includeResponseHeaders = false,
    ): array|CallToolResult {
        $normalizedBranches = $this->normalizeAllowedBranches($allowedBranches);
        if ([] === $normalizedBranches) {
            return $this->errorEnvelope('allowedBranches must contain at least one non-empty branch code.');
        }

        $resolvedDaysChange = null !== $daysChange
            ? $daysChange
            : (int) ($endpoint->requestDefaults['daysChange'] ?? 365);
        $resolvedReportFlag = null !== $reportFlag
            ? $reportFlag
            : (bool) ($endpoint->requestDefaults['reportFlag'] ?? false);

        try {
            $response = $this->client->requestEndpoint(
                $app,
                $endpoint,
                $this->mapArgumentsToPayload($endpoint, [
                    'allowedBranches' => $normalizedBranches,
                    'daysChange' => $resolvedDaysChange,
                    'reportFlag' => $resolvedReportFlag,
                ])
            );
            $payload = \is_array($response['data']['payload']) ? $response['data']['payload'] : [];

            return $this->successEnvelope(
                [
                    'endpoint' => $endpoint->name,
                    'request' => [
                        'allowedBranches' => $normalizedBranches,
                        'daysChange' => $resolvedDaysChange,
                        'reportFlag' => $resolvedReportFlag,
                    ],
                    'statusCode' => $response['statusCode'],
                    'status' => isset($payload['Status']) && \is_string($payload['Status']) ? $payload['Status'] : null,
                    'summaryOnly' => $resolvedReportFlag,
                    'dataTotalReport' => isset($payload['DataTotalReport']) && \is_array($payload['DataTotalReport']) ? $payload['DataTotalReport'] : null,
                    'dataBranch' => isset($payload['DataBranch']) && \is_array($payload['DataBranch']) ? $payload['DataBranch'] : null,
                    'dataGeneralBranch' => isset($payload['DataGeneralBranch']) && \is_array($payload['DataGeneralBranch']) ? $payload['DataGeneralBranch'] : null,
                    'dataSegment' => isset($payload['DataSegment']) && \is_array($payload['DataSegment']) ? $payload['DataSegment'] : null,
                    'dataConstant' => isset($payload['DataConstant']) && \is_array($payload['DataConstant']) ? $payload['DataConstant'] : null,
                    'responseHeaders' => $includeResponseHeaders ? $response['headers'] : null,
                ],
                $response['requestId']
            );
        } catch (ApiException $exception) {
            return $this->errorEnvelope(
                sprintf('Unable to call %s for %s.', $endpoint->name, $app->name),
                $exception
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|CallToolResult
     */
    public function callConfiguredEndpoint(
        InternalAppDefinition $app,
        InternalAppEndpointDefinition $endpoint,
        array $payload = [],
        bool $includeResponseHeaders = false,
    ): array|CallToolResult {
        try {
            $response = $this->client->requestEndpoint($app, $endpoint, $payload);

            return $this->successEnvelope(
                [
                    'app' => [
                        'slug' => $app->slug,
                        'name' => $app->name,
                    ],
                    'endpoint' => [
                        'name' => $endpoint->name,
                        'toolName' => $endpoint->toolName,
                        'method' => $endpoint->method,
                        'parameterMode' => $endpoint->parameterMode,
                        'url' => $app->apiUrl($endpoint->path),
                    ],
                    'request' => [
                        'payload' => [] === $payload ? null : $payload,
                        'includeResponseHeaders' => $includeResponseHeaders,
                    ],
                    'statusCode' => $response['statusCode'],
                    'contentType' => $response['data']['contentType'],
                    'responseType' => $response['data']['responseType'],
                    'payload' => $response['data']['payload'],
                    'responseHeaders' => $includeResponseHeaders ? $response['headers'] : null,
                ],
                $response['requestId']
            );
        } catch (ApiException $exception) {
            return $this->errorEnvelope(
                sprintf('Unable to call %s for %s.', $endpoint->name, $app->name),
                $exception
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getServerConfiguration(): array
    {
        return $this->config->publicConfiguration();
    }

    /**
     * @return array<string, mixed>
     */
    public function getAuthConfiguration(): array
    {
        return $this->config->authConfiguration();
    }

    /**
     * @return array<string, mixed>
     */
    public function getApplicationsCatalog(): array
    {
        return $this->config->applicationsCatalog();
    }

    /**
     * @return array<string, mixed>
     */
    public function getEndpointContract(): array
    {
        return $this->config->endpointContract();
    }

    /**
     * @return array<string, mixed>
     */
    public function getEndpointDefaults(InternalAppDefinition $app, InternalAppEndpointDefinition $endpoint): array
    {
        return [
            'app' => [
                'slug' => $app->slug,
                'name' => $app->name,
                'description' => $app->description,
            ],
            'endpoint' => $endpoint->name,
            'toolName' => $endpoint->toolName,
            'url' => $app->apiUrl($endpoint->path),
            'defaultRequest' => [
                'method' => $endpoint->method,
                'parameterMode' => $endpoint->parameterMode,
                'arguments' => $endpoint->requestDefaults,
                'upstreamPayload' => $endpoint->requestExample,
            ],
            'headers' => $endpoint->headers,
            'notes' => $endpoint->notes,
            'observedBehavior' => $endpoint->observedBehavior,
            'responseShape' => $endpoint->responseShape,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function successEnvelope(array $data, ?string $requestId = null): array
    {
        return [
            'ok' => true,
            'requestId' => $requestId,
            'data' => $data,
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed>|null $details
     */
    private function errorEnvelope(
        string $message,
        ?ApiException $exception = null,
        ?array $details = null,
    ): CallToolResult {
        $resolvedDetails = $details
            ?? $exception?->responseData
            ?? (null !== $exception ? ['upstreamMessage' => $exception->getMessage()] : null);

        $structured = [
            'ok' => false,
            'requestId' => $exception?->requestId,
            'data' => null,
            'error' => [
                'message' => $message,
                'statusCode' => $exception?->statusCode,
                'requestId' => $exception?->requestId,
                'details' => $resolvedDetails,
            ],
        ];

        return new CallToolResult(
            content: [new TextContent($message)],
            isError: true,
            structuredContent: $structured
        );
    }

    /**
     * @param string[] $allowedBranches
     *
     * @return string[]
     */
    private function normalizeAllowedBranches(array $allowedBranches): array
    {
        $normalized = [];

        foreach ($allowedBranches as $branch) {
            if (!\is_string($branch)) {
                continue;
            }

            $trimmed = trim($branch);
            if ('' === $trimmed) {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function mapArgumentsToPayload(InternalAppEndpointDefinition $endpoint, array $arguments): array
    {
        if ([] === $endpoint->requestMap) {
            return $arguments;
        }

        $payload = [];

        foreach ($endpoint->requestMap as $mapping) {
            $argument = isset($mapping['argument']) && \is_string($mapping['argument']) ? $mapping['argument'] : null;
            $target = isset($mapping['target']) && \is_string($mapping['target']) ? $mapping['target'] : null;
            if (null === $argument || null === $target || !array_key_exists($argument, $arguments)) {
                continue;
            }

            $value = $arguments[$argument];
            $transform = isset($mapping['transform']) && \is_string($mapping['transform']) ? $mapping['transform'] : null;
            if ('bool_string' === $transform) {
                $value = $value ? 'true' : 'false';
            }

            $payload[$target] = $value;
        }

        return $payload;
    }
}
