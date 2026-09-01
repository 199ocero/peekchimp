<?php

use App\Services\Analytics\SourceGrouping;
use Tests\TestCase;

uses(TestCase::class);

test('known referrers use stable source groups without substring false positives', function () {
    $grouping = app(SourceGrouping::class);

    expect($grouping->classify('www.google.com', null)['source'])->toBe('Google')
        ->and($grouping->classify('notgoogle.com', null)['source'])->toBe('notgoogle.com')
        ->and($grouping->classify(null, 'facebook')['source'])->toBe('Facebook');
});

test('direct, search, and social traffic retain useful categories', function () {
    $grouping = app(SourceGrouping::class);

    expect($grouping->classify(null, null))->toMatchArray([
        'source' => 'Direct',
        'category' => 'direct',
    ])
        ->and($grouping->classify(null, 'blog', 'organic')['category'])->toBe('search')
        ->and($grouping->classify(null, 'community', 'social')['category'])->toBe('social');
});

test('internal referrers are treated as direct without overriding UTM sources', function () {
    $grouping = app(SourceGrouping::class);

    expect($grouping->classify('www.pastesheet.com', null, null, ['pastesheet.com']))->toMatchArray([
        'source' => 'Direct',
        'category' => 'direct',
    ])
        ->and($grouping->classify('pastesheet.com', 'newsletter', null, ['pastesheet.com'])['source'])->toBe('newsletter')
        ->and($grouping->classify('notpastesheet.com', null, null, ['pastesheet.com'])['source'])->toBe('notpastesheet.com');
});
