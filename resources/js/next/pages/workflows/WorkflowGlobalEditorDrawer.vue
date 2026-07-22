<script setup lang="ts">
// WorkflowGlobalEditorDrawer — the create / edit form for a workflow GLOBAL (Phase 3).
//
// A right Drawer hosting three sections:
//   1. IDENTITY — `name` (required) + an auto-derived, editable `key` slug (shown with
//      its live `globals.<key>` reference).
//   2. TYPE BUILDER — a `base` picker (text|number|boolean|date|enum|object) + orthogonal
//      `nullable` / `array` toggles, an ENUM options editor ({key,label} rows) and an
//      OBJECT fields editor ({key,label,base} rows, scalar children only — nested
//      object/array/enum children are deferred).
//   3. VALUE — a control that ADAPTS to the chosen type (text→TextInput, number→NumberInput,
//      boolean→Switch, date→DatePicker, enum→Select; array→a repeatable list; object→one
//      control per field; nullable→a "no value" toggle).
//
// The form is PURE: it validates client-side (mirroring WorkflowGlobalTypeValidator — the
// server stays authoritative) and, when valid, EMITS a `submit` payload
// `{ name, key?, descriptor, value }`. The parent (WorkflowGlobalsView) owns the store call,
// toasts, and feeds back 422 `serverErrors`. Nothing here is trusted as authorization.
import { computed, onMounted, ref, watch } from 'vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Switch from '../../ui/forms/Switch.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import Alert from '../../ui/feedback/Alert.vue';
import WorkflowGlobalValueField from './WorkflowGlobalValueField.vue';
import { useI18n } from '../../app/i18n';
import type { IconName } from '../../ui/primitives/icons';
import {
  AUTHORABLE_BASES,
  OBJECT_FIELD_BASES,
  descriptorToDraft,
  draftToDescriptor,
  defaultSingleValue,
  defaultValueForDescriptor,
  emptyFieldDraft,
  emptyOptionDraft,
  isSafeKey,
  slugifyKey,
  validateSingleValue,
  validateValueAgainstDescriptor,
  type EnumOptionDraft,
  type ObjectFieldDraft,
} from './workflowGlobals';
import type {
  WorkflowGlobal,
  WorkflowGlobalBase,
  WorkflowGlobalDescriptor,
  WorkflowGlobalScalarBase,
  WorkflowGlobalWritePayload,
} from './types';

const props = withDefaults(
  defineProps<{
    /** The global being edited, or null/undefined for a create. */
    global?: WorkflowGlobal | null;
    /** True while the parent's store call is in flight (disables Save + inputs). */
    submitting?: boolean;
    /** Backend 422 field errors keyed by dotted path (name / key / descriptor.* / value.*). */
    serverErrors?: Record<string, string> | null;
  }>(),
  { global: null, submitting: false, serverErrors: null },
);

const emit = defineEmits<{
  submit: [WorkflowGlobalWritePayload];
  close: [];
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const isEdit = computed(() => props.global != null);

// --- Base per-type glyphs ---------------------------------------------------
const BASE_ICON: Record<WorkflowGlobalBase, IconName> = {
  text: 'type',
  number: 'hash',
  boolean: 'check-circle',
  date: 'calendar',
  enum: 'list',
  object: 'braces',
};

// --- Identity ---------------------------------------------------------------
const name = ref('');
const keyInput = ref('');
const keyTouched = ref(false);

/** The key input tracks the name slug until the user edits it (then it's independent). */
const keyModel = computed<string>({
  get: () => (keyTouched.value ? keyInput.value : slugifyKey(name.value)),
  set: (value) => {
    keyTouched.value = true;
    keyInput.value = value;
  },
});
/** The RESOLVED key sent/validated (trimmed derived or explicit). */
const resolvedKey = computed(() =>
  (keyTouched.value ? keyInput.value : slugifyKey(name.value)).trim(),
);

// --- Type draft -------------------------------------------------------------
const base = ref<WorkflowGlobalBase>('text');
const nullable = ref(false);
const array = ref(false);
const options = ref<EnumOptionDraft[]>([emptyOptionDraft()]);
const fields = ref<ObjectFieldDraft[]>([emptyFieldDraft()]);

const baseOptions = computed<SegmentOption<WorkflowGlobalBase>[]>(() =>
  AUTHORABLE_BASES.map((b) => ({ value: b, label: t(`workflows.globals.base.${b}`), icon: BASE_ICON[b] })),
);
const fieldBaseOptions = computed<SelectOption[]>(() =>
  OBJECT_FIELD_BASES.map((b) => ({ value: b, label: t(`workflows.globals.base.${b}`), icon: BASE_ICON[b] })),
);

/** ARRAY is not offered for an object base (array-of-object authoring is deferred). */
const arrayDisabled = computed(() => base.value === 'object');

/** The authoritative descriptor built from the draft (the write body's `descriptor`). */
const descriptor = computed<WorkflowGlobalDescriptor>(() =>
  draftToDescriptor({
    base: base.value,
    nullable: nullable.value,
    array: array.value,
    options: options.value,
    fields: fields.value,
  }),
);

// --- Value ------------------------------------------------------------------
const value = ref<unknown>('');

let seeding = false;

/**
 * Keep the value shape aligned with the type as the draft changes (reset an
 * incompatible leaf to its base default, but PRESERVE anything still valid). Never
 * runs while seeding an edit. Value ⇏ descriptor, so there is no feedback loop.
 */
watch(
  descriptor,
  (next) => {
    if (seeding) return;
    value.value = reconcileValue(next, value.value);
  },
  { deep: true },
);

// An object base has no array form (deferred) — force the flag off when it is picked.
watch(base, (next) => {
  if (next === 'object' && array.value) array.value = false;
});

function reconcileValue(desc: WorkflowGlobalDescriptor, current: unknown): unknown {
  if (current === null || current === undefined) {
    return desc.nullable ? null : defaultValueForDescriptor(desc);
  }
  if (desc.array) {
    if (!Array.isArray(current)) return [];
    const element: WorkflowGlobalDescriptor = { ...desc, array: false, nullable: false };
    return current.map((item) => reconcileSingle(element, item));
  }
  if (desc.base === 'object') {
    const source = current && typeof current === 'object' && !Array.isArray(current)
      ? (current as Record<string, unknown>)
      : {};
    const out: Record<string, unknown> = {};
    for (const field of desc.fields ?? []) {
      out[field.key] = reconcileSingle(field.descriptor, source[field.key]);
    }
    return out;
  }
  return reconcileSingle(desc, current);
}

/** Keep a single value if it still matches the base, else fall back to the base default. */
function reconcileSingle(desc: WorkflowGlobalDescriptor, current: unknown): unknown {
  if (current !== undefined && current !== null && validateSingleValue(desc, current) === null) {
    return current;
  }
  return defaultSingleValue(desc.base, desc.options ?? []);
}

/**
 * The "no value (null)" toggle — only meaningful when the type is nullable. A NON-nullable
 * type may legitimately hold null as its unset state (e.g. an empty number) WITHOUT hiding
 * the control, so this reads false there and the input stays visible.
 */
const hasNoValue = computed<boolean>({
  get: () => nullable.value && value.value === null,
  set: (noValue) => {
    value.value = noValue ? null : defaultValueForDescriptor(descriptor.value);
  },
});

// --- Array element helpers --------------------------------------------------
const elementList = computed<unknown[]>(() => (Array.isArray(value.value) ? value.value : []));
/** The element base for the array value editor (scalar/enum — object arrays are deferred). */
const elementBase = computed(() => base.value as WorkflowGlobalScalarBase | 'enum');

function setElement(index: number, next: unknown): void {
  const list = [...elementList.value];
  list[index] = next;
  value.value = list;
}
function addElement(): void {
  value.value = [...elementList.value, defaultSingleValue(base.value, descriptor.value.options ?? [])];
}
function removeElement(index: number): void {
  value.value = elementList.value.filter((_, i) => i !== index);
}

// --- Object field value helpers ---------------------------------------------
const objectValue = computed<Record<string, unknown>>(() =>
  value.value && typeof value.value === 'object' && !Array.isArray(value.value)
    ? (value.value as Record<string, unknown>)
    : {},
);
function setObjectField(key: string, next: unknown): void {
  value.value = { ...objectValue.value, [key]: next };
}

// --- Enum options editor ----------------------------------------------------
function addOption(): void {
  options.value = [...options.value, emptyOptionDraft()];
}
function removeOption(id: string): void {
  options.value = options.value.length > 1 ? options.value.filter((o) => o.id !== id) : options.value;
}

// --- Object fields editor ---------------------------------------------------
function addField(): void {
  fields.value = [...fields.value, emptyFieldDraft()];
}
function removeField(id: string): void {
  fields.value = fields.value.length > 1 ? fields.value.filter((f) => f.id !== id) : fields.value;
}

// --- Seeding ----------------------------------------------------------------
function seed(): void {
  seeding = true;
  const global = props.global;
  clearClientErrors();
  if (global) {
    name.value = global.name;
    keyInput.value = global.key;
    keyTouched.value = true;
    const draft = descriptorToDraft(global.descriptor);
    base.value = draft.base;
    nullable.value = draft.nullable;
    array.value = draft.array;
    options.value = draft.options;
    fields.value = draft.fields;
    value.value = global.value;
  } else {
    name.value = '';
    keyInput.value = '';
    keyTouched.value = false;
    base.value = 'text';
    nullable.value = false;
    array.value = false;
    options.value = [emptyOptionDraft()];
    fields.value = [emptyFieldDraft()];
    value.value = '';
  }
  // Release the guard AFTER the reactive flush so the descriptor watch doesn't wipe
  // the seeded value on the same tick.
  void Promise.resolve().then(() => {
    seeding = false;
  });
}

// Re-seed whenever the drawer OPENS (a future toggle). onMounted covers the case where
// the drawer is mounted already-open (both run after setup, so the error refs exist).
watch(open, (isOpen) => {
  if (isOpen) seed();
});
onMounted(() => {
  if (open.value) seed();
});

// --- Validation + submit ----------------------------------------------------
const clientNameError = ref<string | null>(null);
const clientKeyError = ref<string | null>(null);
const clientTypeError = ref<string | null>(null);
const clientValueError = ref<string | null>(null);

function clearClientErrors(): void {
  clientNameError.value = null;
  clientKeyError.value = null;
  clientTypeError.value = null;
  clientValueError.value = null;
}

/** The first server error whose key equals `prefix` or starts with `prefix.`. */
function firstServerError(prefix: string): string | null {
  const errors = props.serverErrors;
  if (!errors) return null;
  for (const [key, message] of Object.entries(errors)) {
    if (key === prefix || key.startsWith(`${prefix}.`)) return message;
  }
  return null;
}

const nameErrorText = computed(
  () => props.serverErrors?.name ?? (clientNameError.value ? t(clientNameError.value) : null),
);
const keyErrorText = computed(
  () => props.serverErrors?.key ?? (clientKeyError.value ? t(clientKeyError.value) : null),
);
const typeErrorText = computed(
  () => firstServerError('descriptor') ?? (clientTypeError.value ? t(clientTypeError.value) : null),
);
const valueErrorText = computed(
  () => firstServerError('value') ?? (clientValueError.value ? t(clientValueError.value) : null),
);

/** Validate the enum options / object fields structure (mirrors the backend). */
function validateType(): boolean {
  if (base.value === 'enum') {
    const keys = options.value.map((o) => o.key.trim());
    if (keys.some((k) => k === '')) {
      clientTypeError.value = 'workflows.globals.form.errors.enumKeyRequired';
      return false;
    }
    if (new Set(keys).size !== keys.length) {
      clientTypeError.value = 'workflows.globals.form.errors.enumDuplicate';
      return false;
    }
  }
  if (base.value === 'object') {
    const keys = fields.value.map((f) => f.key.trim());
    if (keys.some((k) => !isSafeKey(k))) {
      clientTypeError.value = 'workflows.globals.form.errors.fieldKeyInvalid';
      return false;
    }
    if (new Set(keys).size !== keys.length) {
      clientTypeError.value = 'workflows.globals.form.errors.fieldDuplicate';
      return false;
    }
  }
  return true;
}

function submit(): void {
  clearClientErrors();
  let ok = true;

  if (name.value.trim() === '') {
    clientNameError.value = 'workflows.globals.form.errors.nameRequired';
    ok = false;
  }

  const key = resolvedKey.value;
  if (key === '') {
    clientKeyError.value = 'workflows.globals.form.errors.keyRequired';
    ok = false;
  } else if (!isSafeKey(key)) {
    clientKeyError.value = 'workflows.globals.form.errors.keyInvalid';
    ok = false;
  }

  if (!validateType()) ok = false;

  const valueCode = validateValueAgainstDescriptor(descriptor.value, value.value);
  if (valueCode) {
    clientValueError.value = `workflows.globals.valueError.${valueCode}`;
    ok = false;
  }

  if (!ok) return;

  const payload: WorkflowGlobalWritePayload = {
    name: name.value.trim(),
    descriptor: descriptor.value,
    value: value.value,
  };
  // Send the key ONLY when the user pinned it; otherwise let the backend slug it from
  // the name (identical to our derived slug), keeping the payload minimal.
  if (keyTouched.value && key !== '') payload.key = key;

  emit('submit', payload);
}

function cancel(): void {
  open.value = false;
  emit('close');
}
</script>

<template>
  <Drawer
    v-model:open="open"
    side="right"
    size="xl"
    :show-close="false"
    :aria-label="isEdit ? t('workflows.globals.form.editTitle') : t('workflows.globals.form.createTitle')"
  >
    <template #title>
      {{ isEdit ? t('workflows.globals.form.editTitle') : t('workflows.globals.form.createTitle') }}
    </template>

    <form class="flex flex-col gap-next-6" @submit.prevent="submit">
      <!-- 1. Identity ---------------------------------------------------- -->
      <section class="flex flex-col gap-next-4">
        <FormField
          :label="t('workflows.globals.form.nameLabel')"
          required
          :error="nameErrorText ?? undefined"
        >
          <TextInput
            v-model="name"
            :disabled="submitting"
            :placeholder="t('workflows.globals.form.namePlaceholder')"
          />
        </FormField>

        <FormField
          :label="t('workflows.globals.form.keyLabel')"
          :description="t('workflows.globals.form.keyHint')"
          :error="keyErrorText ?? undefined"
        >
          <TextInput
            v-model="keyModel"
            :disabled="submitting"
            leading-icon="braces"
            :placeholder="t('workflows.globals.form.keyPlaceholder')"
          />
        </FormField>
        <p class="-mt-next-2 flex items-center gap-next-1 text-next-xs text-next-muted-foreground">
          {{ t('workflows.globals.form.referenceHint') }}
          <code class="rounded-next-sm bg-next-muted px-next-1 py-px font-next-mono text-next-fg">
            globals.{{ resolvedKey || '…' }}
          </code>
        </p>
      </section>

      <!-- 2. Type builder ------------------------------------------------ -->
      <section class="flex flex-col gap-next-4">
        <div class="flex flex-col gap-next-1_5">
          <span class="text-next-sm font-next-medium text-next-fg">{{ t('workflows.globals.form.typeLabel') }}</span>
          <SegmentedControl
            v-model="base"
            :options="baseOptions"
            size="sm"
            :disabled="submitting"
            :aria-label="t('workflows.globals.form.typeLabel')"
          />
        </div>

        <div class="flex flex-wrap gap-x-next-6 gap-y-next-3">
          <Switch
            v-model="array"
            :disabled="submitting || arrayDisabled"
            :label="t('workflows.globals.form.arrayLabel')"
          />
          <Switch
            v-model="nullable"
            :disabled="submitting"
            :label="t('workflows.globals.form.nullableLabel')"
          />
        </div>
        <p v-if="arrayDisabled" class="-mt-next-2 text-next-xs text-next-muted-foreground">
          {{ t('workflows.globals.form.arrayObjectNote') }}
        </p>

        <!-- Enum options editor -->
        <div v-if="base === 'enum'" class="flex flex-col gap-next-2 rounded-next-lg border border-next-border p-next-3">
          <span class="text-next-sm font-next-medium text-next-fg">{{ t('workflows.globals.form.optionsLabel') }}</span>
          <div
            v-for="option in options"
            :key="option.id"
            class="flex items-start gap-next-2"
          >
            <TextInput
              v-model="option.key"
              size="sm"
              :disabled="submitting"
              :aria-label="t('workflows.globals.form.optionKey')"
              :placeholder="t('workflows.globals.form.optionKey')"
            />
            <TextInput
              v-model="option.label"
              size="sm"
              :disabled="submitting"
              :aria-label="t('workflows.globals.form.optionLabel')"
              :placeholder="t('workflows.globals.form.optionLabel')"
            />
            <Button
              variant="ghost"
              size="icon-sm"
              type="button"
              :disabled="submitting || options.length <= 1"
              :aria-label="t('workflows.globals.form.removeOption')"
              @click="removeOption(option.id)"
            >
              <Icon name="trash" />
            </Button>
          </div>
          <div>
            <Button variant="outline" size="sm" type="button" leading-icon="plus" :disabled="submitting" @click="addOption">
              {{ t('workflows.globals.form.addOption') }}
            </Button>
          </div>
        </div>

        <!-- Object fields editor (scalar children only) -->
        <div v-if="base === 'object'" class="flex flex-col gap-next-2 rounded-next-lg border border-next-border p-next-3">
          <span class="text-next-sm font-next-medium text-next-fg">{{ t('workflows.globals.form.fieldsLabel') }}</span>
          <div
            v-for="field in fields"
            :key="field.id"
            class="flex items-start gap-next-2"
          >
            <TextInput
              v-model="field.key"
              size="sm"
              :disabled="submitting"
              :aria-label="t('workflows.globals.form.fieldKey')"
              :placeholder="t('workflows.globals.form.fieldKey')"
            />
            <TextInput
              v-model="field.label"
              size="sm"
              :disabled="submitting"
              :aria-label="t('workflows.globals.form.fieldLabelInput')"
              :placeholder="t('workflows.globals.form.fieldLabelInput')"
            />
            <div class="w-32 shrink-0">
              <Select
                v-model="field.base"
                :options="fieldBaseOptions"
                size="sm"
                :disabled="submitting"
                :aria-label="t('workflows.globals.form.fieldType')"
              />
            </div>
            <Button
              variant="ghost"
              size="icon-sm"
              type="button"
              :disabled="submitting || fields.length <= 1"
              :aria-label="t('workflows.globals.form.removeField')"
              @click="removeField(field.id)"
            >
              <Icon name="trash" />
            </Button>
          </div>
          <div>
            <Button variant="outline" size="sm" type="button" leading-icon="plus" :disabled="submitting" @click="addField">
              {{ t('workflows.globals.form.addField') }}
            </Button>
          </div>
        </div>

        <Alert v-if="typeErrorText" variant="danger" size="sm">{{ typeErrorText }}</Alert>
      </section>

      <!-- 3. Value ------------------------------------------------------- -->
      <section class="flex flex-col gap-next-3">
        <div class="flex items-center justify-between gap-next-3">
          <span class="text-next-sm font-next-medium text-next-fg">{{ t('workflows.globals.form.valueLabel') }}</span>
          <Switch
            v-if="nullable"
            v-model="hasNoValue"
            size="sm"
            :disabled="submitting"
            label-position="leading"
            :label="t('workflows.globals.form.noValue')"
          />
        </div>

        <template v-if="!hasNoValue">
          <!-- ARRAY: a repeatable list of element controls. -->
          <div v-if="array" class="flex flex-col gap-next-2">
            <div
              v-for="(item, index) in elementList"
              :key="index"
              class="flex items-start gap-next-2"
            >
              <div class="min-w-0 flex-1">
                <WorkflowGlobalValueField
                  :model-value="item"
                  :base="elementBase"
                  :options="descriptor.options ?? []"
                  :disabled="submitting"
                  :aria-label="t('workflows.globals.form.itemLabel', '', { index: index + 1 })"
                  @update:model-value="(v) => setElement(index, v)"
                />
              </div>
              <Button
                variant="ghost"
                size="icon-sm"
                type="button"
                :disabled="submitting"
                :aria-label="t('workflows.globals.form.removeItem')"
                @click="removeElement(index)"
              >
                <Icon name="trash" />
              </Button>
            </div>
            <p v-if="elementList.length === 0" class="text-next-xs text-next-muted-foreground">
              {{ t('workflows.globals.form.emptyList') }}
            </p>
            <div>
              <Button variant="outline" size="sm" type="button" leading-icon="plus" :disabled="submitting" @click="addElement">
                {{ t('workflows.globals.form.addItem') }}
              </Button>
            </div>
          </div>

          <!-- OBJECT: one control per declared field. -->
          <div v-else-if="base === 'object'" class="flex flex-col gap-next-3 rounded-next-lg border border-next-border p-next-3">
            <FormField
              v-for="field in fields"
              :key="field.id"
              :label="field.label.trim() || field.key || t('workflows.globals.form.fieldFallback')"
            >
              <WorkflowGlobalValueField
                :model-value="objectValue[field.key]"
                :base="field.base"
                :disabled="submitting"
                :aria-label="field.label.trim() || field.key"
                @update:model-value="(v) => setObjectField(field.key, v)"
              />
            </FormField>
          </div>

          <!-- SINGLE scalar / enum. -->
          <WorkflowGlobalValueField
            v-else
            v-model="value"
            :base="(base as WorkflowGlobalScalarBase | 'enum')"
            :options="descriptor.options ?? []"
            :disabled="submitting"
            :invalid="!!valueErrorText"
            :aria-label="t('workflows.globals.form.valueLabel')"
          />
        </template>

        <Alert v-if="valueErrorText" variant="danger" size="sm">{{ valueErrorText }}</Alert>
      </section>
    </form>

    <template #footer>
      <Button variant="outline" type="button" :disabled="submitting" @click="cancel">
        {{ t('common.cancel') }}
      </Button>
      <Button variant="primary" type="button" :loading="submitting" @click="submit">
        {{ isEdit ? t('common.save') : t('workflows.globals.form.create') }}
      </Button>
    </template>
  </Drawer>
</template>
