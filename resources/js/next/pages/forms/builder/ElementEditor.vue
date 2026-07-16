<script setup lang="ts">
// ElementEditor — the per-type property panel for the selected builder element.
//
// Edits a LOCAL clone of the element's `config` and auto-applies changes to the
// parent (debounced via Vue's deep watcher) by emitting `update`. Covers every
// element type's config 1:1 with the backend schema (ValidFormContent) + the
// legacy editor: layout (section/repeater/grid), content (heading/text_block),
// and all input fields incl. select/checklist options. Grid columns can hold a
// single INPUT element, added/edited/removed here. All controls are `next` inputs
// wrapped in FormField; all labels via i18n.
//
// The local config is intentionally loosely typed (`any`) — like the legacy
// editor — because it spans many heterogeneous shapes; the server validates.
import { computed, ref, watch } from 'vue';
import FormField from '../../../ui/forms/FormField.vue';
import TextInput from '../../../ui/forms/TextInput.vue';
import Textarea from '../../../ui/forms/Textarea.vue';
import NumberInput from '../../../ui/forms/NumberInput.vue';
import Switch from '../../../ui/forms/Switch.vue';
import Select, { type SelectOption } from '../../../ui/forms/Select.vue';
import IconInput from '../../../ui/forms/IconInput.vue';
import DatePicker from '../../../ui/forms/DatePicker.vue';
import Button from '../../../ui/primitives/Button.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import {
  clonePlain,
  createElement,
  createGridColumn,
  createOption,
  INPUT_TYPES,
} from './elements';
import type { FormElement, FormElementType } from '../types';
import type { IconName } from '../../../ui/primitives/icons';
import { useI18n } from '../../../app/i18n';

const props = defineProps<{ element: FormElement }>();

const emit = defineEmits<{
  (e: 'update', config: Record<string, unknown>): void;
  (e: 'select', id: string): void;
  (e: 'close'): void;
}>();

const { t } = useI18n();

// Local editable clone. The parent remounts this editor per element (`:key` on
// the element id), so we seed ONCE here — no id-watch / echo-guard needed. JSON
// clone (NOT structuredClone, which throws on Vue reactive proxies).
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const config = ref<any>(clonePlain(props.element.config));

// Auto-apply edits to the parent (deep) — only real user changes reach here.
watch(
  config,
  (value) => emit('update', clonePlain(value)),
  { deep: true },
);

const typeLabel = (type: FormElementType): string => t(`forms.elementTypes.${type}`);

// Grid column type picker options (input fields only).
const inputTypeOptions: SelectOption[] = INPUT_TYPES.map((type) => ({
  value: type,
  label: typeLabel(type),
}));

const WIDTHS = [25, 50, 75, 100] as const;

// IconInput speaks IconName | null; the config stores a plain string (''=unset).
const iconModel = computed<IconName | null>({
  get: () => (config.value.icon ? (config.value.icon as IconName) : null),
  set: (value) => {
    config.value.icon = value ?? '';
  },
});

// Typed array views so v-for `index` is a number (config is intentionally `any`).
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const columns = computed<any[]>(() => config.value.columns ?? []);
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const options = computed<any[]>(() => config.value.options ?? []);

// --- Options (select / checklist) ----------------------------------------
function addOption(): void {
  config.value.options = [...(config.value.options ?? []), createOption()];
}
function removeOption(index: number): void {
  config.value.options.splice(index, 1);
}
function moveOption(index: number, dir: -1 | 1): void {
  const target = index + dir;
  const list = config.value.options;
  if (target < 0 || target >= list.length) return;
  [list[index], list[target]] = [list[target], list[index]];
}

// --- Grid columns ----------------------------------------------------------
function addColumn(): void {
  if ((config.value.columns?.length ?? 0) >= 4) return;
  config.value.columns = [...(config.value.columns ?? []), createGridColumn()];
}
function removeColumn(index: number): void {
  config.value.columns.splice(index, 1);
}
function setColumnWidth(index: number, width: number): void {
  config.value.columns[index].width = width;
}
function addColumnElement(index: number, type: FormElementType): void {
  if (!type) return;
  const element = createElement(type, typeLabel(type));
  config.value.columns[index].element = element;
  // Apply immediately so the parent tree knows about it, then select it.
  emit('update', clonePlain(config.value));
  emit('select', element.id);
}
function clearColumnElement(index: number): void {
  config.value.columns[index].element = null;
}
</script>

<template>
  <div class="flex flex-col gap-next-4">
    <div class="flex items-center justify-between">
      <h4 class="text-next-sm font-next-semibold text-next-fg">{{ typeLabel(element.type) }}</h4>
      <Button variant="ghost" size="icon-xs" leading-icon="x" :aria-label="t('common.close')" @click="emit('close')" />
    </div>

    <!-- Section / Repeater -->
    <template v-if="element.type === 'section' || element.type === 'repeater'">
      <FormField :label="t('common.name')" required>
        <TextInput v-model="config.name" :placeholder="t('forms.builder.namePlaceholder')" />
      </FormField>
      <FormField :label="t('common.icon')">
        <IconInput v-model="iconModel" clearable />
      </FormField>
      <FormField :label="t('common.description')">
        <Textarea v-model="config.description" :rows="2" :placeholder="t('forms.builder.descriptionPlaceholder')" />
      </FormField>
      <template v-if="element.type === 'repeater'">
        <FormField :label="t('forms.builder.minRepetitions')">
          <NumberInput v-model="config.min" :min="0" />
        </FormField>
        <FormField :label="t('forms.builder.maxRepetitions')">
          <NumberInput v-model="config.max" :min="1" />
        </FormField>
      </template>
    </template>

    <!-- Grid -->
    <template v-else-if="element.type === 'grid'">
      <div class="flex items-center justify-between">
        <span class="text-next-sm font-next-medium text-next-fg">{{ t('forms.builder.gridColumns') }}</span>
        <span class="text-next-xs text-next-muted-foreground">{{ (config.columns?.length ?? 0) }}/4</span>
      </div>

      <div
        v-for="(column, index) in columns"
        :key="index"
        class="flex flex-col gap-next-2 rounded-next-md border border-next-border p-next-3"
      >
        <div class="flex items-center justify-between">
          <span class="text-next-xs font-next-medium text-next-muted-foreground">
            {{ t('forms.builder.column') }} #{{ index + 1 }}
          </span>
          <Button variant="ghost" size="icon-xs" leading-icon="x" :aria-label="t('forms.builder.removeColumn')" @click="removeColumn(index)" />
        </div>

        <div class="grid grid-cols-4 gap-next-1">
          <Button
            v-for="w in WIDTHS"
            :key="w"
            :variant="column.width === w ? 'primary' : 'outline'"
            size="xs"
            @click="setColumnWidth(index, w)"
          >
            {{ w }}%
          </Button>
        </div>

        <!-- Column element: add an input, or show + edit/clear the existing one. -->
        <FormField v-if="!column.element" :label="t('forms.builder.columnField')">
          <Select
            :model-value="null"
            :options="inputTypeOptions"
            :placeholder="t('forms.builder.addFieldPlaceholder')"
            @update:model-value="(v: string | null) => v && addColumnElement(index, v as FormElementType)"
          />
        </FormField>
        <div v-else class="flex items-center justify-between gap-next-2 rounded-next-md bg-next-muted/40 px-next-2 py-next-1_5">
          <span class="min-w-0 truncate text-next-sm">{{ column.element.config.label || typeLabel(column.element.type) }}</span>
          <div class="flex shrink-0 gap-next-0_5">
            <Button variant="ghost" size="icon-xs" leading-icon="pencil" :aria-label="t('forms.builder.editField')" @click="emit('select', column.element.id)" />
            <Button variant="ghost" size="icon-xs" leading-icon="trash" :aria-label="t('forms.builder.removeField')" @click="clearColumnElement(index)" />
          </div>
        </div>
      </div>

      <Button
        v-if="(config.columns?.length ?? 0) < 4"
        variant="outline"
        size="sm"
        leading-icon="plus"
        full-width
        @click="addColumn"
      >
        {{ t('forms.builder.addColumn') }}
      </Button>
    </template>

    <!-- Heading -->
    <template v-else-if="element.type === 'heading'">
      <FormField :label="t('forms.builder.headingLevel')">
        <div class="flex gap-next-2">
          <Button
            v-for="lvl in [1, 2, 3]"
            :key="lvl"
            :variant="config.level === lvl ? 'primary' : 'outline'"
            size="sm"
            @click="config.level = lvl"
          >
            H{{ lvl }}
          </Button>
        </div>
      </FormField>
      <FormField :label="t('forms.builder.headingText')" required>
        <TextInput v-model="config.text" />
      </FormField>
    </template>

    <!-- Text block -->
    <template v-else-if="element.type === 'text_block'">
      <FormField :label="t('forms.builder.textContent')">
        <Textarea v-model="config.content" :rows="5" />
      </FormField>
    </template>

    <!-- Divider -->
    <p v-else-if="element.type === 'divider'" class="text-next-sm text-next-muted-foreground">
      {{ t('forms.builder.dividerNoConfig') }}
    </p>

    <!-- Input fields -->
    <template v-else>
      <FormField v-if="element.type !== 'checkbox'" :label="t('common.label')" required>
        <TextInput v-model="config.label" />
      </FormField>
      <FormField v-else :label="t('common.label')">
        <TextInput v-model="config.label" />
      </FormField>

      <FormField
        v-if="element.type !== 'checkbox' && element.type !== 'checklist'"
        :label="t('forms.builder.placeholder')"
      >
        <TextInput v-model="config.placeholder" />
      </FormField>

      <FormField :label="t('forms.builder.hint')">
        <Textarea v-model="config.hint" :rows="2" />
      </FormField>

      <Switch v-if="element.type !== 'checkbox'" v-model="config.required" :label="t('forms.builder.requiredField')" />

      <!-- Short / long text -->
      <template v-if="element.type === 'short_text' || element.type === 'long_text'">
        <FormField :label="t('forms.builder.minLength')">
          <NumberInput v-model="config.minLength" :min="0" />
        </FormField>
        <FormField :label="t('forms.builder.maxLength')">
          <NumberInput v-model="config.maxLength" :min="1" />
        </FormField>
        <FormField v-if="element.type === 'long_text'" :label="t('forms.builder.rows')">
          <NumberInput v-model="config.rows" :min="2" :max="20" />
        </FormField>
      </template>

      <!-- Number -->
      <template v-else-if="element.type === 'number'">
        <FormField :label="t('forms.builder.minValue')">
          <NumberInput v-model="config.min" />
        </FormField>
        <FormField :label="t('forms.builder.maxValue')">
          <NumberInput v-model="config.max" />
        </FormField>
        <FormField :label="t('forms.builder.step')">
          <NumberInput v-model="config.step" :min="0" />
        </FormField>
      </template>

      <!-- Date -->
      <template v-else-if="element.type === 'date'">
        <FormField :label="t('forms.builder.minDate')">
          <DatePicker v-model="config.min" clearable />
        </FormField>
        <FormField :label="t('forms.builder.maxDate')">
          <DatePicker v-model="config.max" clearable />
        </FormField>
      </template>

      <!-- Image (AI prompt) -->
      <template v-else-if="element.type === 'image'">
        <FormField :label="t('forms.builder.maxSizeMb')">
          <NumberInput v-model="config.maxSize" :min="1" :max="50" />
        </FormField>
      </template>

      <!-- Select / checklist options -->
      <template v-else-if="element.type === 'select' || element.type === 'checklist'">
        <Switch v-if="element.type === 'select'" v-model="config.multiple" :label="t('forms.builder.multipleSelection')" />

        <div class="flex items-center justify-between">
          <span class="text-next-sm font-next-medium text-next-fg">{{ t('forms.builder.options') }}</span>
          <Button variant="outline" size="xs" leading-icon="plus" @click="addOption">
            {{ t('forms.builder.addOption') }}
          </Button>
        </div>

        <div
          v-for="(option, index) in options"
          :key="index"
          class="flex flex-col gap-next-2 rounded-next-md border border-next-border p-next-3"
        >
          <div class="flex items-center justify-between">
            <span class="text-next-xs font-next-medium text-next-muted-foreground">
              {{ t('forms.builder.option') }} #{{ index + 1 }}
            </span>
            <div class="flex shrink-0 gap-next-0_5">
              <Button variant="ghost" size="icon-xs" leading-icon="chevron-up" :disabled="index === 0" :aria-label="t('forms.builder.moveUp')" @click="moveOption(index, -1)" />
              <Button variant="ghost" size="icon-xs" leading-icon="chevron-down" :disabled="index === (options.length - 1)" :aria-label="t('forms.builder.moveDown')" @click="moveOption(index, 1)" />
              <Button variant="ghost" size="icon-xs" leading-icon="x" :aria-label="t('forms.builder.removeOption')" @click="removeOption(index)" />
            </div>
          </div>
          <FormField :label="t('common.label')">
            <TextInput v-model="option.label" />
          </FormField>
          <FormField :label="t('forms.builder.value')">
            <TextInput v-model="option.value" />
          </FormField>
        </div>

        <p v-if="!(config.options?.length)" class="py-next-2 text-center text-next-sm text-next-muted-foreground">
          {{ t('forms.builder.noOptions') }}
        </p>
      </template>
    </template>
  </div>
</template>
