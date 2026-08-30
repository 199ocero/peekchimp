<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateChatConversationRequest;
use App\Models\User;
use App\Services\Ai\ChatConversationService;
use App\Services\Websites\CurrentWebsiteResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChatConversationController extends Controller
{
    public function update(
        UpdateChatConversationRequest $request,
        string $conversation,
        CurrentWebsiteResolver $websites,
        ChatConversationService $conversations,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $project = $websites->resolve($user) ?? abort(404);
        $record = $conversations->find($user, $project, $conversation);

        $conversations->rename($record, $request->validated('title'));

        return back();
    }

    public function destroy(
        Request $request,
        string $conversation,
        CurrentWebsiteResolver $websites,
        ChatConversationService $conversations,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $project = $websites->resolve($user) ?? abort(404);
        $record = $conversations->find($user, $project, $conversation);

        $conversations->delete($record);

        return to_route('chat.index');
    }
}
