<?php

declare(strict_types=1);

namespace InternalAppMcp\Http\Psr7;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

abstract class AbstractMessage implements MessageInterface
{
    /**
     * @var array<string, string[]>
     */
    protected array $headers = [];

    /**
     * @var array<string, string>
     */
    protected array $headerNames = [];

    protected StreamInterface $body;

    public function __construct(
        array $headers = [],
        ?StreamInterface $body = null,
        protected string $protocolVersion = '1.1',
    ) {
        $this->body = $body ?? Stream::create();

        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;

        return $clone;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $normalized = strtolower($name);
        if (!isset($this->headerNames[$normalized])) {
            return [];
        }

        return $this->headers[$this->headerNames[$normalized]];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $clone->removeHeader($name);
        $clone->setHeader($name, $value);

        return $clone;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $normalized = strtolower($name);
        $values = $clone->normalizeHeaderValue($value);

        if (isset($clone->headerNames[$normalized])) {
            $originalName = $clone->headerNames[$normalized];
            $clone->headers[$originalName] = array_merge($clone->headers[$originalName], $values);

            return $clone;
        }

        $clone->setHeader($name, $values);

        return $clone;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        $clone = clone $this;
        $clone->removeHeader($name);

        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    /**
     * @param string|string[] $value
     */
    protected function setHeader(string $name, string|array $value): void
    {
        $this->assertValidHeaderName($name);
        $normalized = strtolower($name);
        $values = $this->normalizeHeaderValue($value);

        $this->headers[$name] = $values;
        $this->headerNames[$normalized] = $name;
    }

    protected function removeHeader(string $name): void
    {
        $normalized = strtolower($name);
        if (!isset($this->headerNames[$normalized])) {
            return;
        }

        $originalName = $this->headerNames[$normalized];
        unset($this->headers[$originalName], $this->headerNames[$normalized]);
    }

    /**
     * @param string|string[] $value
     *
     * @return string[]
     */
    protected function normalizeHeaderValue(string|array $value): array
    {
        $values = \is_array($value) ? array_values($value) : [$value];
        $normalized = [];

        foreach ($values as $entry) {
            if (!\is_scalar($entry) && !$entry instanceof \Stringable) {
                throw new \InvalidArgumentException('Header values must be strings or stringable scalars.');
            }

            $entry = trim((string) $entry);
            if (str_contains($entry, "\r") || str_contains($entry, "\n")) {
                throw new \InvalidArgumentException('Header values must not contain line breaks.');
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    protected function assertValidHeaderName(string $name): void
    {
        if ('' === $name || 1 !== preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
            throw new \InvalidArgumentException(sprintf('Invalid HTTP header name "%s".', $name));
        }
    }
}
