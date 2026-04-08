# Internal Apps MCP Server

This project exposes a production-ready MCP server from WAMP at `http://localhost/mcp`.

It now supports multiple internal apps from a single MCP server through a registry file:

- [config/internal-apps.php](/C:/wamp64/www/mcp/config/internal-apps.php)

The current live registry ships with one enabled app:

- `Dashboard SOJ`
- tool: `fetch_general_dashboard`
- upstream: `https://internal.api.co.id/soj_api/Main/FetchGeneralDashboard/`

It also includes one disabled placeholder app entry you can duplicate and enable when you are ready to expose another internal application.

## Quick Start

1. Copy `.env.example` to `.env`.
2. Confirm `INTERNAL_APP_REGISTRY_FILE=config/internal-apps.php`.
3. Confirm the enabled app entries in `config/internal-apps.php` point at the correct upstream URLs and auth headers.
4. Open `http://localhost/mcp` in your browser to confirm the endpoint is live.
5. Point your MCP client at `http://localhost/mcp` using streamable HTTP.

## Multi-App Model

The MCP server now loads apps and endpoints from `config/internal-apps.php`.

Each app can define:

- `slug`, `name`, `description`
- `base_url`, `api_base_path`
- `auth_header`, `auth_scheme`, `api_token`
- one or more `endpoints`

Each endpoint can define:

- `tool_name`
- `path`
- `method`
- `parameter_mode`: `json`, `form`, or `query`
- `handler_profile`: `dashboard_fetch_general` or `generic_payload`
- `input_schema` and `output_schema`
- request defaults, request examples, notes, and resource metadata

## Current Exposed Surface

Shared resources:

- `internal-app://config/server`
- `internal-app://config/auth`
- `internal-app://catalog/apps`
- `internal-app://contract/endpoints`

Current app-specific resource:

- `internal-app://soj/main/fetch-general-dashboard/defaults`

Current tool:

- `fetch_general_dashboard`

Parameters:

- `allowedBranches`: required array of branch codes, for example `['0101']`
- `daysChange`: optional trailing-day window for `getTotalDashboardReport`, default comes from the registry
- `reportFlag`: when `true`, return only `DataTotalReport`; when `false`, return the full dashboard payload
- `includeResponseHeaders`: include upstream headers in the MCP result when debugging

## Adding Another App

1. Duplicate the disabled placeholder app in [config/internal-apps.php](/C:/wamp64/www/mcp/config/internal-apps.php).
2. Set `enabled => true`.
3. Replace the app base URL, base path, auth settings, and endpoint definitions.
4. For simple passthrough endpoints, keep `handler_profile => 'generic_payload'`.
5. Reload the MCP server and confirm the new tool appears in `tools/list`.

## Operational Notes

- The HTTP entrypoint is [index.php](/C:/wamp64/www/mcp/index.php).
- Sessions are stored in `storage/sessions`.
- Logs go to `stderr` and optionally to `MCP_LOG_FILE`.
- TLS verification is controlled by `MCP_VERIFY_TLS`.
- The registry path is controlled by `INTERNAL_APP_REGISTRY_FILE`.
