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
