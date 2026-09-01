<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

class McpConnectionController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $connections = $user->tokens()
            ->with(['client', 'refreshToken'])
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('revoked', false)
                        ->where('expires_at', '>', now());
                })->orWhereHas('refreshToken', function (Builder $query): void {
                    $query->where('revoked', false)
                        ->where('expires_at', '>', now());
                });
            })
            ->get()
            ->filter(fn (Token $token): bool => $token->can('mcp:use') && $token->client !== null)
            ->groupBy('client_id')
            ->map(function ($tokens): array {
                /** @var Token $first */
                $first = $tokens->first();

                return [
                    'id' => (string) $first->client_id,
                    'name' => (string) $first->client->name,
                    'authorizedAt' => $tokens->min('created_at')?->toIso8601String(),
                    'expiresAt' => $tokens->max(fn (Token $token) => $token->refreshToken !== null
                        && ! $token->refreshToken->revoked
                        && $token->refreshToken->expires_at?->isFuture() === true
                            ? $token->refreshToken->expires_at
                            : $token->expires_at)?->toIso8601String(),
                    'tokenCount' => $tokens->count(),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('settings/Mcp', [
            'endpoint' => route('mcp.peekchimp'),
            'resourceUri' => 'peekchimp://guide/analytics-methodology',
            'connections' => $connections,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function destroy(Request $request, string $client): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $tokens = $user->tokens()
            ->where('client_id', $client)
            ->get()
            ->filter(fn (Token $token): bool => $token->can('mcp:use'));

        abort_if($tokens->isEmpty(), 404);

        foreach ($tokens as $token) {
            $token->revoke();
            Passport::refreshToken()->newQuery()
                ->where('access_token_id', $token->getKey())
                ->update(['revoked' => true]);
        }

        return to_route('settings.mcp.edit')
            ->with('status', 'MCP access revoked.');
    }
}
