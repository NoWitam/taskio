<script setup lang="ts">
// SessionSlotSetupCard — the opening, collapsible "Setup" turn of the session chat (owner decision #2).
//
// The typed slot form authored AS the opening message: EXPANDED while the session is a `draft`, then
// COLLAPSED to a summary of the entered values after the first generate — re-expandable on demand and
// still editable during refinement (a `ready` session). A `modified` marker (Badge variant="modified")
// shows when a value changed since the last generate. It reuses `SlotValuesForm` (→ the shared
// `TypedLiteralInput`) for the inputs; the summary reads straight from the value map, so it renders
// even before the source template's descriptors have loaded.
//
// The summary is a DEFINITION LIST, not chips. Real slot values are whole paragraphs (a brief, an
// audience description — hundreds of characters), and a full-radius pill wrapping onto four lines with
// its label drifting off to the left reads as broken layout. A `dt`/`dd` row clamped to two lines looks
// identical whether the value is "3" or an essay. `summarize()` (the empty / list / file cases) is
// untouched — this is presentation only.
import { computed, ref, watch } from 'vue';
import Card from '../../../ui/layout/Card.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Button from '../../../ui/primitives/Button.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import Alert from '../../../ui/feedback/Alert.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import SlotValuesForm from './SlotValuesForm.vue';
import { nextId } from '../../../ui/forms/formField';
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

// A stable id for the form region so the toggle's `aria-controls` always resolves — the region stays
// MOUNTED and is hidden with v-show (never v-if), so SlotValuesForm keeps its per-input state (an
// in-flight file pick included) across a collapse.
const bodyId = nextId('next-setup');

/**
 * The toggle's label. Expanding this card is ALSO entering edit mode, so when editing is actually
 * possible the expand label keeps saying so ("Edit inputs") — that is the honest promise of the click.
 * When the session is read-only it degrades to the shared "Show", because "Edit inputs" would lie.
 * The ICON and the behavior are the shared chevron pattern in either case (owner note #2).
 */
const toggleLabel = computed(() => {
  if (expanded.value) return t('generator.sessions.toggle.collapse');
  return props.canEdit ? t('generator.sessions.setup.edit') : t('generator.sessions.toggle.expand');
});

// --- Summary (read straight from the value map — no descriptors needed) ---
const slotNames = computed(() => props.slots.map((s) => s.name));
/** The rows to summarize: declared slots in order, else whatever keys the values carry. */
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
      <!-- Collapse/expand toggle — the same chevron affordance as every other turn card. Absent while a
           DRAFT on purpose: the draft body holds the only Generate button, so a collapse there would hide
           the screen's critical action behind a control the user has no reason to re-open. -->
      <Button
        v-if="!isDraft"
        variant="ghost"
        size="sm"
        :leading-icon="expanded ? 'chevron-up' : 'chevron-down'"
        :aria-expanded="expanded ? 'true' : 'false'"
        :aria-controls="bodyId"
        @click="toggle"
      >
        {{ toggleLabel }}
      </Button>
    </template>

    <!-- COLLAPSED: the counter line + a definition list of the entered values (2-line clamp, so a
         200-character brief and a one-word value produce the same row shape). -->
    <div v-show="!expanded" class="flex flex-col gap-next-2">
      <span class="text-next-xs text-next-muted-foreground">
        {{ t('generator.sessions.setup.summary', '', { count: summaryCount }) }}
      </span>
      <dl v-if="summaryEntries.length" class="flex flex-col gap-next-2">
        <div v-for="entry in summaryEntries" :key="entry.name" class="flex min-w-0 flex-col gap-next-0_5">
          <dt class="text-next-xs font-next-medium text-next-muted-foreground">{{ entry.name }}</dt>
          <!-- `break-words` so an unbroken 300-character value can never widen the card. -->
          <dd class="line-clamp-2 break-words text-next-sm text-next-fg">{{ summarize(entry.value) }}</dd>
        </div>
      </dl>
    </div>

    <!-- EXPANDED: the typed inputs (+ a Generuj primary while a draft). Hidden with v-show, never v-if,
         so the form (and any in-flight input state) survives a collapse. -->
    <div v-show="expanded" :id="bodyId" class="flex flex-col gap-next-4">
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
