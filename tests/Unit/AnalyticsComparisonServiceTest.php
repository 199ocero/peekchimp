<?php

use App\Services\Analytics\AnalyticsComparisonService;
use Tests\TestCase;

uses(TestCase::class);

test('percentage changes are safe around zero', function () {
    $service = new AnalyticsComparisonService;

    expect($service->percentageChange(125, 100))->toBe(25.0)
        ->and($service->percentageChange(10, 0))->toBeNull()
        ->and($service->percentageChange(0, 0))->toBe(0.0);
});

test('small movement is ignored until the sample and threshold are meaningful', function () {
    $service = new AnalyticsComparisonService;

    expect($service->compare(12, 10, 'visitors'))->toBeNull()
        ->and($service->compare(80, 50, 'visitors'))->toMatchArray([
            'percentage_change' => 60.0,
            'direction' => 'increased',
            'confidence' => 'medium',
        ]);
});

test('rate comparisons use point differences and denominators', function () {
    $service = new AnalyticsComparisonService;

    expect($service->compare(15.0, 8.0, 'conversionRate', ['is_rate' => true, 'sample' => 100]))
        ->toMatchArray(['percentage_change' => 87.5])
        ->and($service->compare(15.0, 8.0, 'conversionRate', ['is_rate' => true, 'sample' => 20]))
        ->toBeNull();
});

test('a sufficiently large new source is treated as a meaningful appearance', function () {
    expect((new AnalyticsComparisonService)->compare(
        24,
        0,
        'visits',
        ['minimum_combined_count' => 20, 'minimum_count' => 10],
    ))->toMatchArray([
        'percentage_change' => null,
        'direction' => 'increased',
    ]);
});
