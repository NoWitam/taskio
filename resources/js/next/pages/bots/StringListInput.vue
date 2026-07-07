<script setup lang="ts">
// StringListInput — a readable repeatable SINGLE-value list (next). Each item is a
// FULL-WIDTH text row (no truncation, unlike a pill/tag), so long entries stay
// legible — used for the bot's Prohibitions (topics/behaviours to avoid).
//
// v-model is a `string[]`. Empty is valid. Blank rows are dropped by the parent on
// save. Labels/placeholder are passed in (i18n at the call site).
import TextInput from '../../ui/forms/TextInput.vue';
import Button from '../../ui/primitives/Button.vue';

const props = withDefaults(
  defineProps<{
    placeholder: string;
    addLabel: string;
    emptyLabel: string;
    /** aria-label for a remove button; `{n}` is the 1-based index. */
    removeLabel: string;
    max?: number;
    disabled?: boolean;
  }>(),
  { max: 100, disabled: false },
);

const items = defineModel<string[]>({ default: () => [] });

function addItem(): void {
  if (props.disabled || items.value.length >= props.max) return;
  items.value = [...items.value, ''];
}
function removeItem(index: number): void {
  if (props.disabled) return;
  items.value = items.value.filter((_, i) => i !== index);
}
function updateItem(index: number, value: string): void {
  items.value = items.value.map((v, i) => (i === index ? value : v));
}

function fmt(text: string, n: number): string {
  return text.replace('{n}', String(n));
}
</script>

<template>
  <div class="flex flex-col gap-next-2">
    <p
      v-if="!items.length"
      class="rounded-next-md bg-next-muted px-next-3 py-next-2 text-next-xs text-next-muted-foreground"
    >
      {{ emptyLabel }}
    </p>

    <div v-for="(item, index) in items" :key="index" class="flex items-center gap-next-2">
      <TextInput
        :model-value="item"
        :maxlength="255"
        size="sm"
        class="min-w-0 flex-1"
        :disabled="disabled"
        :placeholder="placeholder"
        @update:model-value="(v: string) => updateItem(index, v)"
      />
      <Button
        variant="ghost"
        size="icon-sm"
        leading-icon="trash"
        class="shrink-0"
        :disabled="disabled"
        :aria-label="fmt(removeLabel, index + 1)"
        @click="removeItem(index)"
      />
    </div>

    <div>
      <Button
        variant="outline"
        size="sm"
        leading-icon="plus"
        :disabled="disabled || items.length >= max"
        @click="addItem"
      >
        {{ addLabel }}
      </Button>
    </div>
  </div>
</template>
