<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Monitor, Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import WebsiteSwitcher from '@/components/WebsiteSwitcher.vue';
import { useAppearance } from '@/composables/useAppearance';
import { getInitials } from '@/composables/useInitials';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const auth = computed(() => page.props.auth);
const { appearance, updateAppearance } = useAppearance();

const appearanceOptions = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;
</script>

<template>
    <div>
        <div class="border-b border-sidebar-border/80">
            <div
                class="mx-auto flex h-12 w-full items-center gap-2 px-3 md:max-w-7xl md:px-4"
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

                <div class="ml-auto flex items-center gap-1">
                    <DropdownMenu>
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-8 cursor-pointer"
                            >
                                <Sun
                                    v-if="appearance === 'light'"
                                    class="size-4"
                                />
                                <Moon
                                    v-else-if="appearance === 'dark'"
                                    class="size-4"
                                />
                                <Monitor v-else class="size-4" />
                                <span class="sr-only">Change appearance</span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-36">
                            <DropdownMenuRadioGroup :model-value="appearance">
                                <DropdownMenuRadioItem
                                    v-for="option in appearanceOptions"
                                    :key="option.value"
                                    :value="option.value"
                                    class="cursor-pointer"
                                    @select="updateAppearance(option.value)"
                                >
                                    <component
                                        :is="option.Icon"
                                        class="size-4"
                                    />
                                    {{ option.label }}
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>

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
