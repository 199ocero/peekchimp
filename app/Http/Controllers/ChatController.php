<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Ai\ChatConversationService;
use App\Services\Mcp\SetupGuidanceService;
use App\Services\Websites\CurrentWebsiteResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function index(Request $request, CurrentWebsiteResolver $websites, ChatConversationService $conversations, SetupGuidanceService $setupGuidance): Response
    {
        return $this->render($request, $websites, $conversations, $setupGuidance);
    }

    public function show(Request $request, string $conversation, CurrentWebsiteResolver $websites, ChatConversationService $conversations, SetupGuidanceService $setupGuidance): Response
    {
        return $this->render($request, $websites, $conversations, $setupGuidance, $conversation);
    }

    private function render(
        Request $request,
        CurrentWebsiteResolver $websites,
        ChatConversationService $conversations,
        SetupGuidanceService $setupGuidance,
        ?string $conversationId = null,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $project = $websites->resolve($user) ?? abort(404);
        $selected = $conversationId === null ? null : $conversations->find($user, $project, $conversationId);

        return Inertia::render('Chat', [
            'website' => $websites->summary($project),
            'conversations' => $conversations->conversations($user, $project),
            'conversation' => $selected === null ? null : $conversations->conversationData($selected),
            'ai' => $conversations->availability($user),
            'setup' => $setupGuidance->get($project, $user),
        ]);
    }
}
