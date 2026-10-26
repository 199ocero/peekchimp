<?php

namespace App\Actions\Websites;

use App\Contracts\SearchConsoleClient;
use App\Models\ChatConversationContext;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Throwable;

class DeleteWebsiteAction
{
    public function __construct(private readonly SearchConsoleClient $searchConsoleClient) {}

    /**
     * Delete the website and everything collected for it. Project-owned tables
     * cascade on delete; chat conversations live in the AI SDK tables and are
     * removed explicitly.
     */
    public function handle(Project $project): void
    {
        $connection = $project->searchConsoleConnection;

        if ($connection !== null) {
            try {
                $this->searchConsoleClient->revoke($connection);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        DB::transaction(function () use ($project): void {
            $conversationIds = ChatConversationContext::query()
                ->where('project_id', $project->getKey())
                ->pluck('conversation_id');

            ConversationMessage::query()->whereIn('conversation_id', $conversationIds)->delete();
            Conversation::query()->whereKey($conversationIds)->delete();

            $project->delete();
        });

        Cache::forget('dashboard:search-console:'.$project->getKey());
    }
}
