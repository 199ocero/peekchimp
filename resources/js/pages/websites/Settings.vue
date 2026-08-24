<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    Clipboard,
    Code2,
    ExternalLink,
    Globe2,
    KeyRound,
    RotateCcw,
    ShieldCheck,
} from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { update as updateWebsiteSettings } from '@/routes/websites/settings';
import {
    rotate as rotateWebsiteSharing,
    update as updateWebsiteSharing,
} from '@/routes/websites/sharing';

type PublicSection =
    'metrics' | 'traffic' | 'pages' | 'acquisition' | 'audience';

const props = defineProps<{
    website: {
        id: number;
        name: string;
        timezone: string;
        domain: string | null;
        siteKey: string;
        isVerified: boolean;
    };
    timezones: string[];
    trackerUrl: string;
    publicSharing: {
        enabled: boolean;
        url: string | null;
        sections: PublicSection[];
    };
}>();

const generalForm = useForm({
    name: props.website.name,
    timezone: props.website.timezone,
});
const sharingForm = useForm({
    enabled: props.publicSharing.enabled,
    sections: [...props.publicSharing.sections] as PublicSection[],
});
const { copy: copyText, copied } = useClipboard({ copiedDuring: 1600 });

const installationSnippet = computed(
    () =>
        `<script defer data-site="${props.website.siteKey}" src="${props.trackerUrl}"><\/script>`,
);
const sectionOptions: Array<{
    value: PublicSection;
    label: string;
    description: string;
}> = [
    {
        value: 'metrics',
        label: 'Key metrics',
        description: 'Visitors, views, bounce rate, and visit quality.',
    },
    {
        value: 'traffic',
        label: 'Traffic chart',
        description: 'Visitors and views over time.',
    },
    {
        value: 'pages',
        label: 'Most visited pages',
        description: 'The pages receiving the most views.',
    },
    {
        value: 'acquisition',
        label: 'Acquisition',
        description: 'Sources, campaigns, and AI referrals.',
    },
    {
        value: 'audience',
        label: 'Audience',
        description: 'Anonymous countries, devices, and browsers.',
    },
];

function saveGeneral(): void {
    generalForm.submit(updateWebsiteSettings(props.website.id), {
        preserveScroll: true,
    });
}

function saveSharing(): void {
    sharingForm.submit(updateWebsiteSharing(props.website.id), {
        preserveScroll: true,
    });
}

function toggleSection(section: PublicSection, checked: boolean): void {
    if (checked && !sharingForm.sections.includes(section)) {
        sharingForm.sections.push(section);
    }

    if (!checked) {
        sharingForm.sections = sharingForm.sections.filter(
            (selected) => selected !== section,
        );
    }
}

function rotateLink(): void {
    if (
        !window.confirm(
            'Rotate this link? The current public URL will stop working immediately.',
        )
    ) {
        return;
    }

    router.post(rotateWebsiteSharing(props.website.id).url, {
        preserveScroll: true,
    });
}

async function copy(value: string): Promise<void> {
    await copyText(value);
}
</script>

<template>
    <Head :title="`${website.name} settings`" />

    <div
        class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 pt-6 pb-20 sm:px-6 sm:pt-8"
    >
        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div class="min-w-0">
                <p
                    class="text-xs font-medium tracking-[0.14em] text-muted-foreground uppercase"
                >
                    Website settings
                </p>
                <h1 class="mt-2 truncate text-2xl font-medium tracking-tight">
                    {{ website.name }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Manage this website’s identity, installation, and public
                    dashboard.
                </p>
            </div>
            <Button as-child variant="outline">
                <Link :href="dashboard()">Back to dashboard</Link>
            </Button>
        </header>

        <div class="grid gap-6 lg:grid-cols-[12rem_minmax(0,1fr)]">
            <nav
                class="flex gap-1 overflow-x-auto lg:flex-col"
                aria-label="Website settings sections"
            >
                <a
                    v-for="item in [
                        ['general', 'General'],
                        ['installation', 'Installation'],
                        ['sharing', 'Public sharing'],
                    ]"
                    :key="item[0]"
                    :href="`#${item[0]}`"
                    class="shrink-0 rounded-md px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    {{ item[1] }}
                </a>
            </nav>

            <main class="flex min-w-0 flex-col gap-6">
                <section id="general" class="scroll-mt-6">
                    <Card class="gap-0 p-5 sm:p-6">
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-muted-foreground"
                                aria-hidden="true"
                            >
                                <Globe2 class="size-4" />
                            </span>
                            <div>
                                <h2 class="font-medium">General</h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Choose how this website appears in your
                                    account and reports.
                                </p>
                            </div>
                        </div>

                        <form
                            class="mt-6 flex flex-col gap-5"
                            @submit.prevent="saveGeneral"
                        >
                            <div class="grid gap-2">
                                <Label for="website-name">Website name</Label>
                                <Input
                                    id="website-name"
                                    v-model="generalForm.name"
                                    autocomplete="organization"
                                    :aria-invalid="!!generalForm.errors.name"
                                />
                                <InputError
                                    :message="generalForm.errors.name"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="website-timezone"
                                    >Report timezone</Label
                                >
                                <select
                                    id="website-timezone"
                                    v-model="generalForm.timezone"
                                    class="select-with-chevron h-9 w-full rounded-md border border-input bg-transparent text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                >
                                    <option
                                        v-for="timezone in timezones"
                                        :key="timezone"
                                        :value="timezone"
                                    >
                                        {{ timezone }}
                                    </option>
                                </select>
                                <InputError
                                    :message="generalForm.errors.timezone"
                                />
                            </div>

                            <div
                                class="flex flex-col gap-1 rounded-xl border border-border bg-muted/20 p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                            >
                                <span class="text-muted-foreground"
                                    >Connected domain</span
                                >
                                <span class="font-mono text-xs text-foreground">
                                    {{ website.domain ?? 'Not set' }}
                                </span>
                            </div>

                            <div class="flex justify-end">
                                <Button
                                    type="submit"
                                    :disabled="generalForm.processing"
                                >
                                    {{
                                        generalForm.processing
                                            ? 'Saving…'
                                            : 'Save changes'
                                    }}
                                </Button>
                            </div>
                        </form>
                    </Card>
                </section>

                <section id="installation" class="scroll-mt-6">
                    <Card class="gap-0 p-5 sm:p-6">
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-muted-foreground"
                                aria-hidden="true"
                            >
                                <Code2 class="size-4" />
                            </span>
                            <div>
                                <h2 class="font-medium">Installation</h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Keep the tracker installed so reports keep
                                    receiving page views.
                                </p>
                            </div>
                        </div>

                        <div
                            class="mt-6 flex items-center gap-3 rounded-xl border border-border bg-muted/20 p-3"
                            role="status"
                        >
                            <ShieldCheck
                                class="size-4"
                                :class="
                                    website.isVerified
                                        ? 'text-primary'
                                        : 'text-warning'
                                "
                                aria-hidden="true"
                            />
                            <div class="min-w-0">
                                <p class="text-sm font-medium">
                                    {{
                                        website.isVerified
                                            ? 'Website verified'
                                            : 'Waiting for verification'
                                    }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{
                                        website.isVerified
                                            ? 'Peekchimp has received a page view from this domain.'
                                            : 'Open your website after installing the snippet below.'
                                    }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-2">
                            <Label for="site-key">Site key</Label>
                            <div class="flex gap-2">
                                <Input
                                    id="site-key"
                                    :model-value="website.siteKey"
                                    readonly
                                    class="font-mono text-xs"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label="Copy site key"
                                    @click="copy(website.siteKey)"
                                >
                                    <Check
                                        v-if="copied"
                                        class="size-4 text-primary"
                                    />
                                    <KeyRound v-else class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-2">
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <Label for="installation-snippet"
                                    >Tracking snippet</Label
                                >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="copy(installationSnippet)"
                                >
                                    <Check
                                        v-if="copied"
                                        class="size-3.5 text-primary"
                                    />
                                    <Clipboard v-else class="size-3.5" />
                                    {{ copied ? 'Copied' : 'Copy snippet' }}
                                </Button>
                            </div>
                            <pre
                                id="installation-snippet"
                                class="overflow-x-auto rounded-xl border border-border bg-muted/30 p-3 font-mono text-xs leading-6 break-all whitespace-pre-wrap"
                            ><code>{{ installationSnippet }}</code></pre>
                            <p class="text-xs text-muted-foreground">
                                Place this line before the closing
                                <code class="font-mono">&lt;/head&gt;</code> tag
                                on {{ website.domain ?? 'your website' }}.
                            </p>
                        </div>
                    </Card>
                </section>

                <section id="sharing" class="scroll-mt-6">
                    <Card class="gap-0 p-5 sm:p-6">
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-muted-foreground"
                                aria-hidden="true"
                            >
                                <ExternalLink class="size-4" />
                            </span>
                            <div>
                                <h2 class="font-medium">Public sharing</h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Share a read-only dashboard without giving
                                    anyone account access.
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="!website.isVerified"
                            class="mt-6 rounded-xl border border-border bg-muted/20 p-3 text-sm text-muted-foreground"
                            role="status"
                        >
                            Public sharing becomes available after this website
                            is verified.
                        </div>

                        <form
                            class="mt-6 flex flex-col gap-5"
                            @submit.prevent="saveSharing"
                        >
                            <label
                                class="flex items-start gap-3 rounded-xl border border-border bg-muted/20 p-3"
                                :class="!website.isVerified && 'opacity-60'"
                            >
                                <Checkbox
                                    :model-value="sharingForm.enabled"
                                    :disabled="
                                        !website.isVerified ||
                                        sharingForm.processing
                                    "
                                    class="mt-0.5"
                                    @update:model-value="
                                        (value) =>
                                            (sharingForm.enabled =
                                                value === true)
                                    "
                                />
                                <span>
                                    <span class="block text-sm font-medium"
                                        >Enable public dashboard</span
                                    >
                                    <span
                                        class="mt-1 block text-xs text-muted-foreground"
                                    >
                                        Anyone with the link can view the
                                        selected sections.
                                    </span>
                                </span>
                            </label>

                            <fieldset
                                :disabled="
                                    !website.isVerified ||
                                    sharingForm.processing
                                "
                            >
                                <legend class="text-sm font-medium">
                                    Visible sections
                                </legend>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Choose what the public link can show. At
                                    least one section is required.
                                </p>
                                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                    <label
                                        v-for="section in sectionOptions"
                                        :key="section.value"
                                        class="flex cursor-pointer items-start gap-3 rounded-xl border border-border p-3 transition-colors hover:bg-accent/50"
                                    >
                                        <Checkbox
                                            class="mt-0.5"
                                            :model-value="
                                                sharingForm.sections.includes(
                                                    section.value,
                                                )
                                            "
                                            @update:model-value="
                                                (value) =>
                                                    toggleSection(
                                                        section.value,
                                                        value === true,
                                                    )
                                            "
                                        />
                                        <span>
                                            <span
                                                class="block text-sm font-medium"
                                                >{{ section.label }}</span
                                            >
                                            <span
                                                class="mt-1 block text-xs text-muted-foreground"
                                                >{{ section.description }}</span
                                            >
                                        </span>
                                    </label>
                                </div>
                            </fieldset>
                            <InputError
                                :message="sharingForm.errors.sections"
                            />

                            <div v-if="publicSharing.url" class="grid gap-2">
                                <Label for="public-url"
                                    >Public dashboard link</Label
                                >
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <Input
                                        id="public-url"
                                        :model-value="publicSharing.url"
                                        readonly
                                        class="font-mono text-xs"
                                    />
                                    <div class="flex gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            class="flex-1 sm:flex-none"
                                            @click="copy(publicSharing.url)"
                                        >
                                            <Check
                                                v-if="copied"
                                                class="size-3.5 text-primary"
                                            />
                                            <Clipboard
                                                v-else
                                                class="size-3.5"
                                            />
                                            {{
                                                copied ? 'Copied' : 'Copy link'
                                            }}
                                        </Button>
                                        <Button
                                            as-child
                                            type="button"
                                            variant="outline"
                                        >
                                            <a
                                                :href="publicSharing.url"
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                <ExternalLink
                                                    class="size-3.5"
                                                />
                                                Open
                                            </a>
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center"
                            >
                                <Button
                                    v-if="publicSharing.url"
                                    type="button"
                                    variant="ghost"
                                    :disabled="
                                        !website.isVerified ||
                                        sharingForm.processing
                                    "
                                    @click="rotateLink"
                                >
                                    <RotateCcw class="size-3.5" />
                                    Rotate link
                                </Button>
                                <span v-else />
                                <Button
                                    type="submit"
                                    :disabled="
                                        !website.isVerified ||
                                        sharingForm.processing ||
                                        sharingForm.sections.length === 0
                                    "
                                >
                                    {{
                                        sharingForm.processing
                                            ? 'Saving…'
                                            : 'Save sharing settings'
                                    }}
                                </Button>
                            </div>
                        </form>
                    </Card>
                </section>
            </main>
        </div>
    </div>
</template>
