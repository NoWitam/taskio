<script setup lang="ts">
// SlotValuesForm — the descriptor-driven form of typed SLOT VALUES, reused by the session Setup turn.
//
// It renders one control per declared slot through the SHARED `TypedLiteralInput` (object → per-field,
// file → the fixed {id,name,type,size,url} subfields, array → a repeatable list, scalar/enum → one leaf,
// nullable → an explicit "no value" toggle) — EXACTLY as `TemplatePreview.vue` authors its sample
// values. A controlled component: it reads the `{slotName: value}` map from `v-model` and emits a fresh
// map on every edit (the FE never interpolates — the server renders parts). `disabled` renders every
// control inert (e.g. while a session is generating or when `can_edit` is false).
import TypedLiteralInput from '../../../ui/variables/TypedLiteralInput.vue';
import FormField from '../../../ui/forms/FormField.vue';
import Switch from '../../../ui/forms/Switch.vue';
import Button from '../../../ui/primitives/Button.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import { defaultSingleValue } from '../../variables/consts';
import { FILE_SUBFIELDS, slotSampleDefault } from '../templateSlots';
import { useI18n } from '../../../app/i18n';
import type { VariableDescriptorOption, VariableLiteralBase } from '../../../ui/variables/types';
import type { CatalogDescriptorField } from '../../workflows/types';
import type { TemplateSlot } from '../types';

const props = withDefaults(
  defineProps<{
    /** The declared slots ({name, descriptor}) whose typed inputs are rendered. */
    slots: TemplateSlot[];
    /** Disable every control (session generating / not editable). */
    disabled?: boolean;
  }>(),
  { disabled: false },
);

const { t } = useI18n();

const model = defineModel<Record<string, unknown>>({ default: () => ({}) });

function setValue(name: string, value: unknown): void {
  model.value = { ...model.value, [name]: value };
}
function literalBase(base: string): VariableLiteralBase {
  return base as VariableLiteralBase;
}
function optionsOf(slot: TemplateSlot): VariableDescriptorOption[] {
  return (slot.descriptor.options ?? []) as VariableDescriptorOption[];
}

// --- Object / file structured helpers --------------------------------------
function objectFieldsOf(slot: TemplateSlot): CatalogDescriptorField[] {
  return (slot.descriptor.fields ?? []) as CatalogDescriptorField[];
}
function mapValueOf(name: string): Record<string, unknown> {
  const value = model.value[name];
  return value && typeof value === 'object' && !Array.isArray(value) ? (value as Record<string, unknown>) : {};
}
function setMapField(name: string, key: string, value: unknown): void {
  setValue(name, { ...mapValueOf(name), [key]: value });
}
function fieldOptionsOf(field: CatalogDescriptorField): VariableDescriptorOption[] {
  return (field.descriptor.options ?? []) as VariableDescriptorOption[];
}

// --- Array element helpers --------------------------------------------------
function asArray(value: unknown): unknown[] {
  return Array.isArray(value) ? value : [];
}
function setElement(slot: TemplateSlot, index: number, value: unknown): void {
  const list = [...asArray(model.value[slot.name])];
  list[index] = value;
  setValue(slot.name, list);
}
function addElement(slot: TemplateSlot): void {
  setValue(slot.name, [...asArray(model.value[slot.name]), defaultSingleValue(slot.descriptor.base, optionsOf(slot))]);
}
function removeElement(slot: TemplateSlot, index: number): void {
  setValue(slot.name, asArray(model.value[slot.name]).filter((_, i) => i !== index));
}

// --- Nullable "no value" toggle --------------------------------------------
function hasNoValue(slot: TemplateSlot): boolean {
  return model.value[slot.name] === null;
}
function setNoValue(slot: TemplateSlot, on: boolean): void {
  setValue(slot.name, on ? null : slotSampleDefault(slot.descriptor));
}
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <FormField v-for="slot in slots" :key="slot.name" :label="slot.name" :description="slot.description ?? undefined">
      <div class="flex flex-col gap-next-2">
        <div v-if="slot.descriptor.nullable" class="flex items-center">
          <Switch
            :model-value="hasNoValue(slot)"
            size="sm"
            label-position="leading"
            :disabled="disabled"
            :label="t('generator.sessions.setup.noValue')"
            @update:model-value="(v) => setNoValue(slot, v)"
          />
        </div>

        <template v-if="!hasNoValue(slot)">
          <!-- OBJECT: one typed control per declared field. -->
          <div
            v-if="slot.descriptor.base === 'object'"
            class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted/20 p-next-2"
          >
            <FormField
              v-for="field in objectFieldsOf(slot)"
              :key="field.key"
              :label="field.label || field.key"
            >
              <TypedLiteralInput
                :model-value="mapValueOf(slot.name)[field.key]"
                :base="literalBase(field.descriptor.base)"
                :options="fieldOptionsOf(field)"
                :disabled="disabled"
                :aria-label="field.label || field.key"
                @update:model-value="(v) => setMapField(slot.name, field.key, v)"
              />
            </FormField>
            <p v-if="objectFieldsOf(slot).length === 0" class="text-next-xs text-next-muted-foreground">
              {{ t('generator.sessions.setup.noFields') }}
            </p>
          </div>

          <!-- FILE: the fixed {id,name,type,size,url} subfields → a file snapshot. -->
          <div
            v-else-if="slot.descriptor.base === 'file'"
            class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted/20 p-next-2"
          >
            <FormField
              v-for="sub in FILE_SUBFIELDS"
              :key="sub.key"
              :label="t(`generator.sessions.setup.fileSubfield.${sub.key}`)"
            >
              <TypedLiteralInput
                :model-value="mapValueOf(slot.name)[sub.key]"
                :base="literalBase(sub.base)"
                :disabled="disabled"
                :aria-label="t(`generator.sessions.setup.fileSubfield.${sub.key}`)"
                @update:model-value="(v) => setMapField(slot.name, sub.key, v)"
              />
            </FormField>
          </div>

          <!-- ARRAY: a repeatable list of element controls (scalar / enum). -->
          <div v-else-if="slot.descriptor.array" class="flex flex-col gap-next-2">
            <div v-for="(item, index) in asArray(model[slot.name])" :key="index" class="flex items-start gap-next-2">
              <div class="min-w-0 flex-1">
                <TypedLiteralInput
                  :model-value="item"
                  :base="literalBase(slot.descriptor.base)"
                  :options="optionsOf(slot)"
                  :disabled="disabled"
                  :aria-label="slot.name"
                  @update:model-value="(v) => setElement(slot, index, v)"
                />
              </div>
              <Button
                variant="ghost"
                size="icon-sm"
                type="button"
                :disabled="disabled"
                :aria-label="t('generator.sessions.setup.removeItem')"
                @click="removeElement(slot, index)"
              >
                <Icon name="trash" />
              </Button>
            </div>
            <div>
              <Button variant="outline" size="xs" type="button" leading-icon="plus" :disabled="disabled" @click="addElement(slot)">
                {{ t('generator.sessions.setup.addItem') }}
              </Button>
            </div>
          </div>

          <!-- SINGLE scalar / enum. -->
          <TypedLiteralInput
            v-else
            :model-value="model[slot.name]"
            :base="literalBase(slot.descriptor.base)"
            :options="optionsOf(slot)"
            :disabled="disabled"
            :aria-label="slot.name"
            @update:model-value="(v) => setValue(slot.name, v)"
          />
        </template>
      </div>
    </FormField>

    <p v-if="slots.length === 0" class="text-next-xs text-next-muted-foreground">
      {{ t('generator.sessions.setup.noSlots') }}
    </p>
  </div>
</template>
