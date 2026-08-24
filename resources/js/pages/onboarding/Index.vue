<script setup lang="ts">
import { Head, router, useForm, usePoll } from '@inertiajs/vue3';
import {
    Check,
    CheckCircle2,
    Clipboard,
    Code2,
    Globe2,
    LoaderCircle,
} from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import { dashboard } from '@/routes';
import { store as storeOnboardingWebsite } from '@/routes/onboarding/website';
import { current as selectWebsite } from '@/routes/websites';
import {
    store as storeWebsite,
    update as updateWebsite,
} from '@/routes/websites';

type Step = 'details' | 'install' | 'verify' | 'success';
type Direction = 1 | -1;

type Website = {
    id?: number;
    name: string;
    url: string;
    domain: string | null;
    timezone: string;
    siteKey: string;
};

const props = defineProps<{
    website: Website | null;
    timezones: string[];
    defaultTimezone: string;
    trackerUrl: string;
    isVerified: boolean;
    mode?: 'onboarding' | 'create' | 'update';
    backToDashboard?: boolean;
}>();

defineOptions({
    layout: OnboardingLayout,
});

const step = ref<Step>(
    props.isVerified ? 'success' : props.website ? 'install' : 'details',
);
const setupMode = computed(() => props.mode ?? 'onboarding');
const direction = ref<Direction>(1);
const redirectTimer = ref<number>();
const { copy: copyText, copied } = useClipboard({ copiedDuring: 1600 });

const form = useForm({
    name: props.website?.name ?? '',
    url: props.website?.url ?? '',
    timezone: props.website?.timezone ?? props.defaultTimezone,
});

const stepNumber = computed(() => {
    if (step.value === 'details') {
        return 1;
    }

    if (step.value === 'install') {
        return 2;
    }

    return 3;
});

const installationSnippet = computed(() => {
    if (!props.website) {
        return '';
    }

    return `<script defer data-site="${props.website.siteKey}" src="${props.trackerUrl}"><\/script>`;
});

const transitionClasses = computed(() => ({
    enterFrom:
        direction.value === 1
            ? 'translate-x-3 opacity-0'
            : '-translate-x-3 opacity-0',
    leaveTo:
        direction.value === 1
            ? '-translate-x-3 opacity-0'
            : 'translate-x-3 opacity-0',
}));

const { start: startPolling, stop: stopPolling } = usePoll(
    2000,
    { only: ['isVerified'] },
    { autoStart: false },
);

function goTo(nextStep: Step, nextDirection: Direction): void {
    direction.value = nextDirection;
    step.value = nextStep;

    if (nextStep === 'verify') {
        router.reload({ only: ['isVerified'] });
        startPolling();
    } else {
        stopPolling();
    }
}

function submitWebsite(): void {
    const destination =
        setupMode.value === 'onboarding'
            ? storeOnboardingWebsite()
            : setupMode.value === 'create'
              ? storeWebsite()
              : updateWebsite(props.website?.id ?? 0);

    form.submit(destination, {
        onSuccess: () => {
            direction.value = 1;
            step.value = 'install';
        },
    });
}

async function copySnippet(): Promise<void> {
    await copyText(installationSnippet.value);
}

function finishOnboarding(): void {
    stopPolling();
    direction.value = 1;
    step.value = 'success';

    if (redirectTimer.value !== undefined) {
        window.clearTimeout(redirectTimer.value);
    }

    redirectTimer.value = window.setTimeout(() => {
        if (
            setupMode.value === 'onboarding' ||
            props.website?.id === undefined
        ) {
            router.visit(dashboard());

            return;
        }

        router.visit(selectWebsite(props.website.id), { method: 'patch' });
    }, 800);
}

watch(
    () => props.isVerified,
    (isVerified) => {
        if (isVerified) {
            finishOnboarding();
        }
    },
);

onMounted(() => {
    if (!props.website) {
        const detectedTimezone =
            Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (props.timezones.includes(detectedTimezone)) {
            form.timezone = detectedTimezone;
        }
    }

    if (props.isVerified) {
        finishOnboarding();
    }
});

onBeforeUnmount(() => {
    if (redirectTimer.value !== undefined) {
        window.clearTimeout(redirectTimer.value);
    }
});
</script>

<template>
    <div class="flex flex-col gap-7">
        <Head
            :title="
                setupMode === 'create' ? 'Add a website' : 'Set up your website'
            "
        />

        <div class="space-y-3 text-center">
            <p
                class="text-xs font-medium tracking-[0.14em] text-muted-foreground uppercase"
            >
                Website setup
            </p>
            <h1 class="text-3xl font-medium tracking-[-0.045em] sm:text-4xl">
                Start collecting useful analytics
            </h1>
            <p class="mx-auto max-w-md text-sm leading-6 text-muted-foreground">
                Tell us where your website lives, install one small script, and
                we will confirm the first pageview.
            </p>
        </div>

        <Card class="overflow-hidden !border-border p-0">
            <ol
                class="mx-auto flex w-full items-center justify-center px-5 pt-5 sm:px-6 sm:pt-6"
                aria-label="Setup progress"
            >
                <li
                    v-for="(label, index) in ['Website', 'Install', 'Verify']"
                    :key="label"
                    class="flex shrink-0 items-center"
                    :class="
                        index + 1 <= stepNumber
                            ? 'text-foreground'
                            : 'text-muted-foreground'
                    "
                >
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-medium tabular-nums transition-colors"
                        :class="
                            index + 1 <= stepNumber
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-card'
                        "
                    >
                        <Check v-if="index + 1 < stepNumber" class="size-3.5" />
                        <span v-else>{{ index + 1 }}</span>
                    </span>
                    <span class="ml-2 hidden text-xs font-medium sm:inline">{{
                        label
                    }}</span>
                    <span
                        v-if="index < 2"
                        class="mx-3 h-px w-12 bg-border sm:w-16"
                        aria-hidden="true"
                    >
                        <span
                            class="block h-full bg-primary transition-[width] duration-150 motion-reduce:transition-none"
                            :class="index + 1 < stepNumber ? 'w-full' : 'w-0'"
                        />
                    </span>
                </li>
            </ol>

            <div class="relative min-h-[430px] overflow-hidden">
                <Transition
                    mode="out-in"
                    enter-active-class="transition-[transform,opacity] duration-[160ms] ease-out motion-reduce:transition-none"
                    leave-active-class="transition-[transform,opacity] duration-100 ease-in motion-reduce:transition-none"
                    :enter-from-class="transitionClasses.enterFrom"
                    enter-to-class="translate-x-0 opacity-100"
                    leave-from-class="translate-x-0 opacity-100"
                    :leave-to-class="transitionClasses.leaveTo"
                >
                    <form
                        v-if="step === 'details'"
                        key="details"
                        class="flex min-h-[430px] flex-col p-5 sm:p-6"
                        @submit.prevent="submitWebsite"
                    >
                        <div class="space-y-1.5">
                            <div
                                class="flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground"
                            >
                                <Globe2 class="size-5" />
                            </div>
                            <h2
                                class="pt-3 text-xl font-medium tracking-[-0.03em]"
                            >
                                Add your website
                            </h2>
                            <p class="text-sm leading-6 text-muted-foreground">
                                Use the exact URL visitors open, including www
                                when your site uses it.
                            </p>
                        </div>

                        <div class="grid gap-4 pt-6">
                            <div class="grid gap-2">
                                <Label for="website-name">Website name</Label>
                                <Input
                                    id="website-name"
                                    v-model="form.name"
                                    name="name"
                                    autocomplete="organization"
                                    placeholder="Acme"
                                    required
                                    autofocus
                                    :aria-invalid="
                                        form.errors.name ? 'true' : undefined
                                    "
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="website-url">Website URL</Label>
                                <Input
                                    id="website-url"
                                    v-model="form.url"
                                    name="url"
                                    type="url"
                                    inputmode="url"
                                    autocomplete="url"
                                    placeholder="https://example.com"
                                    required
                                    :aria-invalid="
                                        form.errors.url ? 'true' : undefined
                                    "
                                />
                                <InputError :message="form.errors.url" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="website-timezone"
                                    >Reporting timezone</Label
                                >
                                <select
                                    id="website-timezone"
                                    v-model="form.timezone"
                                    name="timezone"
                                    required
                                    class="select-with-chevron h-9 w-full rounded-md border border-input bg-transparent text-sm transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    :aria-invalid="
                                        form.errors.timezone
                                            ? 'true'
                                            : undefined
                                    "
                                >
                                    <option
                                        v-for="timezone in timezones"
                                        :key="timezone"
                                        :value="timezone"
                                    >
                                        {{ timezone.replaceAll('_', ' ') }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.timezone" />
                            </div>
                        </div>

                        <div class="mt-auto flex justify-end pt-7">
                            <Button type="submit" :disabled="form.processing">
                                <Spinner v-if="form.processing" />
                                Save and continue
                            </Button>
                        </div>
                    </form>

                    <section
                        v-else-if="step === 'install'"
                        key="install"
                        class="flex min-h-[430px] flex-col p-5 sm:p-6"
                    >
                        <div class="space-y-1.5">
                            <div
                                class="flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground"
                            >
                                <Code2 class="size-5" />
                            </div>
                            <h2
                                class="pt-3 text-xl font-medium tracking-[-0.03em]"
                            >
                                Install the tracking script
                            </h2>
                            <p class="text-sm leading-6 text-muted-foreground">
                                Paste this line before the closing &lt;/head&gt;
                                tag on {{ website?.domain }}.
                            </p>
                        </div>

                        <div
                            class="relative mt-6 rounded-lg border border-border bg-muted/45 p-3"
                        >
                            <pre
                                class="max-h-36 overflow-x-auto p-2 pr-24 font-mono text-xs leading-6 break-all whitespace-pre-wrap text-foreground"
                            ><code>{{ installationSnippet }}</code></pre>
                            <div
                                class="absolute top-2 right-2 flex items-center gap-1.5"
                            >
                                <span
                                    v-if="copied"
                                    class="animate-in text-xs font-medium text-primary duration-150 fade-in-0 motion-reduce:animate-none"
                                    role="status"
                                    aria-live="polite"
                                >
                                    Copied
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-muted-foreground hover:text-foreground"
                                    :aria-label="
                                        copied ? 'Copied' : 'Copy snippet'
                                    "
                                    @click="copySnippet"
                                >
                                    <Check
                                        v-if="copied"
                                        class="size-4 text-primary"
                                    />
                                    <Clipboard v-else class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div
                            class="mt-4 rounded-lg border border-border bg-card px-4 py-3 text-sm leading-6 text-muted-foreground"
                            role="status"
                        >
                            <span class="font-medium text-foreground"
                                >Publish the change.</span
                            >
                            Then open your website once so Peekchimp can receive
                            its first pageview.
                        </div>

                        <div
                            class="mt-auto flex flex-col-reverse justify-end gap-2 pt-7 sm:flex-row"
                        >
                            <Button
                                type="button"
                                variant="secondary"
                                @click="goTo('details', -1)"
                            >
                                Edit website
                            </Button>
                            <Button type="button" @click="goTo('verify', 1)">
                                Check installation
                            </Button>
                        </div>
                    </section>

                    <section
                        v-else-if="step === 'verify'"
                        key="verify"
                        class="flex min-h-[430px] flex-col items-center p-5 text-center sm:p-6"
                        role="status"
                    >
                        <div
                            class="mt-auto flex max-w-sm flex-col items-center"
                        >
                            <div
                                class="flex size-12 items-center justify-center rounded-full border border-primary/20 bg-primary/10 text-primary"
                                aria-hidden="true"
                            >
                                <LoaderCircle
                                    class="size-5 motion-safe:animate-spin"
                                />
                            </div>
                            <div
                                class="mt-5 inline-flex items-center gap-2 rounded-full border border-primary/15 bg-primary/5 px-3 py-1.5 text-xs font-medium text-primary"
                            >
                                <span class="relative flex size-1.5">
                                    <span
                                        class="absolute inset-0 rounded-full bg-primary/60 motion-safe:animate-ping"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="relative size-1.5 rounded-full bg-primary"
                                    />
                                </span>
                                Checking installation
                            </div>
                            <h2
                                class="pt-5 text-xl font-medium tracking-[-0.03em]"
                            >
                                Waiting for your first pageview
                            </h2>
                            <p
                                class="max-w-sm pt-2 text-sm leading-6 text-muted-foreground"
                            >
                                Open {{ website?.domain }} in another tab. We
                                will verify your site as soon as the tracking
                                script sends a pageview.
                            </p>
                        </div>

                        <div
                            class="mt-6 w-full max-w-sm rounded-xl border border-border bg-muted/30 p-3 text-left"
                        >
                            <div class="flex items-center gap-3 px-1 py-2">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                                    aria-hidden="true"
                                >
                                    <span class="text-xs font-medium">1</span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium">
                                        Open your website
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ website?.domain }}
                                    </p>
                                </div>
                                <span
                                    class="text-xs font-medium text-muted-foreground"
                                    >Next</span
                                >
                            </div>
                            <div class="mx-4 h-px bg-border" />
                            <div class="flex items-center gap-3 px-1 py-2">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-card text-primary"
                                    aria-hidden="true"
                                >
                                    <span class="text-xs font-medium">2</span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium">
                                        Receive pageview
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Listening for your tracking script
                                    </p>
                                </div>
                                <LoaderCircle
                                    class="size-4 text-primary motion-safe:animate-spin"
                                    aria-label="Checking for a pageview"
                                />
                            </div>
                        </div>
                        <Button
                            type="button"
                            variant="secondary"
                            class="mt-6"
                            @click="goTo('install', -1)"
                        >
                            Review installation
                        </Button>
                        <p class="mt-3 mb-auto text-xs text-muted-foreground">
                            This checks automatically every few seconds.
                        </p>
                    </section>

                    <section
                        v-else
                        key="success"
                        class="flex min-h-[430px] flex-col items-center justify-center p-5 text-center sm:p-6"
                        role="status"
                    >
                        <div
                            class="flex size-20 animate-[onboarding-success_320ms_cubic-bezier(0.5,1,0.89,1)] items-center justify-center rounded-full border border-success/20 bg-success/10 text-success motion-reduce:animate-none dark:text-primary-foreground"
                        >
                            <CheckCircle2 class="size-9" />
                        </div>
                        <h2 class="pt-6 text-xl font-medium tracking-[-0.03em]">
                            Your website is connected
                        </h2>
                        <p
                            class="max-w-sm pt-2 text-sm leading-6 text-muted-foreground"
                        >
                            The first pageview arrived. Opening your dashboard…
                        </p>
                    </section>
                </Transition>
            </div>
        </Card>

        <p class="text-center text-xs text-muted-foreground">
            Peekchimp is cookie-free and does not build personal profiles.
        </p>
    </div>
</template>
