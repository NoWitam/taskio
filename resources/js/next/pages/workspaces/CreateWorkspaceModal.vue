<script setup lang="ts">
// CreateWorkspaceModal — create a workspace in a single Modal (next frontend).
//
// Two storage modes (verified backend contract — POST /workspaces):
//   shared → created `ready` instantly.
//   own    → created `provisioning`; a worker provisions a dedicated database
//            asynchronously, then the workspace settles to `ready` or `failed`.
//
// Flow / phases (one Modal, switched by `phase`):
//   form         — name + a Shared/Own SegmentedControl; submit posts /workspaces.
//   provisioning — own-DB only: a spinner + aria-live status while we poll
//                  GET /workspaces/{id} until it settles (or times out).
//   failed       — provisioning errored; an Alert explains it (NO retry — there is
//                  no re-provision endpoint yet; suggest creating again / support).
//   timeout      — still provisioning after the poll budget; tell the user they can
//                  close and check back (the workspace stays in the switcher).
//   success      — handled inline (toast + switch + close), no dedicated phase view.
//
// On `ready` (shared instantly, or own after polling): toast → auth.setCurrentWorkspace
// (switches the active workspace + refreshes context) → close → route to the dashboard.
//
// States: idle form / submitting / provisioning(own) / success / error(422+failed+
// timeout). 422 name errors surface on the name FormField. a11y: Modal focus trap,
// aria-live on the provisioning status, labelled controls, inputs disabled while
// submitting/provisioning. All design-system components; no legacy imports.
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Modal from '../../ui/overlay/Modal.vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import Spinner from '../../ui/primitives/Spinner.vue';
import { useAuthStore } from '../../app/stores/auth';
import { useWorkspacesStore, type WorkspaceDbMode } from '../../app/stores/workspaces';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
  /** Emitted after a workspace is created + switched into (the active scope changed). */
  (e: 'created'): void;
}>();

const { t } = useI18n();
const router = useRouter();
const auth = useAuthStore();
const store = useWorkspacesStore();
const toast = useToast();

type Phase = 'form' | 'provisioning' | 'failed' | 'timeout';
const phase = ref<Phase>('form');

interface FormState {
  name: string;
  db_mode: WorkspaceDbMode;
}
function blankForm(): FormState {
  return { name: '', db_mode: 'shared' };
}
const form = reactive<FormState>(blankForm());

const submitting = ref(false);
// 422 field errors keyed by the backend field name (e.g. `name`).
const fieldErrors = ref<Record<string, string>>({});
const formError = ref<string | null>(null);

// The provisioning poll's stop fn — invoked when the modal closes so we never poll
// a closed modal (cancellable per the store contract).
let stopPoll: (() => void) | null = null;

const modeOptions = computed<SegmentOption<WorkspaceDbMode>[]>(() => [
  { value: 'shared', label: t('workspaces.mode.shared') },
  { value: 'own', label: t('workspaces.mode.own') },
]);

const modeHelper = computed(() =>
  form.db_mode === 'own' ? t('workspaces.mode.ownHelper') : t('workspaces.mode.sharedHelper'),
);

// Inputs are locked while a create request is in flight or while polling.
const inputsDisabled = computed(() => submitting.value || phase.value === 'provisioning');

// Reset everything whenever the modal opens; stop any poll when it closes.
watch(open, (isOpen) => {
  if (isOpen) {
    phase.value = 'form';
    Object.assign(form, blankForm());
    fieldErrors.value = {};
    formError.value = null;
    submitting.value = false;
  } else {
    stopPoll?.();
    stopPoll = null;
  }
});

/** Client-side guard for the required name (server is authoritative). */
function validate(): boolean {
  const errs: Record<string, string> = {};
  if (!form.name.trim()) errs.name = t('workspaces.validation.nameRequired');
  fieldErrors.value = errs;
  return Object.keys(errs).length === 0;
}

/** Switch into the new workspace, toast, close, and route to the dashboard. */
async function enterWorkspace(id: string | number, ownMode: boolean): Promise<void> {
  toast.success(
    ownMode ? t('workspaces.success.toastOwn') : t('workspaces.success.toastShared'),
    { description: t('workspaces.success.description') },
  );
  const switched = await auth.setCurrentWorkspace(id);
  emit('created');
  open.value = false;
  if (switched) {
    void router.push({ name: 'next.dashboard' });
  }
}

async function submit(): Promise<void> {
  formError.value = null;
  if (!validate()) return;

  submitting.value = true;
  try {
    const created = await store.createWorkspace({
      name: form.name.trim(),
      db_mode: form.db_mode,
    });

    if (created.status === 'provisioning') {
      // Own-DB: switch to the provisioning view and poll until it settles.
      await provisionWorkspace(created.id);
    } else {
      // Shared (or any already-ready workspace): switch in immediately.
      await enterWorkspace(created.id, form.db_mode === 'own');
    }
  } catch (err: unknown) {
    handleCreateError(err);
  } finally {
    submitting.value = false;
  }
}

/** Show the provisioning phase and poll until the own-DB workspace settles. */
async function provisionWorkspace(id: string | number): Promise<void> {
  phase.value = 'provisioning';
  const { promise, stop } = store.pollUntilReady(id);
  stopPoll = stop;

  const result = await promise;
  stopPoll = null;

  // The modal was closed (cancelled) while polling — do nothing.
  if (result.cancelled) return;

  if (result.timedOut) {
    phase.value = 'timeout';
    return;
  }
  if (result.workspace?.status === 'ready') {
    await enterWorkspace(result.workspace.id, true);
    return;
  }
  // Anything else that settled (failed) — or a missing workspace — is a failure.
  phase.value = 'failed';
}

/** Map a create error: 422 → field errors; otherwise a generic Alert + toast. */
function handleCreateError(err: unknown): void {
  const response = (err as {
    response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } };
  }).response;

  if (response?.status === 422 && response.data?.errors) {
    const mapped: Record<string, string> = {};
    Object.entries(response.data.errors).forEach(([key, messages]) => {
      mapped[key] = messages?.[0] ?? '';
    });
    fieldErrors.value = mapped;
    formError.value = response.data.message ?? t('workspaces.errors.generic');
  } else {
    formError.value = response?.data?.message ?? t('workspaces.errors.generic');
    toast.danger(t('workspaces.errors.title'), { description: formError.value ?? undefined });
  }
}

function close(): void {
  open.value = false;
}
</script>

<template>
  <Modal
    v-model:open="open"
    size="md"
    :aria-label="t('workspaces.create.title')"
    :close-on-esc="phase !== 'provisioning'"
    :close-on-scrim="phase !== 'provisioning'"
  >
    <template #title>
      <span v-if="phase === 'provisioning'">{{ t('workspaces.provisioning.title') }}</span>
      <span v-else-if="phase === 'failed'">{{ t('workspaces.failed.title') }}</span>
      <span v-else-if="phase === 'timeout'">{{ t('workspaces.timeout.title') }}</span>
      <span v-else>{{ t('workspaces.create.title') }}</span>
    </template>

    <!-- FORM phase: name + storage-mode choice. -->
    <form
      v-if="phase === 'form'"
      class="flex flex-col gap-next-4"
      @submit.prevent="submit"
    >
      <p class="text-next-sm text-next-muted-foreground">
        {{ t('workspaces.create.description') }}
      </p>

      <!-- Error summary (announced via Alert role) -->
      <Alert
        v-if="formError"
        variant="danger"
        size="sm"
        :title="t('workspaces.errors.title')"
      >
        {{ formError }}
      </Alert>

      <!-- Name (required) -->
      <FormField
        :label="t('workspaces.create.nameLabel')"
        required
        :error="fieldErrors.name"
      >
        <TextInput
          v-model="form.name"
          :disabled="inputsDisabled"
          :placeholder="t('workspaces.create.namePlaceholder')"
          :aria-label="t('workspaces.create.nameLabel')"
          autocomplete="off"
        />
      </FormField>

      <!-- Storage mode (required, default shared). The helper updates with the
           choice so each mode's trade-off is always visible (not color-only). -->
      <FormField
        :label="t('workspaces.create.modeLabel')"
        required
        :description="modeHelper"
        :error="fieldErrors.db_mode"
      >
        <SegmentedControl
          v-model="form.db_mode"
          :options="modeOptions"
          equal-width
          :disabled="inputsDisabled"
          :aria-label="t('workspaces.create.modeLabel')"
        />
      </FormField>
    </form>

    <!-- PROVISIONING phase: spinner + live status (own-DB only). The Spinner is
         decorative here because the visible status copy below carries its own
         aria-live region — so the status is announced exactly once. -->
    <div
      v-else-if="phase === 'provisioning'"
      class="flex flex-col items-center gap-next-4 py-next-4 text-center"
    >
      <Spinner size="lg" tone="primary" decorative />
      <p
        class="text-next-sm text-next-muted-foreground"
        role="status"
        aria-live="polite"
      >
        {{ t('workspaces.provisioning.body') }}
      </p>
    </div>

    <!-- FAILED phase: provisioning errored. No retry — there is no re-provision
         endpoint yet; the Alert suggests creating again / contacting support. -->
    <Alert
      v-else-if="phase === 'failed'"
      variant="danger"
      :title="t('workspaces.failed.title')"
    >
      {{ t('workspaces.failed.body') }}
    </Alert>

    <!-- TIMEOUT phase: still provisioning after the poll budget. -->
    <Alert
      v-else-if="phase === 'timeout'"
      variant="info"
      :title="t('workspaces.timeout.title')"
    >
      {{ t('workspaces.timeout.body') }}
    </Alert>

    <template #footer="{ close: closeModal }">
      <!-- FORM phase: cancel + submit. -->
      <template v-if="phase === 'form'">
        <Button variant="ghost" :disabled="submitting" @click="closeModal">
          {{ t('workspaces.create.cancel') }}
        </Button>
        <Button :loading="submitting" @click="submit">
          {{ t('workspaces.create.submit') }}
        </Button>
      </template>

      <!-- FAILED / TIMEOUT phases: a single Close action. -->
      <Button v-else-if="phase === 'failed'" @click="close">
        {{ t('workspaces.failed.close') }}
      </Button>
      <Button v-else-if="phase === 'timeout'" @click="close">
        {{ t('workspaces.timeout.close') }}
      </Button>
      <!-- PROVISIONING phase: no footer actions (closing is via the header ✕,
           which is allowed and stops the poll). -->
    </template>
  </Modal>
</template>
