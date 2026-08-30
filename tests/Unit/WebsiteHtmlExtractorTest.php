<?php

use App\Services\Websites\WebsiteHtmlExtractor;
use App\Services\Websites\WebsiteUrlNormalizer;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('it extracts bounded page content and growth signals from public html', function () {
    $html = <<<'HTML'
<!doctype html>
<html>
<head>
    <title>  Analytics for practical teams  </title>
    <meta name="description" content="Understand what brings customers to your website.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="/analytics?utm_source=test">
    <script type="application/ld+json">{"@type":"SoftwareApplication"}</script>
    <script>window.secret = 'must not be extracted';</script>
</head>
<body>
    <header><a href="/pricing" class="btn">Start free</a></header>
    <main>
        <h1>Understand your website</h1>
        <h2>Find the next opportunity</h2>
        <p>Use aggregate analytics evidence to make a better decision.</p>
        <form><label>Email <input value="private@example.test"></label></form>
    </main>
</body>
</html>
HTML;

    $result = (new WebsiteHtmlExtractor(new WebsiteUrlNormalizer))
        ->extract($html, 'https://example.com/current');

    expect($result)
        ->title->toBe('Analytics for practical teams')
        ->meta_description->toBe('Understand what brings customers to your website.')
        ->canonical_url->toBe('https://example.com/analytics')
        ->robots_directives->toBe(['index', 'follow'])
        ->headings->toHaveCount(2)
        ->links->toHaveCount(1)
        ->cta_candidates->toHaveCount(1)
        ->structured_data->toBe([['valid' => true, 'types' => ['SoftwareApplication']]])
        ->and($result['main_content'])
        ->toContain('Understand your website')
        ->not->toContain('window.secret')
        ->not->toContain('private@example.test');
});

test('it records malformed structured data without exposing the script body', function () {
    $result = (new WebsiteHtmlExtractor(new WebsiteUrlNormalizer))->extract(
        '<html><body><main>Visible copy</main><script type="application/ld+json">{broken}</script></body></html>',
        'https://example.com/',
    );

    expect($result['structured_data'])->toBe([['valid' => false, 'types' => []]])
        ->and($result['main_content'])->toBe('Visible copy');
});
