<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Check,
    ChevronDown,
    CircleAlert,
    Globe2,
    Plus,
    Settings2,
} from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { create as createWebsite } from '@/routes/websites';
import {
    current as selectWebsite,
    setup as setupWebsite,
} from '@/routes/websites';
import { edit as editWebsiteSettings } from '@/routes/websites/settings';
import type { WebsiteSwitcherData } from '@/types';

const page = usePage();
const websites = computed(
    () => page.props.websites as WebsiteSwitcherData | null,
);
const currentWebsite = computed(() => websites.value?.current ?? null);

function visitWebsite(website: WebsiteSwitcherData['items'][number]): void {
    if (website.status === 'setup_required') {
        router.visit(setupWebsite(website.id));

        return;
    }

    router.visit(selectWebsite(website.id), { method: 'patch' });
}
</script>

<template>
    <DropdownMenu v-if="websites">
        <DropdownMenuTrigger :as-child="true">
            <button
                type="button"
                class="group flex max-w-[min(15rem,45vw)] min-w-0 items-center gap-1.5 rounded-md border border-transparent py-1 pr-2.5 pl-1.5 text-left text-sm transition-colors hover:border-border hover:bg-accent focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40 focus-visible:outline-none"
                aria-label="Switch website"
            >
                <Globe2 class="size-4 shrink-0 text-primary" />
                <span class="min-w-0 truncate font-medium">
                    {{ currentWebsite?.name ?? 'Websites' }}
                </span>
                <ChevronDown
                    class="size-3.5 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-180"
                />
            </button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="start" class="w-72">
            <DropdownMenuLabel class="text-xs text-muted-foreground">
                Your websites
            </DropdownMenuLabel>

            <DropdownMenuItem
                v-for="website in websites.items"
                :key="website.id"
                :as-child="true"
            >
                <button
                    type="button"
                    class="flex w-full cursor-pointer items-center gap-2.5 rounded-sm px-2 py-2 text-left"
                    @click="visitWebsite(website)"
                >
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"
                    >
                        <Check
                            v-if="currentWebsite?.id === website.id"
                            class="size-3.5"
                        />
                        <CircleAlert
                            v-else-if="website.status === 'setup_required'"
                            class="size-3.5 text-warning"
                        />
                        <Globe2 v-else class="size-3.5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{
                            website.name
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                        >
                            {{
                                website.status === 'setup_required'
                                    ? 'Finish setup'
                                    : website.domain
                            }}
                        </span>
                    </span>
                    <span
                        v-if="currentWebsite?.id === website.id"
                        class="text-[0.65rem] font-medium text-primary"
                    >
                        Current
                    </span>
                </button>
            </DropdownMenuItem>

            <DropdownMenuSeparator />

            <DropdownMenuItem v-if="currentWebsite" :as-child="true">
                <Link
                    :href="editWebsiteSettings(currentWebsite.id)"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-2"
                >
                    <Settings2 class="size-4" />
                    Website settings
                </Link>
            </DropdownMenuItem>

            <DropdownMenuItem :as-child="true">
                <Link
                    :href="createWebsite()"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-2"
                >
                    <Plus class="size-4" />
                    Add website
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
