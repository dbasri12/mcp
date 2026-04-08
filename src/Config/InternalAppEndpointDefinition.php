<?php

declare(strict_types=1);

namespace InternalAppMcp\Config;

final readonly class InternalAppEndpointDefinition
{
    /**
     * @param array<string, mixed> $inputSchema
     * @param array<string, mixed>|null $outputSchema
     * @param array<int, array<string, mixed>> $requestMap
     * @param array<string, mixed> $requestDefaults
     * @param array<string, mixed> $requestExample
     * @param array<int, array<string, mixed>> $headers
     * @param string[] $notes
     * @param string[] $observedBehavior
     * @param array<string, mixed> $responseShape
     */
    public function __construct(
        public string $appSlug,
        public string $appName,
        public string $name,
        public string $path,
        public string $toolName,
        public string $title,
        public string $description,
        public string $purpose,
        public string $method,
        public string $parameterMode,
        public string $handlerProfile,
        public array $inputSchema,
        public ?array $outputSchema,
        public array $requestMap,
        public array $requestDefaults,
        public array $requestExample,
        public array $headers,
        public array $notes,
        public array $observedBehavior,
        public array $responseShape,
        public string $resourceUri,
        public string $resourceName,
        public string $resourceDescription,
        public bool $readOnlyHint = true,
        public bool $destructiveHint = false,
        public bool $idempotentHint = true,
        public bool $openWorldHint = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(string $url): array
    {
        return [
            'appSlug' => $this->appSlug,
            'appName' => $this->appName,
            'name' => $this->name,
            'path' => $this->path,
            'toolName' => $this->toolName,
            'title' => $this->title,
            'description' => $this->description,
            'purpose' => $this->purpose,
            'method' => $this->method,
            'parameterMode' => $this->parameterMode,
            'handlerProfile' => $this->handlerProfile,
            'url' => $url,
            'headers' => $this->headers,
            'requestDefaults' => $this->requestDefaults,
            'requestExample' => $this->requestExample,
            'notes' => $this->notes,
            'observedBehavior' => $this->observedBehavior,
            'responseShape' => $this->responseShape,
            'resource' => [
                'uri' => $this->resourceUri,
                'name' => $this->resourceName,
                'description' => $this->resourceDescription,
            ],
        ];
    }
}
