<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <img
        src="/images/peekchimp.png"
        alt=""
        aria-hidden="true"
        decoding="async"
        :class="className"
        v-bind="$attrs"
    />
</template>
