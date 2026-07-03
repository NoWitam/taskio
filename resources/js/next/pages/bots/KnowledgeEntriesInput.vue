<script setup lang="ts">
// KnowledgeEntriesInput — the repeatable {title, content} editor for a bot's
// KNOWLEDGE module (next, Batch 6).
//
// v-model is a `BotKnowledgeEntry[]` (`{ title, content }`). The user adds rows
// (title TextInput + content Textarea), removes rows, and edits in place. Empty is
// valid (the knowledge module is optional). Reorder is NOT required.
//
// Per-entry validation messages are passed in via `entryErrors` keyed by the row
// index (`{ 0: { title?, content? } }`) so the parent can surface a server 422
// (`knowledge.entries.<i>.title` / `knowledge.entries.<i>.content`) on the exact field.
//
// When `disabled` (the knowledge module is toggled OFF) every control is disabled
// so the editor is READABLE but non-editable — kept in the accessibility tree (unlike
// `inert`) so a screen-reader user can still preview the module.
//
// A11y: each row is a labelled group; the add/remove controls have explicit
// aria-labels. All strings via t(); no legacy imports; namespaced tokens.
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import { useI18n } from '../../app/i18n';
import type { BotKnowledgeEntry } from './types';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    /** Per-row field errors, keyed by index (from a server 422). */
    entryErrors?: Record<number, { title?: string; content?: string }>;
    /** Max entries (server cap is 50); the add button disables at the cap. */
    max?: number;
    /** Disable every control (module off) — readable but not editable. */
    disabled?: boolean;
  }>(),
  { max: 50, disabled: false },
);

// v-model: the full entries array. We mutate a copy + reassign so parents that
// diff by reference (and the FormField dirty tracker) see each change.
const entries = defineModel<BotKnowledgeEntry[]>({ default: () => [] });

const emit = defineEmits<{
  /** Emitted when a row is added, so the parent can focus / scroll to it. */
  (e: 'add'): void;
}>();

function addEntry(): void {
  if (entries.value.length >= props.max) return;
  entries.value = [...entries.value, { title: '', content: '' }];
  emit('add');
}

function removeEntry(index: number): void {
  entries.value = entries.value.filter((_, i) => i !== index);
}

function updateTitle(index: number, value: string): void {
  entries.value = entries.value.map((entry, i) =>
    i === index ? { ...entry, title: value } : entry,
  );
}

function updateContent(index: number, value: string): void {
  entries.value = entries.value.map((entry, i) =>
    i === index ? { ...entry, content: value } : entry,
  );
}

function errorFor(index: number, field: 'title' | 'content'): string | undefined {
  return props.entryErrors?.[index]?.[field];
}
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <!-- Empty state: a muted note + the add affordance below still shows. -->
    <p
      v-if="!entries.length"
      class="rounded-next-md bg-next-muted px-next-3 py-next-2 text-next-xs text-next-muted-foreground"
    >
      {{ t('bots.editor.knowledge.empty') }}
    </p>

    <!-- Entry rows. Each is a labelled group so AT announces "Entry N". -->
    <div
      v-for="(entry, index) in entries"
      :key="index"
      class="flex flex-col gap-next-3 rounded-next-lg border border-next-border bg-next-bg p-next-3"
      role="group"
      :aria-label="t('bots.editor.knowledge.entryLabel', '', { n: index + 1 })"
    >
      <div class="flex items-center justify-between gap-next-2">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">
          {{ t('bots.editor.knowledge.entryLabel', '', { n: index + 1 }) }}
        </span>
        <Button
          variant="ghost"
          size="icon-xs"
          leading-icon="trash"
          :disabled="disabled"
          :aria-label="t('bots.editor.knowledge.removeEntry', '', { n: index + 1 })"
          @click="removeEntry(index)"
        />
      </div>

      <!-- Title (required ≤255). -->
      <div class="flex flex-col gap-next-1_5">
        <label class="text-next-sm font-next-medium text-next-fg">
          {{ t('bots.editor.knowledge.titleLabel') }}
          <span class="text-next-danger" aria-hidden="true">*</span>
          <span class="sr-only">{{ t('common.requiredMarker') }}</span>
        </label>
        <TextInput
          :model-value="entry.title"
          :maxlength="255"
          :disabled="disabled"
          :aria-invalid="!!errorFor(index, 'title')"
          :placeholder="t('bots.editor.knowledge.titlePlaceholder')"
          :aria-label="t('bots.editor.knowledge.titleLabel')"
          @update:model-value="(v: string) => updateTitle(index, v)"
        />
        <p
          v-if="errorFor(index, 'title')"
          class="flex items-start gap-next-1 text-next-xs text-next-danger"
          role="alert"
        >
          <Icon name="alert-circle" class="mt-px shrink-0" aria-hidden="true" />
          <span>{{ errorFor(index, 'title') }}</span>
        </p>
      </div>

      <!-- Content (required ≤5000). -->
      <div class="flex flex-col gap-next-1_5">
        <label class="text-next-sm font-next-medium text-next-fg">
          {{ t('bots.editor.knowledge.contentLabel') }}
          <span class="text-next-danger" aria-hidden="true">*</span>
          <span class="sr-only">{{ t('common.requiredMarker') }}</span>
        </label>
        <Textarea
          :model-value="entry.content"
          :rows="3"
          :maxlength="5000"
          :disabled="disabled"
          :aria-invalid="!!errorFor(index, 'content')"
          :placeholder="t('bots.editor.knowledge.contentPlaceholder')"
          :aria-label="t('bots.editor.knowledge.contentLabel')"
          @update:model-value="(v: string) => updateContent(index, v)"
        />
        <p
          v-if="errorFor(index, 'content')"
          class="flex items-start gap-next-1 text-next-xs text-next-danger"
          role="alert"
        >
          <Icon name="alert-circle" class="mt-px shrink-0" aria-hidden="true" />
          <span>{{ errorFor(index, 'content') }}</span>
        </p>
      </div>
    </div>

    <!-- Add row (disabled at the server cap, with an explanatory title). -->
    <div>
      <Button
        variant="outline"
        size="sm"
        leading-icon="plus"
        :disabled="disabled || entries.length >= max"
        :title="entries.length >= max ? t('bots.editor.knowledge.maxReached', '', { max }) : undefined"
        @click="addEntry"
      >
        {{ t('bots.editor.knowledge.addEntry') }}
      </Button>
    </div>
  </div>
</template>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
