<script setup lang="ts">
// KnowledgeBaseSettingsDrawer — create a knowledge base, or edit an existing one's settings
// (spec §3.5 / §7). A right Drawer wrapping the ONE shared KnowledgeBaseForm, so the create
// path and the edit path can never drift apart; the settings PAGE (B5) mounts the same form.
//
// The drawer owns only the chrome: title, footer actions, and re-seeding the form each time it
// OPENS (the form re-seeds off its `base` prop, and the `:key` forces that even when the same
// base is reopened after an abandoned edit). The parent owns the store call, the toasts and the
// 422 feedback — an error NEVER closes the drawer, so nothing typed is lost.
import { computed, ref } from 'vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import Button from '../../ui/primitives/Button.vue';
import KnowledgeBaseForm from './KnowledgeBaseForm.vue';
import { useI18n } from '../../app/i18n';
import type { KnowledgeBase, KnowledgeBaseWritePayload } from './types';

const props = withDefaults(
  defineProps<{
    /** The base being edited, or null for a create. */
    base?: KnowledgeBase | null;
    /** True while the parent's store call is in flight. */
    submitting?: boolean;
    /** Backend 422 field errors keyed by dotted path. */
    serverErrors?: Record<string, string> | null;
  }>(),
  { base: null, submitting: false, serverErrors: null },
);

const emit = defineEmits<{
  submit: [KnowledgeBaseWritePayload];
  close: [];
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const isEdit = computed(() => props.base != null);
const title = computed(() =>
  isEdit.value ? t('knowledge.settings.editTitle') : t('knowledge.settings.createTitle'),
);

/**
 * Whether this viewer may change ANYTHING here. A base whose policy grants neither ability is
 * read-only: the form stays visible (a member must be able to read the charter their entries are
 * written against) but the save action goes away rather than promising a 403.
 */
const canSave = computed(() => !props.base || props.base.can_be_edited || props.base.can_be_managed);

const form = ref<InstanceType<typeof KnowledgeBaseForm> | null>(null);

function onSave(): void {
  form.value?.submit();
}

function cancel(): void {
  open.value = false;
  emit('close');
}
</script>

<template>
  <Drawer v-model:open="open" side="right" size="lg" :show-close="false" :aria-label="title">
    <template #title>{{ title }}</template>

    <KnowledgeBaseForm
      v-if="open"
      ref="form"
      :key="base?.id ?? 'new'"
      :base="base"
      :submitting="submitting"
      :server-errors="serverErrors"
      @submit="(payload: KnowledgeBaseWritePayload) => emit('submit', payload)"
    />

    <template #footer>
      <Button variant="outline" type="button" :disabled="submitting" @click="cancel">
        {{ t('common.cancel') }}
      </Button>
      <Button v-if="canSave" variant="primary" type="button" :loading="submitting" @click="onSave">
        {{ isEdit ? t('common.save') : t('knowledge.settings.create') }}
      </Button>
    </template>
  </Drawer>
</template>
