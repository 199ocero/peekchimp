<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, logout } from '@/routes';

const page = usePage();
const canReturnToDashboard = computed(
    () => page.props.backToDashboard === true,
);
</script>

<template>
    <div class="min-h-svh bg-background">
        <header class="flex h-16 items-center justify-between px-4 sm:px-6">
            <div class="flex items-center gap-2.5">
                <Link
                    :href="dashboard()"
                    aria-label="Peekchimp dashboard"
                    class="flex size-9 items-center justify-center transition-opacity hover:opacity-90 focus-visible:ring-3 focus-visible:ring-ring/40 focus-visible:outline-none"
                >
                    <AppLogoIcon class-name="size-5" />
                </Link>
                <span class="text-sm font-medium tracking-[-0.02em]">
                    {{ page.props.name }}
                </span>
            </div>

            <div class="flex items-center gap-1">
                <Button v-if="canReturnToDashboard" as-child size="sm">
                    <Link :href="dashboard()">Back to dashboard</Link>
                </Button>
                <Button as-child variant="ghost" size="sm">
                    <Link :href="logout()" as="button">Log out</Link>
                </Button>
            </div>
        </header>

        <main
            class="mx-auto flex w-full max-w-xl flex-col px-4 pt-8 pb-16 sm:px-6 sm:pt-14"
        >
            <slot />
        </main>
    </div>
</template>
