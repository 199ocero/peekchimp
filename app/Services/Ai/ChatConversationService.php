<?php

namespace App\Services\Ai;

use App\Models\ChatConversationContext;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceAiSetting;
use App\Services\Analytics\AiProviderRegistry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

class ChatConversationService
{
    public function __construct(
        private readonly ConversationStore $store,
        private readonly AiProviderRegistry $providers,
    ) {}

    /**
     * @return array{
     *     available: bool,
     *     isAdmin: bool,
     *     provider: string|null,
     *     model: string|null,
     *     models: array<int, array{value: string, label: string, tier: string, description: string}>,
     *     reason: string|null
     * }
     */
    public function availability(User $user): array
    {
        $setting = $this->setting($user);
        $provider = is_string($setting?->provider) ? $setting->provider : null;
        $hasRequiredKey = $provider !== null
            && (! $this->providers->requiresApiKey($provider) || filled($setting->api_key));
        $available = $setting !== null
            && $setting->is_enabled
            && $provider !== null
            && $this->providers->isSupported($provider)
            && $hasRequiredKey;
        $models = $available ? $this->providers->modelCatalogFor($provider) : [];
        $savedModel = is_string($setting?->model) ? $setting->model : null;
        $model = $available && in_array($savedModel, array_column($models, 'value'), true)
            ? $savedModel
            : ($available ? $this->providers->defaultModelFor($provider) : null);

        return [
            'available' => $available,
            'isAdmin' => $user->is_admin,
            'provider' => $available ? $provider : null,
            'model' => $model,
            'models' => $models,
            'reason' => $available
                ? null
                : ($user->is_admin
                    ? 'Configure and enable a workspace AI provider to start chatting.'
                    : 'Ask your workspace admin to configure and enable the AI provider.'),
        ];
    }

    public function setting(User $user): ?WorkspaceAiSetting
    {
        return $user->workspaceOwnerUser()->workspaceAiSetting()->first();
    }

    /** @return array<int, array{id: string, title: string, updatedAt: string}> */
    public function conversations(User $user, Project $project): array
    {
        return ChatConversationContext::query()
            ->whereBelongsTo($project)
            ->whereHas('conversation', fn ($query) => $query
                ->where('participant_type', Conversation::participantType($user))
                ->where('participant_id', Conversation::participantKey($user)))
            ->with('conversation')
            ->get()
            ->filter(fn (ChatConversationContext $context): bool => $context->conversation !== null)
            ->sortByDesc(fn (ChatConversationContext $context): mixed => $context->conversation?->getAttribute('updated_at'))
            ->take(100)
            ->map(fn (ChatConversationContext $context): array => $this->conversationSummary($context))
            ->values()
            ->all();
    }

    public function create(User $user, Project $project, string $firstMessage): Conversation
    {
        return DB::transaction(function () use ($user, $project, $firstMessage): Conversation {
            $conversationId = $this->store->storeConversation(
                Conversation::participantType($user),
                Conversation::participantKey($user),
                Str::limit(trim($firstMessage), 72, preserveWords: true),
            );

            ChatConversationContext::query()->create([
                'conversation_id' => $conversationId,
                'project_id' => $project->getKey(),
            ]);

            return Conversation::query()->findOrFail($conversationId);
        });
    }

    public function find(User $user, Project $project, string $conversationId): Conversation
    {
        $context = ChatConversationContext::query()
            ->whereBelongsTo($project)
            ->where('conversation_id', $conversationId)
            ->whereHas('conversation', fn ($query) => $query
                ->where('participant_type', Conversation::participantType($user))
                ->where('participant_id', Conversation::participantKey($user)))
            ->with('conversation')
            ->first();

        if ($context?->conversation === null) {
            throw (new ModelNotFoundException)->setModel(Conversation::class, [$conversationId]);
        }

        return $context->conversation;
    }

    /** @return array{id: string, title: string, messages: array<int, array{id: string, role: string, content: string, tools: array<int, string>, createdAt: string}>, pendingApprovals: array<int, array{id: string, tool: string, arguments: array<string, mixed>, reason: string|null}>} */
    public function conversationData(Conversation $conversation): array
    {
        return [
            'id' => (string) $conversation->getKey(),
            'title' => $this->stringAttribute($conversation, 'title'),
            'messages' => $conversation->messages()
                ->oldest()
                ->get()
                ->map(fn (ConversationMessage $message): array => [
                    'id' => (string) $message->getKey(),
                    'role' => $this->stringAttribute($message, 'role'),
                    'content' => $this->stringAttribute($message, 'content'),
                    'tools' => $this->toolNames($message),
                    'createdAt' => $this->dateAttribute($message, 'created_at'),
                ])->all(),
            'pendingApprovals' => $this->pendingApprovals($conversation),
        ];
    }

    public function rename(Conversation $conversation, string $title): void
    {
        $conversation->forceFill(['title' => trim($title)])->save();
    }

    public function delete(Conversation $conversation): void
    {
        DB::transaction(function () use ($conversation): void {
            $conversation->messages()->delete();
            ChatConversationContext::query()->where('conversation_id', $conversation->getKey())->delete();
            $conversation->delete();
        });
    }

    /** @return array{id: string, title: string, updatedAt: string} */
    private function conversationSummary(ChatConversationContext $context): array
    {
        $conversation = $context->conversation;

        return [
            'id' => (string) $context->conversation_id,
            'title' => $conversation === null ? '' : $this->stringAttribute($conversation, 'title'),
            'updatedAt' => $conversation === null ? '' : $this->dateAttribute($conversation, 'updated_at'),
        ];
    }

    private function stringAttribute(Model $model, string $attribute): string
    {
        $value = $model->getAttribute($attribute);

        return is_string($value) ? $value : '';
    }

    private function dateAttribute(Model $model, string $attribute): string
    {
        $value = $model->getAttribute($attribute);

        return $value instanceof CarbonInterface ? $value->toIso8601String() : '';
    }

    /** @return array<int, string> */
    private function toolNames(ConversationMessage $message): array
    {
        $calls = $message->getAttribute('tool_calls');

        if (! is_array($calls)) {
            return [];
        }

        $names = [];

        foreach ($calls as $call) {
            if (is_array($call) && is_string($call['name'] ?? null)) {
                $names[] = $call['name'];
            }
        }

        return $names;
    }

    /** @return array<int, array{id: string, tool: string, arguments: array<string, mixed>, reason: string|null}> */
    private function pendingApprovals(Conversation $conversation): array
    {
        $pausedMessage = $conversation->messages()
            ->whereNotNull('approval_state')
            ->latest()
            ->first();

        if (! $pausedMessage instanceof ConversationMessage) {
            return [];
        }

        $approvalState = $pausedMessage->getAttribute('approval_state');
        $pending = is_array($approvalState) ? ($approvalState['pending'] ?? null) : null;

        if (! is_array($pending) || $pending === []) {
            return [];
        }

        $toolCalls = $pausedMessage->getAttribute('tool_calls');

        return collect(is_array($toolCalls) ? $toolCalls : [])
            ->filter(fn (mixed $call): bool => is_array($call) && is_string($call['id'] ?? null) && array_key_exists($call['id'], $pending))
            ->map(fn (array $call): array => [
                'id' => $call['id'],
                'tool' => is_string($call['name'] ?? null) ? $call['name'] : 'website setup',
                'arguments' => is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
                'reason' => is_string($pending[$call['id']] ?? null) ? $pending[$call['id']] : null,
            ])
            ->values()
            ->all();
    }
}
