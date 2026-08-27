<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppearanceMenu from '@/components/AppearanceMenu.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import WebsiteSwitcher from '@/components/WebsiteSwitcher.vue';
import { getInitials } from '@/composables/useInitials';
import { dashboard } from '@/routes';
import { show as aiVisibilityShow } from '@/routes/websites/ai-visibility';
import { index as goalsIndex } from '@/routes/websites/goals';
import type { BreadcrumbItem, WebsiteSwitcherData } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const auth = computed(() => page.props.auth);
const websites = computed(
    () => page.props.websites as WebsiteSwitcherData | null,
);
const currentWebsite = computed(() => websites.value?.current ?? null);

const navigationItems = computed(() => {
    if (!currentWebsite.value) {
        return [];
    }

    return [
        {
            label: 'Overview',
            href: dashboard().url,
            active: page.url.startsWith('/dashboard'),
        },
        {
            label: 'AI visibility',
            href: aiVisibilityShow(currentWebsite.value.id).url,
            active: page.url.includes('/ai-visibility'),
        },
        {
            label: 'Goals',
            href: goalsIndex(currentWebsite.value.id).url,
            active: page.url.includes('/goals'),
        },
    ];
});
</script>

<template>
    <div>
        <div class="border-b border-sidebar-border/80">
            <div
                class="relative mx-auto flex h-14 w-full items-center gap-2 px-3 md:max-w-[1500px] md:px-6"
            >
                <Link
                    :href="dashboard()"
                    aria-label="Peekchimp dashboard"
                    class="flex size-7 shrink-0 items-center justify-center rounded-md transition-colors hover:opacity-90 focus-visible:ring-3 focus-visible:ring-ring/40 focus-visible:outline-none"
                >
                    <span class="flex size-7 items-center justify-center">
                        <AppLogoIcon class="size-6 fill-current" />
                    </span>
                </Link>

                <div
                    class="mx-1 h-6 w-px bg-sidebar-border/80"
                    aria-hidden="true"
                />

                <WebsiteSwitcher />

                <nav
                    v-if="navigationItems.length"
                    class="absolute left-1/2 hidden -translate-x-1/2 items-center gap-1 md:flex"
                    aria-label="Website analytics"
                >
                    <Link
                        v-for="item in navigationItems"
                        :key="item.label"
                        :href="item.href"
                        class="rounded-full px-4 py-2 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                        :class="
                            item.active
                                ? 'bg-primary/10 text-primary'
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                        "
                    >
                        {{ item.label }}
                    </Link>
                </nav>

                <div class="ml-auto flex items-center gap-1">
                    <AppearanceMenu />

                    <DropdownMenu>
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="relative size-8 rounded-full p-0.5 focus-within:ring-2 focus-within:ring-primary"
                            >
                                <Avatar
                                    class="size-7 overflow-hidden rounded-full"
                                >
                                    <AvatarImage
                                        v-if="auth.user.avatar"
                                        :src="auth.user.avatar"
                                        :alt="auth.user.name"
                                    />
                                    <AvatarFallback
                                        class="rounded-md bg-neutral-200 text-xs font-medium text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ getInitials(auth.user?.name) }}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <UserMenuContent :user="auth.user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>

        <div
            v-if="props.breadcrumbs.length > 1"
            class="flex w-full border-b border-sidebar-border/70"
        >
            <div
                class="mx-auto flex h-9 w-full items-center justify-start px-3 text-neutral-500 md:max-w-7xl md:px-4"
            >
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </div>
        </div>
    </div>
</template>
