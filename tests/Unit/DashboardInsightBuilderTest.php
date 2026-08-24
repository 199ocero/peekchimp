<?php

use App\Services\Analytics\DashboardInsightBuilder;

test('it waits for a useful sample before suggesting changes', function () {
    $insights = new DashboardInsightBuilder()->build(19, 100, [
        ['label' => 'Direct', 'value' => 19],
    ]);

    expect($insights)->toBe([[
        'type' => 'insufficient_data',
        'tone' => 'neutral',
        'value' => 19,
    ]]);
});

test('it flags a high single page rate at the threshold', function () {
    $insights = new DashboardInsightBuilder()->build(20, 70, [
        ['label' => 'google.com', 'value' => 20],
    ]);

    expect($insights)->toContain([
        'type' => 'high_single_page_rate',
        'tone' => 'attention',
        'value' => 70.0,
    ]);
});

test('it flags mostly unattributed traffic at the threshold', function () {
    $insights = new DashboardInsightBuilder()->build(20, 40, [
        ['label' => 'Direct', 'value' => 60],
        ['label' => 'google.com', 'value' => 40],
    ]);

    expect($insights)->toContain([
        'type' => 'unattributed_traffic',
        'tone' => 'attention',
        'value' => 60.0,
    ]);
});

test('it returns engagement before attribution when both need attention', function () {
    $insights = new DashboardInsightBuilder()->build(20, 80, [
        ['label' => 'Direct', 'value' => 80],
        ['label' => 'google.com', 'value' => 20],
    ]);

    expect(array_column($insights, 'type'))->toBe([
        'high_single_page_rate',
        'unattributed_traffic',
    ]);
});

test('it returns a positive insight when no supported issue is present', function () {
    $insights = new DashboardInsightBuilder()->build(20, 35, [
        ['label' => 'google.com', 'value' => 80],
        ['label' => 'Direct', 'value' => 20],
    ]);

    expect($insights)->toBe([[
        'type' => 'healthy_engagement',
        'tone' => 'positive',
        'value' => 35.0,
    ]]);
});
