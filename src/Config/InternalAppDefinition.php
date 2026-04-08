<?php

declare(strict_types=1);

namespace InternalAppMcp\Config;

final readonly class InternalAppDefinition
{
    /**
     * @param array<string, InternalAppEndpointDefinition> $endpoints
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $description,
        public string $baseUrl,
        public string $apiBasePath,
        public ?string $apiToken,
        public string $authHeader,
        public string $authScheme,
        public string $userAgent,
        public array $endpoints,
    ) {
    }

    public function authHeaderValue(): ?string
    {
        if (null === $this->apiToken || '' === $this->apiToken) {
            return null;
        }

        if ('' === $this->authScheme) {
            return $this->apiToken;
        }

        return trim($this->authScheme.' '.$this->apiToken);
    }

    public function apiUrl(string $path = ''): string
    {
        $base = $this->baseUrl.$this->apiBasePath;

        if ('' === $path) {
            return $base;
        }

        return $base.'/'.ltrim($path, '/');
    }

    public function isPlaceholderBaseUrl(): bool
    {
        $host = (string) parse_url($this->baseUrl, \PHP_URL_HOST);

        return str_contains($host, 'example.com');
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'baseUrl' => $this->baseUrl,
            'apiBasePath' => $this->apiBasePath,
            'apiBaseUrl' => $this->apiUrl(),
            'authHeader' => $this->authHeader,
            'authScheme' => $this->authScheme,
            'apiTokenConfigured' => null !== $this->apiToken && '' !== $this->apiToken,
            'isPlaceholderBaseUrl' => $this->isPlaceholderBaseUrl(),
            'userAgent' => $this->userAgent,
            'endpointCount' => \count($this->endpoints),
            'tools' => array_map(
                fn (InternalAppEndpointDefinition $endpoint): array => [
                    'toolName' => $endpoint->toolName,
                    'title' => $endpoint->title,
                    'endpoint' => $endpoint->name,
                    'method' => $endpoint->method,
                    'parameterMode' => $endpoint->parameterMode,
                    'url' => $this->apiUrl($endpoint->path),
                ],
                array_values($this->endpoints)
            ),
        ];
    }
}
