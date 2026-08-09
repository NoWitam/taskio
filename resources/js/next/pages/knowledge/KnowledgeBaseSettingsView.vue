<script setup lang="ts">
// KnowledgeBaseSettingsView — the base's own settings section.
//
// Mounts the SAME `KnowledgeBaseForm` the create drawer uses (B4 built it for exactly this), so
// creating a base and editing one can never drift apart. This page adds only what a page has that a
// drawer does not: a PageHeader, and the danger zone.
//
// The `<h1>` names the SECTION, not the base — the base's identity is carried by the module aside
// (ADR-0011). The reader is the one exception in this module, because there the heading of the page
// really is the article's heading.
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import Surface from '../../ui/layout/Surface.vue';
import Button from '../../ui/primitives/Button.vue';
import Text from '../../ui/primitives/Text.vue';
import Heading from '../../ui/primitives/Heading.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import KnowledgeBaseForm from './KnowledgeBaseForm.vue';
import { useKnowledgeStore } from '../../app/stores/knowledge';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useI18n } from '../../app/i18n';
import type { KnowledgeBaseWritePayload } from './types';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const store = useKnowledgeStore();
const toast = useToast();
const confirm = useConfirm();

const baseId = computed(() => String(route.params.baseId ?? ''));
const base = computed(() => (store.openBase?.id === baseId.value ? store.openBase : null));

const submitting = ref(false);
const serverErrors = ref<Record<string, string> | null>(null);
const form = ref<InstanceType<typeof KnowledgeBaseForm> | null>(null);

/** A viewer with neither ability reads the charter but is offered no save button (not a 403). */
const canSave = computed(() => !!base.value && (base.value.can_be_edited || base.value.can_be_managed));

function fieldErrorsOf(err: unknown): Record<string, string> | null {
  const errors = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
  if (!errors) return null;
  const flat: Record<string, string> = {};
  for (const [key, messages] of Object.entries(errors)) {
    if (Array.isArray(messages) && messages.length) flat[key] = messages[0];
  }
  return flat;
}

async function onSubmit(payload: KnowledgeBaseWritePayload): Promise<void> {
  submitting.value = true;
  serverErrors.value = null;
  try {
    await store.updateBase(baseId.value, payload);
    toast.success(t('knowledge.bases.toasts.updated'));
  } catch (err: unknown) {
    const fieldErrors = fieldErrorsOf(err);
    if (fieldErrors) serverErrors.value = fieldErrors;
    else toast.danger(t('knowledge.bases.toasts.error'));
  } finally {
    submitting.value = false;
  }
}

async function onTrash(): Promise<void> {
  const current = base.value;
  if (!current) return;

  const ok = await confirm({
    title: t('knowledge.bases.trashConfirm.title'),
    message: t('knowledge.bases.trashConfirm.message', '', { count: current.entries_count ?? 0 }),
    confirmLabel: t('knowledge.settings.dangerTrash'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
  if (!ok) return;

  try {
    await store.deleteBase(current.id);
    toast.success(t('knowledge.bases.toasts.trashed'));
    void router.push({ name: 'next.knowledge' });
  } catch {
    toast.danger(t('knowledge.bases.toasts.error'));
  }
}
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <PageHeader icon="settings" :title="t('knowledge.settings.title')" :description="t('knowledge.settings.subtitle')" />

    <!-- Loading: the shape of the form (label + control pairs), not a spinner. -->
    <Surface
      v-if="store.openBaseLoading && !base"
      bg="card"
      border
      elevation="sm"
      radius="lg"
      class="flex flex-col gap-next-4 p-next-6"
      role="status"
      :aria-label="t('knowledge.common.loadingLabel')"
    >
      <Skeleton v-for="n in 5" :key="`sk-${n}`" variant="text" :width="n % 2 ? '40%' : '80%'" />
    </Surface>

    <EmptyState
      v-else-if="!base"
      variant="error"
      :title="t('knowledge.common.loadError')"
    >
      <template #action>
        <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="store.fetchOpenBase(baseId)">
          {{ t('knowledge.common.retry') }}
        </Button>
      </template>
    </EmptyState>

    <template v-else>
      <Surface bg="card" border elevation="sm" radius="lg" class="flex flex-col gap-next-4 p-next-6">
        <!-- Governance is TWO abilities, not one: a member may rename a base without being allowed
             to rewrite the charter + schema every entry is validated against. The form gates them
             separately; this note explains the split rather than letting a 403 do it. -->
        <Alert v-if="!base.can_be_managed && base.can_be_edited" variant="info" size="sm">
          {{ t('knowledge.settings.governedLocked') }}
        </Alert>
        <Alert v-else-if="!base.can_be_edited && !base.can_be_managed" variant="info" size="sm">
          {{ t('knowledge.settings.editLocked') }}
        </Alert>

        <KnowledgeBaseForm
          ref="form"
          :base="base"
          :submitting="submitting"
          :server-errors="serverErrors"
          @submit="onSubmit"
        />

        <div v-if="canSave" class="flex items-center justify-end">
          <Button :loading="submitting" @click="form?.submit()">{{ t('common.save') }}</Button>
        </div>
      </Surface>

      <!-- Danger zone. Separated by a border, not by a colour alone. -->
      <Surface
        v-if="base.can_be_deleted"
        bg="card"
        border
        radius="lg"
        class="flex flex-col gap-next-3 border-next-danger/40 p-next-6"
      >
        <Heading level="2" size="sm">{{ t('knowledge.settings.danger') }}</Heading>
        <Text variant="caption" tone="muted">{{ t('knowledge.settings.dangerHint') }}</Text>
        <Button variant="danger" leading-icon="trash" class="self-start" @click="onTrash">
          {{ t('knowledge.settings.dangerTrash') }}
        </Button>
      </Surface>
    </template>
  </div>
</template>
