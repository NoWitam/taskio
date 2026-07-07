<script setup lang="ts">
// EntryListInput — a COMPACT two-field entry editor (next), shared by the bot's
// Knowledge ({title, content}), Dictionary ({term, meaning}) and Phrases
// ({phrase, context}) modules so they all look + FEEL the same.
//
// Saved entries render as COLLAPSED rows (primary value + a truncated secondary
// preview) with expand / edit / delete always available; the full two-field form
// only appears when ADDING or EDITING. This keeps a long list scannable instead of
// a wall of open inputs.
//
// v-model is a `Record<string, string>[]` — the two field keys are configurable via
// `primaryKey` / `secondaryKey`. The secondary field can be optional (Phrases'
// context). Empty is valid. Per-row 422 errors come in via `entryErrors` keyed by
// index (`{ 0: { <primaryKey>?, <secondaryKey>? } }`); a 422 re-opens that row.
//
// The FIELD labels/placeholders + the section add/empty copy are passed in (they
// differ per module); the form chrome (adding/editing/save/cancel/expand/remove/
// required) uses a shared `bots.editor.entryForm.*` i18n block.
//
// When `disabled` (module OFF) every control is disabled so the list stays READABLE
// but non-editable (kept in the a11y tree, unlike `inert`).
import { ref, watch } from 'vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import { useI18n } from '../../app/i18n';

type EntryRow = Record<string, string>;

const props = withDefaults(
  defineProps<{
    primaryKey: string;
    secondaryKey: string;
    /** When true the secondary field is optional (only the primary is required). */
    secondaryOptional?: boolean;
    /** Rows for the secondary Textarea. */
    secondaryRows?: number;
    // Field-specific copy (differs per module).
    primaryLabel: string;
    primaryPlaceholder: string;
    secondaryLabel: string;
    secondaryPlaceholder: string;
    /** "Add an entry" section button. */
    addLabel: string;
    /** Empty-state note. */
    emptyLabel: string;
    /** Per-row field errors keyed by index (server 422), keyed by field key. */
    entryErrors?: Record<number, Record<string, string>>;
    max?: number;
    disabled?: boolean;
  }>(),
  { secondaryOptional: false, secondaryRows: 2, max: 100, disabled: false },
);

const { t } = useI18n();

const rows = defineModel<EntryRow[]>({ default: () => [] });

// --- Add / edit form state ------------------------------------------------
// `editingIndex` = the index being edited, -1 while ADDING, or null when closed.
const editingIndex = ref<number | null>(null);
const draft = ref<EntryRow>(blank());
const expanded = ref<Set<number>>(new Set());

function blank(): EntryRow {
  return { [props.primaryKey]: '', [props.secondaryKey]: '' };
}
function primaryOf(row: EntryRow): string {
  return row[props.primaryKey] ?? '';
}
function secondaryOf(row: EntryRow): string {
  return row[props.secondaryKey] ?? '';
}

const isFormOpen = () => editingIndex.value !== null;
const isAdding = () => editingIndex.value === -1;

function startAdd(): void {
  if (props.disabled || rows.value.length >= props.max) return;
  draft.value = blank();
  editingIndex.value = -1;
}
function startEdit(index: number): void {
  if (props.disabled) return;
  draft.value = { ...rows.value[index] };
  editingIndex.value = index;
}
function cancelForm(): void {
  editingIndex.value = null;
  draft.value = blank();
  formError.value = null;
}

const formError = ref<string | null>(null);
function saveForm(): void {
  const primary = (draft.value[props.primaryKey] ?? '').trim();
  const secondary = (draft.value[props.secondaryKey] ?? '').trim();
  if (!primary || (!props.secondaryOptional && !secondary)) {
    formError.value = props.secondaryOptional
      ? t('bots.editor.entryForm.primaryRequired')
      : t('bots.editor.entryForm.bothRequired');
    return;
  }
  formError.value = null;
  const row: EntryRow = { [props.primaryKey]: primary, [props.secondaryKey]: secondary };
  if (isAdding()) {
    rows.value = [...rows.value, row];
  } else {
    const idx = editingIndex.value as number;
    rows.value = rows.value.map((r, i) => (i === idx ? row : r));
  }
  cancelForm();
}

function removeRow(index: number): void {
  if (props.disabled) return;
  rows.value = rows.value.filter((_, i) => i !== index);
  if (editingIndex.value !== null && editingIndex.value >= index) cancelForm();
  expanded.value = new Set([...expanded.value].filter((i) => i !== index));
}

function toggleExpand(index: number): void {
  const next = new Set(expanded.value);
  next.has(index) ? next.delete(index) : next.add(index);
  expanded.value = next;
}

function preview(text: string): string {
  const collapsed = text.replace(/\s+/g, ' ').trim();
  return collapsed.length > 140 ? `${collapsed.slice(0, 140)}…` : collapsed;
}

// A 422 for a specific row re-opens that row's form so the error shows in context.
watch(
  () => props.entryErrors,
  (errs) => {
    if (!errs) return;
    const idx = Object.keys(errs)[0];
    if (idx !== undefined && !isFormOpen()) startEdit(Number(idx));
  },
);

function draftError(key: string): string | undefined {
  if (editingIndex.value === null || isAdding()) return undefined;
  return props.entryErrors?.[editingIndex.value]?.[key];
}
</script>

<template>
  <div class="flex flex-col gap-next-2">
    <!-- Empty note (no rows AND the form is closed). -->
    <p
      v-if="!rows.length && !isFormOpen()"
      class="rounded-next-md bg-next-muted px-next-3 py-next-2 text-next-xs text-next-muted-foreground"
    >
      {{ emptyLabel }}
    </p>

    <!-- COMPACT saved rows. The row being edited is replaced by the inline form. -->
    <ul v-if="rows.length" class="flex flex-col gap-next-1_5">
      <template v-for="(row, index) in rows" :key="index">
        <li
          v-if="editingIndex !== index"
          class="flex flex-col gap-next-1 rounded-next-md border border-next-border bg-next-bg px-next-3 py-next-2"
        >
          <div class="flex items-center gap-next-2">
            <button
              type="button"
              class="flex min-w-0 flex-1 items-center gap-next-2 text-left"
              :aria-expanded="expanded.has(index)"
              :aria-label="t('bots.editor.entryForm.expand', '', { label: primaryOf(row) })"
              @click="toggleExpand(index)"
            >
              <Icon
                :name="expanded.has(index) ? 'chevron-down' : 'chevron-right'"
                class="shrink-0 text-next-muted-foreground"
                aria-hidden="true"
              />
              <span class="min-w-0 flex-1">
                <span class="block truncate text-next-sm font-next-medium text-next-fg">{{ primaryOf(row) }}</span>
                <span
                  v-if="!expanded.has(index) && secondaryOf(row)"
                  class="block truncate text-next-xs text-next-muted-foreground"
                >
                  {{ preview(secondaryOf(row)) }}
                </span>
              </span>
            </button>
            <div class="flex shrink-0 items-center gap-next-0_5">
              <Button
                variant="ghost"
                size="icon-xs"
                leading-icon="pencil"
                :disabled="disabled"
                :aria-label="t('bots.editor.entryForm.edit', '', { label: primaryOf(row) })"
                @click="startEdit(index)"
              />
              <Button
                variant="ghost"
                size="icon-xs"
                leading-icon="trash"
                :disabled="disabled"
                :aria-label="t('bots.editor.entryForm.remove', '', { label: primaryOf(row) })"
                @click="removeRow(index)"
              />
            </div>
          </div>
          <p
            v-if="expanded.has(index) && secondaryOf(row)"
            class="whitespace-pre-wrap pl-next-6 text-next-xs text-next-muted-foreground"
          >
            {{ secondaryOf(row) }}
          </p>
        </li>

        <!-- Inline EDIT form (replaces the row it edits). -->
        <li v-else>
          <div class="flex flex-col gap-next-2 rounded-next-md border border-next-primary/40 bg-next-primary-subtle/20 p-next-3">
            <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('bots.editor.entryForm.editing') }}</span>
            <TextInput
              v-model="draft[primaryKey]"
              :maxlength="255"
              :disabled="disabled"
              :aria-invalid="!!draftError(primaryKey)"
              :placeholder="primaryPlaceholder"
              :aria-label="primaryLabel"
            />
            <Textarea
              v-model="draft[secondaryKey]"
              :rows="secondaryRows"
              :maxlength="5000"
              :disabled="disabled"
              :aria-invalid="!!draftError(secondaryKey)"
              :placeholder="secondaryPlaceholder"
              :aria-label="secondaryLabel"
            />
            <p v-if="formError" class="flex items-start gap-next-1 text-next-xs text-next-danger" role="alert">
              <Icon name="alert-circle" class="mt-px shrink-0" aria-hidden="true" />
              <span>{{ formError }}</span>
            </p>
            <div class="flex items-center gap-next-2">
              <Button size="sm" :disabled="disabled" @click="saveForm">{{ t('bots.editor.entryForm.save') }}</Button>
              <Button size="sm" variant="ghost" @click="cancelForm">{{ t('common.cancel') }}</Button>
            </div>
          </div>
        </li>
      </template>
    </ul>

    <!-- Inline ADD form. -->
    <div
      v-if="isAdding()"
      class="flex flex-col gap-next-2 rounded-next-md border border-next-primary/40 bg-next-primary-subtle/20 p-next-3"
    >
      <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('bots.editor.entryForm.adding') }}</span>
      <TextInput
        v-model="draft[primaryKey]"
        :maxlength="255"
        :disabled="disabled"
        :placeholder="primaryPlaceholder"
        :aria-label="primaryLabel"
      />
      <Textarea
        v-model="draft[secondaryKey]"
        :rows="secondaryRows"
        :maxlength="5000"
        :disabled="disabled"
        :placeholder="secondaryPlaceholder"
        :aria-label="secondaryLabel"
      />
      <p v-if="formError" class="flex items-start gap-next-1 text-next-xs text-next-danger" role="alert">
        <Icon name="alert-circle" class="mt-px shrink-0" aria-hidden="true" />
        <span>{{ formError }}</span>
      </p>
      <div class="flex items-center gap-next-2">
        <Button size="sm" :disabled="disabled" @click="saveForm">{{ t('bots.editor.entryForm.save') }}</Button>
        <Button size="sm" variant="ghost" @click="cancelForm">{{ t('common.cancel') }}</Button>
      </div>
    </div>

    <!-- Add affordance (hidden while a form is open). -->
    <div v-if="!isFormOpen()">
      <Button
        variant="outline"
        size="sm"
        leading-icon="plus"
        :disabled="disabled || rows.length >= max"
        @click="startAdd"
      >
        {{ addLabel }}
      </Button>
    </div>
  </div>
</template>
