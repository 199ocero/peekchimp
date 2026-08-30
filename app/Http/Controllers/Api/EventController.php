<?php

namespace App\Http\Controllers\Api;

use App\Actions\Analytics\IngestEventsAction;
use App\Actions\Websites\QueueWebsiteCrawlAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEventsRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function __invoke(
        StoreEventsRequest $request,
        IngestEventsAction $action,
        QueueWebsiteCrawlAction $queueWebsiteCrawl,
    ): JsonResponse {
        if ((int) ($request->header('Content-Length') ?? 0) > (int) config('analytics.ingestion_max_bytes', 65536)) {
            return response()->json(['message' => 'Payload is too large.'], 413);
        }

        $project = Project::query()
            ->where('site_key', $request->string('site')->toString())
            ->where('is_active', true)
            ->with('domains')
            ->first();

        if (! $project) {
            return response()->json(['message' => 'Unknown site.'], 404);
        }

        $origin = $request->header('Origin');

        if (! $this->originAllowed($project, $origin)) {
            return response()->json(['message' => 'Origin is not allowed.'], 403);
        }

        $result = $action->handle($project, $request->validated(), $request);

        if ($result['accepted_page_view']) {
            $wasJustVerified = $this->verifyDomain($project, $origin);

            if ($wasJustVerified) {
                $queueWebsiteCrawl->handle($project);
            }
        }

        return response()
            ->json(Arr::only($result, ['accepted', 'filtered', 'duplicate']), 202)
            ->withHeaders($this->corsHeaders($origin));
    }

    private function originAllowed(Project $project, ?string $origin): bool
    {
        if ($origin === null || trim($origin) === '') {
            return true;
        }

        $host = $this->originHost($origin);

        if ($host === null) {
            return false;
        }

        $domains = $project->domains->pluck('domain')->map(
            fn (string $domain): string => $this->normalizeHost($domain),
        );

        return $domains->isEmpty() || $domains->contains($host);
    }

    private function verifyDomain(Project $project, ?string $origin): bool
    {
        $host = $this->originHost($origin);

        if ($host === null) {
            return false;
        }

        return $project->domains()
            ->where('domain', $host)
            ->where('is_verified', false)
            ->update(['is_verified' => true]) === 1;
    }

    private function originHost(?string $origin): ?string
    {
        if ($origin === null || trim($origin) === '') {
            return null;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        return is_string($host) ? $this->normalizeHost($host) : null;
    }

    private function normalizeHost(string $host): string
    {
        return Str::of($host)->trim()->lower()->rtrim('.')->toString();
    }

    /**
     * @return array<string, string>
     */
    private function corsHeaders(?string $origin): array
    {
        return [
            'Access-Control-Allow-Origin' => $origin ?: '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ];
    }
}
