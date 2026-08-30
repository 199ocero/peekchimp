<?php

use App\Ai\Agents\WebsiteChatAgent;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\User;
use App\Models\WorkspaceAiSetting;
use App\Services\Ai\ApprovableProjectScopedMcpTool;
use App\Services\Ai\ChatConversationService;
use App\Services\Ai\ProjectScopedMcpTool;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall as AiToolCall;
use Laravel\Ai\Tools\Request as AiToolRequest;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response as McpResponse;
use Laravel\Mcp\Server\Tool as McpTool;

function enableChatFor(User $owner): void
{
    WorkspaceAiSetting::factory()->for($owner, 'workspaceOwner')->create([
        'provider' => 'openai',
        'model' => 'gpt-5.6-terra',
        'api_key' => 'test-key',
        'is_enabled' => true,
        'status' => 'configured',
    ]);
}

test('guests are redirected to login', function () {
    $this->get(route('chat.index'))->assertRedirect(route('login'));
});

test('users need a configured website to open chat', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('chat.index'))
        ->assertRedirect(route('onboarding.show'));
});

test('workspace members can use the shared AI provider without receiving its credentials', function () {
    $owner = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    enableChatFor($owner);
    $member = User::factory()->create([
        'workspace_owner_id' => $owner->getKey(),
        'is_admin' => false,
    ]);

    $this->actingAs($member)
        ->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Chat')
            ->where('ai.available', true)
            ->where('ai.isAdmin', false)
            ->where('ai.provider', 'openai')
            ->where('ai.model', 'gpt-5.6-terra')
            ->where('ai.models.0.value', 'gpt-5.6-luna')
            ->missing('ai.apiKey')
            ->missing('ai.baseUrl'));
});

test('chat exposes only the saved provider models', function () {
    $owner = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    WorkspaceAiSetting::factory()->for($owner, 'workspaceOwner')->create([
        'provider' => 'deepseek',
        'model' => 'deepseek-v4-pro',
        'api_key' => 'test-key',
        'is_enabled' => true,
        'status' => 'configured',
    ]);

    $this->actingAs($owner)
        ->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ai.provider', 'deepseek')
            ->where('ai.model', 'deepseek-v4-pro')
            ->has('ai.models', 2)
            ->where('ai.models.0.value', 'deepseek-v4-flash')
            ->where('ai.models.1.value', 'deepseek-v4-pro'));
});

test('chat shows the appropriate setup state when AI credentials are unavailable', function () {
    $admin = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ai.available', false)
            ->where('ai.isAdmin', true)
            ->where('ai.reason', 'Configure and enable a workspace AI provider to start chatting.')
            ->has('setup.areas', 11)
            ->where('setup.areas.6.key', 'ai_settings')
            ->where('setup.areas.6.status', 'needs_setup')
            ->where('setup.areas.6.current.configured', false));
});

test('a streamed response creates and remembers an SDK conversation', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    enableChatFor($user);
    WebsiteChatAgent::fake(['Visitors increased after the pricing page gained traffic.']);

    $response = $this->actingAs($user)->postJson(route('chat.messages.store'), [
        'message' => 'What changed this month?',
    ]);

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
        ->assertHeader('X-Conversation-Id');

    $content = $response->streamedContent();
    $conversationId = $response->headers->get('X-Conversation-Id');

    expect($content)
        ->toContain('"type":"text-delta"')
        ->toContain('Visitors')
        ->toContain('data: [DONE]');
    expect($conversationId)->toBeString();
    $this->assertDatabaseHas('chat_conversation_contexts', [
        'conversation_id' => $conversationId,
        'project_id' => $user->projects()->sole()->getKey(),
    ]);
    expect(ConversationMessage::query()->where('conversation_id', $conversationId)->count())->toBe(2);
    WebsiteChatAgent::assertPrompted('What changed this month?');
});

test('a saved conversation loads when its page is refreshed directly', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    enableChatFor($user);
    WebsiteChatAgent::fake(['The pricing page gained traffic.']);

    $created = $this->actingAs($user)->postJson(route('chat.messages.store'), [
        'message' => 'What changed this month?',
    ]);
    $created->streamedContent();
    $conversationId = $created->headers->get('X-Conversation-Id');

    $this->get(route('chat.show', $conversationId))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Chat')
            ->where('conversation.id', $conversationId)
            ->has('conversation.messages', 2)
            ->where('conversation.messages.0.content', 'What changed this month?')
            ->where('conversation.messages.1.content', 'The pricing page gained traffic.'));
});

test('a growth context save emits an approval request for the chat card', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    enableChatFor($user);
    WebsiteChatAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval(
                id: 'call_growth_context',
                tool: 'save-growth-context',
                arguments: ['audience' => 'Independent bakery owners'],
                reason: 'Approve the exact website setup change before Peekchimp applies it.',
            ),
        ]),
    ]);

    $response = $this->actingAs($user)->postJson(route('chat.messages.store'), [
        'message' => 'Save that our audience is independent bakery owners.',
    ]);

    expect($response->streamedContent())
        ->toContain('"type":"tool-approval-request"')
        ->toContain('"toolCallId":"call_growth_context"')
        ->toContain('"reason":"Approve the exact website setup change before Peekchimp applies it."');
    $this->assertDatabaseHas('agent_conversation_messages', [
        'approval_state' => json_encode([
            'pending' => [
                'call_growth_context' => 'Approve the exact website setup change before Peekchimp applies it.',
            ],
        ]),
    ]);
});

test('rejecting an approval gives the user a follow-up question without applying the change', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    enableChatFor($user);
    WebsiteChatAgent::fake([
        new AiToolCall(
            id: 'call_growth_context',
            name: 'save-growth-context',
            arguments: ['audience' => 'Independent bakery owners'],
        ),
        'No changes were made. What would you like to adjust?',
    ]);

    $approval = $this->actingAs($user)->postJson(route('chat.messages.store'), [
        'message' => 'Save that our audience is independent bakery owners.',
    ]);
    $approval->streamedContent();
    $conversationId = $approval->headers->get('X-Conversation-Id');

    $rejection = $this->postJson(route('chat.messages.store'), [
        'conversation_id' => $conversationId,
        'decisions' => [
            'call_growth_context' => ['action' => 'reject'],
        ],
    ]);

    expect($rejection->streamedContent())
        ->toContain('"delta":"No"')
        ->toContain('"delta":" changes"')
        ->toContain('"delta":" adjust?"');
    WebsiteChatAgent::assertPrompted(fn ($prompt): bool => $prompt->hasApprovalDecisions()
        && $prompt->approvalDecisions->get('call_growth_context')?->isRejected() === true);
});

test('refresh does not restore an older approval after the latest approval is resolved', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    enableChatFor($user);
    WebsiteChatAgent::fake([
        new AiToolCall(
            id: 'call_growth_context',
            name: 'save-growth-context',
            arguments: ['audience' => 'Independent bakery owners'],
        ),
    ]);

    $approval = $this->actingAs($user)->postJson(route('chat.messages.store'), [
        'message' => 'Save that our audience is independent bakery owners.',
    ]);
    $approval->streamedContent();
    $conversationId = $approval->headers->get('X-Conversation-Id');

    $resolvedPause = ConversationMessage::query()
        ->where('conversation_id', $conversationId)
        ->whereNotNull('approval_state')
        ->latest()
        ->firstOrFail();

    expect($resolvedPause->approval_state['pending'])->toHaveKey('call_growth_context');

    $resolvedPause->forceFill([
        'approval_state' => ['pending' => []],
    ])->save();

    ConversationMessage::query()->create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => $resolvedPause->participant_type,
        'participant_id' => $resolvedPause->participant_id,
        'agent' => $resolvedPause->agent,
        'role' => 'assistant',
        'content' => '',
        'attachments' => [],
        'tool_calls' => [[
            'id' => 'call_abandoned_growth_context',
            'name' => 'save-growth-context',
            'arguments' => ['audience' => 'An abandoned earlier draft'],
        ]],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'approval_state' => [
            'pending' => [
                'call_abandoned_growth_context' => 'Approve the earlier draft.',
            ],
        ],
        'created_at' => $resolvedPause->created_at->subMinute(),
        'updated_at' => $resolvedPause->created_at->subMinute(),
    ]);

    $this->get(route('chat.show', $conversationId))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.id', $conversationId)
            ->has('conversation.pendingApprovals', 0));
});

test('chat can override the model without changing AI settings', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    enableChatFor($user);
    WebsiteChatAgent::fake(['A concise answer from the cheaper model.']);

    $response = $this->actingAs($user)->postJson(route('chat.messages.store'), [
        'message' => 'Summarize my traffic.',
        'model' => 'gpt-5.6-luna',
    ]);
    $response->streamedContent();

    $response->assertSuccessful();
    WebsiteChatAgent::assertPrompted(fn ($prompt): bool => $prompt->model === 'gpt-5.6-luna');
    expect($user->workspaceAiSetting()->sole()->model)->toBe('gpt-5.6-terra');
});

test('chat rejects a model outside the saved provider', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    enableChatFor($user);

    $this->actingAs($user)->postJson(route('chat.messages.store'), [
        'message' => 'Summarize my traffic.',
        'model' => 'deepseek-v4-flash',
    ])->assertUnprocessable()->assertJsonValidationErrors('model');
});

test('existing conversations continue with SDK memory', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    enableChatFor($user);
    WebsiteChatAgent::fake(['First answer.', 'Second answer.']);

    $first = $this->actingAs($user)->postJson(route('chat.messages.store'), [
        'message' => 'First question',
    ]);
    $first->streamedContent();
    $conversationId = $first->headers->get('X-Conversation-Id');

    $second = $this->postJson(route('chat.messages.store'), [
        'message' => 'Follow up',
        'conversation_id' => $conversationId,
    ]);
    $second->streamedContent();

    expect(ConversationMessage::query()->where('conversation_id', $conversationId)->count())->toBe(4);
});

test('conversations are private to the user and selected website', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $firstProject = $user->projects()->sole();
    $secondProject = Project::factory()
        ->for($user)
        ->has(ProjectDomain::factory()->verified(), 'domains')
        ->create();
    $conversation = app(ChatConversationService::class)->create($user, $firstProject, 'Private project question');

    $user->currentProject()->associate($secondProject);
    $user->save();

    $this->actingAs($user)
        ->get(route('chat.show', $conversation->getKey()))
        ->assertNotFound();

    $otherUser = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $this->actingAs($otherUser)
        ->get(route('chat.show', $conversation->getKey()))
        ->assertNotFound();
});

test('owners can rename and delete only their current website conversations', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    $conversation = app(ChatConversationService::class)->create($user, $project, 'Original title');

    $this->actingAs($user)
        ->patch(route('chat.update', $conversation->getKey()), ['title' => 'Updated title'])
        ->assertRedirect();
    $this->assertDatabaseHas('agent_conversations', [
        'id' => $conversation->getKey(),
        'title' => 'Updated title',
    ]);

    $this->delete(route('chat.destroy', $conversation->getKey()))
        ->assertRedirect(route('chat.index'));
    $this->assertDatabaseMissing('agent_conversations', ['id' => $conversation->getKey()]);
    $this->assertDatabaseMissing('chat_conversation_contexts', ['conversation_id' => $conversation->getKey()]);
});

test('chat message validation and provider availability are enforced', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);

    $this->actingAs($user)
        ->postJson(route('chat.messages.store'), ['message' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');

    $this->postJson(route('chat.messages.store'), ['message' => 'What changed?'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');
});

test('the MCP adapter hides and overrides the selected project identifier', function () {
    $project = Project::factory()->create();
    $mcpTool = new class extends McpTool
    {
        public function handle(McpRequest $request): McpResponse
        {
            return McpResponse::text((string) $request->get('project_id'));
        }

        public function schema(JsonSchemaContract $schema): array
        {
            return [
                'project_id' => $schema->integer()->required(),
                'range' => $schema->string(),
            ];
        }
    };
    $tool = new ProjectScopedMcpTool($project, $mcpTool);

    $schema = $tool->schema(new JsonSchemaTypeFactory);
    $result = $tool->handle(new AiToolRequest([
        'project_id' => 999999,
        'range' => '30d',
    ]));

    expect($schema)
        ->not->toHaveKey('project_id')
        ->toHaveKey('range');
    expect((string) $result)->toBe((string) $project->getKey());
});

test('the chat wrapper requires approval before applying an MCP setup change', function () {
    $project = Project::factory()->create();
    $mcpTool = new class extends McpTool
    {
        public function handle(McpRequest $request): McpResponse
        {
            return McpResponse::text((string) $request->get('project_id'));
        }

        public function schema(JsonSchemaContract $schema): array
        {
            return ['project_id' => $schema->integer()->required()];
        }
    };
    $tool = new ApprovableProjectScopedMcpTool($project, $mcpTool);

    expect($tool->shouldRequestApproval(new AiToolRequest([])))
        ->not->toBeNull();
});

test('the chat agent stays website focused and uses a natural writing style', function () {
    $project = Project::factory()->make(['name' => 'Acme Bakery']);
    $instructions = (string) (new WebsiteChatAgent($project))->instructions();

    expect($instructions)
        ->toContain('Before using a tool or answering, decide whether the request is within that scope.')
        ->toContain('If it is unrelated, do not call any tool and do not answer the unrelated request.')
        ->toContain('I can help you understand and improve Acme Bakery')
        ->toContain('Never use an em dash.')
        ->toContain('Write like an experienced teammate, not a report generator:')
        ->toContain('immediately call save-growth-context with exactly those details')
        ->toContain('the approval card is the confirmation step')
        ->toContain('clearly say that no changes were made')
        ->toContain('ask what they would like adjusted')
        ->toContain('Do not repeat the user\'s question')
        ->not->toContain('—');
});

test('chat interface sanitizes markdown and supports streaming controls', function () {
    $page = file_get_contents(resource_path('js/pages/Chat.vue'));
    $rail = file_get_contents(resource_path('js/components/chat/ConversationRail.vue'));
    $markdown = file_get_contents(resource_path('js/components/chat/MarkdownRenderer.vue'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($page.$rail.$markdown.$styles)
        ->toContain("from 'dompurify'")
        ->toContain("from 'marked'")
        ->toContain('MarkdownRenderer')
        ->toContain('h-[calc(100dvh-3.5rem)]')
        ->toContain('overscroll-contain')
        ->toContain('scrollbar-gutter:stable')
        ->toContain('.chat-markdown :deep(table)')
        ->toContain('.chat-markdown :deep(pre)')
        ->toContain('role="log"')
        ->toContain('aria-live="polite"')
        ->toContain('AbortController')
        ->toContain('reactive<ChatMessage>')
        ->toContain('function syncConversation')
        ->toContain('Array.isArray(message.tools)')
        ->toContain('buffer.match(/\\r?\\n\\r?\\n/)')
        ->toContain('streamingMessageId === message.id')
        ->toContain('v-model="selectedModel"')
        ->toContain('model: selectedModel.value')
        ->toContain('Changing the model here does')
        ->toContain('DropdownMenuRadioGroup')
        ->toContain('tool-approval-request')
        ->toContain('resolveApproval')
        ->toContain('No changes were made. What would you like to change')
        ->toContain("if (action === 'reject')")
        ->toContain('assistant.isProvisional = true')
        ->toContain('if (assistant.isProvisional)')
        ->toContain('type="button"')
        ->toContain('Set up AI to unlock guided website setup')
        ->toContain('Peekchimp can guide you through your growth context')
        ->toContain('Set up AI')
        ->toContain('#app:empty')
        ->toContain('peekchimp-loading')
        ->toContain('Shift+Enter')
        ->toContain('AI can make mistakes')
        ->toContain('Private to you · scoped to this website')
        ->not->toContain('v-html="message.content"');
});
