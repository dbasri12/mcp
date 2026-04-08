<?php

declare(strict_types=1);

use InternalAppMcp\Schema\ToolSchemas;

$defaultDashboardDaysChange = (int) (getenv('SOJ_FETCH_GENERAL_DASHBOARD_DAYS_CHANGE') ?: 365);
$defaultBaseUrl = getenv('INTERNAL_APP_BASE_URL') ?: 'https://internal.api.co.id';
$defaultBasePath = getenv('INTERNAL_APP_API_BASE_PATH') ?: '/soj_api/Main';
$defaultAuthHeader = getenv('INTERNAL_APP_AUTH_HEADER') ?: 'INT-ID';
$defaultAuthScheme = trim((string) (getenv('INTERNAL_APP_AUTH_SCHEME') ?: ''));
$defaultApiToken = getenv('INTERNAL_APP_API_TOKEN') ?: null;
$defaultUserAgent = getenv('INTERNAL_APP_USER_AGENT') ?: 'internal-app-mcp-server/1.1.0';

return [
    'apps' => [
        [
            'slug' => 'dashboard-soj',
            'name' => 'Dashboard SOJ',
            'description' => 'Operational dashboard and reference data for SOJ Main.',
            'base_url' => $defaultBaseUrl,
            'api_base_path' => $defaultBasePath,
            'auth_header' => $defaultAuthHeader,
            'auth_scheme' => $defaultAuthScheme,
            'api_token' => $defaultApiToken,
            'user_agent' => $defaultUserAgent,
            'endpoints' => [
                [
                    'name' => 'FetchGeneralDashboard',
                    'path' => 'FetchGeneralDashboard/',
                    'tool_name' => 'fetch_general_dashboard',
                    'title' => 'Fetch General Dashboard',
                    'description' => 'Fetch SOJ dashboard reference data and report totals.',
                    'purpose' => 'Fetch SOJ dashboard reference data and report totals from PolisService.',
                    'method' => 'POST',
                    'parameter_mode' => 'json',
                    'handler_profile' => 'dashboard_fetch_general',
                    'input_schema' => ToolSchemas::dashboardFetchGeneralInput($defaultDashboardDaysChange),
                    'output_schema' => ToolSchemas::dashboardFetchGeneralOutput(),
                    'request_map' => [
                        ['argument' => 'allowedBranches', 'target' => 'allowedBranches'],
                        ['argument' => 'daysChange', 'target' => 'days_change'],
                        ['argument' => 'reportFlag', 'target' => 'reportflag', 'transform' => 'bool_string'],
                    ],
                    'request_defaults' => [
                        'allowedBranches' => ['0101'],
                        'daysChange' => $defaultDashboardDaysChange,
                        'reportFlag' => false,
                    ],
                    'request_example' => [
                        'allowedBranches' => ['0101'],
                        'days_change' => $defaultDashboardDaysChange,
                        'reportflag' => 'false',
                    ],
                    'headers' => [
                        [
                            'name' => $defaultAuthHeader,
                            'required' => false,
                            'purpose' => 'User context forwarded into PolisService.',
                        ],
                    ],
                    'notes' => [
                        'This endpoint is POST-only and reads JSON from php://input.',
                        'When reportflag is "true", the endpoint returns only DataTotalReport.',
                        'The source code also reads the optional BCAi-ID header and passes it into PolisService.',
                    ],
                    'observed_behavior' => [
                        'On April 7, 2026, POST JSON {"allowedBranches":["0101"],"days_change":365,"reportflag":"true"} returned HTTP 200 with Status=Success and DataTotalReport.',
                        'On April 7, 2026, POST JSON {"allowedBranches":["0101"],"days_change":365,"reportflag":"false"} returned HTTP 200 with Status=Success and the full dashboard payload.',
                    ],
                    'response_shape' => [
                        'Status' => 'Success',
                        'DataTotalReport' => [
                            'Waiting Inforced' => ['count' => 16, 'change_percentage' => null],
                            'Inforced' => ['count' => 3878, 'change_percentage' => null],
                        ],
                    ],
                    'resource_uri' => 'internal-app://soj/main/fetch-general-dashboard/defaults',
                    'resource_name' => 'internal_app_fetch_general_dashboard_defaults',
                    'resource_description' => 'Configured request defaults and observed behavior for the SOJ FetchGeneralDashboard endpoint.',
                ],
            ],
        ],
        [
            'enabled' => false,
            'slug' => 'internal-app-placeholder',
            'name' => 'Internal App Placeholder',
            'description' => 'Disabled example app. Duplicate and update this entry when you want to expose another internal application through the same MCP server.',
            'base_url' => 'https://internal-app.example.com',
            'api_base_path' => '/api',
            'auth_header' => 'Authorization',
            'auth_scheme' => 'Bearer',
            'api_token' => null,
            'user_agent' => $defaultUserAgent,
            'endpoints' => [
                [
                    'name' => 'Health',
                    'path' => 'health',
                    'tool_name' => 'placeholder_health',
                    'title' => 'Placeholder Health',
                    'description' => 'Example generic endpoint registration for a second app.',
                    'purpose' => 'Demonstrates how to add another app and endpoint without changing the MCP server code.',
                    'method' => 'GET',
                    'parameter_mode' => 'query',
                    'handler_profile' => 'generic_payload',
                    'input_schema' => ToolSchemas::payloadToolInput([
                        'type' => 'object',
                        'properties' => [
                            'tenant' => ['type' => 'string'],
                        ],
                        'additionalProperties' => true,
                    ]),
                    'output_schema' => ToolSchemas::genericEndpointOutput(),
                    'request_defaults' => [],
                    'request_example' => [],
                    'notes' => [
                        'This example is disabled by default.',
                        'Set enabled=true and replace the base URL, path, and schemas to expose a second internal app.',
                    ],
                ],
            ],
        ],
    ],
];
