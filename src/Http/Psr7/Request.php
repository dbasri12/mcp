<?php

declare(strict_types=1);

namespace InternalAppMcp\Http\Psr7;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

class Request extends AbstractMessage implements RequestInterface
{
    private ?string $requestTarget = null;

    public function __construct(
        private string $method,
        private UriInterface $uri,
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
    ) {
        parent::__construct($headers, $body, $protocolVersion);

        if (!$this->hasHeader('Host') && '' !== $this->uri->getHost()) {
            $this->setHeader('Host', $this->formatHost($this->uri));
        }
    }

    public function getRequestTarget(): string
    {
        if (null !== $this->requestTarget) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();
        if ('' === $target) {
            $target = '/';
        }

        if ('' !== $this->uri->getQuery()) {
            $target .= '?'.$this->uri->getQuery();
        }

        return $target;
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        $clone = clone $this;
        $clone->requestTarget = $requestTarget;

        return $clone;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): RequestInterface
    {
        if ('' === trim($method)) {
            throw new \InvalidArgumentException('HTTP method cannot be empty.');
        }

        $clone = clone $this;
        $clone->method = $method;

        return $clone;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $clone = clone $this;
        $clone->uri = $uri;

        $host = $uri->getHost();
        if ('' === $host) {
            return $clone;
        }

        if ($preserveHost && $clone->hasHeader('Host') && '' !== $clone->getHeaderLine('Host')) {
            return $clone;
        }

        $clone->removeHeader('Host');
        $clone->setHeader('Host', $this->formatHost($uri));

        return $clone;
    }

    private function formatHost(UriInterface $uri): string
    {
        $host = $uri->getHost();
        $port = $uri->getPort();

        return null === $port ? $host : $host.':'.$port;
    }
}
