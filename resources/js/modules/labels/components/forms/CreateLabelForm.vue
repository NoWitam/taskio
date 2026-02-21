<script setup lang="ts">
import { computed, ref } from 'vue';
import { useLabelsStore } from '@/store/labels';
import { useI18n } from '@/composables/useI18n';
import type { Label } from '@/types';

import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Icon from '@/components/ui/Icon.vue';
import TextInput from '@/components/ui/inputs/TextInput.vue';
import ColorInput from '@/components/ui/inputs/ColorInput.vue';
import IconInput from '@/components/ui/inputs/IconInput.vue';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    submitLabel?: string;
    cancelLabel?: string;
    cancelable?: boolean;
    disabled?: boolean;
  }>(),
  {
    cancelable: true,
    disabled: false,
  }
);

const emit = defineEmits<{
  (e: 'created', label: Label): void;
  (e: 'cancel'): void;
}>();

const labelsStore = useLabelsStore();

const submitting = ref(false);
const touched = ref(false);
const error = ref<string | null>(null);

const name = ref('');
const color = ref<string | null>(null);
const icon = ref<string | null>(null);

// Computed properties for labels
const submitButtonLabel = computed(() => props.submitLabel || t('common.create'));
const cancelButtonLabel = computed(() => props.cancelLabel || t('common.cancel'));

const nameError = computed(() => {
  if (!touched.value) return undefined;
  return name.value.trim() ? undefined : t('validation.required');
});

const previewText = computed(() => name.value.trim() || t('labels.newLabel'));
const previewTone = computed(() => (color.value ? 'custom' : 'neutral'));
const previewColor = computed(() => (color.value ? color.value : undefined));

async function submit() {
  touched.value = true;
  error.value = null;
  
  if (!name.value.trim()) return;

  submitting.value = true;
  try {
    const created = await labelsStore.createLabel({
      name: name.value.trim(),
      color: color.value,
      icon: icon.value,
    });

    emit('created', created);
  } catch (e: any) {
    error.value = labelsStore.error || e?.response?.data?.message || e?.message || t('labels.labelCreated');
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <div>
      <div class="text-sm font-semibold text-muted-foreground mb-2">{{ t('common.preview') }}</div>
      <Badge :tone="previewTone" :color="previewColor" class="font-medium gap-2">
        <Icon v-if="icon" :name="icon" size="xs" />
        <span class="truncate">{{ previewText }}</span>
      </Badge>
    </div>

    <TextInput v-model="name" :label="t('labels.labelName')" :placeholder="`${t('forms.enterName')}…`" :error="nameError" />

    <ColorInput
      v-model="color"
      :label="t('labels.color') + ' (' + t('common.optional') + ')'"
      :placeholder="t('labels.chooseColor') + '…'"
      :clearable="true"
    />

    <IconInput v-model="icon" :label="t('labels.icon') + ' (' + t('common.optional') + ')'" :placeholder="t('labels.chooseIcon') + '…'" />

    <p v-if="error" class="text-xs text-danger">{{ error }}</p>

    <div class="flex justify-end gap-3 pt-2">
      <Button
        v-if="cancelable"
        type="button"
        variant="secondary"
        :disabled="submitting || disabled"
        @click="emit('cancel')"
      >
        {{ cancelButtonLabel }}
      </Button>
      <Button type="submit" variant="primary" :disabled="submitting || disabled">
        {{ submitButtonLabel }}
      </Button>
    </div>
  </form>
</template>
