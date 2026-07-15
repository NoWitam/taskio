<script setup lang="ts">
// ReportCard — one row in a form's reports list (next).
//
// Built on EntityCard: the report name, a Completed/Generating status badge
// (icon + text), the guidelines as a clamped subtitle, a metadata footer
// (date range, sources, creator), and a kebab — download (when completed),
// delete (Active) or restore / permanent delete (Deleted). Clicking opens the
// report detail. All strings via i18n.
import { computed } from 'vue';
import EntityCard, { type EntityMetaItem } from '../../ui/patterns/EntityCard.vue';
import StatusBadge, { type StatusDescriptor } from '../../ui/data/StatusBadge.vue';
import Button from '../../ui/primitives/Button.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import { creatorIcon, creatorLabel } from '../../ui/patterns/creator';
import { useI18n } from '../../app/i18n';
import type { FormReport } from './types';

const props = defineProps<{
  report: FormReport;
  /** Rendered in the Deleted tab → swaps delete for restore / permanent delete. */
  trashed?: boolean;
}>();
const emit = defineEmits<{
  (e: 'select', report: FormReport): void;
  (e: 'delete', report: FormReport): void;
  (e: 'restore', report: FormReport): void;
  (e: 'force-delete', report: FormReport): void;
}>();

const { t } = useI18n();

const badge = computed<StatusDescriptor>(() =>
  props.report.is_completed
    ? { label: t('forms.reports.completed'), variant: 'success', tone: 'subtle', icon: 'check-circle' }
    : { label: t('forms.reports.pending'), variant: 'warning', tone: 'subtle', icon: 'clock' },
);

function formatDate(iso: string | null): string {
  if (!iso) return '—';
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : iso;
}

// Who + when the report was generated, and from which sources.
const meta = computed<EntityMetaItem[]>(() => {
  const out: EntityMetaItem[] = [];
  if (props.report.creator) {
    out.push({ icon: creatorIcon(props.report.creator), label: creatorLabel(props.report.creator, t) });
  }
  out.push({ icon: 'clock', label: formatDate(props.report.created_at) });
  if (props.report.sources_formatted?.length) {
    out.push({ icon: 'inbox', label: props.report.sources_formatted.join(', ') });
  }
  return out;
});

function download(): void {
  if (props.report.file?.path) window.open(props.report.file.path, '_blank', 'noopener');
}
</script>

<template>
  <EntityCard
    :title="report.name"
    :subtitle="report.guidelines ?? undefined"
    :meta="meta"
    :action-label="t('forms.reports.open', '', { name: report.name })"
    @click="emit('select', report)"
  >
    <template #status>
      <StatusBadge
        :status="report.is_completed ? 'completed' : 'pending'"
        :label="badge.label"
        :status-map="{ [report.is_completed ? 'completed' : 'pending']: badge }"
        size="sm"
      />
    </template>

    <template #actions>
      <DropdownMenu placement="bottom-end" :aria-label="t('forms.reports.actions.menu')">
        <template #trigger="{ props: triggerProps }">
          <Button v-bind="triggerProps" variant="ghost" size="icon-sm" leading-icon="more-vertical" :aria-label="t('forms.reports.actions.menu')" />
        </template>

        <template v-if="trashed">
          <DropdownMenuItem icon="rotate-ccw" :label="t('forms.reports.actions.restore')" @select="emit('restore', report)">
            {{ t('forms.reports.actions.restore') }}
          </DropdownMenuItem>
          <DropdownMenuItem icon="trash" destructive :label="t('forms.reports.actions.forceDelete')" @select="emit('force-delete', report)">
            {{ t('forms.reports.actions.forceDelete') }}
          </DropdownMenuItem>
        </template>
        <template v-else>
          <DropdownMenuItem v-if="report.is_completed && report.file" icon="download" :label="t('forms.reports.actions.download')" @select="download">
            {{ t('forms.reports.actions.download') }}
          </DropdownMenuItem>
          <DropdownMenuItem icon="trash" destructive :label="t('forms.reports.actions.delete')" @select="emit('delete', report)">
            {{ t('forms.reports.actions.delete') }}
          </DropdownMenuItem>
        </template>
      </DropdownMenu>
    </template>
  </EntityCard>
</template>
