<?php

declare(strict_types=1);

namespace InternalAppMcp\Schema;

final class ToolSchemas
{
    /**
     * @return array<string, mixed>
     */
    public static function dashboardFetchGeneralInput(int $defaultDaysChange = 365): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'allowedBranches' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 1,
                    'maxItems' => 100,
                ],
                'daysChange' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 3650,
                    'default' => $defaultDaysChange,
                ],
                'reportFlag' => [
                    'type' => 'boolean',
                    'default' => false,
                ],
                'includeResponseHeaders' => [
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
            'required' => ['allowedBranches'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $payloadSchema
     *
     * @return array<string, mixed>
     */
    public static function payloadToolInput(array $payloadSchema, bool $payloadRequired = false): array
    {
        $required = $payloadRequired ? ['payload'] : [];

        return [
            'type' => 'object',
            'properties' => [
                'payload' => $payloadSchema,
                'includeResponseHeaders' => [
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function dashboardFetchGeneralOutput(): array
    {
        return self::envelope([
            'type' => 'object',
            'properties' => [
                'endpoint' => ['type' => 'string'],
                'request' => [
                    'type' => 'object',
                    'properties' => [
                        'allowedBranches' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'daysChange' => ['type' => 'integer'],
                        'reportFlag' => ['type' => 'boolean'],
                    ],
                    'required' => ['allowedBranches', 'daysChange', 'reportFlag'],
                    'additionalProperties' => false,
                ],
                'statusCode' => ['type' => 'integer'],
                'status' => ['type' => ['string', 'null']],
                'summaryOnly' => ['type' => 'boolean'],
                'dataTotalReport' => [
                    'anyOf' => [
                        self::genericObject(),
                        ['type' => 'null'],
                    ],
                ],
                'dataBranch' => [
                    'anyOf' => [
                        ['type' => 'array', 'items' => self::genericObject()],
                        ['type' => 'null'],
                    ],
                ],
                'dataGeneralBranch' => [
                    'anyOf' => [
                        ['type' => 'array', 'items' => self::genericObject()],
                        ['type' => 'null'],
                    ],
                ],
                'dataSegment' => [
                    'anyOf' => [
                        ['type' => 'array', 'items' => self::genericObject()],
                        ['type' => 'null'],
                    ],
                ],
                'dataConstant' => [
                    'anyOf' => [
                        ['type' => 'array', 'items' => self::genericObject()],
                        ['type' => 'null'],
                    ],
                ],
                'responseHeaders' => [
                    'anyOf' => [
                        self::genericObject(),
                        ['type' => 'null'],
                    ],
                ],
            ],
            'required' => [
                'endpoint',
                'request',
                'statusCode',
                'status',
                'summaryOnly',
                'dataTotalReport',
                'dataBranch',
                'dataGeneralBranch',
                'dataSegment',
                'dataConstant',
                'responseHeaders',
            ],
            'additionalProperties' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function genericEndpointOutput(): array
    {
        return self::envelope([
            'type' => 'object',
            'properties' => [
                'app' => [
                    'type' => 'object',
                    'properties' => [
                        'slug' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                    ],
                    'required' => ['slug', 'name'],
                    'additionalProperties' => false,
                ],
                'endpoint' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'toolName' => ['type' => 'string'],
                        'method' => ['type' => 'string'],
                        'parameterMode' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                    ],
                    'required' => ['name', 'toolName', 'method', 'parameterMode', 'url'],
                    'additionalProperties' => false,
                ],
                'request' => [
                    'type' => 'object',
                    'properties' => [
                        'payload' => [
                            'anyOf' => [
                                self::genericObject(),
                                ['type' => 'array', 'items' => self::genericObject()],
                                ['type' => 'null'],
                            ],
                        ],
                        'includeResponseHeaders' => ['type' => 'boolean'],
                    ],
                    'required' => ['payload', 'includeResponseHeaders'],
                    'additionalProperties' => false,
                ],
                'statusCode' => ['type' => 'integer'],
                'contentType' => ['type' => ['string', 'null']],
                'responseType' => ['type' => 'string'],
                'payload' => [
                    'anyOf' => [
                        self::genericObject(),
                        ['type' => 'array', 'items' => self::genericObject()],
                        ['type' => 'string'],
                        ['type' => 'number'],
                        ['type' => 'boolean'],
                        ['type' => 'null'],
                    ],
                ],
                'responseHeaders' => [
                    'anyOf' => [
                        self::genericObject(),
                        ['type' => 'null'],
                    ],
                ],
            ],
            'required' => ['app', 'endpoint', 'request', 'statusCode', 'contentType', 'responseType', 'payload', 'responseHeaders'],
            'additionalProperties' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fetchGeneralDashboard(): array
    {
        return self::dashboardFetchGeneralOutput();
    }

    /**
     * @param array<string, mixed> $dataSchema
     *
     * @return array<string, mixed>
     */
    private static function envelope(array $dataSchema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ok' => ['type' => 'boolean'],
                'requestId' => ['type' => ['string', 'null']],
                'data' => [
                    'anyOf' => [
                        $dataSchema,
                        ['type' => 'null'],
                    ],
                ],
                'error' => [
                    'anyOf' => [
                        self::errorSchema(),
                        ['type' => 'null'],
                    ],
                ],
            ],
            'required' => ['ok', 'requestId', 'data', 'error'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
                'statusCode' => ['type' => ['integer', 'null']],
                'requestId' => ['type' => ['string', 'null']],
                'details' => [
                    'anyOf' => [
                        self::genericObject(),
                        ['type' => 'null'],
                    ],
                ],
            ],
            'required' => ['message', 'statusCode', 'requestId', 'details'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function genericObject(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => true,
        ];
    }
}
