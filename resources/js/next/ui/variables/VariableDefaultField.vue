<script setup lang="ts">
// VariableDefaultField — the "Default when empty" block of a variable REFERENCE: the optional
// literal a backend substitutes when the referenced value resolves empty (null/'').
//
// Promoted VERBATIM from ValueOrVariableField (§refinement 1) in B3, so every reference-editing
// surface gets the identical, already-agreed rules:
//   • it renders ONLY for a NULLABLE variable whose base is TYPEABLE (text/number/boolean/date/
//     enum). A non-nullable variable can never resolve empty, so a default is meaningless there;
//     a file/object/time base has no single literal control.
//   • the control is TYPED to that base — it is `TypedLiteralInput`, the same control the globals
//     editor uses.
//   • BOOLEAN is a TRI-STATE (no default / yes / no), NOT a switch, so "no default" can never
//     silently serialize `false`.
//   • EMPTY normalises to `null`, which is the host's signal to OMIT the key entirely — a
//     reference without a default must stay byte-identical to one that never had one.
//
// Renders NOTHING when the gate is closed, so a host can mount it unconditionally.
import { computed } from 'vue';
import Select from '../forms/Select.vue';
import TypedLiteralInput from './TypedLiteralInput.vue';
import { useI18n } from '../../app/i18n';
import type {
  VariableBase,
  VariableDescriptorOption,
  VariableLiteral,
  VariableLiteralBase,
} from './types';

const props = withDefaults(
  defineProps<{
    /** The referenced variable's structural base (`VariableNode.base`). */
    base?: VariableBase | null;
    /** Whether the referenced variable may resolve empty (`VariableNode.nullable`). */
    nullable?: boolean;
    /** The referenced variable's enum choices (`VariableNode.options`). */
    options?: VariableDescriptorOption[];
    disabled?: boolean;
  }>(),
  { base: null, nullable: false, options: () => [], disabled: false },
);

/** `null` ⇒ NO default (the host omits the key). `false` / `0` are meaningful values. */
const model = defineModel<VariableLiteral>({ default: null });

const { t } = useI18n();

/** The scalar bases a default can be authored for; `enum` is handled alongside them. */
const TYPEABLE_BASES: VariableLiteralBase[] = ['text', 'number', 'boolean', 'date', 'enum'];

/** The typed-control base, or null when the variable's base has no single literal control. */
const literalBase = computed<VariableLiteralBase | null>(() => {
  const base = props.base;
  return base && (TYPEABLE_BASES as string[]).includes(base) ? (base as VariableLiteralBase) : null;
});

/** The gate: a default is only ever offered for a NULLABLE variable with a typeable base. */
const visible = computed(() => props.nullable === true && literalBase.value !== null);

/**
 * The typed control's binding. Writing an EMPTY value (a cleared text box, a cleared date /
 * enum Select — all of which emit `''`) normalises to `null`, so "cleared" and "never set" are
 * the same state and the host has a single omit rule.
 */
const literalValue = computed<unknown>({
  get: () => model.value,
  set: (value) => (model.value = (value === '' || value === undefined ? null : value) as VariableLiteral),
});

/** Tri-state boolean binding ('' = no default, 'true' / 'false') over the model. */
const booleanValue = computed<string>({
  get: () => (model.value === true ? 'true' : model.value === false ? 'false' : ''),
  set: (v) => (model.value = v === 'true' ? true : v === 'false' ? false : null),
});
const booleanOptions = computed(() => [
  { value: '', label: t('workflows.field.defaultNone') },
  { value: 'true', label: t('common.yes') },
  { value: 'false', label: t('common.no') },
]);
</script>

<template>
  <!-- `data-vov-default` is the long-standing hook the value-or-variable specs query; it is kept
       so the block stays addressable wherever it is now hosted. -->
  <div v-if="visible" class="flex flex-col gap-next-1_5" data-vov-default>
    <span class="text-next-sm font-next-medium text-next-fg">{{ t('workflows.field.defaultLabel') }}</span>
    <div class="[&>*]:w-full">
      <Select
        v-if="literalBase === 'boolean'"
        v-model="booleanValue"
        :options="booleanOptions"
        :disabled="disabled"
        :aria-label="t('workflows.field.defaultLabel')"
      />
      <TypedLiteralInput
        v-else
        v-model="literalValue"
        :base="(literalBase as VariableLiteralBase)"
        :options="options"
        :disabled="disabled"
        :aria-label="t('workflows.field.defaultLabel')"
      />
    </div>
    <p class="text-next-xs text-next-muted-foreground">{{ t('workflows.field.defaultHint') }}</p>
  </div>
</template>
