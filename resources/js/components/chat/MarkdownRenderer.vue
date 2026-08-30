<script setup lang="ts">
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed } from 'vue';

const props = defineProps<{
    content: string;
}>();

const html = computed(() => {
    const rendered = marked.parse(props.content, {
        breaks: true,
        gfm: true,
    }) as string;
    const sanitized = DOMPurify.sanitize(rendered, {
        USE_PROFILES: { html: true },
    });

    return sanitized.replace(
        /<a /g,
        '<a target="_blank" rel="noopener noreferrer" ',
    );
});
</script>

<template>
    <div class="chat-markdown text-sm leading-7" v-html="html" />
</template>

<style scoped>
.chat-markdown {
    min-width: 0;
    overflow-wrap: anywhere;
}

.chat-markdown :deep(> :first-child) {
    margin-top: 0;
}

.chat-markdown :deep(> :last-child) {
    margin-bottom: 0;
}

.chat-markdown :deep(p) {
    margin-block: 0 0.9rem;
}

.chat-markdown :deep(h1),
.chat-markdown :deep(h2),
.chat-markdown :deep(h3),
.chat-markdown :deep(h4) {
    margin-block: 1.35rem 0.6rem;
    color: var(--foreground);
    font-weight: 600;
    letter-spacing: -0.015em;
    line-height: 1.35;
}

.chat-markdown :deep(h1) {
    font-size: 1.45rem;
}

.chat-markdown :deep(h2) {
    font-size: 1.25rem;
}

.chat-markdown :deep(h3) {
    font-size: 1.1rem;
}

.chat-markdown :deep(h4) {
    font-size: 1rem;
}

.chat-markdown :deep(ul),
.chat-markdown :deep(ol) {
    margin-block: 0.55rem 0.95rem;
    padding-left: 1.4rem;
}

.chat-markdown :deep(ul) {
    list-style: disc;
}

.chat-markdown :deep(ul ul) {
    list-style: circle;
}

.chat-markdown :deep(ol) {
    list-style: decimal;
}

.chat-markdown :deep(li) {
    margin-block: 0.25rem;
    padding-left: 0.2rem;
}

.chat-markdown :deep(li > p) {
    margin-bottom: 0.35rem;
}

.chat-markdown :deep(li:has(> input[type='checkbox'])) {
    list-style: none;
}

.chat-markdown :deep(input[type='checkbox']) {
    margin-right: 0.45rem;
    accent-color: var(--primary);
}

.chat-markdown :deep(strong) {
    color: var(--foreground);
    font-weight: 600;
}

.chat-markdown :deep(a) {
    color: var(--primary);
    font-weight: 500;
    text-decoration: underline;
    text-decoration-thickness: 1px;
    text-underline-offset: 3px;
}

.chat-markdown :deep(a:hover) {
    text-decoration-thickness: 2px;
}

.chat-markdown :deep(code) {
    border: 1px solid var(--border);
    border-radius: 0.35rem;
    background: var(--muted);
    padding: 0.12rem 0.32rem;
    font-family: var(--font-mono);
    font-size: 0.84em;
}

.chat-markdown :deep(pre) {
    margin-block: 0.85rem 1rem;
    max-width: 100%;
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: 0.75rem;
    background: var(--muted);
    padding: 0.95rem;
    line-height: 1.65;
}

.chat-markdown :deep(pre code) {
    border: 0;
    background: transparent;
    padding: 0;
    white-space: pre;
}

.chat-markdown :deep(blockquote) {
    margin-block: 0.85rem 1rem;
    border-left: 3px solid var(--primary);
    padding: 0.15rem 0 0.15rem 0.95rem;
    color: var(--muted-foreground);
}

.chat-markdown :deep(blockquote > :last-child) {
    margin-bottom: 0;
}

.chat-markdown :deep(hr) {
    margin-block: 1.25rem;
    border: 0;
    border-top: 1px solid var(--border);
}

.chat-markdown :deep(table) {
    display: block;
    width: 100%;
    max-width: 100%;
    margin-block: 0.85rem 1rem;
    overflow-x: auto;
    border-spacing: 0;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.chat-markdown :deep(th),
.chat-markdown :deep(td) {
    min-width: 7rem;
    border: 1px solid var(--border);
    padding: 0.5rem 0.65rem;
    text-align: left;
    vertical-align: top;
}

.chat-markdown :deep(th) {
    background: var(--muted);
    color: var(--foreground);
    font-weight: 600;
}

.chat-markdown :deep(img) {
    display: block;
    max-width: 100%;
    height: auto;
    margin-block: 0.85rem;
    border-radius: 0.75rem;
}
</style>
