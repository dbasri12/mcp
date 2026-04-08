<?php
declare(strict_types=1);

use InternalAppMcp\Config\InternalAppServerConfig;
use InternalAppMcp\Http\Psr7\Factory;
use InternalAppMcp\InternalAppServerFactory;
use Mcp\Server\Transport\StreamableHttpTransport;

require __DIR__.'/vendor/autoload.php';

$factory = new Factory();
$request = $factory->createServerRequestFromGlobals();

if (\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
    $config = InternalAppServerConfig::fromEnvironment(__DIR__);
    $accept = strtolower($request->getHeaderLine('Accept'));
    $appsCatalog = $config->applicationsCatalog();

    $payload = [
        'name' => $config->serverName,
        'version' => $config->serverVersion,
        'endpoint' => (string) $request->getUri()->withQuery('')->withFragment(''),
        'transport' => 'streamable-http',
        'message' => 'Send MCP JSON-RPC requests to this URL with HTTP POST. DELETE closes a session and OPTIONS returns CORS preflight headers.',
        'registryFile' => $config->registryFile,
        'appCount' => \count($appsCatalog['apps']),
        'apps' => $appsCatalog['apps'],
        'resources' => $config->resourceUris(),
        'tools' => $config->toolNames(),
    ];

    if (str_contains($accept, 'text/html')) {
        $appsHtml = implode('', array_map(static function (array $app): string {
            $toolsHtml = implode('', array_map(static function (array $tool): string {
                return sprintf(
                    '<li><code>%s</code> via <code>%s %s</code></li>',
                    htmlspecialchars((string) $tool['toolName'], \ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string) $tool['method'], \ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string) $tool['url'], \ENT_QUOTES, 'UTF-8')
                );
            }, $app['tools'] ?? []));

            return sprintf(
                '<section style="margin:0 0 24px"><h3 style="margin-bottom:6px">%s</h3><p class="muted" style="margin-top:0">%s</p><p><strong>Base URL:</strong> <code>%s</code>%s</p><ul>%s</ul></section>',
                htmlspecialchars((string) $app['name'], \ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) ($app['description'] ?? ''), \ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $app['apiBaseUrl'], \ENT_QUOTES, 'UTF-8'),
                !empty($app['isPlaceholderBaseUrl']) ? ' <strong>(placeholder)</strong>' : '',
                $toolsHtml
            );
        }, $appsCatalog['apps']));

        $resourcesHtml = implode('', array_map(static fn (string $uri): string => sprintf('<li><code>%s</code></li>', htmlspecialchars($uri, \ENT_QUOTES, 'UTF-8')), $config->resourceUris()));
        $toolsHtml = implode('', array_map(static fn (string $toolName): string => sprintf('<li><code>%s</code></li>', htmlspecialchars($toolName, \ENT_QUOTES, 'UTF-8')), $config->toolNames()));

        $html = sprintf(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>%s</title><style>body{font-family:Segoe UI,Arial,sans-serif;max-width:1000px;margin:40px auto;padding:0 20px;line-height:1.6}code,pre{font-family:Consolas,monospace;background:#f5f5f5}pre{padding:16px;border-radius:8px;overflow:auto}h1{margin-bottom:.2em}.muted{color:#666}section{border:1px solid #e5e5e5;border-radius:12px;padding:16px 18px}ul{padding-left:20px}</style></head><body><h1>%s</h1><p class="muted">HTTP MCP endpoint exposed by WAMP at <code>%s</code>.</p><p>Use <code>POST</code> to send MCP JSON-RPC messages to this same URL. <code>DELETE</code> closes a session. <code>OPTIONS</code> handles preflight requests.</p><p><strong>Registry file:</strong> <code>%s</code></p><h2>Configured Apps</h2>%s<h2>Exposed Tools</h2><ul>%s</ul><h2>Resources</h2><ul>%s</ul><h2>Example initialize request</h2><pre>%s</pre><h2>Notes</h2><ul><li>This page is only a landing page for browser visits. MCP clients should use HTTP POST.</li><li>Add more apps by editing <code>config/internal-apps.php</code> or pointing <code>INTERNAL_APP_REGISTRY_FILE</code> at another PHP registry file.</li><li>Each endpoint exposes its own defaults resource plus the shared catalog and contract resources.</li></ul></body></html>',
            htmlspecialchars($config->serverName, \ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($config->serverName, \ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) $request->getUri()->withQuery('')->withFragment(''), \ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($config->registryFile, \ENT_QUOTES, 'UTF-8'),
            $appsHtml,
            $toolsHtml,
            $resourcesHtml,
            htmlspecialchars(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-03-26',
                    'capabilities' => new stdClass(),
                    'clientInfo' => ['name' => 'your-client', 'version' => '1.0.0'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), \ENT_QUOTES, 'UTF-8')
        );

        $response = $factory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($factory->createStream($html));

        $factory->emit($response, 'HEAD' !== $request->getMethod());
        exit(0);
    }

    $response = $factory->createResponse(200)
        ->withHeader('Content-Type', 'application/json')
        ->withBody($factory->createStream(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

    $factory->emit($response, 'HEAD' !== $request->getMethod());
    exit(0);
}

try {
    ['server' => $server, 'logger' => $logger] = InternalAppServerFactory::build(__DIR__);
    $transport = new StreamableHttpTransport(
        request: $request,
        responseFactory: $factory,
        streamFactory: $factory,
        logger: $logger,
    );

    $response = $server->run($transport);
    $factory->emit($response);
} catch (\Throwable $exception) {
    $response = $factory->createResponse(500)
        ->withHeader('Content-Type', 'application/json')
        ->withBody($factory->createStream(json_encode([
            'error' => 'bootstrap_failure',
            'message' => $exception->getMessage(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

    $factory->emit($response);
}
