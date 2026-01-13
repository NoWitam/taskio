<script setup lang="ts">
import { computed, ref } from 'vue';
import { useLabelsStore } from '@/store/labels';
import type { Label } from '@/types';

import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Icon from '@/components/ui/Icon.vue';
import TextInput from '@/components/ui/inputs/TextInput.vue';
import ColorInput from '@/components/ui/inputs/ColorInput.vue';
import IconInput from '@/components/ui/inputs/IconInput.vue';

const props = withDefaults(
  defineProps<{
    submitLabel?: string;
    cancelLabel?: string;
    cancelable?: boolean;
    disabled?: boolean;
  }>(),
  {
    submitLabel: 'Utwórz',
    cancelLabel: 'Anuluj',
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

const nameError = computed(() => {
  if (!touched.value) return undefined;
  return name.value.trim() ? undefined : 'Nazwa jest wymagana';
});

const previewText = computed(() => name.value.trim() || 'Nowa etykieta');
const previewTone = computed(() => (color.value ? 'custom' : 'neutral'));
const previewColor = computed(() => (color.value ? color.value : undefined));

async function submit() {
  touched.value = true;
  error.value = null;

  const text = name.value.trim();
  if (!text) return;

  submitting.value = true;
  try {
    const created = await labelsStore.createLabel({
      text,
      color: color.value,
      icon: icon.value,
    });

    emit('created', created);
  } catch (e: any) {
    error.value = labelsStore.error || e?.response?.data?.message || e?.message || 'Nie udało się utworzyć etykiety';
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <div>
      <div class="text-sm font-semibold text-muted-foreground mb-2">Podgląd</div>
      <Badge :tone="previewTone" :color="previewColor" class="font-medium gap-2">
        <Icon v-if="icon" :name="icon" size="xs" />
        <span class="truncate">{{ previewText }}</span>
      </Badge>
    </div>

    <TextInput v-model="name" label="Nazwa" placeholder="Np. Pilne" :error="nameError" />

    <ColorInput
      v-model="color"
      label="Kolor (opcjonalnie)"
      placeholder="Wybierz kolor…"
      :clearable="true"
    />

    <IconInput v-model="icon" label="Ikona (opcjonalnie)" placeholder="Wybierz ikonę…" />

    <p v-if="error" class="text-xs text-danger">{{ error }}</p>

    <div class="flex justify-end gap-3 pt-2">
      <Button
        v-if="cancelable"
        type="button"
        variant="secondary"
        :disabled="submitting || disabled"
        @click="emit('cancel')"
      >
        {{ cancelLabel }}
      </Button>
      <Button type="submit" variant="primary" :disabled="submitting || disabled">
        {{ submitLabel }}
      </Button>
    </div>
  </form>
</template>
