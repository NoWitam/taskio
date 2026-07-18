<script setup lang="ts">
// InputRenderer — renders ONE form element (and its descendants) on `next`
// inputs, for both the builder's live PREVIEW and (Batch 3) the FILL experience.
//
// Mirrors the legacy FormViewer/InputRenderer 1:1 in structure: layout elements
// (section / grid / repeater) are containers that recurse; content elements
// (heading / text_block / divider) are static; input fields bind to
// `formData[element.id]` (checklist uses `formData[`${id}_${value}`]`). Every
// labelled control renders THROUGH FormField (the `next` inputs get their label /
// hint / error / required from the surrounding FormField). In `preview` mode the
// controls are disabled. Self-recursive (by filename).
//
// NOTE: the `image` field (wire type kept for back-compat) is a single-FILE
// upload — its answer is a Disk temp-file id (see FormFileInput), not a string.
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import NumberInput from '../../ui/forms/NumberInput.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import Checkbox from '../../ui/forms/Checkbox.vue';
import DatePicker from '../../ui/forms/DatePicker.vue';
import TimePicker from '../../ui/forms/TimePicker.vue';
import Card from '../../ui/layout/Card.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import FormFileInput from './FormFileInput.vue';
import { useI18n } from '../../app/i18n';
import type { FormElement, FormElementOption } from './types';

const props = defineProps<{
  element: FormElement;
  mode: 'preview' | 'fill';
  formData: Record<string, unknown>;
  repeaterInstances: Record<string, number>;
  getError: (id: string) => string | undefined;
}>();

const emit = defineEmits<{
  (e: 'add-repeater', id: string): void;
  (e: 'remove-repeater', id: string): void;
}>();

const { t } = useI18n();

const isPreview = (): boolean => props.mode === 'preview';

function selectOptions(options: FormElementOption[] | undefined): SelectOption[] {
  return (options ?? []).map((o) => ({ value: o.value, label: o.label || o.value }));
}

// Grid columns map a width (25/50/75/100) to a flex-grow weight.
function gridTemplate(columns: { width: number }[] | undefined): string {
  return (columns ?? []).map((c) => `${c.width}fr`).join(' ');
}

function repeaterCount(el: FormElement): number {
  return props.repeaterInstances[el.id] ?? el.config.min ?? 1;
}

// Seed sane defaults for THIS element's value so controls never bind to an
// `undefined` (a multi-select reads `.length`/`.map` on its model, a checklist
// reads per-option booleans). Runs for every rendered element — including each
// repeater instance (whose id is already suffixed) — so all ids are covered.
const el = props.element;
if (el.type === 'select' && el.config.multiple) {
  if (props.formData[el.id] === undefined) props.formData[el.id] = [];
} else if (el.type === 'checkbox') {
  if (props.formData[el.id] === undefined) props.formData[el.id] = false;
} else if (el.type === 'image') {
  if (props.formData[el.id] === undefined) props.formData[el.id] = null;
} else if (el.type === 'checklist') {
  for (const option of el.config.options ?? []) {
    const key = `${el.id}_${option.value}`;
    if (props.formData[key] === undefined) props.formData[key] = false;
  }
}
</script>

<template>
  <!-- Section -->
  <Card v-if="element.type === 'section'" class="flex flex-col gap-next-4 p-next-5">
    <div class="flex items-start gap-next-3 border-b border-next-border pb-next-3">
      <Icon v-if="element.config.icon" :name="(element.config.icon as any)" class="mt-next-0_5 text-next-lg text-next-primary" />
      <div class="min-w-0">
        <h3 class="text-next-base font-next-semibold text-next-fg">{{ element.config.name }}</h3>
        <p v-if="element.config.description" class="mt-next-0_5 text-next-sm text-next-muted-foreground">
          {{ element.config.description }}
        </p>
      </div>
    </div>
    <div class="flex flex-col gap-next-4">
      <InputRenderer
        v-for="child in (element.config.children ?? [])"
        :key="child.id"
        :element="child"
        :mode="mode"
        :form-data="formData"
        :repeater-instances="repeaterInstances"
        :get-error="getError"
        @add-repeater="emit('add-repeater', $event)"
        @remove-repeater="emit('remove-repeater', $event)"
      />
    </div>
  </Card>

  <!-- Grid -->
  <div
    v-else-if="element.type === 'grid'"
    class="grid gap-next-4"
    :style="{ gridTemplateColumns: gridTemplate(element.config.columns) }"
  >
    <div v-for="(column, index) in (element.config.columns ?? [])" :key="index" class="min-w-0">
      <InputRenderer
        v-if="column.element"
        :element="column.element"
        :mode="mode"
        :form-data="formData"
        :repeater-instances="repeaterInstances"
        :get-error="getError"
        @add-repeater="emit('add-repeater', $event)"
        @remove-repeater="emit('remove-repeater', $event)"
      />
    </div>
  </div>

  <!-- Repeater -->
  <Card v-else-if="element.type === 'repeater'" class="flex flex-col gap-next-4 p-next-5">
    <div class="flex items-start justify-between gap-next-3 border-b border-next-border pb-next-3">
      <div class="flex items-start gap-next-3">
        <Icon v-if="element.config.icon" :name="(element.config.icon as any)" class="mt-next-0_5 text-next-lg text-next-primary" />
        <div class="min-w-0">
          <h3 class="text-next-base font-next-semibold text-next-fg">{{ element.config.name }}</h3>
          <p v-if="element.config.description" class="mt-next-0_5 text-next-sm text-next-muted-foreground">
            {{ element.config.description }}
          </p>
        </div>
      </div>
      <div v-if="mode === 'fill'" class="flex shrink-0 gap-next-2">
        <Button
          variant="outline"
          size="icon-sm"
          leading-icon="plus"
          :aria-label="t('forms.viewer.addRepeater', 'Add')"
          :disabled="repeaterCount(element) >= (element.config.max ?? 1)"
          @click="emit('add-repeater', element.id)"
        />
        <Button
          variant="outline"
          size="icon-sm"
          leading-icon="minus"
          :aria-label="t('forms.viewer.removeRepeater', 'Remove')"
          :disabled="repeaterCount(element) <= (element.config.min ?? 0)"
          @click="emit('remove-repeater', element.id)"
        />
      </div>
    </div>
    <div class="flex flex-col gap-next-5">
      <div
        v-for="instance in repeaterCount(element)"
        :key="instance"
        class="flex flex-col gap-next-4 rounded-next-md border border-next-border bg-next-muted/30 p-next-4"
      >
        <span class="text-next-xs font-next-medium text-next-muted-foreground">#{{ instance }}</span>
        <InputRenderer
          v-for="child in (element.config.children ?? [])"
          :key="`${child.id}_${instance}`"
          :element="{ ...child, id: `${child.id}_${instance}` }"
          :mode="mode"
          :form-data="formData"
          :repeater-instances="repeaterInstances"
          :get-error="getError"
          @add-repeater="emit('add-repeater', $event)"
          @remove-repeater="emit('remove-repeater', $event)"
        />
      </div>
    </div>
  </Card>

  <!-- Heading -->
  <component
    :is="`h${element.config.level ?? 2}`"
    v-else-if="element.type === 'heading'"
    :class="{
      'text-next-2xl font-next-bold text-next-fg': element.config.level === 1,
      'text-next-xl font-next-bold text-next-fg': element.config.level === 2 || !element.config.level,
      'text-next-lg font-next-semibold text-next-fg': element.config.level === 3,
    }"
  >
    {{ element.config.text }}
  </component>

  <!-- Text block -->
  <p
    v-else-if="element.type === 'text_block'"
    class="whitespace-pre-wrap text-next-sm text-next-muted-foreground"
  >
    {{ element.config.content }}
  </p>

  <!-- Divider -->
  <hr v-else-if="element.type === 'divider'" class="border-next-border" />

  <!-- Short text / URL -->
  <FormField
    v-else-if="element.type === 'short_text' || element.type === 'url'"
    :label="element.config.label"
    :description="element.config.hint || undefined"
    :required="element.config.required"
    :error="getError(element.id)"
  >
    <TextInput
      v-model="(formData[element.id] as string)"
      :type="element.type === 'url' ? 'url' : 'text'"
      :placeholder="element.config.placeholder"
      :disabled="isPreview()"
    />
  </FormField>

  <!-- Long text -->
  <FormField
    v-else-if="element.type === 'long_text'"
    :label="element.config.label"
    :description="element.config.hint || undefined"
    :required="element.config.required"
    :error="getError(element.id)"
  >
    <Textarea
      v-model="(formData[element.id] as string)"
      :rows="element.config.rows ?? 4"
      :placeholder="element.config.placeholder"
      :disabled="isPreview()"
    />
  </FormField>

  <!-- File (single-file upload; answer is a Disk temp-file id) -->
  <FormField
    v-else-if="element.type === 'image'"
    :label="element.config.label"
    :description="element.config.hint || undefined"
    :required="element.config.required"
    :error="getError(element.id)"
  >
    <FormFileInput
      v-model="(formData[element.id] as string | null)"
      :accepted-types="element.config.acceptedTypes"
      :max-size="element.config.maxSize"
      :disabled="isPreview()"
    />
  </FormField>

  <!-- Number -->
  <FormField
    v-else-if="element.type === 'number'"
    :label="element.config.label"
    :description="element.config.hint || undefined"
    :required="element.config.required"
    :error="getError(element.id)"
  >
    <NumberInput
      v-model="(formData[element.id] as number | null)"
      :min="element.config.min"
      :max="element.config.max"
      :step="element.config.step ?? 1"
      :placeholder="element.config.placeholder"
      :disabled="isPreview()"
    />
  </FormField>

  <!-- Select -->
  <FormField
    v-else-if="element.type === 'select'"
    :label="element.config.label"
    :description="element.config.hint || undefined"
    :required="element.config.required"
    :error="getError(element.id)"
  >
    <Select
      v-if="element.config.multiple"
      v-model:values="(formData[element.id] as any)"
      multiple
      :options="selectOptions(element.config.options)"
      :placeholder="element.config.placeholder"
      :disabled="isPreview()"
    />
    <Select
      v-else
      v-model="(formData[element.id] as any)"
      :options="selectOptions(element.config.options)"
      :placeholder="element.config.placeholder"
      :disabled="isPreview()"
    />
  </FormField>

  <!-- Date -->
  <FormField
    v-else-if="element.type === 'date'"
    :label="element.config.label"
    :description="element.config.hint || undefined"
    :required="element.config.required"
    :error="getError(element.id)"
  >
    <DatePicker v-model="(formData[element.id] as string | null)" :disabled="isPreview()" />
  </FormField>

  <!-- Time -->
  <FormField
    v-else-if="element.type === 'time'"
    :label="element.config.label"
    :description="element.config.hint || undefined"
    :required="element.config.required"
    :error="getError(element.id)"
  >
    <TimePicker v-model="(formData[element.id] as string | null)" :disabled="isPreview()" />
  </FormField>

  <!-- Checklist (a group of checkboxes; each option → formData[id_value]) -->
  <FormField
    v-else-if="element.type === 'checklist'"
    :label="element.config.label"
    :description="element.config.hint || undefined"
    :required="element.config.required"
    :error="getError(element.id)"
  >
    <div class="flex flex-col gap-next-2">
      <Checkbox
        v-for="option in (element.config.options ?? [])"
        :key="option.value"
        v-model="(formData[`${element.id}_${option.value}`] as boolean)"
        :label="option.label || option.value"
        :description="option.hint || undefined"
        :disabled="isPreview()"
      />
    </div>
  </FormField>

  <!-- Checkbox (single boolean) -->
  <Checkbox
    v-else-if="element.type === 'checkbox'"
    v-model="(formData[element.id] as boolean)"
    :label="element.config.label"
    :description="element.config.hint || undefined"
    :disabled="isPreview()"
  />
</template>
