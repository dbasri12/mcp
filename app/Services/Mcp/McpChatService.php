<?php

namespace App\Services\Mcp;

use App\Services\Chat\AssistantResponse;
use Illuminate\Session\Store;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

class McpChatService
{
    private const STREAM_CHUNK_CHARACTER_LIMIT = 18;

    private const STREAM_CHUNK_DELAY_MICROSECONDS = 60000;

    public function __construct(
        private readonly McpAppCatalog $catalog,
        private readonly McpHttpClient $client,
        private readonly McpSessionStore $sessionStore,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function handle(Store $session, array $messages, ?callable $onChunk = null): ?AssistantResponse
    {
        $latestPrompt = $this->extractLatestPromptMessage($messages);

        if ($latestPrompt === null) {
            return null;
        }

        $content = trim((string) ($latestPrompt['content'] ?? ''));

        if ($content === '') {
            return null;
        }

        $match = $this->catalog->matchPrompt($content);

        if ($match === null) {
            return null;
        }

        $app = $match['app'];
        $prompt = $match['prompt'];

        if ($prompt === '') {
            return $this->respond(
                text: sprintf("Start with `%s` followed by your request. Example: `%s show today's dashboard summary`.", $app['command_prefix'], $app['command_prefix']),
                app: $app,
                onChunk: $onChunk,
            );
        }

        $sessionId = $this->sessionStore->getSessionId($session, (string) $app['slug']);

        if ($sessionId === null) {
            return $this->respond(
                text: sprintf(
                    '%s is not connected yet. Open the Apps page, connect it, then retry with `%s %s`.',
                    $app['name'],
                    $app['command_prefix'],
                    $prompt,
                ),
                app: $app,
                onChunk: $onChunk,
            );
        }

        try {
            $tools = $this->listToolsWithRecovery($session, $app, $sessionId);
        } catch (McpException $exception) {
            return $this->respond(
                text: sprintf(
                    "I couldn't load tools from %s right now. %s",
                    $app['name'],
                    trim($exception->getMessage()),
                ),
                app: $app,
                onChunk: $onChunk,
            );
        }

        if ($tools === []) {
            return $this->respond(
                text: sprintf('%s is connected, but its MCP server did not expose any tools.', $app['name']),
                app: $app,
                onChunk: $onChunk,
            );
        }

        $model = trim((string) config('mcp.chat_model', config('openai.model_name', '')));

        if ($model === '') {
            return $this->respond(
                text: sprintf(
                    '%s is connected, but the OpenAI model is not configured for tool-enabled chat in this environment.',
                    $app['name'],
                ),
                app: $app,
                onChunk: $onChunk,
            );
        }

        try {
            $answer = $this->completeWithTools($session, $app, $sessionId, $prompt, $tools, $model);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->respond(
                text: sprintf(
                    'I reached %s, but the tool-enabled answer failed before I could finish. %s',
                    $app['name'],
                    trim($exception->getMessage()),
                ),
                app: $app,
                onChunk: $onChunk,
            );
        }

        return $this->respond(text: $answer, app: $app, onChunk: $onChunk);
    }

    /**
     * @param  array<string, mixed>  $app
     * @param  array<int, array<string, mixed>>  $tools
     */
    private function completeWithTools(Store $session, array $app, string &$sessionId, string $prompt, array $tools, string $model): string
    {
        $messages = [
            [
                'role' => 'system',
                'content' => $this->buildSystemPrompt($app),
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        $openAiTools = array_map(function (array $tool): array {
            return [
                'type' => 'function',
                'function' => [
                    'name' => (string) ($tool['name'] ?? 'unknown_tool'),
                    'description' => (string) ($tool['description'] ?? ''),
                    'parameters' => is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : (object) [],
                ],
            ];
        }, $tools);

        $maxIterations = max(1, (int) config('mcp.chat_max_tool_iterations', 5));
        $lastToolResult = null;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $toolSelection = OpenAI::chat()->create([
                'model' => $model,
                'messages' => $messages,
                'tools' => $openAiTools,
                'tool_choice' => 'required',
                'temperature' => 0.2,
            ]);

            $choice = $toolSelection->choices[0] ?? null;

            if ($choice === null) {
                break;
            }

            $toolCalls = $choice->message->toolCalls;

            if ($toolCalls === []) {
                $content = trim((string) ($choice->message->content ?? ''));

                if ($content !== '') {
                    return $content;
                }

                break;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $choice->message->content,
                'tool_calls' => array_map(
                    static fn ($toolCall): array => $toolCall->toArray(),
                    $toolCalls,
                ),
            ];

            foreach ($toolCalls as $toolCall) {
                $arguments = json_decode($toolCall->function->arguments, true);
                $arguments = is_array($arguments) ? $arguments : [];

                $toolResult = $this->callToolWithRecovery(
                    session: $session,
                    app: $app,
                    sessionId: $sessionId,
                    toolName: $toolCall->function->name,
                    arguments: $arguments,
                );

                $lastToolResult = $toolResult;

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall->id,
                    'content' => $this->encodeToolResult($toolResult),
                ];
            }

            $finalResponse = OpenAI::chat()->create([
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.2,
            ]);

            $finalText = trim((string) ($finalResponse->choices[0]->message->content ?? ''));

            if ($finalText !== '') {
                return $finalText;
            }

            $finalAssistantMessage = trim((string) ($finalResponse->choices[0]->message->content ?? ''));

            if ($finalAssistantMessage !== '') {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $finalAssistantMessage,
                ];
            }
        }

        if ($lastToolResult !== null) {
            return sprintf(
                'I ran %s successfully, but the model did not produce a final summary. Raw tool result: %s',
                $app['name'],
                Str::limit($this->encodeToolResult($lastToolResult), 1600),
            );
        }

        return sprintf("I couldn't finish the %s request. Please try a more specific prompt.", $app['name']);
    }

    /**
     * @param  array<string, mixed>  $app
     * @return array<int, array<string, mixed>>
     */
    private function listToolsWithRecovery(Store $session, array $app, string &$sessionId): array
    {
        try {
            return $this->client->listTools($app, $sessionId);
        } catch (McpException $exception) {
            $sessionId = $this->reconnect($session, $app);

            return $this->client->listTools($app, $sessionId);
        }
    }

    /**
     * @param  array<string, mixed>  $app
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callToolWithRecovery(Store $session, array $app, string &$sessionId, string $toolName, array $arguments): array
    {
        try {
            return $this->client->callTool($app, $sessionId, $toolName, $arguments);
        } catch (McpException $exception) {
            $sessionId = $this->reconnect($session, $app);

            return $this->client->callTool($app, $sessionId, $toolName, $arguments);
        }
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function reconnect(Store $session, array $app): string
    {
        $connection = $this->client->initialize($app);

        $this->sessionStore->put(
            session: $session,
            slug: (string) $app['slug'],
            sessionId: $connection['session_id'],
            payload: [
                'server_info' => $connection['result']['serverInfo'] ?? null,
                'server_capabilities' => $connection['result']['capabilities'] ?? null,
                'protocol_version' => $connection['result']['protocolVersion'] ?? null,
            ],
        );

        return $connection['session_id'];
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function buildSystemPrompt(array $app): string
    {
        return trim(sprintf(
            'You are Gojeka using the internal application "%s" over MCP. Always use the provided tools before answering. Base factual statements only on tool output. If the tool output is incomplete, say so clearly. Keep the answer concise, useful, and operational.',
            $app['name'],
        ));
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function respond(string $text, array $app, ?callable $onChunk = null): AssistantResponse
    {
        $trimmedText = trim($text);

        if ($trimmedText === '') {
            $trimmedText = sprintf("I couldn't produce a response from %s.", $app['name']);
        }

        if ($onChunk) {
            $this->emitTextWithCadence($trimmedText, $onChunk);
        }

        return new AssistantResponse(
            mode: 'mcp',
            intent: 'app',
            text: $trimmedText,
            references: [],
            meta: [
                'app' => [
                    'slug' => $app['slug'],
                    'name' => $app['name'],
                    'command_prefix' => $app['command_prefix'],
                ],
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>|null
     */
    private function extractLatestPromptMessage(array $messages): ?array
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];

            if (is_array($message) && ($message['type'] ?? null) === 'prompt') {
                return $message;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $toolResult
     */
    private function encodeToolResult(array $toolResult): string
    {
        $encoded = json_encode($toolResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '{}';
    }

    private function emitTextWithCadence(string $text, callable $onChunk): void
    {
        if ($text === '') {
            return;
        }

        preg_match_all('/.{1,'.self::STREAM_CHUNK_CHARACTER_LIMIT.'}/us', $text, $matches);
        $chunks = $matches[0] ?? [$text];

        foreach ($chunks as $index => $chunk) {
            if (! is_string($chunk) || $chunk === '') {
                continue;
            }

            $onChunk($chunk);

            if ($index < count($chunks) - 1) {
                usleep(self::STREAM_CHUNK_DELAY_MICROSECONDS);
            }
        }
    }
}
