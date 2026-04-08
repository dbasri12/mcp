<?php

declare(strict_types=1);

namespace InternalAppMcp\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Stringable;

final class StreamLogger extends AbstractLogger
{
    /**
     * @var array<string, int>
     */
    private const LEVEL_PRIORITY = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    /**
     * @var resource|null
     */
    private $stderrHandle;

    /**
     * @var resource|null
     */
    private $fileHandle;

    public function __construct(
        private readonly string $minimumLevel = 'warning',
        ?string $logFile = null,
    ) {
        $this->stderrHandle = @fopen('php://stderr', 'wb');

        if (null !== $logFile) {
            $directory = dirname($logFile);
            if (!is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            $this->fileHandle = @fopen($logFile, 'ab');
        }
    }

    public function __destruct()
    {
        if (\is_resource($this->fileHandle)) {
            fclose($this->fileHandle);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $level = strtolower((string) $level);
        if (!isset(self::LEVEL_PRIORITY[$level])) {
            throw new InvalidArgumentException(sprintf('Unsupported PSR-3 log level "%s".', $level));
        }

        if (self::LEVEL_PRIORITY[$level] < self::LEVEL_PRIORITY[$this->minimumLevel]) {
            return;
        }

        $line = sprintf(
            "[%s] %s %s%s",
            date(\DATE_ATOM),
            strtoupper($level),
            (string) $message,
            [] === $context ? '' : ' '.json_encode(
                $this->normalizeContext($context),
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
            )
        );

        if (\is_resource($this->stderrHandle)) {
            fwrite($this->stderrHandle, $line.\PHP_EOL);
        }

        if (\is_resource($this->fileHandle)) {
            fwrite($this->fileHandle, $line.\PHP_EOL);
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            $normalized[$key] = $this->normalizeValue($key, $value);
        }

        return $normalized;
    }

    private function normalizeValue(string $key, mixed $value): mixed
    {
        if ($this->shouldRedact($key)) {
            return '[REDACTED]';
        }

        if (null === $value || \is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \Throwable) {
            return [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
            ];
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (\is_array($value)) {
            $normalized = [];
            foreach ($value as $childKey => $childValue) {
                $childName = \is_string($childKey) ? $childKey : (string) $childKey;
                $normalized[$childKey] = $this->normalizeValue($childName, $childValue);
            }

            return $normalized;
        }

        if (\is_object($value)) {
            return ['class' => $value::class];
        }

        return gettype($value);
    }

    private function shouldRedact(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (['authorization', 'token', 'secret', 'password'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
