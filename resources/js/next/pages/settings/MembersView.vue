<script setup lang="ts">
// MembersView — workspace member + invitation management for the "next" frontend.
//
// One settings page (no /settings shell — a standalone page) that lets the CURRENT
// workspace's OWNER:
//   • see the member roster (name / email / owner badge) and remove non-owners,
//   • invite people by free-text email,
//   • manage pending invitations (revoke / resend).
//
// Owner-gating: `can_manage_members` is NOT on the auth-context workspaces, so on
// mount we fetch the current workspace (`GET /workspaces/{id}` via the workspaces
// store, which mirrors it into auth) and read the flag. A non-owner sees a
// READ-ONLY roster + an explanatory banner — never an invite/remove control that
// would 403 on click.
//
// States: every list region covers loading (skeleton via Table) / error (+retry) /
// empty (EmptyState) / success. Toasts confirm writes; useConfirm guards destructive
// actions. All text is i18n; the owner Badge + status StatusBadge never rely on color
// alone.
import { computed, onMounted, ref } from 'vue';
import { useAuthStore } from '../../app/stores/auth';
import { useWorkspacesStore } from '../../app/stores/workspaces';
import { useMembersStore, type WorkspaceMember } from '../../app/stores/members';
import {
  useInvitationsStore,
  type WorkspaceInvitation,
} from '../../app/stores/invitations';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useI18n } from '../../app/i18n';
import Container from '../../ui/layout/Container.vue';
import Stack from '../../ui/layout/Stack.vue';
import Card from '../../ui/layout/Card.vue';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import Table, { type TableColumn } from '../../ui/data/Table.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Avatar from '../../ui/primitives/Avatar.vue';
import Alert from '../../ui/feedback/Alert.vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import type { StatusMap } from '../../ui/data/StatusBadge.vue';

const { t } = useI18n();
const auth = useAuthStore();
const workspaces = useWorkspacesStore();
const members = useMembersStore();
const invitations = useInvitationsStore();
const toast = useToast();
const confirm = useConfirm();

// --- Owner-gating ---------------------------------------------------------
// `can_manage_members` is only on the per-workspace resource, so resolve it by
// fetching the current workspace on mount (the store mirrors it into auth). Until
// it resolves we treat the page as read-only (no flicker of owner-only controls).
const gateLoading = ref(true);
const currentWorkspace = computed(() => auth.currentWorkspace);
const canManage = computed(() => currentWorkspace.value?.can_manage_members === true);
const workspaceName = computed(() => currentWorkspace.value?.name ?? '');

// --- Member removal in-flight tracking (per-row spinner) ------------------
const removingId = ref<string | number | null>(null);

// --- Invite form ----------------------------------------------------------
const inviteEmail = ref('');
const inviteError = ref('');

// --- Resend in-flight tracking --------------------------------------------
const resendingId = ref<string | number | null>(null);

// --- Table columns --------------------------------------------------------
const memberColumns: TableColumn<WorkspaceMember>[] = [
  { key: 'name', label: t('members.columns.name') },
  { key: 'email', label: t('members.columns.email') },
];

const invitationColumns: TableColumn<WorkspaceInvitation>[] = [
  { key: 'email', label: t('members.columns.email') },
  { key: 'status', label: t('invitations.columns.status') },
  { key: 'invited_by', label: t('invitations.columns.invitedBy') },
  { key: 'expires_at', label: t('invitations.columns.expires') },
];

// Map the backend invitation statuses onto StatusBadge descriptors (icon + label,
// never color-only). Pending = warning clock; expired = neutral; revoked = danger.
const invitationStatusMap = computed<StatusMap>(() => ({
  pending: { label: t('invitations.status.pending'), variant: 'warning', tone: 'subtle', icon: 'clock' },
  accepted: { label: t('invitations.status.accepted'), variant: 'success', tone: 'subtle', icon: 'check-circle' },
  revoked: { label: t('invitations.status.revoked'), variant: 'danger', tone: 'subtle', icon: 'x-circle' },
  expired: { label: t('invitations.status.expired'), variant: 'neutral', tone: 'subtle', icon: 'alert-triangle' },
}));

// --- Date formatting ------------------------------------------------------
const dateFormatter = new Intl.DateTimeFormat(undefined, {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
});
function formatDate(value: string | null): string {
  if (!value) return '—';
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? '—' : dateFormatter.format(parsed);
}

// --- Lifecycle ------------------------------------------------------------
onMounted(async () => {
  // Resolve the management flag for the current workspace, then load roster (+
  // invitations only when the viewer can manage them — the endpoint is owner-only).
  const id = auth.currentWorkspaceId;
  if (id != null) {
    try {
      await workspaces.fetchWorkspace(id);
    } catch {
      // Non-fatal: a failed flag-fetch leaves the page read-only (canManage=false).
    }
  }
  gateLoading.value = false;

  await members.fetch();
  if (canManage.value) {
    await invitations.fetch();
  }
});

// --- Actions --------------------------------------------------------------
async function onRemove(member: WorkspaceMember): Promise<void> {
  if (member.is_owner || removingId.value != null) return;
  const ok = await confirm({
    title: t('members.remove.confirmTitle'),
    message: t('members.remove.confirmBody', undefined, { name: member.name }),
    confirmLabel: t('members.remove.confirm'),
    cancelLabel: t('common.cancel', 'Cancel'),
    variant: 'danger',
  });
  if (!ok) return;

  removingId.value = member.id;
  try {
    await members.remove(member.id);
    toast.success(t('members.remove.success', undefined, { name: member.name }));
  } catch (err: unknown) {
    const kind = (err as { kind?: string })?.kind ?? 'generic';
    const key = kind === 'cannot_remove_owner' ? 'members.errors.cannot_remove_owner' : 'members.errors.removeGeneric';
    toast.danger(t(key));
  } finally {
    removingId.value = null;
  }
}

async function onInvite(): Promise<void> {
  if (invitations.inviting) return;
  inviteError.value = '';
  const email = inviteEmail.value.trim();
  if (!email) {
    inviteError.value = t('invitations.errors.emailRequired');
    return;
  }

  try {
    await invitations.invite(email);
    toast.success(t('invitations.invite.success', undefined, { email }));
    inviteEmail.value = '';
  } catch (err: unknown) {
    const kind = (err as { kind?: string })?.kind ?? 'generic';
    if (kind === 'already_member') inviteError.value = t('invitations.errors.already_member');
    else if (kind === 'already_invited') inviteError.value = t('invitations.errors.already_invited');
    else inviteError.value = t('invitations.errors.inviteGeneric');
  }
}

async function onRevoke(invitation: WorkspaceInvitation): Promise<void> {
  const ok = await confirm({
    title: t('invitations.revoke.confirmTitle'),
    message: t('invitations.revoke.confirmBody', undefined, { email: invitation.email }),
    confirmLabel: t('invitations.revoke.confirm'),
    cancelLabel: t('common.cancel', 'Cancel'),
    variant: 'danger',
  });
  if (!ok) return;

  try {
    await invitations.revoke(invitation.id);
    toast.success(t('invitations.revoke.success', undefined, { email: invitation.email }));
  } catch {
    toast.danger(t('invitations.errors.revokeGeneric'));
  }
}

async function onResend(invitation: WorkspaceInvitation): Promise<void> {
  if (resendingId.value != null) return;
  resendingId.value = invitation.id;
  try {
    await invitations.resend(invitation.id);
    toast.success(t('invitations.resend.success', undefined, { email: invitation.email }));
  } catch {
    toast.danger(t('invitations.errors.resendGeneric'));
  } finally {
    resendingId.value = null;
  }
}

// Empty / state helpers.
const membersEmpty = computed(
  () => !members.loading && !members.error && members.members.length === 0,
);
const invitationsEmpty = computed(
  () => !invitations.loading && !invitations.error && invitations.invitations.length === 0,
);
</script>

<template>
  <!-- flush: the app shell already provides the page gutter (and the main
       landmark) — a padded nested <main> would double both. -->
  <Container size="lg" flush>
    <Stack direction="vertical" gap="6">
      <PageHeader
        :title="t('members.title')"
        :description="workspaceName
          ? t('members.subtitleNamed', undefined, { workspace: workspaceName })
          : t('members.subtitle')"
        icon="users"
      />

      <!-- Read-only notice for non-owners (never a control that 403s on click). -->
      <Alert
        v-if="!gateLoading && !canManage"
        variant="info"
        :title="t('members.readOnly.title')"
      >
        {{ t('members.readOnly.body') }}
      </Alert>

      <!-- ===== Members roster ===== -->
      <Card>
        <template #header>
          <div class="flex min-w-0 flex-col gap-next-0_5">
            <h2 class="text-next-base font-next-semibold text-next-fg">
              {{ t('members.sectionTitle') }}
            </h2>
            <p class="text-next-sm text-next-muted-foreground">
              {{ t('members.sectionDescription') }}
            </p>
          </div>
        </template>

        <Table
          :columns="memberColumns"
          :rows="members.members"
          :loading="members.loading"
          :error="!!members.error"
          row-key="id"
          responsive="stack"
          :caption="t('members.tableCaption')"
        >
          <!-- Name cell: avatar + name + owner badge. -->
          <template #cell-name="{ row }">
            <div class="flex min-w-0 items-center gap-next-2">
              <Avatar :name="row.name" size="sm" />
              <span class="min-w-0 truncate font-next-medium text-next-fg">{{ row.name }}</span>
              <Badge v-if="row.is_owner" variant="primary" tone="subtle" size="sm" icon="star">
                {{ t('members.ownerBadge') }}
              </Badge>
            </div>
          </template>

          <template #cell-email="{ row }">
            <span class="min-w-0 truncate text-next-muted-foreground">{{ row.email }}</span>
          </template>

          <!-- Remove action: disabled on the owner; owner-gated for the rest. -->
          <template v-if="canManage" #row-actions="{ row }">
            <Button
              variant="ghost"
              size="icon-sm"
              leading-icon="trash"
              :disabled="row.is_owner"
              :loading="removingId === row.id"
              :aria-label="row.is_owner
                ? t('members.remove.ownerDisabled')
                : t('members.remove.ariaLabel', undefined, { name: row.name })"
              @click="onRemove(row)"
            />
          </template>

          <template #error>
            <EmptyState
              variant="error"
              size="sm"
              :title="t('members.errors.loadTitle')"
              :description="t('members.errors.loadBody')"
            >
              <template #action>
                <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="members.fetch()">
                  {{ t('common.retry', 'Retry') }}
                </Button>
              </template>
            </EmptyState>
          </template>

          <template #empty>
            <EmptyState
              v-if="membersEmpty"
              size="sm"
              icon="users"
              :title="t('members.empty.title')"
              :description="t('members.empty.body')"
            />
          </template>
        </Table>
      </Card>

      <!-- ===== Invite by email (owner only) ===== -->
      <Card v-if="canManage">
        <template #header>
          <div class="flex min-w-0 flex-col gap-next-0_5">
            <h2 class="text-next-base font-next-semibold text-next-fg">
              {{ t('invitations.invite.title') }}
            </h2>
            <p class="text-next-sm text-next-muted-foreground">
              {{ t('invitations.invite.description') }}
            </p>
          </div>
        </template>

        <form
          class="flex flex-col gap-next-3 next-sm:flex-row next-sm:items-end"
          novalidate
          @submit.prevent="onInvite"
        >
          <FormField
            class="min-w-0 flex-1"
            :label="t('invitations.invite.emailLabel')"
            :error="inviteError || undefined"
            required
          >
            <TextInput
              v-model="inviteEmail"
              type="email"
              name="invite-email"
              autocomplete="off"
              leading-icon="at-sign"
              :placeholder="t('invitations.invite.emailPlaceholder')"
            />
          </FormField>
          <Button
            type="submit"
            variant="primary"
            leading-icon="mail"
            :loading="invitations.inviting"
            class="shrink-0"
          >
            {{ t('invitations.invite.submit') }}
          </Button>
        </form>
      </Card>

      <!-- ===== Pending invitations (owner only) ===== -->
      <Card v-if="canManage">
        <template #header>
          <div class="flex min-w-0 flex-col gap-next-0_5">
            <h2 class="text-next-base font-next-semibold text-next-fg">
              {{ t('invitations.pending.title') }}
            </h2>
            <p class="text-next-sm text-next-muted-foreground">
              {{ t('invitations.pending.description') }}
            </p>
          </div>
        </template>

        <Table
          :columns="invitationColumns"
          :rows="invitations.invitations"
          :loading="invitations.loading"
          :error="!!invitations.error"
          row-key="id"
          responsive="stack"
          :caption="t('invitations.tableCaption')"
        >
          <template #cell-email="{ row }">
            <span class="min-w-0 truncate font-next-medium text-next-fg">{{ row.email }}</span>
          </template>

          <template #cell-status="{ row }">
            <StatusBadge :status="row.status" :status-map="invitationStatusMap" size="sm" />
          </template>

          <template #cell-invited_by="{ row }">
            <span class="text-next-muted-foreground">{{ row.invited_by?.name ?? '—' }}</span>
          </template>

          <template #cell-expires_at="{ row }">
            <span class="text-next-muted-foreground">{{ formatDate(row.expires_at) }}</span>
          </template>

          <template #row-actions="{ row }">
            <div class="flex items-center justify-end gap-next-1">
              <Button
                variant="ghost"
                size="icon-sm"
                leading-icon="rotate-ccw"
                :loading="resendingId === row.id"
                :aria-label="t('invitations.resend.ariaLabel', undefined, { email: row.email })"
                @click="onResend(row)"
              />
              <Button
                variant="ghost"
                size="icon-sm"
                leading-icon="x"
                :aria-label="t('invitations.revoke.ariaLabel', undefined, { email: row.email })"
                @click="onRevoke(row)"
              />
            </div>
          </template>

          <template #error>
            <EmptyState
              variant="error"
              size="sm"
              :title="t('invitations.errors.loadTitle')"
              :description="t('invitations.errors.loadBody')"
            >
              <template #action>
                <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="invitations.fetch()">
                  {{ t('common.retry', 'Retry') }}
                </Button>
              </template>
            </EmptyState>
          </template>

          <template #empty>
            <EmptyState
              v-if="invitationsEmpty"
              size="sm"
              icon="mail"
              :title="t('invitations.empty.title')"
              :description="t('invitations.empty.body')"
            />
          </template>
        </Table>
      </Card>
    </Stack>
  </Container>
</template>
