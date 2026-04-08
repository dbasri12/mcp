<?php

declare(strict_types=1);

namespace InternalAppMcp\Http;

final class ApiException extends \RuntimeException
{
    /**
     * @param array<string, mixed>|null $responseData
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $requestId = null,
        public readonly ?array $responseData = null,
        public readonly ?string $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }
}
