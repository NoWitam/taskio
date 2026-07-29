<script setup lang="ts">
// SessionFillReportPanel — the outcome of a bot DELEGATION, rendered inline in the chat (R2 sub-stage 3).
//
// After `store.delegate(...)` the bot's autonomous slot-fill returns a {@see SlotFillReport}; this panel makes
// it legible: WHICH inputs the bot FILLED, which it SKIPPED (with a localized reason — unknown_slot /
// out_of_scope / invalid), and the `unfilled_required` inputs the human MUST still complete. A required FILE
// slot gets a distinct "needs your file" line (files are never bot-filled), and any unfilled-required surfaces
// a "Complete inputs" action that routes the human to the setup form. Every signal is icon + text (never
// color-only). Dismissable — the report is transient (it rides the delegate response, not the persisted wire).
//
// HONEST "nothing to fill": when the server reports `nothing_to_fill` (gaps mode, no empty slots) it made NO
// AI call and spent nothing — the panel says exactly that instead of rendering a misleading "filled 0 inputs"
// summary, and points at the "propose everything fresh" mode. The MODE that ran is always surfaced, so a
// human who picked "only the empty inputs" understands why little (or nothing) changed.
import { computed } from 'vue';
import Card from '../../../ui/layout/Card.vue';
import Button from '../../../ui/primitives/Button.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import { useI18n } from '../../../app/i18n';
import type { SlotFillMode, SlotFillReport, SlotSkipReason } from '../sessionTypes';
import type { TemplateSlot } from '../types';

const props = defineProps<{
  /** The autonomous fill outcome to render. */
  report: SlotFillReport;
  /** The delegated bot's display name (for the "{bot} filled…" heading). */
  botName: string;
  /** The source-template slots — used to flag a required FILE slot ("needs your file"). */
  slots: TemplateSlot[];
}>();

const emit = defineEmits<{ dismiss: []; completeInputs: [] }>();

const { t } = useI18n();

const filledCount = computed(() => props.report.filled.length);
const hasSkipped = computed(() => props.report.skipped.length > 0);
const hasUnfilled = computed(() => props.report.unfilled_required.length > 0);

/** The server ran with nothing to do (gaps mode, no empty slots): no AI call, nothing spent. */
const nothingToFill = computed(() => props.report.nothing_to_fill === true);
/** The mode the server actually used — only rendered for a mode we can name. */
const mode = computed<SlotFillMode | null>(() =>
  props.report.mode === 'gaps' || props.report.mode === 'fresh' ? props.report.mode : null,
);
const modeLine = computed(() =>
  mode.value
    ? t('generator.sessions.delegate.report.modeLine', '', {
        mode: t(`generator.sessions.delegate.report.mode.${mode.value}`),
      })
    : null,
);

/** The descriptor base for a slot name (from the source template), or null when unknown. */
function slotBase(name: string): string | null {
  const slot = props.slots.find((s) => s.name === name);
  return (slot?.descriptor?.base as string | undefined) ?? null;
}

/** A required FILE slot the bot can never fill — surfaced as a distinct "needs your file" state. */
function isFileSlot(name: string): boolean {
  return slotBase(name) === 'file';
}

function reasonLabel(reason: SlotSkipReason): string {
  return t(`generator.sessions.delegate.report.reason.${reason}`, reason);
}
</script>

<template>
  <Card variant="default">
    <template #header>
      <div class="flex min-w-0 flex-1 items-center gap-next-2">
        <span
          class="flex h-7 w-7 shrink-0 items-center justify-center rounded-next-full bg-next-primary-subtle text-next-primary-subtle-foreground"
          aria-hidden="true"
        >
          <Icon name="sparkles" />
        </span>
        <h3 class="min-w-0 truncate text-next-sm font-next-semibold text-next-fg">
          {{
            nothingToFill
              ? t('generator.sessions.delegate.report.nothingTitle', '', { name: botName })
              : t('generator.sessions.delegate.report.title', '', { name: botName })
          }}
        </h3>
      </div>
    </template>

    <template #headerActions>
      <Button
        variant="ghost"
        size="icon-sm"
        :aria-label="t('generator.sessions.delegate.report.dismiss')"
        @click="emit('dismiss')"
      >
        <Icon name="x" />
      </Button>
    </template>

    <div class="flex flex-col gap-next-3">
      <!-- Which mode actually ran — explains WHY little (or nothing) changed. -->
      <span
        v-if="modeLine"
        class="inline-flex items-center gap-next-1 self-start rounded-next-full bg-next-muted px-next-2 py-next-0_5 text-next-xs text-next-muted-foreground"
      >
        <Icon name="settings" aria-hidden="true" />
        {{ modeLine }}
      </span>

      <!-- Nothing to fill (gaps mode, no empty inputs): NO AI call, nothing spent — said honestly. -->
      <div
        v-if="nothingToFill"
        class="flex flex-col gap-next-1_5 rounded-next-lg border border-next-border bg-next-muted/20 p-next-3"
      >
        <span class="inline-flex items-start gap-next-1_5 text-next-sm text-next-fg">
          <Icon name="info" class="mt-px shrink-0 text-next-muted-foreground" aria-hidden="true" />
          <span>{{ t('generator.sessions.delegate.report.nothingToFill', '', { name: botName }) }}</span>
        </span>
        <span class="text-next-xs text-next-muted-foreground">
          {{ t('generator.sessions.delegate.report.nothingToFillNoSpend') }}
        </span>
        <span class="text-next-xs text-next-muted-foreground">
          {{ t('generator.sessions.delegate.report.nothingToFillHint') }}
        </span>
      </div>

      <!-- Filled -->
      <div v-else class="flex flex-col gap-next-1_5">
        <span class="inline-flex items-center gap-next-1 text-next-xs font-next-medium text-next-muted-foreground">
          <Icon name="check-circle" class="text-next-success" aria-hidden="true" />
          {{ t('generator.sessions.delegate.report.filledSummary', '', { name: botName, count: filledCount }) }}
        </span>
        <div v-if="filledCount" class="flex flex-wrap gap-next-1_5">
          <span
            v-for="name in report.filled"
            :key="name"
            class="inline-flex items-center gap-next-1 rounded-next-full bg-next-muted px-next-2 py-next-0_5 text-next-xs text-next-fg"
          >
            {{ name }}
          </span>
        </div>
        <span v-else class="text-next-xs text-next-muted-foreground">
          {{ t('generator.sessions.delegate.report.noneFilled') }}
        </span>
      </div>

      <!-- Skipped (with a localized reason) -->
      <div v-if="hasSkipped" class="flex flex-col gap-next-1_5">
        <span class="inline-flex items-center gap-next-1 text-next-xs font-next-medium text-next-muted-foreground">
          <Icon name="alert-circle" class="text-next-warning" aria-hidden="true" />
          {{ t('generator.sessions.delegate.report.skipped') }}
        </span>
        <ul class="flex flex-col gap-next-1">
          <li
            v-for="item in report.skipped"
            :key="item.name"
            class="flex items-center gap-next-1 text-next-xs text-next-muted-foreground"
          >
            <span class="font-next-medium text-next-fg">{{ item.name }}</span>
            <span aria-hidden="true">—</span>
            <span>{{ reasonLabel(item.reason) }}</span>
          </li>
        </ul>
      </div>

      <!-- Unfilled required (esp. required FILE slots the bot can't fill) -->
      <div v-if="hasUnfilled" class="flex flex-col gap-next-2 rounded-next-lg border border-next-warning-subtle bg-next-warning-subtle/40 p-next-3">
        <span class="inline-flex items-center gap-next-1 text-next-xs font-next-semibold text-next-warning-subtle-foreground">
          <Icon name="alert-triangle" aria-hidden="true" />
          {{ t('generator.sessions.delegate.report.unfilled') }}
        </span>
        <ul class="flex flex-col gap-next-1">
          <li
            v-for="name in report.unfilled_required"
            :key="name"
            class="flex items-center gap-next-1_5 text-next-xs text-next-fg"
          >
            <Icon
              :name="isFileSlot(name) ? 'upload' : 'circle'"
              class="shrink-0 text-next-muted-foreground"
              aria-hidden="true"
            />
            <span class="font-next-medium">{{ name }}</span>
            <span v-if="isFileSlot(name)" class="text-next-muted-foreground">
              — {{ t('generator.sessions.delegate.report.needsYourFile') }}
            </span>
          </li>
        </ul>
        <div>
          <Button variant="outline" size="sm" leading-icon="arrow-right" @click="emit('completeInputs')">
            {{ t('generator.sessions.delegate.report.completeInputs') }}
          </Button>
        </div>
      </div>
    </div>
  </Card>
</template>
