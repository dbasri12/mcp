<?php

declare(strict_types=1);

namespace InternalAppMcp\Http\Psr7;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

final class UploadedFile implements UploadedFileInterface
{
    private bool $moved = false;

    public function __construct(
        private StreamInterface $stream,
        private ?int $size = null,
        private int $error = \UPLOAD_ERR_OK,
        private ?string $clientFilename = null,
        private ?string $clientMediaType = null,
    ) {
    }

    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new \RuntimeException('Uploaded file stream is no longer available after moveTo().');
        }

        return $this->stream;
    }

    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new \RuntimeException('Uploaded file has already been moved.');
        }

        if ('' === trim($targetPath)) {
            throw new \InvalidArgumentException('Target path cannot be empty.');
        }

        $directory = dirname($targetPath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create upload target directory "%s".', $directory));
        }

        $resource = fopen($targetPath, 'wb');
        if (false === $resource) {
            throw new \RuntimeException(sprintf('Unable to open upload target "%s".', $targetPath));
        }

        try {
            $source = $this->getStream();
            if ($source->isSeekable()) {
                $source->rewind();
            }

            while (!$source->eof()) {
                $chunk = $source->read(8192);
                if ('' === $chunk) {
                    break;
                }

                fwrite($resource, $chunk);
            }
        } finally {
            fclose($resource);
        }

        $this->moved = true;
    }

    public function getSize(): ?int
    {
        return $this->size ?? $this->stream->getSize();
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}
