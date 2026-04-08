<?php

declare(strict_types=1);

namespace InternalAppMcp;

use InternalAppMcp\Capabilities\InternalAppCapabilities;
use InternalAppMcp\Config\InternalAppDefinition;
use InternalAppMcp\Config\InternalAppEndpointDefinition;
use InternalAppMcp\Config\InternalAppServerConfig;
use InternalAppMcp\Http\InternalAppApiClient;
use InternalAppMcp\Logging\StreamLogger;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Psr\Log\LoggerInterface;

final class InternalAppServerFactory
{
    /**
     * @return array{server: Server, logger: LoggerInterface}
     */
    public static function build(string $projectRoot): array
    {
        $config = InternalAppServerConfig::fromEnvironment($projectRoot);
        $logger = new StreamLogger($config->logLevel, $config->logFile);
        $client = new InternalAppApiClient($config, $logger);
        $capabilities = new InternalAppCapabilities($client, $config);
        $primaryApp = $config->appDefinitions()[0] ?? null;

        $builder = Server::builder()
            ->setLogger($logger)
            ->setServerInfo(
                $config->serverName,
                $config->serverVersion,
                'Production-ready MCP adapter for multiple internal application endpoints.',
                websiteUrl: $primaryApp?->apiUrl()
            )
            ->setInstructions($config->instructions())
            ->setPaginationLimit($config->paginationLimit)
            ->setSession(new FileSessionStore($config->sessionDirectory, $config->sessionTtl), ttl: $config->sessionTtl)
            ->addResource(
                \Closure::fromCallable([$capabilities, 'getServerConfiguration']),
                'internal-app://config/server',
                'internal_app_server_config',
                'Sanitized MCP server runtime configuration.',
                'application/json'
            )
            ->addResource(
                \Closure::fromCallable([$capabilities, 'getAuthConfiguration']),
                'internal-app://config/auth',
                'internal_app_auth_config',
                'Redacted authentication configuration for the configured upstream internal apps.',
                'application/json'
            )
            ->addResource(
                \Closure::fromCallable([$capabilities, 'getApplicationsCatalog']),
                'internal-app://catalog/apps',
                'internal_app_catalog',
                'Catalog of apps and tools exposed by this MCP server.',
                'application/json'
            )
            ->addResource(
                \Closure::fromCallable([$capabilities, 'getEndpointContract']),
                'internal-app://contract/endpoints',
                'internal_app_endpoint_contract',
                'The upstream endpoint contract currently exposed by this MCP adapter.',
                'application/json'
            );

        foreach ($config->appDefinitions() as $app) {
            foreach ($app->endpoints as $endpoint) {
                $builder->addTool(
                    self::toolHandler($capabilities, $app, $endpoint),
                    $endpoint->toolName,
                    $endpoint->description,
                    annotations: new ToolAnnotations(
                        title: $endpoint->title,
                        readOnlyHint: $endpoint->readOnlyHint,
                        destructiveHint: $endpoint->destructiveHint,
                        idempotentHint: $endpoint->idempotentHint,
                        openWorldHint: $endpoint->openWorldHint,
                    ),
                    inputSchema: $endpoint->inputSchema,
                    outputSchema: $endpoint->outputSchema,
                );

                $builder->addResource(
                    static fn () => $capabilities->getEndpointDefaults($app, $endpoint),
                    $endpoint->resourceUri,
                    $endpoint->resourceName,
                    $endpoint->resourceDescription,
                    'application/json'
                );
            }
        }

        return [
            'server' => $builder->build(),
            'logger' => $logger,
        ];
    }

    /**
     * @return callable
     */
    private static function toolHandler(
        InternalAppCapabilities $capabilities,
        InternalAppDefinition $app,
        InternalAppEndpointDefinition $endpoint,
    ): callable {
        return match ($endpoint->handlerProfile) {
            'dashboard_fetch_general' => static function (
                array $allowedBranches,
                ?int $daysChange = null,
                ?bool $reportFlag = null,
                bool $includeResponseHeaders = false,
            ) use ($capabilities, $app, $endpoint): array|\Mcp\Schema\Result\CallToolResult {
                return $capabilities->callDashboardFetchGeneral(
                    $app,
                    $endpoint,
                    $allowedBranches,
                    $daysChange,
                    $reportFlag,
                    $includeResponseHeaders,
                );
            },
            default => static function (
                array $payload = [],
                bool $includeResponseHeaders = false,
            ) use ($capabilities, $app, $endpoint): array|\Mcp\Schema\Result\CallToolResult {
                return $capabilities->callConfiguredEndpoint($app, $endpoint, $payload, $includeResponseHeaders);
            },
        };
    }
}
