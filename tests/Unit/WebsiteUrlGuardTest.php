<?php

use App\Models\Project;
use App\Models\ProjectDomain;
use App\Services\Websites\WebsiteUrlGuard;
use App\Services\Websites\WebsiteUrlNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

function websiteGuardWithAddresses(array $addresses): WebsiteUrlGuard
{
    return new class($addresses) extends WebsiteUrlGuard
    {
        /** @param array<int, string> $addresses */
        public function __construct(private readonly array $resolvedAddresses) {}

        protected function addresses(string $host): array
        {
            return $this->resolvedAddresses;
        }
    };
}

test('it allows only verified public website URLs', function () {
    $project = Project::factory()->create();
    ProjectDomain::factory()->for($project)->verified()->create(['domain' => 'example.com']);
    $guard = websiteGuardWithAddresses(['93.184.216.34']);

    expect($guard->allows($project, 'https://example.com/pricing'))->toBeTrue()
        ->and($guard->allows($project, 'http://example.com:80/about'))->toBeTrue()
        ->and($guard->allows($project, 'https://www.example.com/'))->toBeFalse()
        ->and($guard->allows($project, 'https://example.com:8443/'))->toBeFalse()
        ->and($guard->allows($project, 'https://user:pass@example.com/'))->toBeFalse()
        ->and($guard->allows($project, 'file:///etc/passwd'))->toBeFalse();
});

test('it rejects verified hosts that resolve to private addresses', function () {
    $project = Project::factory()->create();
    ProjectDomain::factory()->for($project)->verified()->create(['domain' => 'internal.example']);

    expect(websiteGuardWithAddresses(['127.0.0.1'])->allows($project, 'https://internal.example/'))->toBeFalse()
        ->and(websiteGuardWithAddresses(['10.0.0.8'])->allows($project, 'https://internal.example/'))->toBeFalse();
});

test('it resolves and canonicalizes crawl URLs consistently', function () {
    $normalizer = new WebsiteUrlNormalizer;

    expect($normalizer->absolute(
        'https://Example.COM/guides/current',
        '../pricing/?utm_source=email&plan=team&gclid=secret',
    ))->toBe('https://example.com/pricing?plan=team')
        ->and($normalizer->normalizePath('https://example.com/guides/../pricing/'))->toBe('/pricing')
        ->and($normalizer->absolute('https://example.com/', 'mailto:hello@example.com'))->toBeNull();
});
