<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

defineProps<{
    email: string;
    acceptUrl: string;
}>();

defineOptions({
    layout: {
        title: 'Join your workspace',
        description: 'Create your account to accept the invitation',
    },
});
</script>

<template>
    <Head title="Join your workspace" />

    <Form
        :action="acceptUrl"
        method="post"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Invited email address</Label>
                <Input id="email" :model-value="email" type="email" disabled />
            </div>

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="Confirm password"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button type="submit" class="mt-2 w-full" :disabled="processing">
                <Spinner v-if="processing" />
                Join workspace
            </Button>
        </div>
    </Form>
</template>
