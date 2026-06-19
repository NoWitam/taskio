<script setup lang="ts">
// Gallery: StatCard + StatsGrid — KPI cards and their responsive wrapper
// (Patterns tier).
//
// Shows label/value, trend deltas (arrow + sign + tone, never color-only),
// invertTrend for "down is good" metrics, sizes, icon + helper, a sparkline
// slot, the whole-card link, loading skeletons, the StatsGrid (responsive +
// equal heights + loading passthrough), light + dark, and the API.
import StatCard from '../../ui/patterns/StatCard.vue';
import StatsGrid from '../../ui/patterns/StatsGrid.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

// A tiny inline sparkline (no chart lib) for the sparkline-slot demo.
const spark = [4, 7, 5, 9, 8, 12, 11, 15];
const sparkMax = Math.max(...spark);
function points(values: number[]): string {
  const w = 100;
  const h = 28;
  return values
    .map((v, i) => {
      const x = (i / (values.length - 1)) * w;
      const y = h - (v / sparkMax) * h;
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    })
    .join(' ');
}

const statPropRows: ApiRow[] = [
  { name: 'label', type: 'string', default: '—', description: 'Metric name.' },
  { name: 'value', type: 'string | number', default: '—', description: 'The metric value (numbers get tabular-nums).' },
  { name: 'icon', type: 'IconName', default: '—', description: 'Leading icon in a tinted bubble.' },
  { name: 'delta', type: 'number', default: '—', description: 'Signed change → arrow + sign + tone.' },
  { name: 'invertTrend', type: 'boolean', default: 'false', description: '"Down is good": a negative delta reads as success.' },
  { name: 'deltaSuffix', type: 'string', default: '—', description: 'Suffix on the delta (e.g. "%").' },
  { name: 'deltaLabel', type: 'string', default: '—', description: 'Explanatory text (e.g. "vs last week").' },
  { name: 'helper', type: 'string', default: '—', description: 'Footnote under the value.' },
  { name: 'to / href', type: 'RouteLocationRaw / string', default: '—', description: 'Whole-card link.' },
  { name: 'actionLabel', type: 'string', default: '—', description: 'Accessible name for the whole-card link.' },
  { name: 'size', type: "'md' | 'lg'", default: "'md'", description: 'Value + bubble scale.' },
  { name: 'loading', type: 'boolean', default: 'false', description: 'Skeleton variant mirroring the geometry.' },
];
const statSlotRows: ApiRow[] = [
  { name: 'delta', type: 'slot', description: 'Custom delta content (overrides the delta prop rendering).' },
  { name: 'sparkline', type: 'slot', description: 'A bare slot for a sparkline — no chart library ships here.' },
];
const gridPropRows: ApiRow[] = [
  { name: 'cols', type: 'number | { base, sm, md, lg, xl }', default: '4', description: 'Columns; a number expands to the ramp 1 / 2 / N.' },
  { name: 'gap', type: "'2'…'6'", default: "'4'", description: 'Grid gap token key.' },
  { name: 'loading', type: 'boolean', default: 'false', description: 'Render `count` skeleton StatCards instead of the slot.' },
  { name: 'count', type: 'number', default: '4', description: 'Skeleton card count while loading.' },
  { name: 'loadingSize', type: "'md' | 'lg'", default: "'md'", description: 'Skeleton card size.' },
];
</script>

<template>
  <StoryPage
    title="StatCard + StatsGrid"
    description="KPI cards built on Card: a label, a big tabular-nums value, an optional trend delta (arrow + sign + tone — never color-only), an icon, helper text, and a sparkline slot. StatsGrid lays them out responsively at equal heights, with a loading passthrough."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The trend delta is never color-only: it pairs the success/danger tone with a direction <strong>arrow</strong> and an explicit <strong>sign</strong>.</li>
        <li><code>invertTrend</code> only swaps the tone (for "down is good" metrics); the arrow still follows the raw sign.</li>
        <li>When <code>to</code> / <code>href</code> is set the whole card is a single accessible link (stretched over the label).</li>
        <li>The loading state mirrors the card geometry with several skeletons, never a spinner.</li>
      </ul>
    </template>

    <StorySection title="Anatomy" description="Icon, label, big value, trend delta, and a helper footnote.">
      <div class="max-w-xs">
        <StatCard
          icon="users"
          label="Active users"
          :value="'12,480'"
          :delta="8.2"
          delta-suffix="%"
          delta-label="vs last week"
          helper="Rolling 7-day average"
        />
      </div>
    </StorySection>

    <StorySection title="Trend deltas" description="Up / down / flat, and invertTrend for metrics where down is good (arrow follows the sign; only the tone swaps).">
      <StoryGrid :cols="2" align="start">
        <StoryCell label="up = good (success)">
          <StatCard icon="arrow-up" label="Revenue" value="$48.2k" :delta="12" delta-suffix="%" delta-label="MoM" />
        </StoryCell>
        <StoryCell label="down = bad (danger)">
          <StatCard icon="users" label="Signups" :value="320" :delta="-6" delta-suffix="%" delta-label="MoM" />
        </StoryCell>
        <StoryCell label="invertTrend: down = good (success)">
          <StatCard icon="alert-triangle" label="Error rate" value="0.42%" :delta="-18" delta-suffix="%" delta-label="WoW" invert-trend />
        </StoryCell>
        <StoryCell label="flat (neutral)">
          <StatCard icon="clock" label="Avg. response" value="240ms" :delta="0" delta-suffix="ms" delta-label="WoW" />
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Sizes" description="md (default) · lg.">
      <StoryGrid :cols="2" align="start">
        <StoryCell label="md">
          <StatCard icon="mail" label="Open rate" value="42%" :delta="3" delta-suffix="pts" size="md" />
        </StoryCell>
        <StoryCell label="lg">
          <StatCard icon="mail" label="Open rate" value="42%" :delta="3" delta-suffix="pts" size="lg" />
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Sparkline slot" description="Drop any inline SVG into #sparkline — no chart library ships in the design system.">
      <div class="max-w-xs">
        <StatCard icon="star" label="Daily active" :value="'1,204'" :delta="5.4" delta-suffix="%">
          <template #sparkline>
            <svg viewBox="0 0 100 28" preserveAspectRatio="none" class="h-8 w-full text-next-primary" aria-hidden="true">
              <polyline :points="points(spark)" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" />
            </svg>
          </template>
        </StatCard>
      </div>
    </StorySection>

    <StorySection title="Whole-card link" description="With href/to the card lifts on hover and is one accessible link (Tab to it).">
      <div class="max-w-xs">
        <StatCard icon="layout-dashboard" label="Open tasks" :value="37" :delta="-4" href="#tasks" action-label="View open tasks" helper="Click to view the list" />
      </div>
    </StorySection>

    <StorySection title="Loading skeleton" description="Mirrors the icon bubble + label + big value + delta line.">
      <StoryGrid :cols="2" align="start">
        <StoryCell label="md"><StatCard label="" value="" loading /></StoryCell>
        <StoryCell label="lg"><StatCard label="" value="" loading size="lg" /></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="StatsGrid" description="A responsive wrapper (base 1 → sm 2 → 4) with equal-height cards.">
      <StatsGrid :cols="4">
        <StatCard icon="users" label="Active users" :value="'12,480'" :delta="8" delta-suffix="%" />
        <StatCard icon="mail" label="Open rate" value="42%" :delta="3" delta-suffix="pts" />
        <StatCard icon="alert-triangle" label="Error rate" value="0.42%" :delta="-18" delta-suffix="%" invert-trend />
        <StatCard icon="clock" label="Avg. response" value="240ms" :delta="0" delta-suffix="ms" />
      </StatsGrid>
    </StorySection>

    <StorySection title="StatsGrid — loading passthrough" description=":loading renders `count` skeleton StatCards in the same grid.">
      <StatsGrid :cols="4" loading :count="4" />
    </StorySection>

    <StorySection title="Light + dark">
      <div class="grid gap-next-4 sm:grid-cols-2">
        <div class="next-root rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <StatCard icon="users" label="Active users" :value="'12,480'" :delta="8" delta-suffix="%" delta-label="vs last week" />
        </div>
        <div class="next-root dark rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <StatCard icon="users" label="Active users" :value="'12,480'" :delta="8" delta-suffix="%" delta-label="vs last week" />
        </div>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="StatCard — Props" :rows="statPropRows" show-default />
        <ApiTable title="StatCard — Slots" type-header="Kind" :rows="statSlotRows" />
        <ApiTable title="StatsGrid — Props" :rows="gridPropRows" show-default />
      </div>
    </StorySection>
  </StoryPage>
</template>
