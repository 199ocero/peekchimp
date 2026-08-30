<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { edit } from '@/routes/settings/mcp';
import { destroy } from '@/routes/settings/mcp/connections';

type Connection = {
    id: string;
    name: string;
    authorizedAt: string | null;
    expiresAt: string | null;
    tokenCount: number;
};

const props = defineProps<{
    endpoint: string;
    resourceUri: string;
    connections: Connection[];
    status?: string | null;
}>();

const copied = ref(false);

async function copyEndpoint(): Promise<void> {
    await navigator.clipboard.writeText(props.endpoint);
    copied.value = true;
    window.setTimeout(() => {
        copied.value = false;
    }, 1800);
}

function formatDate(value: string | null): string {
    return value === null ? 'Unknown' : new Date(value).toLocaleDateString();
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'MCP connections',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="MCP connections" />

    <div class="space-y-8">
        <header>
            <Heading
                variant="small"
                title="MCP connections"
                description="Connect ChatGPT, Claude, or another MCP client to ask questions about your aggregate analytics and Search Console data."
            />
        </header>

        <p
            v-if="props.status"
            class="rounded-md border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300"
        >
            {{ props.status }}
        </p>

        <Card class="space-y-5 p-5">
            <div>
                <h2 class="text-base font-medium">Remote MCP endpoint</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Paste this URL into your MCP client's custom connector
                    setup. OAuth will ask you to approve read-only access.
                </p>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <code
                    class="min-w-0 flex-1 overflow-x-auto rounded-md border bg-muted/40 px-3 py-2 text-xs break-all"
                    >{{ props.endpoint }}</code
                >
                <Button type="button" variant="outline" @click="copyEndpoint">
                    {{ copied ? 'Copied' : 'Copy endpoint' }}
                </Button>
            </div>

            <div class="space-y-2 text-sm text-muted-foreground">
                <p>
                    Available data is aggregate and read-only. No visitor IDs,
                    personal data, writes, or raw OAuth tokens are exposed.
                </p>
                <ol class="list-decimal space-y-1 pl-5">
                    <li>
                        Open your MCP client's custom connector or remote server
                        settings.
                    </li>
                    <li>Paste the endpoint and choose Connect.</li>
                    <li>
                        Complete the Peekchimp OAuth approval, then ask the
                        client to list your websites.
                    </li>
                </ol>
                <p>
                    Methodology resource:
                    <code class="text-xs">{{ props.resourceUri }}</code>
                </p>
            </div>
        </Card>

        <Card class="space-y-4 p-5">
            <div>
                <h2 class="text-base font-medium">Authorized clients</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Revoking a client invalidates its active access and refresh
                    tokens immediately.
                </p>
            </div>

            <div
                v-if="props.connections.length === 0"
                class="rounded-md border border-dashed px-4 py-6 text-sm text-muted-foreground"
            >
                No MCP client has been authorized yet.
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="connection in props.connections"
                    :key="connection.id"
                    class="flex flex-col gap-4 rounded-md border p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <p class="font-medium">{{ connection.name }}</p>
                            <Badge variant="secondary">Read only</Badge>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Authorized
                            {{ formatDate(connection.authorizedAt) }} · expires
                            {{ formatDate(connection.expiresAt) }} ·
                            {{ connection.tokenCount }} active token(s)
                        </p>
                    </div>

                    <Form
                        :action="destroy.url(connection.id)"
                        method="delete"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="processing"
                        >
                            Revoke access
                        </Button>
                    </Form>
                </div>
            </div>
        </Card>
    </div>
</template>
