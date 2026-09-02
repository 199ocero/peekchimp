<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    destroy as destroyMapbox,
    edit as editMapbox,
    update as updateMapbox,
} from '@/routes/settings/mapbox';

defineProps<{ hasToken: boolean }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Map settings', href: editMapbox() }],
    },
});

const form = useForm({ mapbox_public_token: '' });

function save(): void {
    form.patch(updateMapbox().url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function remove(): void {
    if (window.confirm('Remove the Mapbox token from this workspace?')) {
        form.delete(destroyMapbox().url, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Map settings" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Map settings"
            description="Add a public Mapbox token for visitor maps across this workspace."
        />

        <form class="space-y-5" @submit.prevent="save">
            <div class="grid gap-2">
                <div class="flex items-center justify-between gap-3">
                    <Label for="mapbox-public-token">
                        {{ hasToken ? 'Replace public token' : 'Public token' }}
                    </Label>
                    <a
                        href="https://console.mapbox.com/account/access-tokens/"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    >
                        Create a Mapbox token
                    </a>
                </div>
                <Input
                    id="mapbox-public-token"
                    v-model="form.mapbox_public_token"
                    type="password"
                    autocomplete="off"
                    placeholder="pk.ey…"
                    required
                />
                <InputError :message="form.errors.mapbox_public_token" />
                <p class="text-sm leading-6 text-muted-foreground">
                    Use a public token beginning with <code>pk.</code>. For
                    safety, restrict it to your dashboard domains in Mapbox.
                    Secret <code>sk.</code> tokens are not accepted.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <Button :disabled="form.processing">Save token</Button>
                <Button
                    v-if="hasToken"
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="remove"
                >
                    Remove token
                </Button>
            </div>
        </form>
    </div>
</template>
