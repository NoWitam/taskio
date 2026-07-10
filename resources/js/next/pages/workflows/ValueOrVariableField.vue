<script setup lang="ts">
// ValueOrVariableField — the value-or-variable add-on (§4.9.1).
//
// A labelled field that is EITHER a LITERAL control (the field's native input —
// a Select over an enum, a NumberInput, a TextInput; provided via the default
// scoped slot) OR a type-filtered catalog-VARIABLE picker. A trailing `braces`-icon
// `aria-pressed` Button toggle flips between the two modes; in variable mode the
// literal control is replaced by a `Select` of the compatible catalog variables,
// and the chosen variable renders as a VariableChip-STYLED pill (type icon + name,
// removable via a trailing ✕).
//
// v-model is the canonical `WorkflowFieldValue<T>` union
// (`{kind:'literal',value} | {kind:'variable',ref}`); the component always emits a
// normalized union. The HOST feeds `:variables` already filtered to the compatible
// types (via `variablesOfType(catalog, steps, position, [...])` from
// workflowVariables.ts) — this component does NOT know the catalog, only the
// offered variables.
//
// VariableChip REUSE DECISION: the editor's `VariableChip.vue` is a Tiptap NodeView
// (a `NodeViewWrapper` bound to editor state) and its `.next-var-chip` class is
// SCOPED to that SFC, so it is not reusable outside the editor. Per spec §4.9
// ("VariableChip-styled pill") we ECHO its look with module-local styles that reuse
// the SAME semantic tokens (`--color-next-accent` / `--color-next-accent-foreground`,
// §7.7) so the pill reads identically in both themes. The per-type icon comes from
// `variableIcon` (§7.5), which — unlike the editor's primitive-only map — covers
// date/enum/multi too.
import { computed, ref, watch } from 'vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import { useI18n } from '../../app/i18n';
import { variableIcon } from './workflowVariables';
import type { CatalogVariable, WorkflowFieldValue, WorkflowVariableRef } from './types';

const props = withDefaults(
  defineProps<{
    /**
     * The catalog variables offered in variable mode — ALREADY filtered to the
     * compatible types by the host (`variablesOfType`). Empty ⇒ the toggle stays
     * available but the picker shows its empty state.
     */
    variables: CatalogVariable[];
    /** aria-label for the variable-mode `Select` (the field's own label context). */
    pickerLabel?: string;
    /** Placeholder for the variable-mode `Select`. */
    pickerPlaceholder?: string;
    /** Disable the whole field (toggle + controls). */
    disabled?: boolean;
  }>(),
  { disabled: false },
);

const model = defineModel<WorkflowFieldValue | null>({ default: null });

const { t } = useI18n();

/** The active mode, derived from the union kind (literal by default). */
const isVariable = computed(() => model.value?.kind === 'variable');

/** The picked variable's ref (variable mode only). */
const pickedRef = computed<WorkflowVariableRef | null>(() =>
  model.value?.kind === 'variable' ? model.value.ref : null,
);

/** The full catalog variable for the picked ref (for the chip name/icon). */
const pickedVariable = computed<CatalogVariable | null>(() => {
  const ref = pickedRef.value;
  if (!ref) return null;
  return props.variables.find((v) => v.path === ref.path) ?? null;
});

/** The chip label: the catalog name, or the raw path when the var is off-list. */
const pickedLabel = computed(() => pickedVariable.value?.name ?? pickedRef.value?.path ?? '');

/** The chip icon: the TRUE workflow type's glyph (§7.5). */
const pickedIcon = computed(() =>
  variableIcon(pickedVariable.value?.type ?? pickedRef.value?.type ?? 'text'),
);

/** The literal value exposed to the default slot (null in variable mode). */
const literalValue = computed(() =>
  model.value?.kind === 'literal' ? model.value.value : null,
);

/** The variable-picker options (id-path value, catalog name label, type icon). */
const variableOptions = computed<SelectOption[]>(() =>
  props.variables.map((v) => ({
    value: v.path,
    label: v.name,
    icon: variableIcon(v.type),
  })),
);

/** The currently-selected variable path for the picker `Select`. */
const selectedPath = computed<string | null>({
  get: () => pickedRef.value?.path ?? null,
  set: (path) => {
    if (!path) return; // clearing the Select is handled by the chip ✕ instead
    const variable = props.variables.find((v) => v.path === path);
    if (!variable) return;
    model.value = {
      kind: 'variable',
      ref: { source: variable.source, path: variable.path, type: variable.type },
    };
  },
});

/** Update the LITERAL value from the slot control, keeping the union normalized. */
function setLiteral(value: unknown): void {
  model.value = { kind: 'literal', value };
}

// The toggle's pressed state reflects EITHER an active variable ref OR the explicit
// "I want variable mode but haven't picked yet" intent (so the picker shows before a
// selection exists). Any concrete union value (literal, or a picked ref) resets the
// intent, since the mode is then determined by the union itself.
const intendVariable = ref(model.value?.kind === 'variable');
watch(
  () => model.value?.kind,
  (kind) => {
    if (kind) intendVariable.value = kind === 'variable';
  },
);

/** Whether the variable UI (chip or picker) is shown vs the literal slot. */
const showPicker = computed(() => isVariable.value || intendVariable.value);
const togglePressed = computed(() => showPicker.value);

/** Flip modes. Literal mode preserves any current literal; variable mode opens the picker. */
function onToggle(): void {
  if (props.disabled) return;
  if (showPicker.value) {
    intendVariable.value = false;
    model.value = { kind: 'literal', value: literalValue.value ?? null };
  } else {
    intendVariable.value = true;
  }
}

/** Remove the picked variable → return to literal mode. */
function removeVariable(): void {
  if (props.disabled) return;
  intendVariable.value = false;
  model.value = { kind: 'literal', value: null };
}
</script>

<template>
  <div class="flex items-start gap-next-1">
    <div class="min-w-0 flex-1">
      <!-- LITERAL mode: the field's native control, provided by the host via the
           default slot with { value, setValue } so any literal input (Select /
           NumberInput / TextInput) plugs in unchanged. -->
      <slot
        v-if="!showPicker"
        :value="literalValue"
        :set-value="setLiteral"
        :disabled="disabled"
      />

      <!-- VARIABLE mode: a chip once picked, else the type-filtered picker. -->
      <template v-else>
        <span
          v-if="pickedRef"
          class="next-wf-var-pill"
          :class="disabled ? 'opacity-60' : ''"
        >
          <Icon :name="pickedIcon" class="next-wf-var-pill__icon" aria-hidden="true" />
          <span class="next-wf-var-pill__label">{{ pickedLabel }}</span>
          <!-- Trailing ✕ (X-before-nothing trailing rule): removes the variable. -->
          <Button
            variant="ghost"
            size="icon-xs"
            leading-icon="x"
            class="next-wf-var-pill__remove"
            :disabled="disabled"
            :aria-label="t('workflows.field.removeVariable')"
            @click="removeVariable"
          />
        </span>
        <Select
          v-else
          v-model="selectedPath"
          :options="variableOptions"
          :disabled="disabled"
          leading-icon="braces"
          :placeholder="pickerPlaceholder ?? t('workflows.field.pickVariable')"
          :aria-label="pickerLabel ?? t('workflows.field.pickVariable')"
        />
      </template>
    </div>

    <!-- The braces toggle: a real aria-pressed Button announcing its state. -->
    <Button
      variant="ghost"
      size="icon-xs"
      leading-icon="braces"
      :disabled="disabled"
      :aria-pressed="togglePressed"
      :aria-label="togglePressed ? t('workflows.field.useLiteral') : t('workflows.field.useVariable')"
      @click="onToggle"
    />
  </div>
</template>

<style scoped>
/* VariableChip-STYLED pill (§4.9 / §7.7). Echoes ui/editor/extensions/VariableChip
   using the SAME accent tokens so it reads identically light/dark, without
   importing the editor's scoped NodeView styles. */
.next-wf-var-pill {
  display: inline-flex;
  min-width: 0;
  max-width: 100%;
  align-items: center;
  gap: 0.25rem;
  padding: 0.125rem 0.25rem 0.125rem 0.5rem;
  border-radius: var(--radius-next-md);
  background-color: var(--color-next-accent);
  color: var(--color-next-accent-foreground);
  font-weight: var(--font-weight-next-medium);
  font-size: 0.9em;
  line-height: 1.4;
}
.next-wf-var-pill__icon {
  flex-shrink: 0;
  opacity: 0.85;
}
.next-wf-var-pill__label {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.next-wf-var-pill__remove {
  flex-shrink: 0;
  color: inherit;
}
</style>
