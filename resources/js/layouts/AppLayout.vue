<script setup lang="ts">
import AppLayout from '@/layouts/app/AppHeaderLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppLayout
        :breadcrumbs="breadcrumbs"
        class="sticky top-0 z-50 h-svh !min-h-0 overflow-hidden"
    >
        <slot />
    </AppLayout>
</template>
