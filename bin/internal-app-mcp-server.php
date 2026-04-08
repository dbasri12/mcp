#!/usr/bin/env php
<?php

declare(strict_types=1);

use InternalAppMcp\InternalAppServerFactory;
use Mcp\Server\Transport\StdioTransport;

require dirname(__DIR__).'/vendor/autoload.php';

if (\in_array('--help', $argv, true) || \in_array('-h', $argv, true)) {
    fwrite(
        \STDOUT,
        <<<TEXT
Internal Application MCP Server

Usage:
  php bin/internal-app-mcp-server.php

Environment:
  Copy .env.example to .env and update INTERNAL_APP_BASE_URL plus auth values.

TEXT
    );

    exit(0);
}

try {
    ['server' => $server, 'logger' => $logger] = InternalAppServerFactory::build(dirname(__DIR__));
    $transport = new StdioTransport(logger: $logger);

    exit($server->run($transport));
} catch (\Throwable $exception) {
    fwrite(\STDERR, sprintf(
        "[%s] Bootstrap failure: %s%s",
        date(\DATE_ATOM),
        $exception->getMessage(),
        \PHP_EOL
    ));

    exit(1);
}
