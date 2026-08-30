<?php

use App\Mcp\Servers\PeekchimpServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

Mcp::oauthRoutes();

Mcp::web('/mcp', PeekchimpServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use'), 'throttle:mcp'])
    ->name('mcp.peekchimp');
