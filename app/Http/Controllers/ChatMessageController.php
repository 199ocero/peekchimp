<?php

namespace App\Http\Controllers;

use App\Ai\Agents\WebsiteChatAgent;
use App\Http\Requests\StoreChatMessageRequest;
use App\Models\User;
use App\Services\Ai\ChatConversationService;
use App\Services\Analytics\AiProviderRegistry;
use App\Services\Websites\CurrentWebsiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Ai;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Models\Conversation;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ChatMessageController extends Controller
{
    public function store(
        StoreChatMessageRequest $request,
        CurrentWebsiteResolver $websites,
        ChatConversationService $conversations,
        AiProviderRegistry $providers,
    ): Response|JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $project = $websites->resolve($user) ?? abort(404);
        $setting = $conversations->setting($user);
        $availability = $conversations->availability($user);

        if (! $availability['available'] || $setting === null) {
            throw ValidationException::withMessages([
                'message' => [$availability['reason'] ?? 'AI chat is not available.'],
            ]);
        }

        $message = $request->validated('message');
        $decisions = $request->validated('decisions');
        $requestedModel = $request->validated('model');
        $conversationId = $request->validated('conversation_id');
        $isApprovalContinuation = is_array($decisions);
        $conversation = is_string($conversationId)
            ? $conversations->find($user, $project, $conversationId)
            : $conversations->create($user, $project, trim((string) $message));
        $prompt = $isApprovalContinuation
            ? Decisions::from(collect($decisions)
                ->map(fn (array $decision): Decision => $decision['action'] === 'approve'
                    ? Decision::approve()
                    : Decision::reject('The user declined this setup change. Clearly confirm that no changes were made and ask what they would like adjusted before proposing another approval.'))
                ->all())
                ->rejectRemaining('The user did not approve this setup change.')
            : trim((string) $message);
        $lockKey = 'chat:generation:'.$conversation->getKey();

        if (! Cache::add($lockKey, true, now()->addMinutes(3))) {
            return response()->json([
                'message' => 'This conversation is already generating a response.',
            ], 409);
        }

        $provider = (string) $setting->provider;
        $runtimeProvider = 'peekchimp_chat_'.$project->getKey().'_'.Str::lower(Str::random(12));
        config(['ai.providers.'.$runtimeProvider => $providers->runtimeConfig(
            $provider,
            (string) ($setting->api_key ?? ''),
            $setting->base_url,
        )]);

        try {
            $stream = WebsiteChatAgent::make(project: $project)
                ->continue((string) $conversation->getKey(), $user)
                ->stream(
                    $prompt,
                    provider: $runtimeProvider,
                    model: is_string($requestedModel)
                        ? $requestedModel
                        : (filled($setting->model) ? (string) $setting->model : null),
                    timeout: 120,
                )
                ->usingVercelDataProtocol();

            $stream->then(function () use ($runtimeProvider, $lockKey): void {
                Ai::forgetInstance($runtimeProvider);
                Cache::forget($lockKey);
            });

            $response = $stream->toResponse($request);
            $response->headers->set('X-Conversation-Id', (string) $conversation->getKey());
            $response->headers->set('X-Accel-Buffering', 'no');

            return $response;
        } catch (ApprovalMismatchException $exception) {
            Ai::forgetInstance($runtimeProvider);
            Cache::forget($lockKey);

            throw ValidationException::withMessages([
                'decisions' => ['That setup approval is no longer pending. Refresh the conversation and try again.'],
            ]);
        } catch (Throwable $exception) {
            Ai::forgetInstance($runtimeProvider);
            Cache::forget($lockKey);
            $this->deleteEmptyNewConversation($conversations, $conversation, is_string($conversationId));
            report($exception);

            return response()->json([
                'message' => 'Peekchimp could not start the AI response. Please try again.',
            ], 502);
        }
    }

    private function deleteEmptyNewConversation(
        ChatConversationService $conversations,
        Conversation $conversation,
        bool $wasExisting,
    ): void {
        if (! $wasExisting && ! $conversation->messages()->exists()) {
            $conversations->delete($conversation);
        }
    }
}
