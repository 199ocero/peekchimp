<?php

namespace App\Mcp\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

trait DefinesMcpSchema
{
    /** @return array<string, Type> */
    protected function projectInputSchema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->required()
                ->description('The Peekchimp website project ID returned by list-websites.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    protected function reportInputSchema(JsonSchema $schema): array
    {
        return [
            ...$this->projectInputSchema($schema),
            'range' => $schema->string()
                ->enum(['today', 'yesterday', '7d', '30d', 'month', 'custom'])
                ->default('30d')
                ->description('A bounded reporting range. Defaults to the last 30 days.'),
            'from' => $schema->string()
                ->nullable()
                ->description('Custom range start in YYYY-MM-DD format; required when range is custom.'),
            'to' => $schema->string()
                ->nullable()
                ->description('Custom range end in YYYY-MM-DD format; required when range is custom.'),
        ];
    }

    /** @return array<string, Type> */
    protected function pageReportInputSchema(JsonSchema $schema): array
    {
        return [
            ...$this->reportInputSchema($schema),
            'path' => $schema->string()
                ->required()
                ->description('The website path to inspect, such as /pricing.'),
        ];
    }

    /** @return array<string, Type> */
    protected function structuredOutputSchema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->required(),
            'range' => $schema->object()->nullable(),
            'data' => $schema->union(['object', 'array', 'null'])->nullable(),
        ];
    }

    /**
     * @return array<string, Type>
     */
    protected function reportOutputSchema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->required(),
            'range' => $schema->object([
                'key' => $schema->string(),
                'label' => $schema->string(),
                'from' => $schema->string(),
                'to' => $schema->string(),
            ])->nullable(),
            'data' => $schema->union(['object', 'array', 'null'])->nullable(),
        ];
    }
}
