<?php

use App\Services\Analytics\InsightPriority;

test('insights are ordered by severity confidence and change magnitude', function () {
    $prioritized = new InsightPriority()->sort([
        ['label' => 'Lower confidence critical', 'severity' => 'critical', 'confidence' => 'low', 'percentage_change' => 150],
        ['label' => 'Warning', 'severity' => 'warning', 'confidence' => 'high', 'percentage_change' => 400],
        ['label' => 'Smaller high confidence critical', 'severity' => 'critical', 'confidence' => 'high', 'percentage_change' => 80],
        ['label' => 'Larger high confidence critical', 'severity' => 'critical', 'confidence' => 'high', 'percentage_change' => -120],
        ['label' => 'Informational', 'severity' => 'info', 'confidence' => 'high', 'percentage_change' => 800],
    ]);

    expect(array_column($prioritized, 'label'))->toBe([
        'Larger high confidence critical',
        'Smaller high confidence critical',
        'Lower confidence critical',
        'Warning',
        'Informational',
    ]);
});
