<?php

return [
    'connect_timeout' => (int) env('MCP_CONNECT_TIMEOUT', 8),
    'timeout' => (int) env('MCP_TIMEOUT', 15),
    'protocol_version' => env('MCP_PROTOCOL_VERSION', '2025-03-26'),
    'chat_model' => env('MCP_CHAT_MODEL', env('OPENAI_MODEL')),
    'chat_max_tool_iterations' => (int) env('MCP_CHAT_MAX_TOOL_ITERATIONS', 5),
    'client_info' => [
        'name' => env('MCP_CLIENT_NAME', config('app.name', 'Gojeka')),
        'version' => env('MCP_CLIENT_VERSION', '1.0.0'),
    ],
    'apps' => [
        'dashboard-soj' => [
            'slug' => 'dashboard-soj',
            'name' => 'Dashboard SOJ',
            'short_name' => 'SOJ',
            'tagline' => 'Operational dashboard',
            'description' => 'Query SOJ dashboard totals and recent operational metrics through the internal MCP gateway.',
            'command_prefix' => '@Dashboard SOJ',
            'endpoint' => env('MCP_DASHBOARD_SOJ_URL', 'http://localhost/mcp'),
            'capabilities' => [
                'Fetch live dashboard totals',
                'Summarize branch-level operational data',
                'Use MCP tools from chat with a server-side session',
            ],
            'sample_prompts' => [
                '@Dashboard SOJ show today''s overall dashboard summary',
                '@Dashboard SOJ compare waiting inforced vs terkirim for branch 0101',
                '@Dashboard SOJ what should operations watch most closely right now?',
            ],
        ],
    ],
];
