<?php

namespace App\Services\Mcp;

use Illuminate\Session\Store;

class McpAppCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $apps = config('mcp.apps', []);

        if (! is_array($apps)) {
            return [];
        }

        return array_values(array_filter($apps, fn (mixed $app): bool => is_array($app)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        foreach ($this->all() as $app) {
            if (($app['slug'] ?? null) === $slug) {
                return $app;
            }
        }

        return null;
    }

    /**
     * @return array{app:array<string, mixed>,prompt:string}|null
     */
    public function matchPrompt(string $message): ?array
    {
        $normalizedMessage = trim($message);

        if ($normalizedMessage === '') {
            return null;
        }

        foreach ($this->all() as $app) {
            $commandPrefix = trim((string) ($app['command_prefix'] ?? ''));

            if ($commandPrefix === '') {
                continue;
            }

            $segments = preg_split('/\s+/', $commandPrefix) ?: [];
            $escapedSegments = array_map(
                static fn (string $segment): string => preg_quote($segment, '/'),
                array_filter($segments, static fn (string $segment): bool => $segment !== ''),
            );

            if ($escapedSegments === []) {
                continue;
            }

            $pattern = '/^'.implode('\s+', $escapedSegments).'\b[\s,:-]*(.*)$/iu';
            $matches = [];

            if (preg_match($pattern, $normalizedMessage, $matches) !== 1) {
                continue;
            }

            return [
                'app' => $app,
                'prompt' => trim((string) ($matches[1] ?? '')),
            ];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sharedPayload(Store $session, McpSessionStore $sessionStore): array
    {
        return array_map(function (array $app) use ($session, $sessionStore): array {
            $connection = $sessionStore->get($session, (string) $app['slug']) ?? [];
            $endpoint = trim((string) ($app['endpoint'] ?? ''));
            $endpointHost = parse_url($endpoint, PHP_URL_HOST);
            $endpointPath = parse_url($endpoint, PHP_URL_PATH);

            return [
                'slug' => $app['slug'],
                'name' => $app['name'],
                'shortName' => $app['short_name'] ?? $app['name'],
                'tagline' => $app['tagline'] ?? null,
                'description' => $app['description'] ?? null,
                'commandPrefix' => $app['command_prefix'],
                'capabilities' => array_values(array_filter($app['capabilities'] ?? [], 'is_string')),
                'samplePrompts' => array_values(array_filter($app['sample_prompts'] ?? [], 'is_string')),
                'endpoint' => $endpoint,
                'endpointLabel' => is_string($endpointHost) ? $endpointHost.($endpointPath ? $endpointPath : '') : $endpoint,
                'isConnected' => is_string($connection['session_id'] ?? null) && trim((string) $connection['session_id']) !== '',
                'connectedAt' => $connection['connected_at'] ?? null,
                'serverInfo' => $connection['server_info'] ?? null,
            ];
        }, $this->all());
    }
}
