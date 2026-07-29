<script setup lang="ts">
// TemplateSlotsPanel — the repeatable builder of a template's DECLARED typed SLOTS, modeled on
// FunctionEditorDrawer's args builder. Each slot row authors {name, description, descriptor}: a
// safe identifier NAME (validated client-side against the SAME rule the backend enforces — unique,
// safe, never a reserved reference root), an optional DESCRIPTION, and a TYPE (base Select +
// orthogonal nullable / array toggles + an enum options / object fields sub-editor). The descriptor
// is built by the shared consts type model, so a slot's type and a const's type stay one
// implementation. An `object` slot authors nested FIELDS (exactly like an object const); a `file`
// slot is a FIXED composite (no user-authored fields — its subfields are backend-fixed).
//
// PURE-ish: the drafts are a v-model owned by the editor (so it can post them to the catalog /
// preview live); this panel only renders + mutates them and surfaces client + server per-row errors.
import { computed } from 'vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Switch from '../../ui/forms/Switch.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import { OBJECT_FIELD_BASES, emptyFieldDraft, emptyOptionDraft } from '../variables/consts';
import {
  SLOT_BASES,
  emptySlotDraft,
  isStructuralBase,
  slotFieldError,
  validateSlotDrafts,
  type SlotBase,
  type SlotDraft,
} from './templateSlots';
import { useI18n } from '../../app/i18n';
import type { IconName } from '../../ui/primitives/icons';

const props = withDefaults(
  defineProps<{
    /** True while the parent's store call is in flight (disables the controls). */
    submitting?: boolean;
    /** Backend 422 field errors keyed by dotted path (`slots.<index>.name` / `.description` / `.descriptor`). */
    serverErrors?: Record<string, string> | null;
  }>(),
  { submitting: false, serverErrors: null },
);

const slots = defineModel<SlotDraft[]>({ default: () => [] });

const { t } = useI18n();

// --- Base Select (reuses the consts base labels/icons for one type vocabulary) --------------
const BASE_ICON: Record<SlotBase, IconName> = {
  text: 'type',
  number: 'hash',
  boolean: 'check-circle',
  date: 'calendar',
  enum: 'list',
  object: 'braces',
  file: 'file-text',
};
const baseOptions = computed<SelectOption[]>(() =>
  SLOT_BASES.map((base) => ({ value: base, label: t(`variables.consts.base.${base}`), icon: BASE_ICON[base] })),
);
const fieldBaseOptions = computed<SelectOption[]>(() =>
  OBJECT_FIELD_BASES.map((base) => ({ value: base, label: t(`variables.consts.base.${base}`), icon: BASE_ICON[base] })),
);

// --- Row add / remove -------------------------------------------------------
function addSlot(): void {
  slots.value = [...slots.value, emptySlotDraft()];
}
function removeSlot(id: string): void {
  slots.value = slots.value.filter((slot) => slot.id !== id);
}

/** Picking a structural base (object / file) clears the array modifier (array-of-container deferred). */
function onBaseChange(slot: SlotDraft): void {
  if (isStructuralBase(slot.base)) slot.array = false;
}

// --- Enum options sub-editor ------------------------------------------------
function addOption(slot: SlotDraft): void {
  slot.options = [...slot.options, emptyOptionDraft()];
}
function removeOption(slot: SlotDraft, id: string): void {
  slot.options = slot.options.length > 1 ? slot.options.filter((option) => option.id !== id) : slot.options;
}

// --- Object fields sub-editor -----------------------------------------------
function addField(slot: SlotDraft): void {
  slot.fields = [...slot.fields, emptyFieldDraft()];
}
function removeField(slot: SlotDraft, id: string): void {
  slot.fields = slot.fields.length > 1 ? slot.fields.filter((field) => field.id !== id) : slot.fields;
}

// --- Reactive client validation (index → error code), mirroring the backend -----------------
const slotErrors = computed(() => validateSlotDrafts(slots.value));

/** The message under a slot NAME: the server error wins, else the localized client code. */
function nameErrorText(index: number): string | null {
  const server = props.serverErrors?.[`slots.${index}.name`];
  if (server) return server;
  const code = slotErrors.value[index];
  return code ? t(`generator.templates.slots.errors.${code}`) : null;
}
/** A server error on the slot's description / descriptor (no client check — server authoritative). */
function descriptionErrorText(index: number): string | null {
  return props.serverErrors?.[`slots.${index}.description`] ?? null;
}
function descriptorErrorText(index: number): string | null {
  const server = props.serverErrors ?? {};
  for (const [key, message] of Object.entries(server)) {
    if (key === `slots.${index}.descriptor` || key.startsWith(`slots.${index}.descriptor.`)) return message;
  }
  return null;
}
/** The message under an OBJECT slot's fields editor: the client field-key check (server authoritative). */
function fieldErrorText(slot: SlotDraft): string | null {
  const code = slotFieldError(slot);
  return code ? t(`generator.templates.slots.errors.${code}`) : null;
}
</script>

<template>
  <section class="flex flex-col gap-next-3">
    <div class="flex flex-col gap-next-0_5">
      <span class="text-next-sm font-next-medium text-next-fg">{{ t('generator.templates.slots.label') }}</span>
      <span class="text-next-xs text-next-muted-foreground">{{ t('generator.templates.slots.hint') }}</span>
    </div>

    <div
      v-for="(slot, index) in slots"
      :key="slot.id"
      class="flex flex-col gap-next-2 rounded-next-lg border border-next-border p-next-3"
    >
      <!-- Name + base + remove -->
      <div class="flex items-start gap-next-2">
        <div class="min-w-0 flex-1">
          <TextInput
            v-model="slot.name"
            size="sm"
            class="font-next-mono"
            :disabled="submitting"
            :aria-invalid="!!nameErrorText(index)"
            leading-icon="braces"
            :aria-label="t('generator.templates.slots.name')"
            :placeholder="t('generator.templates.slots.namePlaceholder')"
          />
        </div>
        <div class="w-36 shrink-0">
          <Select
            v-model="slot.base"
            :options="baseOptions"
            size="sm"
            :disabled="submitting"
            :aria-label="t('generator.templates.slots.type')"
            @update:model-value="onBaseChange(slot)"
          />
        </div>
        <Button
          variant="ghost"
          size="icon-sm"
          type="button"
          :disabled="submitting"
          :aria-label="t('generator.templates.slots.remove')"
          @click="removeSlot(slot.id)"
        >
          <Icon name="trash" />
        </Button>
      </div>

      <!-- Description -->
      <TextInput
        v-model="slot.description"
        size="sm"
        :disabled="submitting"
        :aria-invalid="!!descriptionErrorText(index)"
        :aria-label="t('generator.templates.slots.description')"
        :placeholder="t('generator.templates.slots.descriptionPlaceholder')"
      />

      <!-- Modifiers -->
      <div class="flex flex-wrap items-center gap-x-next-6 gap-y-next-2">
        <Switch
          v-model="slot.array"
          size="sm"
          :disabled="submitting || isStructuralBase(slot.base)"
          :label="t('generator.templates.slots.array')"
        />
        <Switch v-model="slot.nullable" size="sm" :disabled="submitting" :label="t('generator.templates.slots.nullable')" />
        <code class="rounded-next-sm bg-next-muted px-next-1 py-px font-next-mono text-next-xs text-next-fg">
          slots.{{ slot.name.trim() || '…' }}
        </code>
      </div>
      <p v-if="isStructuralBase(slot.base)" class="-mt-next-1 text-next-xs text-next-muted-foreground">
        {{ t('generator.templates.slots.structuralArrayNote') }}
      </p>

      <!-- Enum options sub-editor -->
      <div v-if="slot.base === 'enum'" class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted/30 p-next-2">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('generator.templates.slots.optionsLabel') }}</span>
        <div v-for="option in slot.options" :key="option.id" class="flex items-start gap-next-2">
          <TextInput
            v-model="option.key"
            size="sm"
            :disabled="submitting"
            :aria-label="t('generator.templates.slots.optionKey')"
            :placeholder="t('generator.templates.slots.optionKey')"
          />
          <TextInput
            v-model="option.label"
            size="sm"
            :disabled="submitting"
            :aria-label="t('generator.templates.slots.optionLabel')"
            :placeholder="t('generator.templates.slots.optionLabel')"
          />
          <Button
            variant="ghost"
            size="icon-sm"
            type="button"
            :disabled="submitting || slot.options.length <= 1"
            :aria-label="t('generator.templates.slots.removeOption')"
            @click="removeOption(slot, option.id)"
          >
            <Icon name="trash" />
          </Button>
        </div>
        <div>
          <Button variant="outline" size="xs" type="button" leading-icon="plus" :disabled="submitting" @click="addOption(slot)">
            {{ t('generator.templates.slots.addOption') }}
          </Button>
        </div>
      </div>

      <!-- Object fields sub-editor (scalar children only — mirrors the consts object type-builder) -->
      <div v-if="slot.base === 'object'" class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted/30 p-next-2">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('generator.templates.slots.fieldsLabel') }}</span>
        <div v-for="field in slot.fields" :key="field.id" class="flex items-start gap-next-2">
          <TextInput
            v-model="field.key"
            size="sm"
            class="font-next-mono"
            :disabled="submitting"
            :aria-label="t('generator.templates.slots.fieldKey')"
            :placeholder="t('generator.templates.slots.fieldKey')"
          />
          <TextInput
            v-model="field.label"
            size="sm"
            :disabled="submitting"
            :aria-label="t('generator.templates.slots.fieldLabel')"
            :placeholder="t('generator.templates.slots.fieldLabel')"
          />
          <div class="w-28 shrink-0">
            <Select
              v-model="field.base"
              :options="fieldBaseOptions"
              size="sm"
              :disabled="submitting"
              :aria-label="t('generator.templates.slots.fieldType')"
            />
          </div>
          <Button
            variant="ghost"
            size="icon-sm"
            type="button"
            :disabled="submitting || slot.fields.length <= 1"
            :aria-label="t('generator.templates.slots.removeField')"
            @click="removeField(slot, field.id)"
          >
            <Icon name="trash" />
          </Button>
        </div>
        <div>
          <Button variant="outline" size="xs" type="button" leading-icon="plus" :disabled="submitting" @click="addField(slot)">
            {{ t('generator.templates.slots.addField') }}
          </Button>
        </div>
        <p v-if="fieldErrorText(slot)" class="text-next-xs text-next-danger">{{ fieldErrorText(slot) }}</p>
      </div>

      <!-- File subfields note (fixed composite — no authoring) -->
      <p v-if="slot.base === 'file'" class="text-next-xs text-next-muted-foreground">
        {{ t('generator.templates.slots.fileNote') }}
      </p>

      <p v-if="nameErrorText(index)" class="text-next-xs text-next-danger">{{ nameErrorText(index) }}</p>
      <p v-else-if="descriptionErrorText(index)" class="text-next-xs text-next-danger">{{ descriptionErrorText(index) }}</p>
      <p v-else-if="descriptorErrorText(index)" class="text-next-xs text-next-danger">{{ descriptorErrorText(index) }}</p>
    </div>

    <p v-if="slots.length === 0" class="text-next-xs text-next-muted-foreground">
      {{ t('generator.templates.slots.empty') }}
    </p>
    <div>
      <Button variant="outline" size="sm" type="button" leading-icon="plus" :disabled="submitting" @click="addSlot">
        {{ t('generator.templates.slots.add') }}
      </Button>
    </div>
  </section>
</template>
