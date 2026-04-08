<?php

declare(strict_types=1);

namespace InternalAppMcp\Http\Psr7;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class ServerRequest extends Request implements ServerRequestInterface
{
    /**
     * @param array<string, mixed> $serverParams
     */
    public function __construct(
        array $serverParams,
        string $method,
        UriInterface $uri,
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
        private array $cookieParams = [],
        private array $queryParams = [],
        private array $uploadedFiles = [],
        private mixed $parsedBody = null,
        private array $attributes = [],
    ) {
        parent::__construct($method, $uri, $headers, $body, $protocolVersion);
        $this->serverParams = $serverParams;
    }

    /**
     * @var array<string, mixed>
     */
    private array $serverParams;

    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->cookieParams = $cookies;

        return $clone;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function withQueryParams(array $query): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->queryParams = $query;

        return $clone;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $this->assertUploadedFiles($uploadedFiles);

        $clone = clone $this;
        $clone->uploadedFiles = $uploadedFiles;

        return $clone;
    }

    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        if (null !== $data && !\is_array($data) && !\is_object($data)) {
            throw new \InvalidArgumentException('Parsed body must be null, array, or object.');
        }

        $clone = clone $this;
        $clone->parsedBody = $data;

        return $clone;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }

    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);

        return $clone;
    }

    private function assertUploadedFiles(array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $file) {
            if (\is_array($file)) {
                $this->assertUploadedFiles($file);
                continue;
            }

            if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
                throw new \InvalidArgumentException('Uploaded files must contain only UploadedFileInterface instances.');
            }
        }
    }
}
