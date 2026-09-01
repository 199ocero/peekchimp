<script setup lang="ts">
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/vue3';
import {
    Check,
    Clipboard,
    Code2,
    ExternalLink,
    Globe2,
    KeyRound,
    RefreshCw,
    RotateCcw,
    Search,
    ShieldCheck,
    Target,
    Unplug,
} from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { store as queueWebsiteCrawl } from '@/routes/websites/crawl';
import {
    connect as connectSearchConsole,
    destroy as destroySearchConsole,
    store as storeSearchConsole,
    sync as syncSearchConsole,
} from '@/routes/websites/search-console';
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
        autocaptureEnabled: boolean;
        growthContext: {
            audience: string;
            products_services: string;
            value_proposition: string;
            brand_voice: string;
            primary_conversion_goals: string[];
        };
    };
    timezones: string[];
    trackerUrl: string;
    websiteCrawl: {
        status: string;
        lastCrawledAt: string | null;
        pageCount: number;
        error: string | null;
    };
    publicSharing: {
        enabled: boolean;
        url: string | null;
        sections: PublicSection[];
    };
    searchConsole: {
        connection: {
            propertySiteUrl: string;
            propertyType: string;
            permissionLevel: string;
            status: string;
            dataThrough: string | null;
            lastSyncedAt: string | null;
            lastError: string | null;
        } | null;
        candidates: Array<{
            siteUrl: string;
            propertyType: string;
            permissionLevel: string;
        }>;
        canManage: boolean;
    };
}>();

const generalForm = useForm({
    name: props.website.name,
    timezone: props.website.timezone,
    autocapture_enabled: props.website.autocaptureEnabled,
});
const contextForm = useForm({
    name: props.website.name,
    timezone: props.website.timezone,
    growth_context: {
        audience: props.website.growthContext.audience,
        products_services: props.website.growthContext.products_services,
        value_proposition: props.website.growthContext.value_proposition,
        brand_voice: props.website.growthContext.brand_voice,
        primary_conversion_goals: [
            ...props.website.growthContext.primary_conversion_goals,
        ],
    },
});
const conversionGoals = ref(
    props.website.growthContext.primary_conversion_goals.join('\n'),
);
const sharingForm = useForm({
    enabled: props.publicSharing.enabled,
    sections: [...props.publicSharing.sections] as PublicSection[],
});
const propertyForm = useForm({
    site_url: props.searchConsole.candidates[0]?.siteUrl ?? '',
});
const page = usePage();
const { start: startCrawlPolling, stop: stopCrawlPolling } = usePoll(
    3000,
    { only: ['websiteCrawl'] },
    { autoStart: false },
);
const isCrawlInProgress = computed(() =>
    ['queued', 'running'].includes(props.websiteCrawl.status),
);
const searchConsoleError = computed(() =>
    typeof page.props.errors?.search_console === 'string'
        ? page.props.errors.search_console
        : '',
);
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

function saveGrowthContext(): void {
    contextForm
        .transform((data) => ({
            ...data,
            growth_context: {
                ...data.growth_context,
                primary_conversion_goals: conversionGoals.value
                    .split('\n')
                    .map((goal) => goal.trim())
                    .filter(Boolean),
            },
        }))
        .submit(updateWebsiteSettings(props.website.id), {
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

function selectSearchConsoleProperty(): void {
    propertyForm.submit(storeSearchConsole(props.website.id), {
        preserveScroll: true,
    });
}

function syncGoogleSearchConsole(): void {
    router.post(
        syncSearchConsole(props.website.id).url,
        {},
        {
            preserveScroll: true,
        },
    );
}

function runWebsiteCrawl(): void {
    router.post(
        queueWebsiteCrawl(props.website.id).url,
        {},
        { preserveScroll: true },
    );
}

function disconnectGoogleSearchConsole(): void {
    if (
        !window.confirm(
            'Disconnect Google Search Console and delete all imported search data?',
        )
    ) {
        return;
    }

    router.delete(destroySearchConsole(props.website.id).url, {
        preserveScroll: true,
    });
}

function formatTimestamp(
    value: string | null,
    fallback = 'Not synced yet',
): string {
    if (!value) {
        return fallback;
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

watch(
    isCrawlInProgress,
    (inProgress) => {
        if (inProgress) {
            startCrawlPolling();
        } else {
            stopCrawlPolling();
        }
    },
    { immediate: true },
);

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
                        ['growth-context', 'Growth context'],
                        ['installation', 'Installation'],
                        ['website-crawl', 'Website crawl'],
                        ['search-console', 'Search Console'],
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

                            <div
                                class="rounded-xl border border-primary/25 bg-primary/5 p-4"
                            >
                                <div class="flex items-start gap-3">
                                    <span
                                        class="flex size-8 shrink-0 items-center justify-center rounded-md border border-primary/20 bg-primary/10 text-primary"
                                        aria-hidden="true"
                                    >
                                        <ShieldCheck class="size-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <h3 class="text-sm font-medium">
                                                Behavioral signals
                                            </h3>
                                            <span
                                                class="rounded-full border border-primary/20 bg-primary/10 px-2 py-0.5 text-[10px] font-medium tracking-wide text-primary uppercase"
                                            >
                                                {{
                                                    generalForm.autocapture_enabled
                                                        ? 'Enabled'
                                                        : 'Disabled'
                                                }}
                                            </span>
                                        </div>
                                        <p
                                            class="mt-1 text-xs leading-5 text-muted-foreground"
                                        >
                                            Give Peekchimp enough evidence to
                                            explain where visitors get stuck,
                                            without recording what they type.
                                        </p>
                                    </div>
                                </div>

                                <label
                                    class="mt-4 flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-card/60 p-3 transition-colors hover:bg-card"
                                >
                                    <Checkbox
                                        id="autocapture-enabled"
                                        aria-describedby="autocapture-description"
                                        :model-value="
                                            generalForm.autocapture_enabled
                                        "
                                        :disabled="generalForm.processing"
                                        class="mt-0.5"
                                        @update:model-value="
                                            (value) =>
                                                (generalForm.autocapture_enabled =
                                                    value === true)
                                        "
                                    />
                                    <span>
                                        <span class="block text-sm font-medium"
                                            >Collect privacy-safe behavior
                                            signals</span
                                        >
                                        <span
                                            id="autocapture-description"
                                            class="mt-1 block text-xs leading-5 text-muted-foreground"
                                        >
                                            Clicks, form submits, LCP, and
                                            failed requests are summarized by
                                            page and session. Field values,
                                            request bodies, messages, and stacks
                                            are never collected.
                                        </span>
                                    </span>
                                </label>
                                <InputError
                                    :message="
                                        generalForm.errors.autocapture_enabled
                                    "
                                />
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

                <section id="growth-context" class="scroll-mt-6">
                    <Card class="gap-0 p-5 sm:p-6">
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-muted-foreground"
                                aria-hidden="true"
                            >
                                <Target class="size-4" />
                            </span>
                            <div>
                                <h2 class="font-medium">Growth context</h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Give the read-only consultant enough context
                                    to make specific recommendations in your
                                    voice.
                                </p>
                            </div>
                        </div>

                        <form
                            class="mt-6 grid gap-5"
                            @submit.prevent="saveGrowthContext"
                        >
                            <div class="grid gap-2">
                                <Label for="growth-audience"
                                    >Target audience</Label
                                >
                                <textarea
                                    id="growth-audience"
                                    v-model="
                                        contextForm.growth_context.audience
                                    "
                                    rows="3"
                                    maxlength="2000"
                                    placeholder="Who you serve, what they care about, and what brings them to the site."
                                    class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                />
                                <InputError
                                    :message="
                                        contextForm.errors[
                                            'growth_context.audience'
                                        ]
                                    "
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="growth-products"
                                    >Products and services</Label
                                >
                                <textarea
                                    id="growth-products"
                                    v-model="
                                        contextForm.growth_context
                                            .products_services
                                    "
                                    rows="4"
                                    maxlength="3000"
                                    placeholder="Describe your offers, pricing model, and important differentiators."
                                    class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                />
                                <InputError
                                    :message="
                                        contextForm.errors[
                                            'growth_context.products_services'
                                        ]
                                    "
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="growth-value-proposition"
                                    >Value proposition</Label
                                >
                                <textarea
                                    id="growth-value-proposition"
                                    v-model="
                                        contextForm.growth_context
                                            .value_proposition
                                    "
                                    rows="3"
                                    maxlength="2000"
                                    placeholder="Why a visitor should choose you and what outcome you promise."
                                    class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                />
                                <InputError
                                    :message="
                                        contextForm.errors[
                                            'growth_context.value_proposition'
                                        ]
                                    "
                                />
                            </div>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="growth-brand-voice"
                                        >Brand voice</Label
                                    >
                                    <textarea
                                        id="growth-brand-voice"
                                        v-model="
                                            contextForm.growth_context
                                                .brand_voice
                                        "
                                        rows="5"
                                        maxlength="1000"
                                        placeholder="For example: direct, warm, practical, and never hype-driven."
                                        class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    />
                                    <InputError
                                        :message="
                                            contextForm.errors[
                                                'growth_context.brand_voice'
                                            ]
                                        "
                                    />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="growth-conversion-goals"
                                        >Primary conversion goals</Label
                                    >
                                    <textarea
                                        id="growth-conversion-goals"
                                        v-model="conversionGoals"
                                        rows="5"
                                        placeholder="Start a free trial\nBook a demo\nJoin the newsletter"
                                        class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        Add one goal per line, up to 10.
                                    </p>
                                    <InputError
                                        :message="
                                            contextForm.errors[
                                                'growth_context.primary_conversion_goals'
                                            ]
                                        "
                                    />
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <Button
                                    type="submit"
                                    :disabled="contextForm.processing"
                                >
                                    {{
                                        contextForm.processing
                                            ? 'Saving…'
                                            : 'Save growth context'
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

                <section id="website-crawl" class="scroll-mt-6">
                    <Card class="gap-0 p-5 sm:p-6">
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-muted-foreground"
                                aria-hidden="true"
                            >
                                <RefreshCw
                                    class="size-4"
                                    :class="isCrawlInProgress && 'animate-spin'"
                                />
                            </span>
                            <div>
                                <h2 class="font-medium">Website crawl</h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Peekchimp captures public page content and
                                    technical signals for its growth guidance.
                                </p>
                            </div>
                        </div>

                        <div
                            class="mt-6 flex flex-col gap-4 rounded-xl border border-border bg-muted/20 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-medium capitalize">
                                        {{
                                            websiteCrawl.status.replace(
                                                '_',
                                                ' ',
                                            )
                                        }}
                                    </p>
                                    <span
                                        class="rounded-full border border-border bg-card px-2 py-0.5 text-[10px] font-medium"
                                    >
                                        {{ websiteCrawl.pageCount }}
                                        {{
                                            websiteCrawl.pageCount === 1
                                                ? 'page'
                                                : 'pages'
                                        }}
                                    </span>
                                </div>
                                <p class="mt-2 text-xs text-muted-foreground">
                                    Last captured
                                    {{
                                        formatTimestamp(
                                            websiteCrawl.lastCrawledAt,
                                            'Not crawled yet',
                                        )
                                    }}
                                </p>
                                <p
                                    v-if="websiteCrawl.error"
                                    class="mt-2 text-xs text-destructive"
                                >
                                    {{ websiteCrawl.error }}
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="isCrawlInProgress"
                                @click="runWebsiteCrawl"
                            >
                                <RefreshCw
                                    class="size-3.5"
                                    :class="isCrawlInProgress && 'animate-spin'"
                                />
                                {{
                                    isCrawlInProgress
                                        ? 'Crawl in progress'
                                        : 'Run crawl'
                                }}
                            </Button>
                        </div>
                    </Card>
                </section>

                <section id="search-console" class="scroll-mt-6">
                    <Card class="gap-0 p-5 sm:p-6">
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-muted-foreground"
                                aria-hidden="true"
                            >
                                <Search class="size-4" />
                            </span>
                            <div>
                                <h2 class="font-medium">
                                    Google Search Console
                                </h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Add organic search clicks, impressions,
                                    rankings, queries, and landing pages to your
                                    analytics.
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="searchConsoleError"
                            class="mt-5 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                            role="alert"
                        >
                            {{ searchConsoleError }}
                        </div>

                        <form
                            v-if="searchConsole.candidates.length > 1"
                            class="mt-6 rounded-xl border border-border bg-muted/20 p-4"
                            @submit.prevent="selectSearchConsoleProperty"
                        >
                            <Label for="search-console-property">
                                Choose a matching property
                            </Label>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Each option exactly matches
                                {{ website.domain }}. Pick the property you use
                                for reporting.
                            </p>
                            <select
                                id="search-console-property"
                                v-model="propertyForm.site_url"
                                class="select-with-chevron mt-3 h-9 w-full rounded-md border border-input bg-background text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                            >
                                <option
                                    v-for="candidate in searchConsole.candidates"
                                    :key="candidate.siteUrl"
                                    :value="candidate.siteUrl"
                                >
                                    {{ candidate.siteUrl }} ·
                                    {{ candidate.permissionLevel }}
                                </option>
                            </select>
                            <InputError
                                class="mt-2"
                                :message="propertyForm.errors.site_url"
                            />
                            <div class="mt-4 flex justify-end">
                                <Button
                                    type="submit"
                                    :disabled="propertyForm.processing"
                                >
                                    {{
                                        propertyForm.processing
                                            ? 'Connecting…'
                                            : 'Use this property'
                                    }}
                                </Button>
                            </div>
                        </form>

                        <div
                            v-else-if="searchConsole.connection"
                            class="mt-6 space-y-4"
                        >
                            <div
                                class="rounded-xl border border-border bg-muted/20 p-4"
                            >
                                <div
                                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                                >
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <p
                                                class="truncate font-mono text-xs font-medium"
                                            >
                                                {{
                                                    searchConsole.connection
                                                        .propertySiteUrl
                                                }}
                                            </p>
                                            <span
                                                class="rounded-full border border-border bg-card px-2 py-0.5 text-[10px] font-medium capitalize"
                                            >
                                                {{
                                                    searchConsole.connection.status.replace(
                                                        '_',
                                                        ' ',
                                                    )
                                                }}
                                            </span>
                                        </div>
                                        <p
                                            class="mt-2 text-xs text-muted-foreground"
                                        >
                                            Data through
                                            {{
                                                searchConsole.connection
                                                    .dataThrough ??
                                                'the first completed import'
                                            }}
                                            · Last sync
                                            {{
                                                formatTimestamp(
                                                    searchConsole.connection
                                                        .lastSyncedAt,
                                                )
                                            }}
                                        </p>
                                    </div>
                                    <ShieldCheck
                                        class="size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                    />
                                </div>
                                <p
                                    v-if="searchConsole.connection.lastError"
                                    class="mt-3 text-xs text-destructive"
                                >
                                    {{ searchConsole.connection.lastError }}
                                </p>
                            </div>

                            <div
                                v-if="searchConsole.canManage"
                                class="flex flex-wrap justify-end gap-2"
                            >
                                <Button
                                    v-if="
                                        searchConsole.connection.status ===
                                        'reconnect_required'
                                    "
                                    as-child
                                >
                                    <Link
                                        :href="connectSearchConsole(website.id)"
                                    >
                                        Reconnect Google
                                    </Link>
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="
                                        searchConsole.connection.status ===
                                        'syncing'
                                    "
                                    @click="syncGoogleSearchConsole"
                                >
                                    <RefreshCw
                                        :class="[
                                            'size-3.5',
                                            searchConsole.connection.status ===
                                                'syncing' && 'animate-spin',
                                        ]"
                                    />
                                    Sync now
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    class="text-destructive hover:text-destructive"
                                    @click="disconnectGoogleSearchConsole"
                                >
                                    <Unplug class="size-3.5" />
                                    Disconnect
                                </Button>
                            </div>
                        </div>

                        <div v-else class="mt-6">
                            <p class="text-sm text-muted-foreground">
                                Peekchimp only accepts properties whose host
                                exactly matches the verified domain above.
                                Google data usually trails live traffic by a few
                                days.
                            </p>
                            <Button
                                v-if="searchConsole.canManage"
                                as-child
                                class="mt-4"
                            >
                                <Link :href="connectSearchConsole(website.id)">
                                    <Search class="size-3.5" />
                                    Connect Google Search Console
                                </Link>
                            </Button>
                            <p
                                v-else
                                class="mt-3 text-xs text-muted-foreground"
                            >
                                A workspace admin can connect this integration.
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
