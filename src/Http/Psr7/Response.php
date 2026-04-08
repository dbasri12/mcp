<?php

declare(strict_types=1);

namespace InternalAppMcp\Http\Psr7;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class Response extends AbstractMessage implements ResponseInterface
{
    /**
     * @var array<int, string>
     */
    private const REASON_PHRASES = [
        200 => 'OK',
        202 => 'Accepted',
        204 => 'No Content',
        400 => 'Bad Request',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error',
    ];

    public function __construct(
        private int $statusCode = 200,
        private string $reasonPhrase = '',
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
    ) {
        parent::__construct($headers, $body, $protocolVersion);

        if ('' === $this->reasonPhrase) {
            $this->reasonPhrase = self::REASON_PHRASES[$this->statusCode] ?? '';
        }
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        if ($code < 100 || $code > 599) {
            throw new \InvalidArgumentException('HTTP status code must be between 100 and 599.');
        }

        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = '' !== $reasonPhrase ? $reasonPhrase : (self::REASON_PHRASES[$code] ?? '');

        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }
}
