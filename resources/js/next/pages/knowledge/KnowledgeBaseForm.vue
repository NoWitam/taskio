<script setup lang="ts">
// KnowledgeBaseForm — the ONE form body for a knowledge base, used by the settings drawer today
// (create + edit) and reused by the base settings page when that lands (B5). Sections follow the
// spec (§3.5 / §7): Identity (name · description · language) → Charter → Metadata schema.
//
// TWO ABILITIES, NEVER MERGED (KnowledgeBasePolicy):
//   • can_be_edited  → name / description / language. Any workspace member.
//   • can_be_managed → the CHARTER and the METADATA SCHEMA. The base's creator or the workspace
//                      owner. Governance: a schema edit retro-actively decides whether every
//                      existing entry's metadata is still valid.
// A UI that collapses them produces 403s it cannot explain, so the governed section is disabled
// (with the reason spelled out) rather than hidden — a member has to be able to READ the charter
// their entries are written against.
//
// The form is PURE: it validates client-side (mirroring the backend) and EMITS a payload. The
// parent owns the store call, the toasts and the 422 feedback.
//
// PATCH DIFFING IS LOAD-BEARING. On edit the payload carries ONLY what actually changed, because
// on the server an ABSENT key means UNCHANGED — and because merely INCLUDING `charter` /
// `metadata_schema` with a different value escalates the request from the `update` ability to
// `manage`. Echoing back an untouched charter would 403 an ordinary member renaming a base.
import { computed, ref, watch } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Surface from '../../ui/layout/Surface.vue';
import Heading from '../../ui/primitives/Heading.vue';
import DescriptorSchemaBuilder from './DescriptorSchemaBuilder.vue';
import { schemasEqual } from './schema';
import { useI18n } from '../../app/i18n';
import type { KnowledgeBase, KnowledgeBaseWritePayload, KnowledgeSchemaField } from './types';

/** Backend caps (StoreKnowledgeBaseRequest) — the counters must not promise more than the server takes. */
const DESCRIPTION_MAX = 2000;
const CHARTER_MAX = 10000;

/** The languages offered by default. A base already speaking another tag keeps its own. */
const DEFAULT_LANGUAGES = ['pl', 'en'];

const props = withDefaults(
  defineProps<{
    /** The base being edited, or null for a create. */
    base?: KnowledgeBase | null;
    /** True while the parent's store call is in flight (disables inputs). */
    submitting?: boolean;
    /** Backend 422 field errors keyed by dotted path (name / charter / metadata_schema.*). */
    serverErrors?: Record<string, string> | null;
  }>(),
  { base: null, submitting: false, serverErrors: null },
);

const emit = defineEmits<{ submit: [KnowledgeBaseWritePayload] }>();

const { t, locale } = useI18n();

const isEdit = computed(() => props.base != null);

// --- Capability gating -------------------------------------------------------
/** Name / description / language. A create is always allowed (the policy gates the POST). */
const canEdit = computed(() => (props.base ? props.base.can_be_edited : true));
/** Charter + metadata schema (governance). */
const canManage = computed(() => (props.base ? props.base.can_be_managed : true));

// --- Draft -------------------------------------------------------------------
const name = ref('');
const description = ref('');
const language = ref('en');
const charter = ref('');
const schema = ref<KnowledgeSchemaField[]>([]);
const schemaValid = ref(true);

const clientNameError = ref<string | null>(null);
const showSchemaErrors = ref(false);

function seed(): void {
  const base = props.base;
  clientNameError.value = null;
  showSchemaErrors.value = false;
  name.value = base?.name ?? '';
  description.value = base?.description ?? '';
  // A NEW base defaults to the language the author is working in — the same guess the backend
  // makes when the field is omitted (StoreKnowledgeBaseRequest::resolvedLanguage → app locale),
  // so the picker agrees with the server instead of quietly overriding it.
  language.value = base?.language || (DEFAULT_LANGUAGES.includes(locale.value) ? locale.value : DEFAULT_LANGUAGES[0]);
  charter.value = base?.charter ?? '';
  schema.value = base?.metadata_schema ?? [];
}

watch(() => props.base, seed, { immediate: true });

// --- Language options --------------------------------------------------------
const languageOptions = computed<SelectOption[]>(() => {
  const tags = [...DEFAULT_LANGUAGES];
  const current = props.base?.language;
  // A base written through the API may speak a tag the picker does not offer (`pt-BR`). Keep it
  // in the list rather than silently re-labelling the base on the next save.
  if (current && !tags.includes(current)) tags.push(current);
  return tags.map((tag) => ({ value: tag, label: t(`knowledge.language.${tag}`, tag.toUpperCase()) }));
});

// --- Errors ------------------------------------------------------------------
const nameError = computed(
  () => props.serverErrors?.name ?? (clientNameError.value ? t(clientNameError.value) : undefined),
);
const descriptionError = computed(() => props.serverErrors?.description);
const charterError = computed(() => props.serverErrors?.charter);

// --- Submit ------------------------------------------------------------------
/** `''` and `null` mean the same absence to the server; compare them as one. */
function normalizeProse(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

/**
 * Build the write payload.
 *
 * CREATE sends the full record. EDIT sends only the DIFF — see the module header: an absent key
 * is "unchanged", and a present governed key is what demands the `manage` ability.
 */
function buildPayload(): KnowledgeBaseWritePayload {
  const base = props.base;
  const nextName = name.value.trim();
  const nextDescription = normalizeProse(description.value);
  const nextCharter = normalizeProse(charter.value);

  if (!base) {
    const payload: KnowledgeBaseWritePayload = { name: nextName, language: language.value };
    if (nextDescription !== null) payload.description = nextDescription;
    if (nextCharter !== null) payload.charter = nextCharter;
    if (schema.value.length > 0) payload.metadata_schema = schema.value;
    return payload;
  }

  const payload: KnowledgeBaseWritePayload = {};
  if (nextName !== base.name) payload.name = nextName;
  if (nextDescription !== (base.description ?? null)) payload.description = nextDescription;
  if (language.value !== base.language) payload.language = language.value;

  // Governed fields: only a manager can have changed them, and only a real change is sent.
  if (canManage.value) {
    if (nextCharter !== (base.charter ?? null)) payload.charter = nextCharter;
    if (!schemasEqual(schema.value, base.metadata_schema)) payload.metadata_schema = schema.value;
  }

  return payload;
}

function submit(): void {
  clientNameError.value = null;
  showSchemaErrors.value = true;

  let ok = true;
  if (name.value.trim() === '') {
    clientNameError.value = 'knowledge.settings.nameRequired';
    ok = false;
  }
  if (canManage.value && !schemaValid.value) ok = false;

  if (!ok) return;

  emit('submit', buildPayload());
}

defineExpose({ submit });
</script>

<template>
  <form class="flex flex-col gap-next-4" @submit.prevent="submit">
    <!-- A viewer who may not edit at all still SEES the base; say why the controls are inert. -->
    <Alert v-if="isEdit && !canEdit" variant="info" size="sm">
      {{ t('knowledge.settings.editLocked') }}
    </Alert>

    <!-- 1. Identity ---------------------------------------------------------- -->
    <Surface bg="card" border elevation="sm" radius="lg" class="flex flex-col gap-next-4 p-next-4">
      <Heading :level="3" size="h5">{{ t('knowledge.settings.identity') }}</Heading>

      <FormField :label="t('knowledge.settings.nameLabel')" required :error="nameError">
        <TextInput
          v-model="name"
          :disabled="submitting || !canEdit"
          :placeholder="t('knowledge.settings.namePlaceholder')"
        />
      </FormField>

      <FormField :label="t('knowledge.settings.descriptionLabel')" :error="descriptionError">
        <Textarea
          v-model="description"
          :rows="2"
          auto-grow
          counter
          :maxlength="DESCRIPTION_MAX"
          :disabled="submitting || !canEdit"
          :placeholder="t('knowledge.settings.descriptionPlaceholder')"
        />
      </FormField>

      <FormField
        :label="t('knowledge.settings.languageLabel')"
        :description="t('knowledge.settings.languageHint')"
      >
        <Select
          v-model="language"
          :options="languageOptions"
          :disabled="submitting || !canEdit"
          :aria-label="t('knowledge.settings.languageLabel')"
        />
      </FormField>
    </Surface>

    <!-- 2. Charter (governed) ------------------------------------------------ -->
    <Surface bg="card" border elevation="sm" radius="lg" class="flex flex-col gap-next-3 p-next-4">
      <Heading :level="3" size="h5">{{ t('knowledge.settings.charter') }}</Heading>

      <Alert v-if="isEdit && !canManage" variant="info" size="sm">
        {{ t('knowledge.settings.governedLocked') }}
      </Alert>

      <FormField
        :label="t('knowledge.settings.charter')"
        hide-label
        :description="t('knowledge.settings.charterHint')"
        :error="charterError"
      >
        <!-- ONE field, not five: five questions as a placeholder is a prompt; five inputs is a
             questionnaire, and the AI consumes prose either way. -->
        <Textarea
          v-model="charter"
          :rows="8"
          auto-grow
          counter
          :maxlength="CHARTER_MAX"
          :disabled="submitting || !canManage"
          :placeholder="t('knowledge.settings.charterPlaceholder')"
        />
      </FormField>

      <Alert variant="info" size="sm">{{ t('knowledge.settings.charterNotice') }}</Alert>
    </Surface>

    <!-- 3. Metadata schema (governed) ---------------------------------------- -->
    <Surface bg="card" border elevation="sm" radius="lg" class="flex flex-col gap-next-3 p-next-4">
      <div class="flex flex-col gap-next-1">
        <Heading :level="3" size="h5">{{ t('knowledge.schema.title') }}</Heading>
        <p class="text-next-xs text-next-muted-foreground">{{ t('knowledge.schema.hint') }}</p>
      </div>

      <DescriptorSchemaBuilder
        v-model="schema"
        :disabled="submitting || !canManage"
        :show-errors="showSchemaErrors"
        :server-errors="serverErrors"
        @update:valid="(valid: boolean) => (schemaValid = valid)"
      />
    </Surface>
  </form>
</template>
