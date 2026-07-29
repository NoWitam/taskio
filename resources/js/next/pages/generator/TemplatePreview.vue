<script setup lang="ts">
// TemplatePreview — the FAITHFUL, PER-PART live preview pane of the template editor.
//
// It authors one SAMPLE VALUE per declared slot (typed by the slot's descriptor via the shared
// `TypedLiteralInput`), then calls `POST /generator/preview` (debounced) with
// `{content_type, content, slots, slot_values}` and renders each PART's result BY KIND through
// `TemplatePartPreview` (text → MarkdownViewer, image → a plan card, scene → per-scene summary). The
// interpolation engine runs on the BACKEND — the FE never re-implements it.
//
// `@[ai-text]` blocks render INERT in this sub-stage (the server emits a labeled `[AI: …]` placeholder),
// so a persistent legend makes the "generated at run time" treatment explicit. States: seeding hint
// (no slots) / loading skeleton / error+retry / rendered per-part output.
import { computed, ref, watch } from 'vue';
import TypedLiteralInput from '../../ui/variables/TypedLiteralInput.vue';
import TemplatePartPreview from './TemplatePartPreview.vue';
import FormField from '../../ui/forms/FormField.vue';
import Switch from '../../ui/forms/Switch.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import Alert from '../../ui/feedback/Alert.vue';
import { defaultSingleValue } from '../variables/consts';
import { FILE_SUBFIELDS, slotSampleDefault } from './templateSlots';
import { useTemplatesStore } from '../../app/stores/templates';
import { useDebounce } from '../../app/composables/useDebounce';
import { useI18n } from '../../app/i18n';
import type { VariableDescriptorOption, VariableLiteralBase } from '../../ui/variables/types';
import type { CatalogDescriptorField, CatalogVariableDescriptor } from '../workflows/types';
import type {
  ContentTypePart,
  PartPreview,
  TemplateCatalogSlot,
  TemplateContent,
} from './types';

const props = defineProps<{
  /** The selected content type id (drives which parts are rendered). */
  contentType: string;
  /** The per-part authored content map. */
  content: TemplateContent;
  /** The definition's declared parts (kind + key + label), in order. */
  parts: ContentTypePart[];
  /** The VALID draft slots ({name, descriptor}) — the same list posted to the catalog. */
  slots: TemplateCatalogSlot[];
}>();

const { t } = useI18n();
const store = useTemplatesStore();

// --- Sample values (one per slot, keyed by name) ----------------------------
const slotValues = ref<Record<string, unknown>>({});

/**
 * Whether a kept sample value still fits a slot's descriptor. A slot's base can change while its NAME
 * stays the same; keeping a shape-mismatched value would post a sample the server can't resolve, so a
 * mismatch re-seeds. `null` is kept ONLY for a nullable slot; `undefined` (a brand-new slot) never fits.
 */
function valueFits(descriptor: CatalogVariableDescriptor, value: unknown): boolean {
  if (value === null) return !!descriptor.nullable;
  if (value === undefined) return false;
  if (descriptor.array) return Array.isArray(value);
  if (descriptor.base === 'object' || descriptor.base === 'file') {
    return typeof value === 'object' && !Array.isArray(value);
  }
  return typeof value !== 'object';
}

/** Reconcile the sample-value map to the current slots: keep a still-fitting value by NAME, else re-seed. */
watch(
  () => props.slots,
  (slots) => {
    const next: Record<string, unknown> = {};
    for (const slot of slots) {
      const existing = slot.name in slotValues.value ? slotValues.value[slot.name] : undefined;
      next[slot.name] = valueFits(slot.descriptor, existing) ? existing : slotSampleDefault(slot.descriptor);
    }
    slotValues.value = next;
  },
  { deep: true, immediate: true },
);

function setValue(name: string, value: unknown): void {
  slotValues.value = { ...slotValues.value, [name]: value };
}
function literalBase(base: string): VariableLiteralBase {
  return base as VariableLiteralBase;
}
function optionsOf(slot: TemplateCatalogSlot): VariableDescriptorOption[] {
  return (slot.descriptor.options ?? []) as VariableDescriptorOption[];
}

// --- Object / file structured sample helpers --------------------------------
function objectFieldsOf(slot: TemplateCatalogSlot): CatalogDescriptorField[] {
  return (slot.descriptor.fields ?? []) as CatalogDescriptorField[];
}
function mapValueOf(name: string): Record<string, unknown> {
  const value = slotValues.value[name];
  return value && typeof value === 'object' && !Array.isArray(value) ? (value as Record<string, unknown>) : {};
}
function setMapField(name: string, key: string, value: unknown): void {
  setValue(name, { ...mapValueOf(name), [key]: value });
}
function fieldOptionsOf(field: CatalogDescriptorField): VariableDescriptorOption[] {
  return (field.descriptor.options ?? []) as VariableDescriptorOption[];
}

// Array-slot element helpers.
function asArray(value: unknown): unknown[] {
  return Array.isArray(value) ? value : [];
}
function setElement(slot: TemplateCatalogSlot, index: number, value: unknown): void {
  const list = [...asArray(slotValues.value[slot.name])];
  list[index] = value;
  setValue(slot.name, list);
}
function addElement(slot: TemplateCatalogSlot): void {
  setValue(slot.name, [...asArray(slotValues.value[slot.name]), defaultSingleValue(slot.descriptor.base, optionsOf(slot))]);
}
function removeElement(slot: TemplateCatalogSlot, index: number): void {
  setValue(slot.name, asArray(slotValues.value[slot.name]).filter((_, i) => i !== index));
}

// Nullable "no value" toggle.
function hasNoValue(slot: TemplateCatalogSlot): boolean {
  return slotValues.value[slot.name] === null;
}
function setNoValue(slot: TemplateCatalogSlot, on: boolean): void {
  setValue(slot.name, on ? null : slotSampleDefault(slot.descriptor));
}

// --- Faithful per-part preview call -----------------------------------------
const partResults = ref<Record<string, PartPreview>>({});
const previewing = ref(false);
const previewError = ref(false);
let previewToken = 0;

async function runPreview(): Promise<void> {
  const myToken = (previewToken += 1);
  previewing.value = true;
  previewError.value = false;
  try {
    const result = await store.preview({
      content_type: props.contentType,
      content: props.content,
      slots: props.slots,
      slot_values: slotValues.value,
    });
    if (myToken !== previewToken) return;
    partResults.value = result.parts;
  } catch {
    if (myToken !== previewToken) return;
    previewError.value = true;
  } finally {
    if (myToken === previewToken) previewing.value = false;
  }
}
const debouncedPreview = useDebounce(runPreview, 500);

watch(
  [() => props.contentType, () => props.content, () => props.slots, slotValues],
  () => debouncedPreview(),
  { deep: true, immediate: true },
);

const hasRendered = computed(() => Object.keys(partResults.value).length > 0);
const showSkeleton = computed(() => previewing.value && !hasRendered.value);
</script>

<template>
  <div class="flex flex-col gap-next-4">
    <div class="flex flex-col gap-next-1">
      <div class="flex items-center gap-next-2">
        <Icon name="eye" class="text-next-muted-foreground" aria-hidden="true" />
        <span class="text-next-sm font-next-medium text-next-fg">{{ t('generator.templates.preview.title') }}</span>
        <Icon
          v-if="previewing"
          name="loader"
          class="animate-spin text-next-muted-foreground"
          :aria-label="t('generator.templates.preview.loading')"
        />
      </div>
      <p class="text-next-xs text-next-muted-foreground">{{ t('generator.templates.preview.subtitle') }}</p>
    </div>

    <!-- Sample slot values (typed by each slot's descriptor). -->
    <div v-if="slots.length > 0" class="flex flex-col gap-next-3 rounded-next-lg border border-next-border p-next-3">
      <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('generator.templates.preview.sampleValues') }}</span>
      <FormField v-for="slot in slots" :key="slot.name" :label="slot.name">
        <div class="flex flex-col gap-next-2">
          <div v-if="slot.descriptor.nullable" class="flex items-center">
            <Switch
              :model-value="hasNoValue(slot)"
              size="sm"
              label-position="leading"
              :label="t('generator.templates.preview.noValue')"
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
                  :aria-label="field.label || field.key"
                  @update:model-value="(v) => setMapField(slot.name, field.key, v)"
                />
              </FormField>
              <p v-if="objectFieldsOf(slot).length === 0" class="text-next-xs text-next-muted-foreground">
                {{ t('generator.templates.preview.noFields') }}
              </p>
            </div>

            <!-- FILE: the fixed {id,name,type,size,url} subfields → a sample-file snapshot. -->
            <div
              v-else-if="slot.descriptor.base === 'file'"
              class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted/20 p-next-2"
            >
              <FormField
                v-for="sub in FILE_SUBFIELDS"
                :key="sub.key"
                :label="t(`generator.templates.preview.fileSubfield.${sub.key}`)"
              >
                <TypedLiteralInput
                  :model-value="mapValueOf(slot.name)[sub.key]"
                  :base="literalBase(sub.base)"
                  :aria-label="t(`generator.templates.preview.fileSubfield.${sub.key}`)"
                  @update:model-value="(v) => setMapField(slot.name, sub.key, v)"
                />
              </FormField>
            </div>

            <!-- ARRAY: a repeatable list of element controls (scalar / enum). -->
            <div v-else-if="slot.descriptor.array" class="flex flex-col gap-next-2">
              <div v-for="(item, index) in asArray(slotValues[slot.name])" :key="index" class="flex items-start gap-next-2">
                <div class="min-w-0 flex-1">
                  <TypedLiteralInput
                    :model-value="item"
                    :base="literalBase(slot.descriptor.base)"
                    :options="optionsOf(slot)"
                    :aria-label="slot.name"
                    @update:model-value="(v) => setElement(slot, index, v)"
                  />
                </div>
                <Button variant="ghost" size="icon-sm" type="button" :aria-label="t('generator.templates.preview.removeItem')" @click="removeElement(slot, index)">
                  <Icon name="trash" />
                </Button>
              </div>
              <div>
                <Button variant="outline" size="xs" type="button" leading-icon="plus" @click="addElement(slot)">
                  {{ t('generator.templates.preview.addItem') }}
                </Button>
              </div>
            </div>

            <!-- SINGLE scalar / enum. -->
            <TypedLiteralInput
              v-else
              :model-value="slotValues[slot.name]"
              :base="literalBase(slot.descriptor.base)"
              :options="optionsOf(slot)"
              :aria-label="slot.name"
              @update:model-value="(v) => setValue(slot.name, v)"
            />
          </template>
        </div>
      </FormField>
    </div>

    <!-- ai-text legend (inert in this sub-stage). -->
    <div class="flex items-start gap-next-2 rounded-next-md bg-next-muted/40 px-next-3 py-next-2 text-next-xs text-next-muted-foreground">
      <Icon name="sparkles" class="mt-px shrink-0" aria-hidden="true" />
      <span>{{ t('generator.templates.preview.aiTextNote') }}</span>
    </div>

    <!-- Output -->
    <div class="flex flex-col gap-next-4">
      <Alert v-if="previewError" variant="danger" size="sm">
        <div class="flex items-center justify-between gap-next-2">
          <span>{{ t('generator.templates.preview.error') }}</span>
          <Button variant="ghost" size="xs" leading-icon="rotate-ccw" @click="runPreview">
            {{ t('generator.templates.preview.retry') }}
          </Button>
        </div>
      </Alert>

      <div v-else-if="showSkeleton" class="flex flex-col gap-next-2 rounded-next-lg border border-next-border bg-next-card p-next-4" aria-hidden="true">
        <Skeleton variant="text" width="90%" />
        <Skeleton variant="text" width="100%" />
        <Skeleton variant="text" width="70%" />
      </div>

      <template v-else>
        <TemplatePartPreview
          v-for="part in parts"
          :key="part.key"
          :part="part"
          :result="partResults[part.key]"
        />
      </template>
    </div>
  </div>
</template>
