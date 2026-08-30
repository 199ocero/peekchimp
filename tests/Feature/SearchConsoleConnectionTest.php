<?php

use App\Contracts\SearchConsoleClient;
use App\Jobs\StartSearchConsoleSync;
use App\Models\User;
use App\Services\SearchConsole\SearchConsolePropertyMatcher;
use Illuminate\Support\Facades\Queue;

it('only offers verified Search Console properties with the exact project host', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    $project->domains()->update(['domain' => 'example.com']);

    $matches = app(SearchConsolePropertyMatcher::class)->matching($project, [
        ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner'],
        ['siteUrl' => 'https://example.com/blog/', 'permissionLevel' => 'siteFullUser'],
        ['siteUrl' => 'https://www.example.com/', 'permissionLevel' => 'siteOwner'],
        ['siteUrl' => 'sc-domain:sub.example.com', 'permissionLevel' => 'siteOwner'],
        ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteUnverifiedUser'],
    ]);

    expect($matches)->toHaveCount(2)
        ->and(array_column($matches, 'siteUrl'))->toBe([
            'sc-domain:example.com',
            'https://example.com/blog/',
        ]);
});

it('connects the single exact-match property returned by Google', function () {
    Queue::fake();
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    $project->domains()->update(['domain' => 'example.com']);
    $client = Mockery::mock(SearchConsoleClient::class);
    $client->shouldReceive('exchangeAuthorizationCode')->once()->with('google-code')->andReturn([
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_in' => 3600,
    ]);
    $client->shouldReceive('listSites')->once()->with('access-token')->andReturn([
        ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner'],
        ['siteUrl' => 'sc-domain:other.test', 'permissionLevel' => 'siteOwner'],
    ]);
    app()->instance(SearchConsoleClient::class, $client);
    $state = str_repeat('a', 64);

    $this->actingAs($user)
        ->withSession(['search_console.oauth' => [
            'state' => $state,
            'project_id' => $project->getKey(),
            'expires_at' => now()->addMinute()->timestamp,
        ]])
        ->get(route('google-search-console.callback', ['state' => $state, 'code' => 'google-code']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('websites.settings.edit', $project));

    $connection = $project->searchConsoleConnection()->sole();
    expect($connection->property_site_url)->toBe('sc-domain:example.com')
        ->and($connection->refresh_token)->toBe('refresh-token');
    Queue::assertPushed(StartSearchConsoleSync::class, fn (StartSearchConsoleSync $job): bool => $job->connectionId === $connection->getKey());
});

it('rejects an invalid OAuth state before exchanging credentials', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    $client = Mockery::mock(SearchConsoleClient::class);
    $client->shouldNotReceive('exchangeAuthorizationCode');
    app()->instance(SearchConsoleClient::class, $client);

    $this->actingAs($user)
        ->withSession(['search_console.oauth' => [
            'state' => str_repeat('a', 64),
            'project_id' => $project->getKey(),
            'expires_at' => now()->addMinute()->timestamp,
        ]])
        ->get(route('google-search-console.callback', ['state' => 'wrong', 'code' => 'google-code']))
        ->assertSessionHasErrors('search_console')
        ->assertRedirect(route('websites.settings.edit', $project));
});
