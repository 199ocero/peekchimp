<?php

use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Client;
use Laravel\Passport\Token;

test('mcp settings show the endpoint and active connections', function () {
    $user = User::factory()->withVerifiedWebsite()->create();

    $this->actingAs($user)
        ->get(route('settings.mcp.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Mcp')
            ->where('endpoint', route('mcp.peekchimp'))
            ->where('resourceUri', 'peekchimp://guide/analytics-methodology')
            ->where('connections', []),
        );
});

test('users can revoke an authorized mcp client and its refresh tokens', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $client = Client::factory()->asPublic()->create([
        'name' => 'Claude',
        'scopes' => ['mcp:use'],
    ]);
    $token = Token::query()->create([
        'id' => Str::random(80),
        'user_id' => $user->getKey(),
        'client_id' => $client->getKey(),
        'name' => 'Claude MCP',
        'scopes' => ['mcp:use'],
        'revoked' => false,
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->get(route('settings.mcp.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.name', 'Claude')
            ->where('connections.0.tokenCount', 1),
        );

    $this->actingAs($user)
        ->delete(route('settings.mcp.connections.destroy', ['client' => $client->getKey()]))
        ->assertRedirect(route('settings.mcp.edit'))
        ->assertSessionHas('status', 'MCP access revoked.');

    expect($token->refresh()->revoked)->toBeTrue();
});

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
