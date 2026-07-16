<script setup lang="ts">
// Gallery: Timeline (+ TimelineItem) — vertical activity / history feed
// (Patterns tier).
//
// Shows the default + compact density, node tones, per-item highlighted /
// pending / last states, Avatar nodes, item actions, a clamped/expandable
// description, loading skeletons, the empty state, a realistic approvals
// history, light + dark, and the API.
import Timeline, { type TimelineEntry } from '../../ui/patterns/Timeline.vue';
import TimelineItem from '../../ui/patterns/TimelineItem.vue';
import Avatar from '../../ui/primitives/Avatar.vue';
import Button from '../../ui/primitives/Button.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const history: TimelineEntry[] = [
  { id: 1, title: 'Form created', icon: 'plus', tone: 'neutral', time: '13 Jun, 09:14', datetime: '2026-06-13T09:14', description: 'Draft created by Anna Kowalska.' },
  { id: 2, title: 'Submitted for review', icon: 'upload', tone: 'info', time: '13 Jun, 11:02', datetime: '2026-06-13T11:02', description: 'Sent to the marketing approvers.' },
  { id: 3, title: 'Changes requested', icon: 'alert-triangle', tone: 'warning', time: '13 Jun, 14:30', datetime: '2026-06-13T14:30', description: 'Reviewer asked to tighten the intro copy and fix the CTA color contrast in dark mode.' },
  { id: 4, title: 'Awaiting re-review', icon: 'clock', tone: 'primary', time: '14 Jun, 08:45', datetime: '2026-06-14T08:45', description: 'Resubmitted; pending approver response.', pending: true },
];

const approvalPropRows: ApiRow[] = [
  { name: 'items', type: 'TimelineEntry[]', default: '—', description: 'Convenience data source (or compose TimelineItem in the slot).' },
  { name: 'compact', type: 'boolean', default: 'false', description: 'Dense layout (smaller nodes, tighter spacing).' },
  { name: 'clampLines', type: 'number', default: '—', description: 'Clamp each entry description to N lines (+ Show more).' },
  { name: 'ariaLabel', type: 'string', default: "'Activity timeline'", description: 'Accessible label for the <ol>.' },
  { name: 'loading', type: 'boolean', default: 'false', description: 'Skeleton items mirroring node + lines.' },
  { name: 'loadingCount', type: 'number', default: '4', description: 'Skeleton item count.' },
  { name: 'emptyTitle / emptyDescription', type: 'string', default: '—', description: 'EmptyState content when there are no items.' },
];
const itemPropRows: ApiRow[] = [
  { name: 'title', type: 'string', default: '—', description: 'Item title (or #title slot).' },
  { name: 'icon', type: 'IconName', default: '—', description: 'Node icon (or #node slot for an Avatar).' },
  { name: 'tone', type: 'NodeTone', default: "'neutral'", description: 'Node circle tone.' },
  { name: 'time / datetime', type: 'string', default: '—', description: 'Timestamp text + machine-readable <time datetime>.' },
  { name: 'description', type: 'string', default: '—', description: 'Body text (or default slot for rich content).' },
  { name: 'clampLines', type: 'number', default: '—', description: 'Clamp the description + offer a Show more toggle.' },
  { name: 'highlighted', type: 'boolean', default: 'false', description: 'Accent ring on the node.' },
  { name: 'pending', type: 'boolean', default: 'false', description: 'In-progress step — a soft pulsing node.' },
  { name: 'last', type: 'boolean', default: 'false', description: 'Drop the connector below this item.' },
];
const itemSlotRows: ApiRow[] = [
  { name: 'node', type: 'slot', description: 'Custom node (e.g. an Avatar) instead of the toned icon circle.' },
  { name: 'title / afterTitle', type: 'slot', description: 'Custom title content / inline content after the title.' },
  { name: 'default', type: 'slot', description: 'Rich description content (overrides the description prop).' },
  { name: 'actions', type: 'slot', description: 'Per-item actions (right-aligned, above the connector).' },
];
</script>

<template>
  <StoryPage
    title="Timeline"
    description="A vertical activity / history feed. A connector line links toned-icon (or Avatar) nodes, each with a title, optional clamped description, a real <time> timestamp, and optional actions. Density via compact; per-item highlighted / pending / last states; loading + empty states."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The feed is an <code>&lt;ol&gt;</code> with an <code>aria-label</code>; each entry is an <code>&lt;li&gt;</code>.</li>
        <li>Timestamps use a real <code>&lt;time datetime&gt;</code> so they're machine-readable.</li>
        <li>Node tone is decorative — meaning lives in the title/description text, never color alone.</li>
        <li>The loading state mirrors node + lines with several skeletons (not a spinner); the empty state is an announced EmptyState.</li>
      </ul>
    </template>

    <StorySection title="Default" description="Toned-icon nodes, titles, timestamps, and descriptions. The last item drops its connector.">
      <Timeline :items="history" aria-label="Form approval history" />
    </StorySection>

    <StorySection title="Compact density" description="Smaller nodes and tighter spacing for dense logs.">
      <Timeline :items="history" compact aria-label="Form approval history (compact)" />
    </StorySection>

    <StorySection title="Node tones" description="neutral · primary · success · warning · danger · info.">
      <Timeline aria-label="Tones">
        <TimelineItem icon="plus" tone="neutral" title="Neutral" time="step 1" datetime="2026-06-13" description="A routine event." />
        <TimelineItem icon="upload" tone="info" title="Info" time="step 2" datetime="2026-06-13" description="An informational event." />
        <TimelineItem icon="clock" tone="primary" title="Primary" time="step 3" datetime="2026-06-13" description="A primary / in-flight event." />
        <TimelineItem icon="check-circle" tone="success" title="Success" time="step 4" datetime="2026-06-13" description="A successful outcome." />
        <TimelineItem icon="alert-triangle" tone="warning" title="Warning" time="step 5" datetime="2026-06-13" description="Needs attention." />
        <TimelineItem icon="x-circle" tone="danger" title="Danger" time="step 6" datetime="2026-06-13" description="A failure." last />
      </Timeline>
    </StorySection>

    <StorySection title="Item states" description="highlighted (ring) · pending (pulsing, in-progress) · Avatar node · per-item actions.">
      <Timeline aria-label="Item states">
        <TimelineItem icon="check-circle" tone="success" title="Approved by Anna" time="09:14" datetime="2026-06-13T09:14" description="Looks good — shipping it." />
        <TimelineItem title="In review (highlighted)" tone="primary" icon="eye" highlighted time="11:02" datetime="2026-06-13T11:02" description="Currently the focused entry." />
        <TimelineItem title="Awaiting build (pending)" tone="primary" icon="clock" pending time="11:40" datetime="2026-06-13T11:40" description="A soft pulse marks the in-progress step." />
        <TimelineItem title="Comment from Piotr" time="12:10" datetime="2026-06-13T12:10" description="Left a note on the copy." last>
          <template #node><Avatar name="Piotr Nowak" size="sm" /></template>
          <template #actions>
            <Button size="xs" variant="ghost" leading-icon="external-link" aria-label="Open comment">Open</Button>
          </template>
        </TimelineItem>
      </Timeline>
    </StorySection>

    <StorySection title="Clamped + expandable description" description="Long descriptions clamp to N lines with a Show more toggle.">
      <Timeline aria-label="Clamped">
        <TimelineItem
          icon="file-text"
          tone="info"
          title="Long changelog entry"
          time="14:30"
          datetime="2026-06-13T14:30"
          :clamp-lines="2"
          description="Reworked the entire onboarding flow: split the signup into three steps, added inline validation to every field, mapped the empty and error states for each screen, replaced the legacy spinner loading with skeletons, and tightened the dark-mode contrast on every status badge to pass WCAG AA."
          last
        />
      </Timeline>
    </StorySection>

    <StorySection title="Loading" description="Several skeleton items mimicking node + title + lines.">
      <Timeline loading :loading-count="4" aria-label="Loading activity" />
    </StorySection>

    <StorySection title="Empty" description="An items array of length 0 renders an EmptyState (sm).">
      <Timeline :items="[]" empty-title="No activity yet" empty-description="Actions on this form will show up here." aria-label="Empty activity" />
    </StorySection>

    <StorySection title="Realistic approvals history">
      <Timeline aria-label="Approval history">
        <TimelineItem icon="plus" tone="neutral" title="Created" time="13 Jun, 09:14" datetime="2026-06-13T09:14">
          <template #node><Avatar name="Anna Kowalska" size="sm" /></template>
          Anna Kowalska drafted the campaign form.
        </TimelineItem>
        <TimelineItem icon="upload" tone="info" title="Submitted for review" time="13 Jun, 11:02" datetime="2026-06-13T11:02">
          Routed to two marketing approvers.
        </TimelineItem>
        <TimelineItem title="Changes requested" tone="warning" icon="alert-triangle" time="13 Jun, 14:30" datetime="2026-06-13T14:30">
          <template #afterTitle><StatusBadge status="warning" label="Action needed" size="sm" /></template>
          Tighten the intro copy and fix the CTA contrast in dark mode.
        </TimelineItem>
        <TimelineItem title="Re-review pending" tone="primary" icon="clock" pending time="14 Jun, 08:45" datetime="2026-06-14T08:45" last>
          Resubmitted; waiting on the approver.
        </TimelineItem>
      </Timeline>
    </StorySection>

    <StorySection title="Light + dark">
      <div class="grid gap-next-4 sm:grid-cols-2">
        <div class="next-root rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <Timeline :items="history.slice(0, 3)" aria-label="Light" />
        </div>
        <div class="next-root dark rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <Timeline :items="history.slice(0, 3)" aria-label="Dark" />
        </div>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Timeline — Props" :rows="approvalPropRows" show-default />
        <ApiTable title="TimelineItem — Props" :rows="itemPropRows" show-default />
        <ApiTable title="TimelineItem — Slots" type-header="Kind" :rows="itemSlotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
