<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, Link2, Trash2, UserPlus } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import MemberInvitationController from '@/actions/App/Http/Controllers/MemberInvitationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/members';

type Invitation = {
    id: number;
    email: string;
    expiresAt: string | null;
    url: string | null;
};

type Member = {
    id: number;
    name: string;
    email: string;
    createdAt: string;
};

defineProps<{
    members: Member[];
    invitations: Invitation[];
    status?: string;
    passwordResetLink?: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Members',
                href: edit(),
            },
        ],
    },
});

const { copy, copied } = useClipboard({ copiedDuring: 1600 });

function formatExpiry(expiresAt: string | null): string {
    if (expiresAt === null) {
        return 'Expired';
    }

    return `Expires ${new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(expiresAt))}`;
}

function formatJoined(createdAt: string): string {
    return `Joined ${new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
    }).format(new Date(createdAt))}`;
}

function confirmRemoval(event: SubmitEvent, name: string): void {
    if (
        !window.confirm(
            `Remove ${name} from this workspace? They will lose access immediately.`,
        )
    ) {
        event.preventDefault();
    }
}
</script>

<template>
    <Head title="Members" />

    <h1 class="sr-only">Members</h1>

    <div class="flex flex-col gap-8">
        <Heading
            variant="small"
            title="Members"
            description="Invite people to view and manage this workspace."
        />

        <p
            v-if="status"
            class="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success"
            role="status"
        >
            {{ status }}
        </p>

        <Card>
            <CardHeader>
                <CardTitle>Workspace members</CardTitle>
                <CardDescription>
                    Manage people who can access this workspace. Your admin
                    account is not shown here.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div
                    v-if="members.length === 0"
                    class="py-4 text-sm text-muted-foreground"
                >
                    No members yet. Create an invitation below to add someone.
                </div>

                <div v-else class="flex flex-col gap-3">
                    <div
                        v-for="member in members"
                        :key="member.id"
                        class="flex flex-col gap-4 rounded-md border border-border p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ member.name }}
                            </p>
                            <p class="truncate text-sm text-muted-foreground">
                                {{ member.email }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatJoined(member.createdAt) }}
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <Form
                                v-bind="
                                    MemberInvitationController.createPasswordResetLink.form(
                                        member.id,
                                    )
                                "
                                v-slot="{ processing }"
                            >
                                <Button
                                    type="submit"
                                    variant="secondary"
                                    size="sm"
                                    :disabled="processing"
                                >
                                    <KeyRound class="size-4" />
                                    Create reset link
                                </Button>
                            </Form>

                            <Form
                                v-bind="
                                    MemberInvitationController.destroyMember.form(
                                        member.id,
                                    )
                                "
                                v-slot="{ processing }"
                                @submit="confirmRemoval($event, member.name)"
                            >
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="processing"
                                    class="text-destructive hover:text-destructive"
                                >
                                    <Trash2 class="size-4" />
                                    Remove
                                </Button>
                            </Form>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card v-if="passwordResetLink">
            <CardHeader>
                <CardTitle>Password reset link</CardTitle>
                <CardDescription>
                    Send this link privately to the member. It expires using the
                    configured password-reset window and can be used once.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-3 sm:flex-row">
                <Input
                    :model-value="passwordResetLink"
                    readonly
                    aria-label="Password reset link"
                    class="min-w-0 flex-1"
                />
                <Button
                    type="button"
                    variant="secondary"
                    @click="copy(passwordResetLink)"
                >
                    <Check v-if="copied" class="size-4" />
                    <Copy v-else class="size-4" />
                    {{ copied ? 'Copied' : 'Copy link' }}
                </Button>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Invite a member</CardTitle>
                <CardDescription>
                    The link expires in seven days and can be copied to send
                    manually.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="MemberInvitationController.store.form()"
                    v-slot="{ errors, processing }"
                    class="flex flex-col gap-4 sm:flex-row sm:items-end"
                >
                    <div class="grid flex-1 gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            autocomplete="email"
                            placeholder="member@example.com"
                            required
                        />
                        <InputError :message="errors.email" />
                    </div>
                    <Button type="submit" :disabled="processing">
                        <UserPlus class="size-4" />
                        Create invitation
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Pending invitations</CardTitle>
                <CardDescription>
                    Each invitation can be used once by the invited email
                    address.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div
                    v-if="invitations.length === 0"
                    class="py-4 text-sm text-muted-foreground"
                >
                    No invitations yet. Create one above to give someone access.
                </div>

                <div v-else class="flex flex-col gap-3">
                    <div
                        v-for="invitation in invitations"
                        :key="invitation.id"
                        class="flex flex-col gap-3 rounded-md border border-border p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ invitation.email }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatExpiry(invitation.expiresAt) }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <Button
                                v-if="invitation.url"
                                type="button"
                                variant="secondary"
                                size="sm"
                                @click="copy(invitation.url)"
                            >
                                <Check v-if="copied" class="size-4" />
                                <Copy v-else class="size-4" />
                                {{ copied ? 'Copied' : 'Copy link' }}
                            </Button>
                            <span v-else class="text-xs text-muted-foreground"
                                >Expired</span
                            >

                            <Form
                                v-bind="
                                    MemberInvitationController.destroy.form(
                                        invitation.id,
                                    )
                                "
                                v-slot="{ processing }"
                            >
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="processing"
                                >
                                    <Link2 class="size-4" />
                                    Revoke
                                </Button>
                            </Form>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
