<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();

const appearanceOptions = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger :as-child="true">
            <Button
                variant="ghost"
                size="icon"
                class="size-8 cursor-pointer rounded-full"
                aria-label="Change appearance"
            >
                <Sun v-if="appearance === 'light'" class="size-4" />
                <Moon v-else-if="appearance === 'dark'" class="size-4" />
                <Monitor v-else class="size-4" />
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
                    <component :is="option.Icon" class="size-4" />
                    {{ option.label }}
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
