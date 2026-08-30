<?php

use App\Jobs\CrawlWebsitePage;
use App\Jobs\FinalizeWebsiteCrawl;
use App\Jobs\RunAiVisibilityScan;
use App\Models\AiVisibilityScan;
use App\Models\Project;
use App\Models\User;
use App\Services\Websites\WebsiteHtmlExtractor;
use App\Services\Websites\WebsitePageFetcher;
use App\Services\Websites\WebsiteSnapshotService;
use App\Services\Websites\WebsiteUrlGuard;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Stream;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('owners can queue one website crawl at a time from settings', function () {
    Queue::fake();
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();

    $this->actingAs($user)
        ->post(route('websites.crawl.store', $project))
        ->assertRedirect(route('websites.settings.edit', $project));

    Queue::assertPushed(RunAiVisibilityScan::class, fn (RunAiVisibilityScan $job): bool => $job->project->is($project));
    expect($project->aiVisibilityScans()->sole()->status)->toBe('queued');

    $this->actingAs($user)
        ->post(route('websites.crawl.store', $project))
        ->assertRedirect(route('websites.settings.edit', $project));

    expect($project->aiVisibilityScans()->count())->toBe(1);
});

test('the crawl job stores extracted page snapshots and finalizes scan coverage', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $domain = $project->domains()->sole()->domain;
    $scan = AiVisibilityScan::factory()->for($project)->create([
        'status' => 'running',
        'score' => null,
        'findings' => ['discoveredPages' => 1],
        'completed_at' => null,
    ]);

    $guard = new class extends WebsiteUrlGuard
    {
        public function allows(Project $project, string $url): bool
        {
            return true;
        }
    };
    $this->app->instance(WebsiteUrlGuard::class, $guard);
    Http::fake([
        '*' => Http::response(
            '<html><head><title>Pricing</title><meta name="description" content="Simple plans"><script type="application/ld+json">{"@type":"Product"}</script></head><body><main><h1>Pricing</h1><a class="cta" href="/signup">Start free</a></main></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        ),
    ]);

    $job = new CrawlWebsitePage((int) $project->getKey(), (int) $scan->getKey(), 'https://'.$domain.'/pricing');
    $job->handle(
        $this->app->make(WebsitePageFetcher::class),
        $this->app->make(WebsiteHtmlExtractor::class),
        $this->app->make(WebsiteSnapshotService::class),
    );
    $job->handle(
        $this->app->make(WebsitePageFetcher::class),
        $this->app->make(WebsiteHtmlExtractor::class),
        $this->app->make(WebsiteSnapshotService::class),
    );

    $snapshot = $scan->pageSnapshots()->sole();
    expect($snapshot->project_id)->toBe($project->getKey())
        ->and($snapshot->normalized_path)->toBe('/pricing')
        ->and($snapshot->title)->toBe('Pricing')
        ->and($snapshot->headings)->toBe([['level' => 1, 'text' => 'Pricing']])
        ->and($snapshot->cta_candidates)->toHaveCount(1)
        ->and($snapshot->main_content)->not->toContain('{"@type"');

    (new FinalizeWebsiteCrawl((int) $project->getKey(), (int) $scan->getKey()))
        ->handle($this->app->make(WebsiteSnapshotService::class));

    $scan->refresh();
    expect($scan->status)->toBe('completed')
        ->and($scan->score)->toBe(100)
        ->and($scan->findings['crawl'])->toMatchArray([
            'pagesDiscovered' => 1,
            'pagesCrawled' => 1,
            'pagesSuccessful' => 1,
            'pagesFailed' => 0,
        ]);
});

test('the page fetcher stops reading responses beyond the configured size limit', function () {
    config()->set('analytics.website_crawl.max_response_bytes', 32);
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $guard = new class extends WebsiteUrlGuard
    {
        public function allows(Project $project, string $url): bool
        {
            return true;
        }
    };
    $this->app->instance(WebsiteUrlGuard::class, $guard);
    Http::fake(['*' => Http::response(str_repeat('x', 100), 200, ['Content-Type' => 'text/html'])]);

    $result = $this->app->make(WebsitePageFetcher::class)->fetch($project, 'https://example.test/large');

    expect($result['body'])->toBe('')
        ->and($result['response_bytes'])->toBe(33)
        ->and($result['error'])->toBe('The response exceeded the crawl size limit.');
});

test('the page fetcher reads a streamed response until its body is complete', function () {
    config()->set('analytics.website_crawl.max_response_bytes', 20000);
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $guard = new class extends WebsiteUrlGuard
    {
        public function allows(Project $project, string $url): bool
        {
            return true;
        }
    };
    $this->app->instance(WebsiteUrlGuard::class, $guard);

    $resource = fopen('php://temp', 'r+');
    fwrite($resource, '<html><body>'.str_repeat(' ', 9000).'<h1>Pricing</h1><a class="cta" href="/signup">Start free</a></body></html>');
    rewind($resource);
    $stream = new class($resource) extends Stream
    {
        public function read(int $length): string
        {
            return parent::read(min($length, 512));
        }
    };
    Http::fake(fn (): FulfilledPromise => new FulfilledPromise(new PsrResponse(200, ['Content-Type' => 'text/html'], $stream)));

    $fetch = $this->app->make(WebsitePageFetcher::class)->fetch($project, 'https://example.test/pricing');
    $extracted = $this->app->make(WebsiteHtmlExtractor::class)->extract($fetch['body'], $fetch['final_url']);

    expect($fetch['error'])->toBeNull()
        ->and($extracted['headings'])->toBe([['level' => 1, 'text' => 'Pricing']])
        ->and($extracted['links'])->toHaveCount(1)
        ->and($extracted['cta_candidates'])->toHaveCount(1);
});
