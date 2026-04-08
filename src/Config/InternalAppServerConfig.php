<?php

declare(strict_types=1);

namespace InternalAppMcp\Config;

use InternalAppMcp\Schema\ToolSchemas;
use Psr\Log\LogLevel;

final readonly class InternalAppServerConfig
{
    private const DEFAULT_BASE_URL = 'https://newdev.bcainsurance.co.id';

    /**
     * @param array<string, InternalAppDefinition> $apps
     */
    public function __construct(
        public string $projectRoot,
        public string $serverName,
        public string $serverVersion,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
        public int $maxRetries,
        public int $paginationLimit,
        public int $sessionTtl,
        public bool $verifyTls,
        public string $sessionDirectory,
        public string $logLevel,
        public ?string $logFile,
        public string $registryFile,
        public array $apps,
    ) {
    }

    public static function fromEnvironment(string $projectRoot): self
    {
        Environment::loadFile($projectRoot.'/.env');

        $sessionDirectory = self::resolvePath(
            $projectRoot,
            self::envString('MCP_SESSION_DIR', 'storage/sessions')
        );

        $logFile = self::envOptionalString('MCP_LOG_FILE');
        if (null !== $logFile) {
            $logFile = self::resolvePath($projectRoot, $logFile);
        }

        $registryFile = self::resolvePath(
            $projectRoot,
            self::envString('INTERNAL_APP_REGISTRY_FILE', 'config/internal-apps.php')
        );

        return new self(
            projectRoot: $projectRoot,
            serverName: self::envString('MCP_SERVER_NAME', 'Internal Applications MCP Server'),
            serverVersion: self::envString('MCP_SERVER_VERSION', '1.1.0'),
            connectTimeoutSeconds: self::envInt('MCP_CONNECT_TIMEOUT_SECONDS', 5, 1),
            timeoutSeconds: self::envInt('MCP_TIMEOUT_SECONDS', 20, 1),
            maxRetries: self::envInt('MCP_MAX_RETRIES', 2, 0),
            paginationLimit: self::envInt('MCP_PAGINATION_LIMIT', 50, 1),
            sessionTtl: self::envInt('MCP_SESSION_TTL', 3600, 60),
            verifyTls: self::envBool('MCP_VERIFY_TLS', false),
            sessionDirectory: $sessionDirectory,
            logLevel: self::normalizeLogLevel(self::envString('MCP_LOG_LEVEL', LogLevel::WARNING)),
            logFile: $logFile,
            registryFile: $registryFile,
            apps: self::loadApplications($registryFile)
        );
    }

    /**
     * @return array<int, InternalAppDefinition>
     */
    public function appDefinitions(): array
    {
        return array_values($this->apps);
    }

    /**
     * @return array<int, InternalAppEndpointDefinition>
     */
    public function endpointDefinitions(): array
    {
        $endpoints = [];

        foreach ($this->apps as $app) {
            foreach ($app->endpoints as $endpoint) {
                $endpoints[] = $endpoint;
            }
        }

        return $endpoints;
    }

    /**
     * @return array<int, string>
     */
    public function toolNames(): array
    {
        return array_values(array_map(
            static fn (InternalAppEndpointDefinition $endpoint): string => $endpoint->toolName,
            $this->endpointDefinitions()
        ));
    }

    /**
     * @return array<int, string>
     */
    public function resourceUris(): array
    {
        $uris = [
            'internal-app://config/server',
            'internal-app://config/auth',
            'internal-app://catalog/apps',
            'internal-app://contract/endpoints',
        ];

        foreach ($this->endpointDefinitions() as $endpoint) {
            $uris[] = $endpoint->resourceUri;
        }

        return array_values(array_unique($uris));
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfiguration(): array
    {
        return [
            'serverName' => $this->serverName,
            'serverVersion' => $this->serverVersion,
            'registryFile' => $this->registryFile,
            'timeouts' => [
                'connectSeconds' => $this->connectTimeoutSeconds,
                'requestSeconds' => $this->timeoutSeconds,
            ],
            'retries' => $this->maxRetries,
            'paginationLimit' => $this->paginationLimit,
            'verifyTls' => $this->verifyTls,
            'sessionDirectory' => $this->sessionDirectory,
            'sessionTtl' => $this->sessionTtl,
            'logLevel' => $this->logLevel,
            'logFile' => $this->logFile,
            'appCount' => \count($this->apps),
            'apps' => $this->applicationsCatalog()['apps'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function authConfiguration(): array
    {
        return [
            'apps' => array_map(
                fn (InternalAppDefinition $app): array => [
                    'slug' => $app->slug,
                    'name' => $app->name,
                    'authHeader' => $app->authHeader,
                    'authScheme' => $app->authScheme,
                    'apiTokenConfigured' => null !== $app->apiToken && '' !== $app->apiToken,
                    'apiTokenPreview' => $this->tokenPreview($app->apiToken),
                    'notes' => array_values(array_unique(array_merge(
                        ['Authentication requirements depend on the upstream app and its configured headers.'],
                        ...array_map(static fn (InternalAppEndpointDefinition $endpoint): array => $endpoint->notes, array_values($app->endpoints))
                    ))),
                ],
                $this->appDefinitions()
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function applicationsCatalog(): array
    {
        return [
            'apps' => array_map(
                static fn (InternalAppDefinition $app): array => $app->summary(),
                $this->appDefinitions()
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function endpointContract(): array
    {
        return [
            'serverName' => $this->serverName,
            'appCount' => \count($this->apps),
            'foldersAreEndpoints' => true,
            'apps' => array_map(
                static fn (InternalAppDefinition $app): array => [
                    'slug' => $app->slug,
                    'name' => $app->name,
                    'description' => $app->description,
                    'baseApiUrl' => $app->apiUrl(),
                    'endpoints' => array_map(
                        static fn (InternalAppEndpointDefinition $endpoint): array => $endpoint->summary($app->apiUrl($endpoint->path)),
                        array_values($app->endpoints)
                    ),
                ],
                $this->appDefinitions()
            ),
        ];
    }

    public function instructions(): string
    {
        $appNames = array_map(static fn (InternalAppDefinition $app): string => $app->name, $this->appDefinitions());
        $toolNames = $this->toolNames();

        return sprintf(
            'Use this server to access %d configured internal application(s): %s. Available MCP tools: %s. Browse internal-app://catalog/apps for the app catalog and internal-app://contract/endpoints for full upstream contracts. Each endpoint also exposes a defaults resource with request examples and notes.',
            \count($appNames),
            '' === implode(', ', $appNames) ? 'none' : implode(', ', $appNames),
            '' === implode(', ', $toolNames) ? 'none' : implode(', ', $toolNames),
        );
    }

    /**
     * @return array<string, InternalAppDefinition>
     */
    private static function loadApplications(string $registryFile): array
    {
        $registry = self::loadRegistryFile($registryFile) ?? self::defaultRegistry();
        $rawApps = $registry['apps'] ?? null;

        if (!\is_array($rawApps)) {
            throw new \InvalidArgumentException('The internal app registry must contain an apps array.');
        }

        $apps = [];

        foreach ($rawApps as $rawApp) {
            if (!\is_array($rawApp)) {
                continue;
            }

            if (array_key_exists('enabled', $rawApp) && false === self::toBool($rawApp['enabled'])) {
                continue;
            }

            $app = self::normalizeApplication($rawApp);
            $apps[$app->slug] = $app;
        }

        if ([] === $apps) {
            throw new \InvalidArgumentException('No enabled apps were found in the internal app registry.');
        }

        return $apps;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadRegistryFile(string $registryFile): ?array
    {
        if (!is_file($registryFile)) {
            return null;
        }

        $registry = require $registryFile;

        if (!\is_array($registry)) {
            throw new \InvalidArgumentException(sprintf('The registry file %s must return an array.', $registryFile));
        }

        return $registry;
    }

    /**
     * @param array<string, mixed> $rawApp
     */
    private static function normalizeApplication(array $rawApp): InternalAppDefinition
    {
        $slug = self::normalizeSlug(self::requiredString($rawApp, 'slug'));
        $name = self::requiredString($rawApp, 'name');
        $description = self::optionalStringFromArray($rawApp, 'description') ?? 'Internal application';
        $baseUrl = rtrim(self::optionalStringFromArray($rawApp, 'base_url') ?? self::envString('INTERNAL_APP_BASE_URL', self::DEFAULT_BASE_URL), '/');
        $scheme = parse_url($baseUrl, \PHP_URL_SCHEME);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException(sprintf('App %s base_url must start with http:// or https://.', $slug));
        }

        $apiBasePath = self::normalizeApiBasePath(self::optionalStringFromArray($rawApp, 'api_base_path') ?? self::envString('INTERNAL_APP_API_BASE_PATH', '/'));
        $authHeader = self::optionalStringFromArray($rawApp, 'auth_header') ?? self::envString('INTERNAL_APP_AUTH_HEADER', 'Authorization');
        $authScheme = self::optionalStringFromArray($rawApp, 'auth_scheme') ?? self::envMaybeEmptyString('INTERNAL_APP_AUTH_SCHEME', '');
        $apiToken = self::optionalNullableString($rawApp['api_token'] ?? self::envOptionalString('INTERNAL_APP_API_TOKEN'));
        $userAgent = self::optionalStringFromArray($rawApp, 'user_agent') ?? self::envString('INTERNAL_APP_USER_AGENT', 'internal-app-mcp-server/1.1.0');
        $rawEndpoints = $rawApp['endpoints'] ?? null;

        if (!\is_array($rawEndpoints) || [] === $rawEndpoints) {
            throw new \InvalidArgumentException(sprintf('App %s must define at least one endpoint.', $slug));
        }

        $endpoints = [];
        foreach ($rawEndpoints as $rawEndpoint) {
            if (!\is_array($rawEndpoint)) {
                continue;
            }

            if (array_key_exists('enabled', $rawEndpoint) && false === self::toBool($rawEndpoint['enabled'])) {
                continue;
            }

            $endpoint = self::normalizeEndpoint($slug, $name, $rawEndpoint);
            $endpoints[$endpoint->toolName] = $endpoint;
        }

        if ([] === $endpoints) {
            throw new \InvalidArgumentException(sprintf('App %s has no enabled endpoints.', $slug));
        }

        return new InternalAppDefinition(
            slug: $slug,
            name: $name,
            description: $description,
            baseUrl: $baseUrl,
            apiBasePath: $apiBasePath,
            apiToken: $apiToken,
            authHeader: $authHeader,
            authScheme: $authScheme,
            userAgent: $userAgent,
            endpoints: $endpoints,
        );
    }

    /**
     * @param array<string, mixed> $rawEndpoint
     */
    private static function normalizeEndpoint(string $appSlug, string $appName, array $rawEndpoint): InternalAppEndpointDefinition
    {
        $name = self::requiredString($rawEndpoint, 'name');
        $path = self::normalizeEndpointPath(self::optionalStringFromArray($rawEndpoint, 'path') ?? $name);
        $toolName = self::requiredString($rawEndpoint, 'tool_name');
        $title = self::optionalStringFromArray($rawEndpoint, 'title') ?? $name;
        $description = self::optionalStringFromArray($rawEndpoint, 'description') ?? sprintf('Call the %s endpoint for %s.', $name, $appName);
        $purpose = self::optionalStringFromArray($rawEndpoint, 'purpose') ?? $description;
        $method = strtoupper(self::optionalStringFromArray($rawEndpoint, 'method') ?? 'POST');
        $parameterMode = strtolower(self::optionalStringFromArray($rawEndpoint, 'parameter_mode') ?? 'json');
        $handlerProfile = strtolower(self::optionalStringFromArray($rawEndpoint, 'handler_profile') ?? 'generic_payload');
        $requestDefaults = self::mixedArray($rawEndpoint['request_defaults'] ?? []);
        $requestExample = self::mixedArray($rawEndpoint['request_example'] ?? $requestDefaults);
        $inputSchema = self::mixedArray($rawEndpoint['input_schema'] ?? self::defaultInputSchema($handlerProfile, $requestDefaults));
        $outputSchema = self::optionalMixedArray($rawEndpoint['output_schema'] ?? self::defaultOutputSchema($handlerProfile));
        $requestMap = self::listOfMixedArrays($rawEndpoint['request_map'] ?? []);
        $headers = self::listOfMixedArrays($rawEndpoint['headers'] ?? []);
        $notes = self::stringList($rawEndpoint['notes'] ?? []);
        $observedBehavior = self::stringList($rawEndpoint['observed_behavior'] ?? []);
        $responseShape = self::mixedArray($rawEndpoint['response_shape'] ?? []);
        $resourceUri = self::optionalStringFromArray($rawEndpoint, 'resource_uri') ?? sprintf('internal-app://apps/%s/%s/defaults', $appSlug, str_replace('_', '-', $toolName));
        $resourceName = self::optionalStringFromArray($rawEndpoint, 'resource_name') ?? sprintf('internal_app_%s_%s_defaults', str_replace('-', '_', $appSlug), $toolName);
        $resourceDescription = self::optionalStringFromArray($rawEndpoint, 'resource_description') ?? sprintf('Configured request defaults for the %s endpoint in %s.', $name, $appName);

        if (!\in_array($parameterMode, ['json', 'form', 'query'], true)) {
            throw new \InvalidArgumentException(sprintf('Endpoint %s.%s has unsupported parameter_mode %s.', $appSlug, $toolName, $parameterMode));
        }

        if (!\in_array($handlerProfile, ['dashboard_fetch_general', 'generic_payload'], true)) {
            throw new \InvalidArgumentException(sprintf('Endpoint %s.%s has unsupported handler_profile %s.', $appSlug, $toolName, $handlerProfile));
        }

        return new InternalAppEndpointDefinition(
            appSlug: $appSlug,
            appName: $appName,
            name: $name,
            path: $path,
            toolName: $toolName,
            title: $title,
            description: $description,
            purpose: $purpose,
            method: $method,
            parameterMode: $parameterMode,
            handlerProfile: $handlerProfile,
            inputSchema: $inputSchema,
            outputSchema: $outputSchema,
            requestMap: $requestMap,
            requestDefaults: $requestDefaults,
            requestExample: $requestExample,
            headers: $headers,
            notes: $notes,
            observedBehavior: $observedBehavior,
            responseShape: $responseShape,
            resourceUri: $resourceUri,
            resourceName: $resourceName,
            resourceDescription: $resourceDescription,
            readOnlyHint: self::toBool($rawEndpoint['read_only'] ?? true),
            destructiveHint: self::toBool($rawEndpoint['destructive'] ?? false),
            idempotentHint: self::toBool($rawEndpoint['idempotent'] ?? true),
            openWorldHint: self::toBool($rawEndpoint['open_world'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultRegistry(): array
    {
        $daysChange = self::envInt('SOJ_FETCH_GENERAL_DASHBOARD_DAYS_CHANGE', 365, 1);
        $authHeader = self::envString('INTERNAL_APP_AUTH_HEADER', 'BCAi-ID');
        $userAgent = self::envString('INTERNAL_APP_USER_AGENT', 'internal-app-mcp-server/1.1.0');

        return [
            'apps' => [
                [
                    'slug' => 'dashboard-soj',
                    'name' => 'Dashboard SOJ',
                    'description' => 'Operational dashboard and reference data for SOJ Main.',
                    'base_url' => self::envString('INTERNAL_APP_BASE_URL', self::DEFAULT_BASE_URL),
                    'api_base_path' => self::envString('INTERNAL_APP_API_BASE_PATH', '/soj_api/Main'),
                    'auth_header' => $authHeader,
                    'auth_scheme' => self::envMaybeEmptyString('INTERNAL_APP_AUTH_SCHEME', ''),
                    'api_token' => self::envOptionalString('INTERNAL_APP_API_TOKEN'),
                    'user_agent' => $userAgent,
                    'endpoints' => [
                        [
                            'name' => 'FetchGeneralDashboard',
                            'path' => 'FetchGeneralDashboard/',
                            'tool_name' => 'fetch_general_dashboard',
                            'title' => 'Fetch General Dashboard',
                            'description' => 'Fetch SOJ dashboard reference data and report totals.',
                            'purpose' => 'Fetch SOJ dashboard reference data and report totals from PolisService.',
                            'method' => 'POST',
                            'parameter_mode' => 'json',
                            'handler_profile' => 'dashboard_fetch_general',
                            'input_schema' => ToolSchemas::dashboardFetchGeneralInput($daysChange),
                            'output_schema' => ToolSchemas::dashboardFetchGeneralOutput(),
                            'request_map' => [
                                ['argument' => 'allowedBranches', 'target' => 'allowedBranches'],
                                ['argument' => 'daysChange', 'target' => 'days_change'],
                                ['argument' => 'reportFlag', 'target' => 'reportflag', 'transform' => 'bool_string'],
                            ],
                            'request_defaults' => [
                                'allowedBranches' => ['0101'],
                                'daysChange' => $daysChange,
                                'reportFlag' => false,
                            ],
                            'request_example' => [
                                'allowedBranches' => ['0101'],
                                'days_change' => $daysChange,
                                'reportflag' => 'false',
                            ],
                            'headers' => [
                                [
                                    'name' => $authHeader,
                                    'required' => false,
                                    'purpose' => 'User context forwarded into PolisService.',
                                ],
                            ],
                            'notes' => [
                                'This endpoint is POST-only and reads JSON from php://input.',
                                'When reportflag is "true", the endpoint returns only DataTotalReport.',
                                'The source code also reads the optional BCAi-ID header and passes it into PolisService.',
                            ],
                            'observed_behavior' => [
                                'On April 7, 2026, POST JSON {"allowedBranches":["0101"],"days_change":365,"reportflag":"true"} returned HTTP 200 with Status=Success and DataTotalReport.',
                                'On April 7, 2026, POST JSON {"allowedBranches":["0101"],"days_change":365,"reportflag":"false"} returned HTTP 200 with Status=Success and the full dashboard payload.',
                            ],
                            'response_shape' => [
                                'Status' => 'Success',
                                'DataTotalReport' => [
                                    'Waiting Inforced' => ['count' => 16, 'change_percentage' => null],
                                    'Inforced' => ['count' => 3878, 'change_percentage' => null],
                                ],
                            ],
                            'resource_uri' => 'internal-app://soj/main/fetch-general-dashboard/defaults',
                            'resource_name' => 'internal_app_fetch_general_dashboard_defaults',
                            'resource_description' => 'Configured request defaults and observed behavior for the SOJ FetchGeneralDashboard endpoint.',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $requestDefaults
     *
     * @return array<string, mixed>
     */
    private static function defaultInputSchema(string $handlerProfile, array $requestDefaults): array
    {
        if ('dashboard_fetch_general' === $handlerProfile) {
            $daysChange = isset($requestDefaults['daysChange']) && \is_int($requestDefaults['daysChange'])
                ? $requestDefaults['daysChange']
                : self::envInt('SOJ_FETCH_GENERAL_DASHBOARD_DAYS_CHANGE', 365, 1);

            return ToolSchemas::dashboardFetchGeneralInput($daysChange);
        }

        return ToolSchemas::payloadToolInput([
            'type' => 'object',
            'additionalProperties' => true,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function defaultOutputSchema(string $handlerProfile): ?array
    {
        return match ($handlerProfile) {
            'dashboard_fetch_general' => ToolSchemas::dashboardFetchGeneralOutput(),
            default => ToolSchemas::genericEndpointOutput(),
        };
    }

    private function tokenPreview(?string $token): ?string
    {
        if (null === $token || '' === $token) {
            return null;
        }

        $length = strlen($token);

        return $length <= 8
            ? str_repeat('*', $length)
            : substr($token, 0, 4).str_repeat('*', $length - 8).substr($token, -4);
    }

    private static function normalizeEndpointPath(string $path): string
    {
        $trimmed = trim($path);
        if ('' === $trimmed) {
            return '';
        }

        return trim($trimmed, '/').'/';
    }

    private static function normalizeSlug(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        if ('' === $normalized) {
            throw new \InvalidArgumentException('App slug must contain letters or numbers.');
        }

        return $normalized;
    }

    private static function resolvePath(string $projectRoot, string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2})/', $path) === 1) {
            return $path;
        }

        return rtrim($projectRoot, '\\/').\DIRECTORY_SEPARATOR.str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $path);
    }

    private static function normalizeApiBasePath(string $path): string
    {
        $trimmed = trim($path);
        if ('' === $trimmed || '/' === $trimmed) {
            return '';
        }

        return '/'.trim($trimmed, '/');
    }

    private static function normalizeLogLevel(string $level): string
    {
        $normalized = strtolower(trim($level));
        $allowed = [
            LogLevel::DEBUG,
            LogLevel::INFO,
            LogLevel::NOTICE,
            LogLevel::WARNING,
            LogLevel::ERROR,
            LogLevel::CRITICAL,
            LogLevel::ALERT,
            LogLevel::EMERGENCY,
        ];

        return \in_array($normalized, $allowed, true) ? $normalized : LogLevel::WARNING;
    }

    private static function requiredString(array $values, string $key): string
    {
        $value = self::optionalStringFromArray($values, $key);

        if (null === $value || '' === $value) {
            throw new \InvalidArgumentException(sprintf('Missing required string key %s in internal app registry.', $key));
        }

        return $value;
    }

    private static function optionalStringFromArray(array $values, string $key): ?string
    {
        if (!array_key_exists($key, $values)) {
            return null;
        }

        return self::optionalNullableString($values[$key]);
    }

    private static function optionalNullableString(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private static function mixedArray(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function optionalMixedArray(mixed $value): ?array
    {
        return \is_array($value) ? $value : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function listOfMixedArrays(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => \is_array($item)));
    }

    /**
     * @return string[]
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $item): ?string {
            if (!\is_scalar($item)) {
                return null;
            }

            $trimmed = trim((string) $item);

            return '' === $trimmed ? null : $trimmed;
        }, $value)));
    }

    private static function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return 1 === $value;
        }

        if (\is_string($value)) {
            return \in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private static function envRaw(string $name): string|false
    {
        $value = getenv($name);
        if (false !== $value) {
            return $value;
        }

        if (array_key_exists($name, $_ENV)) {
            return (string) $_ENV[$name];
        }

        if (array_key_exists($name, $_SERVER)) {
            return (string) $_SERVER[$name];
        }

        return false;
    }

    private static function envString(string $name, string $default): string
    {
        $value = self::envRaw($name);
        if (false === $value) {
            return $default;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? $default : $trimmed;
    }

    private static function envMaybeEmptyString(string $name, string $default): string
    {
        $value = self::envRaw($name);
        if (false === $value) {
            return $default;
        }

        return trim($value);
    }

    private static function envOptionalString(string $name): ?string
    {
        $value = self::envRaw($name);
        if (false === $value) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    private static function envBool(string $name, bool $default): bool
    {
        $value = self::envRaw($name);
        if (false === $value) {
            return $default;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off', '' => false,
            default => $default,
        };
    }

    private static function envInt(string $name, int $default, int $minimum): int
    {
        $value = self::envRaw($name);
        if (false === $value) {
            return $default;
        }

        $validated = filter_var($value, \FILTER_VALIDATE_INT);
        if (false === $validated) {
            return $default;
        }

        return max($minimum, (int) $validated);
    }
}
