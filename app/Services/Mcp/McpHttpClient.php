<?php

namespace App\Services\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class McpHttpClient
{
    /**
     * @param  array<string, mixed>  $app
     * @return array{session_id:string,result:array<string, mixed>}
     */
    public function initialize(array $app): array
    {
        $response = $this->request(
            app: $app,
            method: 'initialize',
            params: [
                'protocolVersion' => config('mcp.protocol_version', '2025-03-26'),
                'capabilities' => (object) [],
                'clientInfo' => [
                    'name' => (string) config('mcp.client_info.name', config('app.name', 'Gojeka')),
                    'version' => (string) config('mcp.client_info.version', '1.0.0'),
                ],
            ],
        );

        $sessionId = trim((string) ($response['session_id'] ?? ''));

        if ($sessionId === '') {
            throw new McpException('The MCP server did not return a session ID during initialize.');
        }

        return [
            'session_id' => $sessionId,
            'result' => $response['result'],
        ];
    }

    /**
     * @param  array<string, mixed>  $app
     * @return array<int, array<string, mixed>>
     */
    public function listTools(array $app, string $sessionId): array
    {
        $response = $this->request(
            app: $app,
            method: 'tools/list',
            params: [],
            sessionId: $sessionId,
        );

        $tools = $response['result']['tools'] ?? [];

        if (! is_array($tools)) {
            throw new McpException('The MCP server returned an invalid tool list.');
        }

        return array_values(array_filter($tools, fn (mixed $tool): bool => is_array($tool)));
    }

    /**
     * @param  array<string, mixed>  $app
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function callTool(array $app, string $sessionId, string $name, array $arguments = []): array
    {
        $response = $this->request(
            app: $app,
            method: 'tools/call',
            params: [
                'name' => $name,
                'arguments' => (object) $arguments,
            ],
            sessionId: $sessionId,
        );

        return $response['result'];
    }

    /**
     * @param  array<string, mixed>  $app
     * @param  array<string, mixed>  $params
     * @return array{result:array<string, mixed>,session_id:?string}
     */
    private function request(array $app, string $method, array $params = [], ?string $sessionId = null): array
    {
        $endpoint = trim((string) ($app['endpoint'] ?? ''));

        if ($endpoint === '') {
            throw new McpException('This internal app is missing an MCP endpoint.');
        }

        $headers = [
            'Accept' => 'application/json',
        ];

        if ($sessionId !== null && trim($sessionId) !== '') {
            $headers['Mcp-Session-Id'] = trim($sessionId);
        }

        try {
            $response = Http::connectTimeout((int) config('mcp.connect_timeout', 8))
                ->timeout((int) config('mcp.timeout', 15))
                ->acceptJson()
                ->asJson()
                ->withHeaders($headers)
                ->post($endpoint, [
                    'jsonrpc' => '2.0',
                    'id' => (string) Str::uuid(),
                    'method' => $method,
                    'params' => $params === [] ? (object) [] : $params,
                ]);
        } catch (ConnectionException $exception) {
            throw new McpException('Unable to reach the MCP server: '.$exception->getMessage(), previous: $exception);
        }

        $payload = $response->json();

        if (! $response->successful()) {
            $message = is_array($payload) && is_array($payload['error'] ?? null)
                ? (string) ($payload['error']['message'] ?? 'The MCP request failed.')
                : 'The MCP request failed with HTTP '.$response->status().'.';

            throw new McpException($message);
        }

        if (! is_array($payload)) {
            throw new McpException('The MCP server returned a non-JSON response.');
        }

        if (is_array($payload['error'] ?? null)) {
            throw new McpException((string) ($payload['error']['message'] ?? 'The MCP server returned an error.'));
        }

        $result = $payload['result'] ?? [];

        if (! is_array($result)) {
            throw new McpException('The MCP server returned an invalid response payload.');
        }

        return [
            'result' => $result,
            'session_id' => $response->header('Mcp-Session-Id'),
        ];
    }
}
