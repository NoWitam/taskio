<script setup lang="ts">
// SessionSlotSetupCard — the opening, collapsible "Setup" turn of the session chat (owner decision #2).
//
// The typed slot form authored AS the opening message: EXPANDED while the session is a `draft`, then
// COLLAPSED to a one-line summary of input chips after the first generate — re-expandable on demand and
// still editable during refinement (a `ready` session). A `modified` marker (Badge variant="modified")
// shows when a value changed since the last generate. It reuses `SlotValuesForm` (→ the shared
// `TypedLiteralInput`) for the inputs; the summary chips read straight from the value map, so they
// render even before the source template's descriptors have loaded.
import { computed, ref, watch } from 'vue';
import Card from '../../../ui/layout/Card.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Button from '../../../ui/primitives/Button.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import Alert from '../../../ui/feedback/Alert.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import SlotValuesForm from './SlotValuesForm.vue';
import { useI18n } from '../../../app/i18n';
import type { TemplateSlot } from '../types';
import type { SessionStatus } from '../sessionTypes';

const props = withDefaults(
  defineProps<{
    /** The declared slots ({name, descriptor}) from the source template — drives the typed inputs. */
    slots: TemplateSlot[];
    /** Session lifecycle (draft ⇒ starts expanded; anything else ⇒ starts collapsed). */
    status: SessionStatus;
    /** Whether inputs may be edited right now (server `can_edit`). */
    canEdit?: boolean;
    /** A value changed since the last generate (drives the "modified" marker). */
    modified?: boolean;
    /** The source template (for descriptors) is still loading. */
    loadingSlots?: boolean;
    /** The source template failed to load (inputs can't be edited, summary still shows). */
    slotsError?: boolean;
  }>(),
  { canEdit: false, modified: false, loadingSlots: false, slotsError: false },
);

const emit = defineEmits<{ generate: [] }>();

const { t } = useI18n();

const values = defineModel<Record<string, unknown>>({ default: () => ({}) });

const isDraft = computed(() => props.status === 'draft');

// Expanded while a draft (fill the inputs) or when the user opts to edit; collapsed to a summary
// once generated. `manualExpanded` overrides after the first generate.
const manualExpanded = ref<boolean | null>(null);
watch(
  () => props.status,
  (s) => {
    // Reset the manual override when the lifecycle changes so a fresh draft is expanded again.
    if (s === 'draft') manualExpanded.value = null;
  },
);
const expanded = computed(() => (manualExpanded.value ?? isDraft.value));

function toggle(): void {
  manualExpanded.value = !expanded.value;
}

// --- Summary chips (read straight from the value map — no descriptors needed) ---
const slotNames = computed(() => props.slots.map((s) => s.name));
/** The chips to summarize: declared slots in order, else whatever keys the values carry. */
const summaryEntries = computed(() => {
  const names = slotNames.value.length ? slotNames.value : Object.keys(values.value);
  return names.map((name) => ({ name, value: values.value[name] }));
});

function summarize(value: unknown): string {
  if (value === null || value === undefined || value === '') return t('generator.sessions.setup.emptyValue');
  if (Array.isArray(value)) return t('generator.sessions.setup.itemCount', '', { count: value.length });
  if (typeof value === 'object') {
    const name = (value as Record<string, unknown>).name;
    if (typeof name === 'string' && name) return name;
    return t('generator.sessions.setup.itemCount', '', { count: Object.keys(value as object).length });
  }
  if (typeof value === 'boolean') return value ? t('common.yes', 'Yes') : t('common.no', 'No');
  return String(value);
}

const summaryCount = computed(() => summaryEntries.value.length);
</script>

<template>
  <Card variant="default">
    <template #header>
      <div class="flex min-w-0 flex-1 items-center gap-next-2">
        <Icon name="file-text" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
        <h3 class="min-w-0 truncate text-next-sm font-next-semibold text-next-fg">
          {{ t('generator.sessions.setup.title') }}
        </h3>
        <Badge v-if="modified" variant="modified" tone="subtle" size="sm" icon="pencil">
          {{ t('generator.sessions.setup.dirty') }}
        </Badge>
      </div>
    </template>

    <template #headerActions>
      <!-- Collapse/expand toggle: only after the first generate (a draft stays open). -->
      <Button
        v-if="!isDraft"
        variant="ghost"
        size="sm"
        :leading-icon="expanded ? 'chevron-up' : 'pencil'"
        @click="toggle"
      >
        {{ expanded ? t('generator.sessions.setup.collapse') : t('generator.sessions.setup.edit') }}
      </Button>
    </template>

    <!-- COLLAPSED: a one-line summary of input chips. -->
    <div v-if="!expanded" class="flex flex-col gap-next-2">
      <span class="text-next-xs text-next-muted-foreground">
        {{ t('generator.sessions.setup.summary', '', { count: summaryCount }) }}
      </span>
      <div v-if="summaryEntries.length" class="flex flex-wrap gap-next-1_5">
        <span
          v-for="entry in summaryEntries"
          :key="entry.name"
          class="inline-flex items-center gap-next-1 rounded-next-full bg-next-muted px-next-2 py-next-0_5 text-next-xs text-next-muted-foreground"
        >
          {{ entry.name }}
          <span class="font-next-medium text-next-fg">{{ summarize(entry.value) }}</span>
        </span>
      </div>
    </div>

    <!-- EXPANDED: the typed inputs (+ a Generuj primary while a draft). -->
    <div v-else class="flex flex-col gap-next-4">
      <div v-if="loadingSlots" class="flex flex-col gap-next-2" aria-hidden="true">
        <Skeleton variant="text" width="30%" />
        <Skeleton variant="rect" width="100%" height="2.25rem" radius="md" />
        <Skeleton variant="text" width="30%" />
        <Skeleton variant="rect" width="100%" height="2.25rem" radius="md" />
      </div>

      <Alert v-else-if="slotsError" variant="warning" size="sm">
        {{ t('generator.sessions.setup.slotsError') }}
      </Alert>

      <SlotValuesForm v-else v-model="values" :slots="slots" :disabled="!canEdit" />

      <div v-if="isDraft" class="flex">
        <Button leading-icon="sparkles" @click="emit('generate')">
          {{ t('generator.sessions.generate') }}
        </Button>
      </div>
    </div>
  </Card>
</template>
