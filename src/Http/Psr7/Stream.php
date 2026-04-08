<?php

declare(strict_types=1);

namespace InternalAppMcp\Http\Psr7;

use Psr\Http\Message\StreamInterface;

final class Stream implements StreamInterface
{
    /**
     * @param resource|null $stream
     */
    public function __construct(
        private $stream,
    ) {
        if (null !== $this->stream && !\is_resource($this->stream)) {
            throw new \InvalidArgumentException('Stream must be a resource or null.');
        }
    }

    public static function create(string $content = ''): self
    {
        $resource = fopen('php://temp', 'r+');
        if (false === $resource) {
            throw new \RuntimeException('Unable to create temporary stream.');
        }

        if ('' !== $content) {
            fwrite($resource, $content);
            rewind($resource);
        }

        return new self($resource);
    }

    public static function fromFile(string $filename, string $mode = 'r'): self
    {
        $resource = @fopen($filename, $mode);
        if (false === $resource) {
            throw new \RuntimeException(sprintf('Unable to open stream file "%s".', $filename));
        }

        return new self($resource);
    }

    /**
     * @param resource $resource
     */
    public static function fromResource($resource): self
    {
        if (!\is_resource($resource)) {
            throw new \InvalidArgumentException('Resource expected when creating a stream from resource.');
        }

        return new self($resource);
    }

    public function __toString(): string
    {
        if (!$this->stream) {
            return '';
        }

        try {
            if ($this->isSeekable()) {
                rewind($this->stream);
            }

            $contents = stream_get_contents($this->stream);

            return false === $contents ? '' : $contents;
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    public function detach()
    {
        $stream = $this->stream;
        $this->stream = null;

        return $stream;
    }

    public function getSize(): ?int
    {
        if (!$this->stream) {
            return null;
        }

        $stats = fstat($this->stream);

        return \is_array($stats) && isset($stats['size']) ? (int) $stats['size'] : null;
    }

    public function tell(): int
    {
        $this->assertStreamAvailable();
        $position = ftell($this->stream);

        if (false === $position) {
            throw new \RuntimeException('Unable to determine stream position.');
        }

        return $position;
    }

    public function eof(): bool
    {
        return !$this->stream || feof($this->stream);
    }

    public function isSeekable(): bool
    {
        if (!$this->stream) {
            return false;
        }

        $metadata = stream_get_meta_data($this->stream);

        return (bool) ($metadata['seekable'] ?? false);
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->assertStreamAvailable();

        if (!$this->isSeekable()) {
            throw new \RuntimeException('Stream is not seekable.');
        }

        if (0 !== fseek($this->stream, $offset, $whence)) {
            throw new \RuntimeException('Unable to seek within stream.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        if (!$this->stream) {
            return false;
        }

        $mode = (string) ($this->getMetadata('mode') ?? '');

        foreach (['w', 'w+', 'rw', 'r+', 'x', 'x+', 'c', 'c+', 'a', 'a+'] as $needle) {
            if (str_contains($mode, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function write(string $string): int
    {
        $this->assertStreamAvailable();

        if (!$this->isWritable()) {
            throw new \RuntimeException('Stream is not writable.');
        }

        $written = fwrite($this->stream, $string);
        if (false === $written) {
            throw new \RuntimeException('Unable to write to stream.');
        }

        return $written;
    }

    public function isReadable(): bool
    {
        if (!$this->stream) {
            return false;
        }

        $mode = (string) ($this->getMetadata('mode') ?? '');

        foreach (['r', 'r+', 'w+', 'a+', 'x+', 'c+'] as $needle) {
            if (str_contains($mode, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function read(int $length): string
    {
        $this->assertStreamAvailable();

        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable.');
        }

        if ($length < 0) {
            throw new \InvalidArgumentException('Stream read length must be zero or greater.');
        }

        if (0 === $length) {
            return '';
        }

        $contents = fread($this->stream, $length);
        if (false === $contents) {
            throw new \RuntimeException('Unable to read from stream.');
        }

        return $contents;
    }

    public function getContents(): string
    {
        $this->assertStreamAvailable();

        $contents = stream_get_contents($this->stream);
        if (false === $contents) {
            throw new \RuntimeException('Unable to read stream contents.');
        }

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        if (!$this->stream) {
            return null === $key ? [] : null;
        }

        $metadata = stream_get_meta_data($this->stream);

        return null === $key ? $metadata : ($metadata[$key] ?? null);
    }

    private function assertStreamAvailable(): void
    {
        if (!$this->stream) {
            throw new \RuntimeException('Stream is detached.');
        }
    }
}
