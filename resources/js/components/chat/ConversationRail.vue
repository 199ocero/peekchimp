<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MessageSquareText, Pencil, Plus, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { index as chatIndex, show as chatShow } from '@/routes/chat';

defineProps<{
    conversations: Array<{
        id: string;
        title: string;
        updatedAt: string;
    }>;
    selectedId: string | null;
}>();

const emit = defineEmits<{
    rename: [id: string, title: string];
    delete: [id: string];
    navigate: [];
}>();
</script>

<template>
    <div class="flex h-full min-h-0 flex-col bg-sidebar">
        <div class="border-b border-sidebar-border p-3">
            <Button as-child variant="outline" class="w-full justify-start">
                <Link :href="chatIndex()" @click="emit('navigate')">
                    <Plus class="size-4" />
                    New chat
                </Link>
            </Button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-2 py-3">
            <p
                class="px-2 pb-2 text-[0.68rem] font-medium tracking-[0.14em] text-muted-foreground uppercase"
            >
                Conversations
            </p>

            <p
                v-if="conversations.length === 0"
                class="px-2 py-6 text-sm leading-6 text-muted-foreground"
            >
                Your conversations for this website will appear here.
            </p>

            <div v-else class="space-y-1">
                <div
                    v-for="conversation in conversations"
                    :key="conversation.id"
                    class="group relative"
                >
                    <Link
                        :href="chatShow(conversation.id)"
                        class="flex min-w-0 items-center gap-2 rounded-lg py-2.5 pr-16 pl-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                        :class="
                            selectedId === conversation.id
                                ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                                : 'text-sidebar-foreground hover:bg-sidebar-accent/70'
                        "
                        @click="emit('navigate')"
                    >
                        <MessageSquareText
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        <span class="truncate">{{ conversation.title }}</span>
                    </Link>

                    <div
                        class="absolute top-1/2 right-1 flex -translate-y-1/2 items-center opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100"
                    >
                        <button
                            type="button"
                            class="rounded-md p-1.5 text-muted-foreground hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                            :aria-label="`Rename ${conversation.title}`"
                            @click="
                                emit(
                                    'rename',
                                    conversation.id,
                                    conversation.title,
                                )
                            "
                        >
                            <Pencil class="size-3.5" />
                        </button>
                        <button
                            type="button"
                            class="rounded-md p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                            :aria-label="`Delete ${conversation.title}`"
                            @click="emit('delete', conversation.id)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div
            class="border-t border-sidebar-border p-3 text-xs text-muted-foreground"
        >
            Private to you · scoped to this website
        </div>
    </div>
</template>
