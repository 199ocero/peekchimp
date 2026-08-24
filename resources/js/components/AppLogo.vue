<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <div class="flex aspect-square size-8 items-center justify-center">
        <AppLogoIcon
            class="size-5 fill-current text-sidebar-primary-foreground"
        />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span
            class="mb-0.5 truncate leading-tight font-medium tracking-[-0.01em]"
            >{{ name }}</span
        >
    </div>
</template>
