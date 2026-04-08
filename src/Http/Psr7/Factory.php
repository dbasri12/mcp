<?php

declare(strict_types=1);

namespace InternalAppMcp\Http\Psr7;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

final class Factory implements RequestFactoryInterface, ResponseFactoryInterface, ServerRequestFactoryInterface, StreamFactoryInterface, UploadedFileFactoryInterface, UriFactoryInterface
{
    /**
     * @param UriInterface|string $uri
     */
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $uri instanceof UriInterface ? $uri : $this->createUri((string) $uri));
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, $reasonPhrase);
    }

    /**
     * @param UriInterface|string $uri
     */
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new ServerRequest($serverParams, $method, $uri instanceof UriInterface ? $uri : $this->createUri((string) $uri));
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return Stream::create($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return Stream::fromFile($filename, $mode);
    }

    /**
     * @param resource $resource
     */
    public function createStreamFromResource($resource): StreamInterface
    {
        return Stream::fromResource($resource);
    }

    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = \UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ): UploadedFileInterface {
        return new UploadedFile($stream, $size, $error, $clientFilename, $clientMediaType);
    }

    public function createUri(string $uri = ''): UriInterface
    {
        return Uri::fromString($uri);
    }

    public function createServerRequestFromGlobals(
        ?array $server = null,
        ?array $get = null,
        ?array $post = null,
        ?array $cookie = null,
        ?array $files = null,
        ?StreamInterface $body = null,
    ): ServerRequestInterface {
        $server ??= $_SERVER;
        $get ??= $_GET;
        $post ??= $_POST;
        $cookie ??= $_COOKIE;
        $files ??= $_FILES;

        $request = $this->createServerRequest(
            $server['REQUEST_METHOD'] ?? 'GET',
            $this->createUriFromGlobals($server),
            $server,
        );

        $request = $request
            ->withProtocolVersion(isset($server['SERVER_PROTOCOL']) ? str_replace('HTTP/', '', (string) $server['SERVER_PROTOCOL']) : '1.1')
            ->withUploadedFiles($this->normalizeFiles($files))
            ->withQueryParams($get)
            ->withParsedBody($post)
            ->withCookieParams($cookie)
            ->withBody($body ?? $this->createBodyFromGlobals());

        foreach ($this->headersFromServer($server) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    public function createUriFromGlobals(?array $server = null): UriInterface
    {
        $server ??= $_SERVER;

        $scheme = (!empty($server['HTTPS']) && 'off' !== strtolower((string) $server['HTTPS'])) ? 'https' : 'http';
        $host = 'localhost';
        $port = isset($server['SERVER_PORT']) ? (int) $server['SERVER_PORT'] : null;

        if (isset($server['HTTP_HOST'])) {
            $parts = parse_url('http://'.$server['HTTP_HOST']);
            if (false !== $parts) {
                $host = (string) ($parts['host'] ?? $host);
                if (isset($parts['port'])) {
                    $port = (int) $parts['port'];
                }
            }
        } elseif (isset($server['SERVER_NAME'])) {
            $host = (string) $server['SERVER_NAME'];
        }

        $requestUri = (string) ($server['REQUEST_URI'] ?? '/');
        $parts = explode('?', $requestUri, 2);
        $path = $parts[0] ?? '/';
        $query = $parts[1] ?? (string) ($server['QUERY_STRING'] ?? '');

        $uri = new Uri();
        $uri = $uri->withScheme($scheme)
            ->withHost($host)
            ->withPath($path)
            ->withQuery($query);

        if (null !== $port) {
            $uri = $uri->withPort($port);
        }

        return $uri;
    }

    public function emit(ResponseInterface $response, bool $sendBody = true): void
    {
        if (!headers_sent()) {
            http_response_code($response->getStatusCode());

            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header($name.': '.$value, false);
                }
            }
        }

        if (!$sendBody) {
            return;
        }

        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if (str_contains($contentType, 'text/event-stream')) {
            $this->prepareStreamingEnvironment();
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ('' !== $chunk) {
                echo $chunk;
            }

            if ('' === $chunk) {
                break;
            }

            if (str_contains($contentType, 'text/event-stream')) {
                flush();
            }
        }
    }

    private function createBodyFromGlobals(): StreamInterface
    {
        $resource = fopen('php://temp', 'r+');
        if (false === $resource) {
            throw new \RuntimeException('Unable to allocate request body stream.');
        }

        $input = fopen('php://input', 'rb');
        if (false !== $input) {
            stream_copy_to_stream($input, $resource);
            fclose($input);
        }

        rewind($resource);

        return Stream::fromResource($resource);
    }

    /**
     * @return array<string, string>
     */
    private function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $name => $value) {
            if (!\is_string($value)) {
                continue;
            }

            if (str_starts_with($name, 'HTTP_')) {
                $headerName = substr($name, 5);
            } elseif (\in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $headerName = $name;
            } else {
                continue;
            }

            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $headerName))));
            $headers[$headerName] = $value;
        }

        if (!isset($headers['Authorization'])) {
            if (isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = (string) $server['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (isset($server['PHP_AUTH_USER'])) {
                $headers['Authorization'] = 'Basic '.base64_encode((string) $server['PHP_AUTH_USER'].':'.((string) ($server['PHP_AUTH_PW'] ?? '')));
            } elseif (isset($server['PHP_AUTH_DIGEST'])) {
                $headers['Authorization'] = (string) $server['PHP_AUTH_DIGEST'];
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $files
     *
     * @return array<string, UploadedFileInterface|array>
     */
    private function normalizeFiles(array $files): array
    {
        foreach ($files as $name => $file) {
            if ($file instanceof UploadedFileInterface) {
                continue;
            }

            if (!\is_array($file)) {
                unset($files[$name]);
                continue;
            }

            if (!isset($file['tmp_name'])) {
                $files[$name] = $this->normalizeFiles($file);
                continue;
            }

            $files[$name] = $this->createUploadedFileFromSpec($file);
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function createUploadedFileFromSpec(array $spec): UploadedFileInterface|array
    {
        $tmpName = $spec['tmp_name'] ?? null;
        if (\is_array($tmpName)) {
            $normalized = [];
            foreach ($tmpName as $index => $value) {
                $normalized[$index] = $this->createUploadedFileFromSpec([
                    'tmp_name' => $value,
                    'size' => $spec['size'][$index] ?? null,
                    'error' => $spec['error'][$index] ?? \UPLOAD_ERR_OK,
                    'name' => $spec['name'][$index] ?? null,
                    'type' => $spec['type'][$index] ?? null,
                ]);
            }

            return $normalized;
        }

        $stream = (\is_string($tmpName) && '' !== $tmpName && is_file($tmpName))
            ? $this->createStreamFromFile($tmpName, 'rb')
            : $this->createStream();

        return $this->createUploadedFile(
            $stream,
            isset($spec['size']) ? (int) $spec['size'] : null,
            isset($spec['error']) ? (int) $spec['error'] : \UPLOAD_ERR_OK,
            isset($spec['name']) ? (string) $spec['name'] : null,
            isset($spec['type']) ? (string) $spec['type'] : null,
        );
    }

    private function prepareStreamingEnvironment(): void
    {
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
    }
}
