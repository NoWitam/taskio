<script setup lang="ts">
// DescriptorSchemaBuilder — the editor for a knowledge base's METADATA SCHEMA (spec §7.3).
//
// The model is an ordered list of `{key, label, descriptor}` — the SAME descriptor vocabulary a
// const's type uses, validated on the server by the same authority. The row layout is lifted
// straight from the "Type" section of `pages/variables/ConstantEditorDrawer.vue`: a base picker
// (SegmentedControl), orthogonal `nullable` / `array` switches, and INLINE `{key,label}` choice
// rows for an enum. Nothing new is introduced — including the choice rows, which stay inline
// rather than promoting `pages/bots/EntryListInput.vue` into the design system (owner decision).
//
// OFFERED bases are text | number | boolean | date | enum. `object` is deliberately absent even
// though the backend would take it: entry metadata has to stay FLAT to be filterable and to fit a
// table column. A field the builder cannot express (an `object` written through the API) is shown
// as a READ-ONLY row and re-emitted untouched — opening this editor never destroys part of a
// schema it did not understand.
//
// Ownership: rows (with their stable local ids) live HERE; the parent speaks only the stored
// schema shape. Validation mirrors the backend and is reported through `update:valid` so the
// parent can block submit, while the MESSAGES stay hidden until the parent flips `showErrors`
// (an empty, just-added row must not shout before the author has typed anything). A server 422
// message for a field always WINS over the client's own.
import { computed, ref, watch } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Switch from '../../ui/forms/Switch.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Text from '../../ui/primitives/Text.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import { useI18n } from '../../app/i18n';
import type { IconName } from '../../ui/primitives/icons';
import {
  MAX_SCHEMA_FIELDS,
  SCHEMA_FIELD_BASES,
  draftToSchema,
  emptyFieldDraft,
  emptyOptionDraft,
  resolvedFieldKey,
  schemaToDraft,
  schemasEqual,
  validateSchemaDraft,
  type KnowledgeFieldBase,
  type SchemaFieldDraft,
} from './schema';
import type { KnowledgeSchemaField } from './types';

const props = withDefaults(
  defineProps<{
    /** The stored schema being edited (v-model). */
    modelValue: KnowledgeSchemaField[];
    /** Read-only mode — the viewer lacks the `manage` ability on this base. */
    disabled?: boolean;
    /** Reveal client validation messages (the parent flips this on a failed submit). */
    showErrors?: boolean;
    /** Backend 422 messages keyed by dotted path (`metadata_schema.<i>.key`, …). */
    serverErrors?: Record<string, string> | null;
  }>(),
  { disabled: false, showErrors: false, serverErrors: null },
);

const emit = defineEmits<{
  'update:modelValue': [KnowledgeSchemaField[]];
  /** Whether the current rows would pass the backend's schema rules. */
  'update:valid': [boolean];
}>();

const { t } = useI18n();

// --- Rows (the local draft) --------------------------------------------------
const rows = ref<SchemaFieldDraft[]>(schemaToDraft(props.modelValue));

// The last schema WE emitted — so the incoming prop echoing our own emission does not re-seed the
// rows (which would churn every v-for key and drop focus mid-typing). A prop that differs
// semantically (a different base opened) does re-seed.
let lastEmitted: KnowledgeSchemaField[] = draftToSchema(rows.value);

watch(
  () => props.modelValue,
  (next) => {
    if (schemasEqual(next, lastEmitted)) return;
    rows.value = schemaToDraft(next);
    lastEmitted = draftToSchema(rows.value);
  },
);

const validation = computed(() => validateSchemaDraft(rows.value));

watch(
  () => validation.value.valid,
  (valid) => emit('update:valid', valid),
  { immediate: true },
);

/** Emit the built schema after any row mutation. */
function commit(): void {
  lastEmitted = draftToSchema(rows.value);
  emit('update:modelValue', lastEmitted);
}

// A deep watch is what keeps the emission honest: the row controls mutate their own fields
// (`v-model="row.label"`), so there is no single mutation funnel to hook.
watch(rows, commit, { deep: true });

// --- Base picker -------------------------------------------------------------
const BASE_ICON: Record<KnowledgeFieldBase, IconName> = {
  text: 'type',
  number: 'hash',
  boolean: 'check-circle',
  date: 'calendar',
  enum: 'list',
};

const baseOptions = computed<SegmentOption<KnowledgeFieldBase>[]>(() =>
  SCHEMA_FIELD_BASES.map((base) => ({
    value: base,
    label: t(`knowledge.schema.base.${base}`),
    icon: BASE_ICON[base],
  })),
);

// --- Row operations ----------------------------------------------------------
function addField(): void {
  rows.value = [...rows.value, emptyFieldDraft()];
}

function removeField(id: string): void {
  rows.value = rows.value.filter((row) => row.id !== id);
}

function moveField(index: number, direction: -1 | 1): void {
  const target = index + direction;
  if (target < 0 || target >= rows.value.length) return;
  const next = [...rows.value];
  [next[index], next[target]] = [next[target], next[index]];
  rows.value = next;
}

/** The key input pins the key; until then it tracks the label's slug (per-row `keyModel`). */
function setKey(row: SchemaFieldDraft, value: string): void {
  row.keyTouched = true;
  row.key = value;
}

function addOption(row: SchemaFieldDraft): void {
  row.options = [...row.options, emptyOptionDraft()];
}

function removeOption(row: SchemaFieldDraft, id: string): void {
  if (row.options.length <= 1) return;
  row.options = row.options.filter((option) => option.id !== id);
}

// --- Errors ------------------------------------------------------------------
/** The first server message for this row's index, whatever sub-path it points at. */
function serverErrorFor(index: number): string | null {
  const errors = props.serverErrors;
  if (!errors) return null;
  const prefix = `metadata_schema.${index}`;
  for (const [key, message] of Object.entries(errors)) {
    if (key === prefix || key.startsWith(`${prefix}.`)) return message;
  }
  return null;
}

/** A row's rendered message: the server's verdict first, then (once revealed) the client's. */
function errorFor(row: SchemaFieldDraft, index: number): string | undefined {
  const server = serverErrorFor(index);
  if (server) return server;
  if (!props.showErrors) return undefined;
  const key = validation.value.fieldErrors[row.id];
  return key ? t(key) : undefined;
}

/** A whole-schema message (currently only the field-count bound). */
const formError = computed<string | null>(() => {
  const server = props.serverErrors?.metadata_schema ?? null;
  if (server) return server;
  const key = validation.value.formError;
  return key ? t(key, '', { max: MAX_SCHEMA_FIELDS }) : null;
});

/** The name a row's icon buttons announce — its label, else its key, else its position. */
function rowName(row: SchemaFieldDraft, index: number): string {
  return row.label.trim() || resolvedFieldKey(row) || t('knowledge.schema.fieldTitle', '', { index: index + 1 });
}
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <!-- Existing entries are never rewritten by a schema change; say so BEFORE the edit. -->
    <Alert variant="info" size="sm">{{ t('knowledge.schema.changeNotice') }}</Alert>

    <EmptyState
      v-if="rows.length === 0"
      size="sm"
      icon="braces"
      :title="t('knowledge.schema.empty')"
      :description="t('knowledge.schema.hint')"
    >
      <template v-if="!disabled" #action>
        <Button size="sm" type="button" leading-icon="plus" @click="addField">
          {{ t('knowledge.schema.addField') }}
        </Button>
      </template>
    </EmptyState>

    <ul v-else class="flex flex-col gap-next-3">
      <li
        v-for="(row, index) in rows"
        :key="row.id"
        :data-row-id="row.id"
        class="flex flex-col gap-next-3 rounded-next-lg border border-next-border bg-next-muted/20 p-next-3"
      >
        <!-- Row header: which field this is + reorder / remove. Keyboard-first (no drag & drop). -->
        <div class="flex items-center justify-between gap-next-2">
          <Text variant="caption">{{ t('knowledge.schema.fieldTitle', '', { index: index + 1 }) }}</Text>
          <div class="flex items-center gap-next-1">
            <Button
              variant="ghost"
              size="icon-xs"
              type="button"
              leading-icon="chevron-up"
              :disabled="disabled || index === 0"
              :aria-label="`${t('knowledge.schema.moveUp')}: ${rowName(row, index)}`"
              @click="moveField(index, -1)"
            />
            <Button
              variant="ghost"
              size="icon-xs"
              type="button"
              leading-icon="chevron-down"
              :disabled="disabled || index === rows.length - 1"
              :aria-label="`${t('knowledge.schema.moveDown')}: ${rowName(row, index)}`"
              @click="moveField(index, 1)"
            />
            <Button
              variant="ghost"
              size="icon-xs"
              type="button"
              leading-icon="trash"
              :disabled="disabled"
              :aria-label="`${t('knowledge.schema.removeField')}: ${rowName(row, index)}`"
              @click="removeField(row.id)"
            />
          </div>
        </div>

        <!-- Key + label under ONE label and ONE status line (they name the same thing twice:
             once for the machine, once for the human). Each input carries its own aria-label so
             a screen reader still hears which half it is in. -->
        <FormField :label="t('knowledge.schema.fieldLegend')" :error="errorFor(row, index)">
          <template #default="{ id }">
            <div class="flex flex-col gap-next-2 next-sm:flex-row">
              <div class="min-w-0 flex-1">
                <TextInput
                  :model-value="resolvedFieldKey(row)"
                  size="sm"
                  leading-icon="braces"
                  :disabled="disabled"
                  :readonly="!!row.unsupported"
                  :placeholder="t('knowledge.schema.keyPlaceholder')"
                  :aria-label="t('knowledge.schema.keyLabel')"
                  @update:model-value="(value: string) => setKey(row, value)"
                />
              </div>
              <div class="min-w-0 flex-1">
                <TextInput
                  v-model="row.label"
                  :id="`${id}-label`"
                  size="sm"
                  :disabled="disabled"
                  :placeholder="t('knowledge.schema.labelPlaceholder')"
                  :aria-label="t('knowledge.schema.labelLabel')"
                />
              </div>
            </div>
          </template>
        </FormField>

        <!-- A descriptor this builder cannot express: kept, shown, never silently rewritten. -->
        <Alert v-if="row.unsupported" variant="warning" size="sm">
          {{ t('knowledge.schema.unsupported') }}
        </Alert>

        <template v-else>
          <div class="flex flex-col gap-next-1_5">
            <span class="text-next-sm font-next-medium text-next-fg">
              {{ t('knowledge.schema.typeLabel') }}
            </span>
            <SegmentedControl
              v-model="row.base"
              :options="baseOptions"
              size="sm"
              :disabled="disabled"
              :aria-label="`${t('knowledge.schema.typeLabel')}: ${rowName(row, index)}`"
            />
          </div>

          <div class="flex flex-wrap gap-x-next-6 gap-y-next-2">
            <Switch
              v-model="row.nullable"
              size="sm"
              :disabled="disabled"
              :label="t('knowledge.schema.nullable')"
            />
            <Switch
              v-model="row.array"
              size="sm"
              :disabled="disabled"
              :label="t('knowledge.schema.array')"
            />
          </div>

          <!-- Enum choices: inline {key,label} rows (the ConstantEditorDrawer convention). -->
          <div
            v-if="row.base === 'enum'"
            class="flex flex-col gap-next-2 rounded-next-md border border-next-border p-next-3"
          >
            <span class="text-next-sm font-next-medium text-next-fg">
              {{ t('knowledge.schema.options') }}
            </span>
            <div v-for="option in row.options" :key="option.id" class="flex items-start gap-next-2">
              <TextInput
                v-model="option.key"
                size="sm"
                :disabled="disabled"
                :placeholder="t('knowledge.schema.optionKey')"
                :aria-label="t('knowledge.schema.optionKey')"
              />
              <TextInput
                v-model="option.label"
                size="sm"
                :disabled="disabled"
                :placeholder="t('knowledge.schema.optionLabel')"
                :aria-label="t('knowledge.schema.optionLabel')"
              />
              <Button
                variant="ghost"
                size="icon-sm"
                type="button"
                :disabled="disabled || row.options.length <= 1"
                :aria-label="t('knowledge.schema.optionRemove')"
                @click="removeOption(row, option.id)"
              >
                <Icon name="trash" />
              </Button>
            </div>
            <div>
              <Button
                variant="outline"
                size="sm"
                type="button"
                leading-icon="plus"
                :disabled="disabled"
                @click="addOption(row)"
              >
                {{ t('knowledge.schema.optionAdd') }}
              </Button>
            </div>
          </div>
        </template>
      </li>
    </ul>

    <Alert v-if="formError" variant="danger" size="sm">{{ formError }}</Alert>

    <div v-if="rows.length > 0 && !disabled">
      <Button variant="outline" size="sm" type="button" leading-icon="plus" @click="addField">
        {{ t('knowledge.schema.addField') }}
      </Button>
    </div>
  </div>
</template>
