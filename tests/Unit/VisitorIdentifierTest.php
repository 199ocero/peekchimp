<?php

use App\Models\Project;
use App\Services\Analytics\VisitorIdentifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

test('visitor ids rotate by project and date without exposing the source values', function () {
    $project = new Project(['timezone' => 'UTC']);
    $project->id = 1;
    $request = Request::create('/', 'POST', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/126',
    ]);
    $identifier = app(VisitorIdentifier::class);

    $today = $identifier->make($project, $request, CarbonImmutable::parse('2026-08-23 12:00:00'));
    $tomorrow = $identifier->make($project, $request, CarbonImmutable::parse('2026-08-24 12:00:00'));

    expect($today)->toHaveLength(64)
        ->and($today)->not->toContain('203.0.113.10')
        ->and($today)->not->toContain('Mozilla')
        ->and($tomorrow)->not->toBe($today);
});
