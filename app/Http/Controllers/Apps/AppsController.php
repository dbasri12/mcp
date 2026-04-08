<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Services\Mcp\McpAppCatalog;
use App\Services\Mcp\McpException;
use App\Services\Mcp\McpHttpClient;
use App\Services\Mcp\McpSessionStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppsController extends Controller
{
    public function __construct(
        private readonly McpAppCatalog $catalog,
        private readonly McpHttpClient $client,
        private readonly McpSessionStore $sessionStore,
    ) {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('apps', [
            'apps' => $this->catalog->sharedPayload($request->session(), $this->sessionStore),
        ]);
    }

    public function connect(Request $request, string $app): RedirectResponse
    {
        $definition = $this->catalog->find($app);

        abort_if($definition === null, 404);

        try {
            $connection = $this->client->initialize($definition);

            $this->sessionStore->put(
                session: $request->session(),
                slug: $app,
                sessionId: $connection['session_id'],
                payload: [
                    'server_info' => $connection['result']['serverInfo'] ?? null,
                    'server_capabilities' => $connection['result']['capabilities'] ?? null,
                    'protocol_version' => $connection['result']['protocolVersion'] ?? null,
                ],
            );

            return redirect()->route('apps.index')->with('mcp', [
                'type' => 'success',
                'message' => sprintf('%s connected successfully.', $definition['name']),
            ]);
        } catch (McpException $exception) {
            report($exception);

            return redirect()->route('apps.index')->with('mcp', [
                'type' => 'error',
                'message' => sprintf('Failed to connect %s. %s', $definition['name'], $exception->getMessage()),
            ]);
        }
    }

    public function disconnect(Request $request, string $app): RedirectResponse
    {
        $definition = $this->catalog->find($app);

        abort_if($definition === null, 404);

        $this->sessionStore->forget($request->session(), $app);

        return redirect()->route('apps.index')->with('mcp', [
            'type' => 'success',
            'message' => sprintf('%s disconnected.', $definition['name']),
        ]);
    }
}
