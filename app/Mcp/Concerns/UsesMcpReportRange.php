<?php

namespace App\Mcp\Concerns;

use App\Models\Project;
use App\Services\Mcp\McpReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait UsesMcpReportRange
{
    /**
     * @return array{key: string, label: string, from: CarbonImmutable, to: CarbonImmutable, dashboardFilters: array<string, string>}|Response
     */
    protected function reportRange(Request $request, Project $project): array|Response
    {
        try {
            Validator::validate($request->all(), [
                'range' => ['nullable', 'string', 'in:today,yesterday,7d,30d,month,custom'],
                'from' => ['nullable', 'string'],
                'to' => ['nullable', 'string'],
            ]);

            return app(McpReportRange::class)->resolve($project, $request->all());
        } catch (InvalidArgumentException|ValidationException $exception) {
            if ($exception instanceof ValidationException) {
                return Response::error($exception->validator->errors()->first());
            }

            return Response::error($exception->getMessage());
        }
    }
}
