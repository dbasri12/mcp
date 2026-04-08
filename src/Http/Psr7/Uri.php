<?php

declare(strict_types=1);

namespace InternalAppMcp\Http\Psr7;

use Psr\Http\Message\UriInterface;

final class Uri implements UriInterface
{
    public function __construct(
        private string $scheme = '',
        private string $user = '',
        private ?string $password = null,
        private string $host = '',
        private ?int $port = null,
        private string $path = '',
        private string $query = '',
        private string $fragment = '',
    ) {
    }

    public static function fromString(string $uri = ''): self
    {
        if ('' === $uri) {
            return new self();
        }

        $parts = parse_url($uri);
        if (false === $parts) {
            throw new \InvalidArgumentException(sprintf('Invalid URI "%s".', $uri));
        }

        return new self(
            scheme: strtolower((string) ($parts['scheme'] ?? '')),
            user: (string) ($parts['user'] ?? ''),
            password: isset($parts['pass']) ? (string) $parts['pass'] : null,
            host: strtolower((string) ($parts['host'] ?? '')),
            port: isset($parts['port']) ? (int) $parts['port'] : null,
            path: (string) ($parts['path'] ?? ''),
            query: (string) ($parts['query'] ?? ''),
            fragment: (string) ($parts['fragment'] ?? ''),
        );
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ('' === $this->host) {
            return '';
        }

        $authority = $this->host;
        $userInfo = $this->getUserInfo();
        if ('' !== $userInfo) {
            $authority = $userInfo.'@'.$authority;
        }

        $port = $this->getPort();
        if (null !== $port) {
            $authority .= ':'.$port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        if ('' === $this->user) {
            return '';
        }

        return null === $this->password ? $this->user : $this->user.':'.$this->password;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        if (null === $this->port) {
            return null;
        }

        $defaultPort = match ($this->scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };

        return $this->port === $defaultPort ? null : $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): UriInterface
    {
        $scheme = strtolower($scheme);
        if ('' !== $scheme && !\in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only http and https URI schemes are supported.');
        }

        $clone = clone $this;
        $clone->scheme = $scheme;

        return $clone;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $clone = clone $this;
        $clone->user = $user;
        $clone->password = $password;

        return $clone;
    }

    public function withHost(string $host): UriInterface
    {
        $clone = clone $this;
        $clone->host = strtolower($host);

        return $clone;
    }

    public function withPort(?int $port): UriInterface
    {
        if (null !== $port && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('Port must be between 1 and 65535.');
        }

        $clone = clone $this;
        $clone->port = $port;

        return $clone;
    }

    public function withPath(string $path): UriInterface
    {
        $clone = clone $this;
        $clone->path = $path;

        return $clone;
    }

    public function withQuery(string $query): UriInterface
    {
        $clone = clone $this;
        $clone->query = ltrim($query, '?');

        return $clone;
    }

    public function withFragment(string $fragment): UriInterface
    {
        $clone = clone $this;
        $clone->fragment = ltrim($fragment, '#');

        return $clone;
    }

    public function __toString(): string
    {
        $uri = '';

        if ('' !== $this->scheme) {
            $uri .= $this->scheme.':';
        }

        $authority = $this->getAuthority();
        if ('' !== $authority) {
            $uri .= '//'.$authority;
        }

        $path = $this->path;
        if ('' !== $path) {
            if ('' !== $authority && !str_starts_with($path, '/')) {
                $path = '/'.$path;
            }
            if ('' === $authority && str_starts_with($path, '//')) {
                $path = '/'.ltrim($path, '/');
            }
        }

        $uri .= $path;

        if ('' !== $this->query) {
            $uri .= '?'.$this->query;
        }

        if ('' !== $this->fragment) {
            $uri .= '#'.$this->fragment;
        }

        return $uri;
    }
}
