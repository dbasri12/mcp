<?php

namespace App\Services\Mcp;

use Illuminate\Session\Store;

class McpSessionStore
{
    private const SESSION_KEY = 'mcp.connections';

    /**
     * @return array<string, mixed>|null
     */
    public function get(Store $session, string $slug): ?array
    {
        $connection = $session->get($this->key($slug));

        return is_array($connection) ? $connection : null;
    }

    public function getSessionId(Store $session, string $slug): ?string
    {
        $connection = $this->get($session, $slug);
        $sessionId = is_array($connection) ? ($connection['session_id'] ?? null) : null;

        return is_string($sessionId) && trim($sessionId) !== '' ? trim($sessionId) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function put(Store $session, string $slug, string $sessionId, array $payload = []): void
    {
        $connection = array_merge($payload, [
            'session_id' => $sessionId,
            'connected_at' => now()->toIso8601String(),
        ]);

        $session->put($this->key($slug), $connection);
    }

    public function forget(Store $session, string $slug): void
    {
        $session->forget($this->key($slug));
    }

    private function key(string $slug): string
    {
        return self::SESSION_KEY.'.'.$slug;
    }
}
