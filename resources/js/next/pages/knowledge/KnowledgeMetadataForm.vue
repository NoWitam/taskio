<script setup lang="ts">
// KnowledgeMetadataForm — the entry's metadata, edited against the BASE's schema.
//
// Built on `ui/variables/TypedLiteralInput`, NOT on the Forms module's InputRenderer. The two solve
// different problems: InputRenderer renders a form DEFINITION (sections, conditions, submission
// lifecycle), while a metadata field is exactly one typed literal — which is the model
// TypedLiteralInput already implements (text→TextInput, number→NumberInput, boolean→Switch,
// date→DatePicker, enum→Select). Composition pattern lifted from SlotValuesForm's `object` branch.
//
// THREE things this component refuses to do, each for a stated reason:
//   • Invent a value for a field the entry does not carry. Absent ≠ null ≠ empty string.
//   • Hide an OFF-SCHEMA key. The backend keeps values when a schema field is removed, so hiding
//     them is how a user concludes their data was destroyed. They render read-only, flagged.
//   • Edit a `time` / `file` / `object` field. The schema builder cannot create them, but the API
//     can, and silently round-tripping a container through a text box would corrupt it.
import { computed } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import Switch from '../../ui/forms/Switch.vue';
import Button from '../../ui/primitives/Button.vue';
import Text from '../../ui/primitives/Text.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Alert from '../../ui/feedback/Alert.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import TypedLiteralInput from '../../ui/variables/TypedLiteralInput.vue';
import { isOfferedBase } from './schema';
import { useI18n } from '../../app/i18n';
import type { KnowledgeSchemaField } from './types';
import type { VariableLiteralBase } from '../../ui/variables/types';

const props = withDefaults(
  defineProps<{
    /** The base's metadata schema — the authority for what may be edited. */
    schema: KnowledgeSchemaField[];
    /** Server-side 422 messages, keyed `metadata.<key>`. They OUTRANK any client hint. */
    serverErrors?: Record<string, string> | null;
    disabled?: boolean;
  }>(),
  { serverErrors: null, disabled: false },
);

/** The whole metadata object. The parent owns persistence; this is a controlled editor. */
const model = defineModel<Record<string, unknown>>({ default: () => ({}) });

const { t } = useI18n();

/** Fields the UI can actually edit; the rest are surfaced as an honest read-only notice. */
const editable = computed(() => props.schema.filter((f) => isOfferedBase(f.descriptor?.base)));
const unsupported = computed(() => props.schema.filter((f) => !isOfferedBase(f.descriptor?.base)));

/** Keys the entry carries that the schema no longer describes. */
const offSchema = computed(() => {
  const known = new Set(props.schema.map((f) => f.key));
  return Object.keys(model.value ?? {}).filter((key) => !known.has(key));
});

function errorFor(key: string): string | null {
  return props.serverErrors?.[`metadata.${key}`] ?? null;
}

function valueOf(key: string): unknown {
  return model.value?.[key];
}

function setValue(key: string, value: unknown): void {
  model.value = { ...model.value, [key]: value };
}

/**
 * "No value" is an explicit NULL, not a deleted key.
 *
 * The distinction is load-bearing: the entry PATCH treats an absent `metadata` as "unchanged", and
 * within the object a null is a stored value meaning "deliberately empty". Toggling this off
 * restores an empty literal rather than resurrecting whatever was typed before, because the
 * previous value is genuinely gone once the user said it should not have one.
 */
function isNull(key: string): boolean {
  return valueOf(key) === null;
}

function setNull(key: string, value: boolean): void {
  setValue(key, value ? null : emptyFor(key));
}

function emptyFor(key: string): unknown {
  const field = props.schema.find((f) => f.key === key);
  if (field?.descriptor?.array) return [];
  switch (field?.descriptor?.base) {
    case 'boolean':
      return false;
    case 'number':
      return null;
    default:
      return '';
  }
}

// --- Array fields (repeaters) ----------------------------------------------
function arrayValue(key: string): unknown[] {
  const value = valueOf(key);
  return Array.isArray(value) ? value : [];
}

function setArrayItem(key: string, index: number, value: unknown): void {
  const next = [...arrayValue(key)];
  next[index] = value;
  setValue(key, next);
}

function addArrayItem(key: string): void {
  const field = props.schema.find((f) => f.key === key);
  setValue(key, [...arrayValue(key), field?.descriptor?.base === 'boolean' ? false : '']);
}

function removeArrayItem(key: string, index: number): void {
  setValue(key, arrayValue(key).filter((_, i) => i !== index));
}

function literalBase(field: KnowledgeSchemaField): VariableLiteralBase {
  return field.descriptor.base as VariableLiteralBase;
}
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <EmptyState
      v-if="schema.length === 0"
      size="sm"
      icon="braces"
      :title="t('knowledge.metadata.empty')"
    />

    <template v-else>
      <FormField
        v-for="field in editable"
        :key="field.key"
        :label="field.label || field.key"
        :error="errorFor(field.key) ?? undefined"
      >
        <div class="flex flex-col gap-next-2">
          <!-- An optional field can be explicitly "no value" — a state a blank text box cannot
               express, since "" is itself a value. -->
          <Switch
            v-if="field.descriptor.nullable"
            :model-value="isNull(field.key)"
            size="sm"
            label-position="leading"
            :label="t('knowledge.metadata.noValue')"
            :disabled="disabled"
            @update:model-value="(v: boolean) => setNull(field.key, v)"
          />

          <template v-if="!isNull(field.key)">
            <!-- Repeater for a list-valued field (same shape as SlotValuesForm). -->
            <div v-if="field.descriptor.array" class="flex flex-col gap-next-2">
              <div
                v-for="(item, index) in arrayValue(field.key)"
                :key="index"
                class="flex items-center gap-next-2"
              >
                <div class="min-w-0 flex-1">
                  <TypedLiteralInput
                    :model-value="item"
                    :base="literalBase(field)"
                    :options="field.descriptor.options ?? []"
                    :disabled="disabled"
                    :invalid="!!errorFor(field.key)"
                    :aria-label="`${field.label || field.key} ${index + 1}`"
                    @update:model-value="(v: unknown) => setArrayItem(field.key, index, v)"
                  />
                </div>
                <Button
                  variant="ghost"
                  size="icon-xs"
                  leading-icon="trash"
                  :disabled="disabled"
                  :aria-label="t('knowledge.metadata.removeItem')"
                  @click="removeArrayItem(field.key, index)"
                />
              </div>
              <Button
                variant="outline"
                size="xs"
                leading-icon="plus"
                :disabled="disabled"
                @click="addArrayItem(field.key)"
              >
                {{ t('knowledge.metadata.addItem') }}
              </Button>
            </div>

            <TypedLiteralInput
              v-else
              :model-value="valueOf(field.key)"
              :base="literalBase(field)"
              :options="field.descriptor.options ?? []"
              :disabled="disabled"
              :invalid="!!errorFor(field.key)"
              :aria-label="field.label || field.key"
              @update:model-value="(v: unknown) => setValue(field.key, v)"
            />
          </template>
        </div>
      </FormField>

      <!-- A type this UI cannot edit. Shown, named, and left alone. -->
      <Alert v-if="unsupported.length > 0" variant="warning" size="sm">
        {{ t('knowledge.metadata.unsupported') }}
        <div class="mt-next-1 flex flex-wrap gap-next-1">
          <Badge v-for="field in unsupported" :key="field.key" variant="neutral" tone="subtle" size="sm">
            {{ field.label || field.key }}
          </Badge>
        </div>
      </Alert>

      <!-- Values whose field left the schema. The backend kept them; so do we. -->
      <div v-if="offSchema.length > 0" class="flex flex-col gap-next-1">
        <div class="flex items-center gap-next-2">
          <Badge variant="warning" tone="subtle" icon="alert-triangle" size="sm">
            {{ t('knowledge.metadata.outOfSchema') }}
          </Badge>
        </div>
        <Text variant="caption" tone="muted">{{ t('knowledge.metadata.outOfSchemaHint') }}</Text>
        <div class="flex flex-wrap gap-next-1">
          <Badge v-for="key in offSchema" :key="key" variant="neutral" tone="subtle" size="sm">
            {{ key }}
          </Badge>
        </div>
      </div>
    </template>
  </div>
</template>
