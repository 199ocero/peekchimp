<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDown,
    Bot,
    Check,
    ChevronDown,
    Copy,
    LoaderCircle,
    Menu,
    RotateCcw,
    Send,
    Settings2,
    Square,
    Sparkles,
    Wrench,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import ConversationRail from '@/components/chat/ConversationRail.vue';
import MarkdownRenderer from '@/components/chat/MarkdownRenderer.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    destroy as destroyChat,
    show as chatShow,
    update as updateChat,
} from '@/routes/chat';
import { store as storeMessage } from '@/routes/chat/messages';

type ConversationSummary = {
    id: string;
    title: string;
    updatedAt: string;
};

type ToolActivity = {
    id: string;
    name: string;
    status: 'running' | 'complete' | 'error';
    input: Record<string, unknown> | null;
};

type PendingApproval = {
    id: string;
    tool: string;
    arguments: Record<string, unknown>;
    reason: string | null;
};

type SetupArea = {
    key: string;
    status: string;
    current: Record<string, unknown>;
    settingsUrl: string;
    nextStep: string;
};

type ChatMessage = {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    tools: ToolActivity[];
    isProvisional?: boolean;
    error?: string;
};

type ChatModel = {
    value: string;
    label: string;
    tier: string;
    description: string;
};

type ChatConversation = {
    id: string;
    title: string;
    messages: Array<{
        id: string;
        role: string;
        content: string;
        tools: string[];
        createdAt: string;
    }>;
    pendingApprovals: PendingApproval[];
};

const props = defineProps<{
    website: { id: number; name: string; domain: string | null };
    conversations: ConversationSummary[];
    conversation: ChatConversation | null;
    ai: {
        available: boolean;
        isAdmin: boolean;
        provider: string | null;
        model: string | null;
        models: ChatModel[];
        reason: string | null;
    };
    setup: {
        areas: SetupArea[];
    };
}>();

const conversationList = ref<ConversationSummary[]>([...props.conversations]);
const conversationId = ref<string | null>(null);
const conversationTitle = ref('New conversation');
const messages = ref<ChatMessage[]>([]);
const draft = ref('');
const selectedModel = ref(props.ai.model ?? props.ai.models[0]?.value ?? '');
const isStreaming = ref(false);
const streamingMessageId = ref<string | null>(null);
const pendingApprovals = ref<PendingApproval[]>([]);
const copiedMessageId = ref<string | null>(null);
const showMobileHistory = ref(false);
const showScrollButton = ref(false);
const shouldAutoScroll = ref(true);
const messageLog = ref<HTMLElement | null>(null);
const composer = ref<HTMLTextAreaElement | null>(null);
let abortController: AbortController | null = null;
let scrollFrame: number | null = null;

function conversationMessages(
    conversation: ChatConversation | null,
): ChatMessage[] {
    return (conversation?.messages ?? [])
        .filter(
            (
                message,
            ): message is typeof message & { role: 'user' | 'assistant' } =>
                message.role === 'user' || message.role === 'assistant',
        )
        .map((message) => ({
            id: message.id,
            role: message.role,
            content: message.content,
            tools: (Array.isArray(message.tools) ? message.tools : []).map(
                (name, index) => ({
                    id: `${message.id}-${index}`,
                    name,
                    status: 'complete',
                    input: null,
                }),
            ),
        }));
}

function syncConversation(conversation: ChatConversation | null): void {
    conversationId.value = conversation?.id ?? null;
    conversationTitle.value = conversation?.title ?? 'New conversation';
    messages.value = conversationMessages(conversation);
    pendingApprovals.value = Array.isArray(conversation?.pendingApprovals)
        ? [...conversation.pendingApprovals]
        : [];
}

const suggestions = [
    'What changed on my website in the last 30 days?',
    'Which pages have the best growth opportunities?',
    'How can I improve conversions based on my data?',
    'Are there technical SEO issues I should fix first?',
];

const canSend = computed(
    () =>
        props.ai.available &&
        draft.value.trim() !== '' &&
        !isStreaming.value &&
        pendingApprovals.value.length === 0,
);
const selectedModelOption = computed(() =>
    props.ai.models.find((model) => model.value === selectedModel.value),
);
const providerLabel = computed(() => {
    const labels: Record<string, string> = {
        openai: 'OpenAI',
        anthropic: 'Anthropic',
        gemini: 'Google Gemini',
        deepseek: 'DeepSeek',
    };

    return props.ai.provider ? (labels[props.ai.provider] ?? 'AI') : 'AI';
});
const aiSetupArea = computed(() =>
    props.setup.areas.find((area) => area.key === 'ai_settings'),
);

function friendlyToolName(name: string): string {
    return name
        .replace(/[-_]/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function csrfToken(): string {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

function scheduleScroll(force = false): void {
    if ((!shouldAutoScroll.value && !force) || scrollFrame !== null) {
        return;
    }

    scrollFrame = window.requestAnimationFrame(() => {
        scrollFrame = null;
        const element = messageLog.value;

        if (element) {
            element.scrollTop = element.scrollHeight;
        }
    });
}

function handleScroll(): void {
    const element = messageLog.value;

    if (!element) {
        return;
    }

    const distance =
        element.scrollHeight - element.scrollTop - element.clientHeight;
    shouldAutoScroll.value = distance < 120;
    showScrollButton.value = distance >= 180;
}

function resizeComposer(): void {
    const element = composer.value;

    if (!element) {
        return;
    }

    element.style.height = 'auto';
    element.style.height = `${Math.min(element.scrollHeight, 200)}px`;
}

function useSuggestion(suggestion: string): void {
    draft.value = suggestion;
    nextTick(() => {
        resizeComposer();
        composer.value?.focus();
    });
}

function streamMessage(): ChatMessage {
    const message = reactive<ChatMessage>({
        id: `assistant-${Date.now()}`,
        role: 'assistant',
        content: '',
        tools: [],
    });
    messages.value.push(message);

    return message;
}

function updateTool(
    message: ChatMessage,
    id: string,
    name: string,
    status: ToolActivity['status'],
    input: Record<string, unknown> | null = null,
): void {
    const existing = message.tools.find((tool) => tool.id === id);

    if (existing) {
        existing.status = status;

        if (input !== null) {
            existing.input = input;
        }

        return;
    }

    message.tools.push({ id, name, status, input });
}

function objectInput(value: unknown): Record<string, unknown> | null {
    return value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}

function handleStreamEvent(
    event: Record<string, unknown>,
    assistant: ChatMessage,
): void {
    if (event.type === 'text-delta' && typeof event.delta === 'string') {
        if (assistant.isProvisional) {
            assistant.content = '';
            assistant.isProvisional = false;
        }

        assistant.content += event.delta;
        scheduleScroll();

        return;
    }

    if (
        event.type === 'tool-input-available' &&
        typeof event.toolCallId === 'string' &&
        typeof event.toolName === 'string'
    ) {
        updateTool(
            assistant,
            event.toolCallId,
            event.toolName,
            'running',
            objectInput(event.input),
        );
        scheduleScroll();

        return;
    }

    if (
        event.type === 'tool-approval-request' &&
        typeof event.toolCallId === 'string'
    ) {
        const tool = assistant.tools.find(
            (activity) => activity.id === event.toolCallId,
        );
        const approval: PendingApproval = {
            id: event.toolCallId,
            tool: tool?.name ?? 'website setup',
            arguments: tool?.input ?? {},
            reason: typeof event.reason === 'string' ? event.reason : null,
        };

        pendingApprovals.value = [
            ...pendingApprovals.value.filter(
                (existing) => existing.id !== approval.id,
            ),
            approval,
        ];
        scheduleScroll();

        return;
    }

    if (
        (event.type === 'tool-output-available' ||
            event.type === 'tool-output-error' ||
            event.type === 'tool-output-denied') &&
        typeof event.toolCallId === 'string'
    ) {
        const existing = assistant.tools.find(
            (tool) => tool.id === event.toolCallId,
        );
        updateTool(
            assistant,
            event.toolCallId,
            existing?.name ?? 'Website data',
            event.type === 'tool-output-available' ? 'complete' : 'error',
        );

        return;
    }

    if (event.type === 'error' && typeof event.errorText === 'string') {
        throw new Error(event.errorText);
    }
}

function handleEventBlock(block: string, assistant: ChatMessage): void {
    const data = block
        .split(/\r?\n/)
        .filter((line) => line.startsWith('data:'))
        .map((line) => line.slice(5).trimStart())
        .join('\n');

    if (data === '' || data.trim() === '[DONE]') {
        return;
    }

    handleStreamEvent(JSON.parse(data) as Record<string, unknown>, assistant);
}

async function parseEventStream(
    response: Response,
    assistant: ChatMessage,
): Promise<void> {
    const reader = response.body?.getReader();

    if (!reader) {
        throw new Error('The response stream was unavailable.');
    }

    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { done, value } = await reader.read();
        buffer += decoder.decode(value, { stream: !done });

        let boundary = buffer.match(/\r?\n\r?\n/);

        while (boundary?.index !== undefined) {
            const block = buffer.slice(0, boundary.index);
            buffer = buffer.slice(boundary.index + boundary[0].length);
            handleEventBlock(block, assistant);
            boundary = buffer.match(/\r?\n\r?\n/);
        }

        if (done) {
            break;
        }
    }

    buffer += decoder.decode();

    if (buffer.trim() !== '') {
        handleEventBlock(buffer, assistant);
    }
}

async function responseMessage(response: Response): Promise<string> {
    try {
        const data = (await response.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        return (
            data.errors?.message?.[0] ??
            data.errors?.model?.[0] ??
            data.message ??
            'Peekchimp could not generate a response.'
        );
    } catch {
        return 'Peekchimp could not generate a response.';
    }
}

function approvalDetails(approval: PendingApproval): string[] {
    return Object.entries(approval.arguments)
        .filter(([key]) => key !== 'project_id')
        .map(([key, value]) => {
            const label = key.replace(/_/g, ' ');
            const formatted = Array.isArray(value)
                ? value.join(', ')
                : typeof value === 'object' && value !== null
                  ? JSON.stringify(value)
                  : String(value);

            return `${label}: ${formatted}`;
        });
}

async function resolveApproval(
    approval: PendingApproval,
    action: 'approve' | 'reject',
): Promise<void> {
    if (!conversationId.value || isStreaming.value) {
        return;
    }

    const previousApprovals = [...pendingApprovals.value];
    const assistant = streamMessage();

    if (action === 'reject') {
        assistant.content =
            'No changes were made. What would you like to change before I prepare another approval?';
        assistant.isProvisional = true;
    }

    isStreaming.value = true;
    streamingMessageId.value = assistant.id;
    pendingApprovals.value = [];
    abortController = new AbortController();
    scheduleScroll(true);

    try {
        const response = await fetch(storeMessage().url, {
            method: 'POST',
            credentials: 'same-origin',
            signal: abortController.signal,
            headers: {
                Accept: 'text/event-stream, application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                conversation_id: conversationId.value,
                model: selectedModel.value,
                decisions: {
                    [approval.id]: { action },
                },
            }),
        });

        if (!response.ok) {
            throw new Error(await responseMessage(response));
        }

        await parseEventStream(response, assistant);
        assistant.isProvisional = false;
    } catch (error) {
        pendingApprovals.value = previousApprovals;

        if (assistant.isProvisional) {
            assistant.content = '';
            assistant.isProvisional = false;
        }

        assistant.error =
            error instanceof Error
                ? error.message
                : 'Peekchimp could not apply that decision.';
    } finally {
        isStreaming.value = false;
        streamingMessageId.value = null;
        abortController = null;
        scheduleScroll(true);
        composer.value?.focus();
    }
}

async function sendMessage(): Promise<void> {
    const content = draft.value.trim();

    if (!canSend.value || content === '') {
        return;
    }

    messages.value.push({
        id: `user-${Date.now()}`,
        role: 'user',
        content,
        tools: [],
    });
    const assistant = streamMessage();
    draft.value = '';
    isStreaming.value = true;
    streamingMessageId.value = assistant.id;
    shouldAutoScroll.value = true;
    abortController = new AbortController();
    await nextTick();
    resizeComposer();
    scheduleScroll(true);

    try {
        const response = await fetch(storeMessage().url, {
            method: 'POST',
            credentials: 'same-origin',
            signal: abortController.signal,
            headers: {
                Accept: 'text/event-stream, application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                message: content,
                conversation_id: conversationId.value,
                model: selectedModel.value,
            }),
        });

        if (!response.ok) {
            throw new Error(await responseMessage(response));
        }

        const responseConversationId =
            response.headers.get('X-Conversation-Id');

        if (responseConversationId && conversationId.value === null) {
            conversationId.value = responseConversationId;
            conversationTitle.value =
                content.length > 72 ? `${content.slice(0, 69)}…` : content;
            window.history.replaceState(
                {},
                '',
                chatShow(responseConversationId).url,
            );
            conversationList.value.unshift({
                id: responseConversationId,
                title: conversationTitle.value,
                updatedAt: new Date().toISOString(),
            });
        }

        await parseEventStream(response, assistant);

        if (assistant.content.trim() === '' && assistant.tools.length === 0) {
            throw new Error('The AI provider returned an empty response.');
        }
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            if (assistant.content.trim() === '') {
                assistant.content = 'Response stopped.';
            }
        } else {
            const message =
                error instanceof Error
                    ? error.message
                    : 'Peekchimp could not generate a response.';
            assistant.error = message;
        }
    } finally {
        isStreaming.value = false;
        streamingMessageId.value = null;
        abortController = null;
        scheduleScroll(true);
        composer.value?.focus();
    }
}

function handleComposerKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void sendMessage();
    }
}

function stopStreaming(): void {
    abortController?.abort();
}

async function copyMessage(message: ChatMessage): Promise<void> {
    await navigator.clipboard.writeText(message.content);
    copiedMessageId.value = message.id;
    window.setTimeout(() => {
        if (copiedMessageId.value === message.id) {
            copiedMessageId.value = null;
        }
    }, 1600);
}

function retryMessage(): void {
    const lastUserMessage = [...messages.value]
        .reverse()
        .find((message) => message.role === 'user');

    if (!lastUserMessage) {
        return;
    }

    draft.value = lastUserMessage.content;
    void nextTick(() => sendMessage());
}

function renameConversation(id: string, currentTitle: string): void {
    const title = window.prompt('Rename conversation', currentTitle)?.trim();

    if (!title || title === currentTitle) {
        return;
    }

    router.patch(
        updateChat(id).url,
        { title },
        {
            preserveScroll: true,
            onSuccess: () => {
                const conversation = conversationList.value.find(
                    (item) => item.id === id,
                );

                if (conversation) {
                    conversation.title = title;
                }

                if (conversationId.value === id) {
                    conversationTitle.value = title;
                }
            },
        },
    );
}

function deleteConversation(id: string): void {
    if (!window.confirm('Delete this conversation? This cannot be undone.')) {
        return;
    }

    router.delete(destroyChat(id).url, {
        preserveScroll: true,
        onSuccess: () => {
            conversationList.value = conversationList.value.filter(
                (conversation) => conversation.id !== id,
            );
        },
    });
}

watch(
    () => props.conversations,
    (value) => {
        conversationList.value = [...value];
    },
);

watch(
    () => props.conversation,
    (conversation) => {
        syncConversation(conversation);
    },
    { immediate: true },
);

onMounted(() => {
    scheduleScroll(true);
    resizeComposer();
});

onBeforeUnmount(() => {
    abortController?.abort();

    if (scrollFrame !== null) {
        window.cancelAnimationFrame(scrollFrame);
    }
});
</script>

<template>
    <div
        class="chat-page flex h-[calc(100dvh-3.5rem)] max-h-[calc(100dvh-3.5rem)] min-h-0 w-full flex-none overflow-hidden border-x border-border bg-background"
    >
        <Head title="Chat" />

        <aside
            class="hidden w-64 shrink-0 border-r border-sidebar-border lg:block"
        >
            <ConversationRail
                :conversations="conversationList"
                :selected-id="conversationId"
                @rename="renameConversation"
                @delete="deleteConversation"
            />
        </aside>

        <Sheet v-model:open="showMobileHistory">
            <SheetContent side="left" class="w-[86vw] max-w-80 p-0">
                <SheetHeader class="sr-only">
                    <SheetTitle>Chat history</SheetTitle>
                    <SheetDescription>
                        Conversations for the selected website
                    </SheetDescription>
                </SheetHeader>
                <ConversationRail
                    :conversations="conversationList"
                    :selected-id="conversationId"
                    @rename="renameConversation"
                    @delete="deleteConversation"
                    @navigate="showMobileHistory = false"
                />
            </SheetContent>
        </Sheet>

        <section
            class="relative flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden"
        >
            <header
                class="flex h-12 shrink-0 items-center gap-2 border-b border-border px-3 sm:px-5"
            >
                <Button
                    variant="ghost"
                    size="icon-sm"
                    class="lg:hidden"
                    aria-label="Open chat history"
                    @click="showMobileHistory = true"
                >
                    <Menu class="size-4" />
                </Button>
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">
                        {{ conversationTitle }}
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ website.name }}
                        <span v-if="website.domain">
                            · {{ website.domain }}</span
                        >
                    </p>
                </div>
                <span
                    v-if="ai.provider"
                    class="ml-auto hidden rounded-full border border-border bg-muted/50 px-2 py-1 font-mono text-[0.65rem] text-muted-foreground sm:inline-flex"
                >
                    {{ ai.provider }}
                </span>
            </header>

            <div
                ref="messageLog"
                role="log"
                aria-live="polite"
                aria-relevant="additions text"
                :aria-busy="isStreaming"
                class="min-h-0 flex-1 touch-pan-y [scrollbar-gutter:stable] overflow-y-auto overscroll-contain"
                @scroll.passive="handleScroll"
            >
                <div
                    v-if="messages.length === 0"
                    class="mx-auto flex min-h-full w-full max-w-3xl flex-col items-center justify-center px-5 py-12 text-center"
                >
                    <div
                        class="flex size-12 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 text-primary"
                    >
                        <Sparkles class="size-6" />
                    </div>
                    <h1
                        class="mt-5 text-2xl font-medium tracking-tight sm:text-3xl"
                    >
                        Ask about {{ website.name }}
                    </h1>
                    <p
                        class="mt-2 max-w-xl text-sm leading-6 text-muted-foreground"
                    >
                        Peekchimp Chat can inspect aggregate analytics, search
                        data, conversions, content opportunities, and technical
                        SEO for the selected website.
                    </p>

                    <div
                        v-if="ai.available"
                        class="mt-7 grid w-full gap-2 sm:grid-cols-2"
                    >
                        <button
                            v-for="suggestion in suggestions"
                            :key="suggestion"
                            type="button"
                            class="rounded-xl border border-border bg-card p-3 text-left text-sm leading-5 transition-colors hover:border-primary/30 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                            @click="useSuggestion(suggestion)"
                        >
                            {{ suggestion }}
                        </button>
                    </div>

                    <div
                        v-else
                        class="mt-7 w-full max-w-xl rounded-xl border border-border bg-muted/50 p-5 text-left"
                    >
                        <p class="text-sm font-medium">
                            Set up AI to unlock guided website setup
                        </p>
                        <p
                            class="mt-1.5 text-sm leading-6 text-muted-foreground"
                        >
                            First, connect an AI provider. Once it is ready,
                            Peekchimp can guide you through your growth context,
                            goals, and website crawl.
                        </p>
                        <Button
                            v-if="ai.isAdmin && aiSetupArea"
                            as-child
                            size="sm"
                            class="mt-3"
                        >
                            <Link :href="aiSetupArea.settingsUrl">
                                <Settings2 class="size-4" />
                                Set up AI
                            </Link>
                        </Button>
                    </div>
                </div>

                <div
                    v-else
                    class="mx-auto w-full max-w-3xl px-4 pt-6 pb-36 sm:px-6"
                >
                    <article
                        v-for="message in messages"
                        :key="message.id"
                        class="group mb-7 flex"
                        :class="
                            message.role === 'user'
                                ? 'justify-end'
                                : 'justify-start'
                        "
                    >
                        <div
                            v-if="message.role === 'assistant'"
                            class="mt-0.5 mr-3 flex size-7 shrink-0 items-center justify-center rounded-lg border border-primary/20 bg-primary/10 text-primary"
                            aria-hidden="true"
                        >
                            <Bot class="size-4" />
                        </div>

                        <div
                            class="min-w-0"
                            :class="
                                message.role === 'user'
                                    ? 'max-w-[85%] rounded-2xl rounded-br-md bg-secondary px-4 py-2.5 sm:max-w-[72%]'
                                    : 'max-w-[calc(100%_-_2.5rem)] flex-1'
                            "
                        >
                            <div
                                v-if="message.tools.length"
                                class="mb-3 space-y-1.5"
                            >
                                <div
                                    v-for="tool in message.tools"
                                    :key="tool.id"
                                    class="flex w-fit items-center gap-2 rounded-lg border border-border bg-card px-2.5 py-1.5 text-xs text-muted-foreground"
                                >
                                    <LoaderCircle
                                        v-if="tool.status === 'running'"
                                        class="size-3.5 animate-spin text-primary motion-reduce:animate-none"
                                    />
                                    <Check
                                        v-else-if="tool.status === 'complete'"
                                        class="size-3.5 text-success"
                                    />
                                    <Wrench
                                        v-else
                                        class="size-3.5 text-destructive"
                                    />
                                    {{ friendlyToolName(tool.name) }}
                                </div>
                            </div>

                            <p
                                v-if="message.role === 'user'"
                                class="text-sm leading-6 whitespace-pre-wrap"
                            >
                                {{ message.content }}
                            </p>
                            <div v-else-if="message.content">
                                <MarkdownRenderer :content="message.content" />
                                <span
                                    v-if="streamingMessageId === message.id"
                                    class="mt-1 inline-block h-4 w-0.5 animate-pulse rounded-full bg-primary align-middle motion-reduce:animate-none"
                                    aria-hidden="true"
                                />
                            </div>
                            <div
                                v-else-if="streamingMessageId === message.id"
                                class="flex items-center gap-1.5 py-2 text-muted-foreground"
                                aria-label="Peekchimp is thinking"
                            >
                                <span
                                    class="size-1.5 animate-pulse rounded-full bg-current"
                                />
                                <span
                                    class="size-1.5 animate-pulse rounded-full bg-current [animation-delay:150ms]"
                                />
                                <span
                                    class="size-1.5 animate-pulse rounded-full bg-current [animation-delay:300ms]"
                                />
                            </div>

                            <div
                                v-if="message.error"
                                class="mt-3 rounded-lg border border-destructive/25 bg-destructive/8 p-3 text-sm text-destructive"
                            >
                                {{ message.error }}
                                <button
                                    type="button"
                                    class="ml-2 inline-flex items-center gap-1 font-medium underline underline-offset-2"
                                    @click="retryMessage"
                                >
                                    <RotateCcw class="size-3.5" /> Retry
                                </button>
                            </div>

                            <button
                                v-if="
                                    message.role === 'assistant' &&
                                    message.content
                                "
                                type="button"
                                class="mt-2 inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 focus:opacity-100 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                                :aria-label="`Copy assistant response`"
                                @click="copyMessage(message)"
                            >
                                <Check
                                    v-if="copiedMessageId === message.id"
                                    class="size-3.5"
                                />
                                <Copy v-else class="size-3.5" />
                                {{
                                    copiedMessageId === message.id
                                        ? 'Copied'
                                        : 'Copy'
                                }}
                            </button>
                        </div>
                    </article>

                    <section
                        v-if="pendingApprovals.length"
                        class="ml-10 space-y-3 rounded-xl border border-primary/25 bg-primary/5 p-4"
                        aria-label="Pending setup approvals"
                    >
                        <div>
                            <p class="text-sm font-medium">
                                Approve website setup change
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Peekchimp will only apply the change you
                                approve.
                            </p>
                        </div>

                        <div
                            v-for="approval in pendingApprovals"
                            :key="approval.id"
                            class="rounded-lg border border-border bg-card p-3"
                        >
                            <p class="text-sm font-medium">
                                {{ friendlyToolName(approval.tool) }}
                            </p>
                            <p
                                v-if="approval.reason"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ approval.reason }}
                            </p>
                            <ul
                                v-if="approvalDetails(approval).length"
                                class="mt-3 space-y-1 text-xs text-muted-foreground"
                            >
                                <li
                                    v-for="detail in approvalDetails(approval)"
                                    :key="detail"
                                >
                                    {{ detail }}
                                </li>
                            </ul>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    :disabled="isStreaming"
                                    @click="
                                        resolveApproval(approval, 'approve')
                                    "
                                >
                                    <Check class="size-4" /> Approve
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    :disabled="isStreaming"
                                    @click="resolveApproval(approval, 'reject')"
                                >
                                    Reject
                                </Button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <Button
                v-if="showScrollButton"
                variant="outline"
                size="icon-sm"
                class="absolute right-5 bottom-32 z-10 rounded-full bg-background shadow-sm"
                aria-label="Scroll to latest message"
                @click="scheduleScroll(true)"
            >
                <ArrowDown class="size-4" />
            </Button>

            <div
                class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-background via-background to-transparent px-3 pt-8 pb-3 sm:px-6"
            >
                <form
                    class="pointer-events-auto mx-auto max-w-3xl"
                    @submit.prevent="sendMessage"
                >
                    <div
                        class="rounded-2xl border border-border bg-card p-2 shadow-[0_12px_40px_rgba(0,0,0,0.08)] transition-colors focus-within:border-primary/40 focus-within:ring-3 focus-within:ring-ring/10"
                    >
                        <textarea
                            ref="composer"
                            v-model="draft"
                            rows="1"
                            :disabled="!ai.available"
                            :placeholder="
                                ai.available
                                    ? `Ask about ${website.name}`
                                    : 'AI chat is not configured'
                            "
                            class="block max-h-[200px] min-h-10 w-full resize-none bg-transparent px-2 py-2 text-sm leading-6 outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed"
                            aria-label="Message Peekchimp Chat"
                            @input="resizeComposer"
                            @keydown="handleComposerKeydown"
                        />
                        <div
                            class="flex items-center justify-between gap-2 px-1 pb-0.5"
                        >
                            <p
                                class="min-w-0 truncate text-[0.68rem] text-muted-foreground"
                            >
                                AI can make mistakes
                                <span class="hidden sm:inline">
                                    · Enter to send · Shift+Enter for a new
                                    line</span
                                >
                            </p>
                            <div class="flex items-center gap-2">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            class="h-8 max-w-48 gap-1.5 rounded-lg px-2 text-xs font-normal hover:bg-muted sm:max-w-60"
                                            :disabled="
                                                !ai.available || isStreaming
                                            "
                                            aria-label="Choose chat model"
                                        >
                                            <span class="truncate font-medium">
                                                {{
                                                    selectedModelOption?.label ??
                                                    'Choose model'
                                                }}
                                            </span>
                                            <span
                                                v-if="selectedModelOption"
                                                class="hidden text-muted-foreground sm:inline"
                                            >
                                                {{ selectedModelOption.tier }}
                                            </span>
                                            <ChevronDown
                                                class="size-3.5 shrink-0 text-muted-foreground"
                                            />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        side="top"
                                        align="end"
                                        :side-offset="10"
                                        class="w-[min(22rem,calc(100vw-1.5rem))] rounded-xl border-border p-2 shadow-xl"
                                    >
                                        <DropdownMenuLabel
                                            class="px-3 pt-2 pb-2 font-normal"
                                        >
                                            <p class="text-sm font-medium">
                                                {{ providerLabel }} models
                                            </p>
                                            <p
                                                class="mt-0.5 text-xs leading-5 text-muted-foreground"
                                            >
                                                Changing the model here does not
                                                change AI Settings.
                                            </p>
                                        </DropdownMenuLabel>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuRadioGroup
                                            v-model="selectedModel"
                                        >
                                            <DropdownMenuRadioItem
                                                v-for="model in ai.models"
                                                :key="model.value"
                                                :value="model.value"
                                                class="items-start rounded-lg py-2.5 pr-10 pl-3 [&>span:first-child]:right-3 [&>span:first-child]:left-auto [&>span:first-child]:mt-1"
                                            >
                                                <template #indicator-icon>
                                                    <Check
                                                        class="size-4 text-primary"
                                                    />
                                                </template>
                                                <div class="min-w-0">
                                                    <div
                                                        class="flex items-center gap-2"
                                                    >
                                                        <span
                                                            class="font-medium"
                                                        >
                                                            {{ model.label }}
                                                        </span>
                                                        <span
                                                            class="rounded-md bg-muted px-1.5 py-0.5 text-[0.65rem] font-medium text-muted-foreground"
                                                        >
                                                            {{ model.tier }}
                                                        </span>
                                                    </div>
                                                    <p
                                                        class="mt-0.5 text-xs leading-5 text-muted-foreground"
                                                    >
                                                        {{ model.description }}
                                                    </p>
                                                </div>
                                            </DropdownMenuRadioItem>
                                        </DropdownMenuRadioGroup>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                                <Button
                                    v-if="isStreaming"
                                    type="button"
                                    size="icon-sm"
                                    variant="outline"
                                    class="rounded-full"
                                    aria-label="Stop response"
                                    @click="stopStreaming"
                                >
                                    <Square class="size-3.5 fill-current" />
                                </Button>
                                <Button
                                    v-else
                                    type="submit"
                                    size="icon-sm"
                                    class="rounded-full"
                                    :disabled="!canSend"
                                    aria-label="Send message"
                                >
                                    <Send class="size-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </section>
    </div>
</template>
