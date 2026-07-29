<script setup lang="ts">
// SessionBudgetChip — a COMPACT inline AI-budget signal near the composer (R2 sub-stage 4).
//
// Reads the shared workspace usage summary. HIDDEN when uncapped (no scary meter for a no-limit workspace);
// an amber "N% of budget" once `warn_reached`; a red "Budget reached" when `blocked`. Never color-only — the
// wallet glyph + the text carry the meaning too, and the amber/red is a token (dark-mode safe). $ figures are
// ESTIMATES; the chip's title carries the caveat.
import { computed } from 'vue';
import Badge from '../../../ui/primitives/Badge.vue';
import { useI18n } from '../../../app/i18n';
import { isUncapped, meterState, usedPercent } from '../../workspaces/aiUsageMeta';
import type { AiUsageSummary } from '../../../app/stores/aiUsage';

const props = defineProps<{
  /** The shared usage summary (null until fetched → the chip renders nothing). */
  summary: AiUsageSummary | null;
}>();

const { t } = useI18n();

// Only meaningful when there IS a cap: an uncapped workspace shows no budget chip at all.
const visible = computed(() => !!props.summary && !isUncapped(props.summary));
const state = computed(() => (props.summary ? meterState(props.summary) : 'ok'));
const percent = computed(() => (props.summary ? usedPercent(props.summary) : 0));

// Show the chip once the warn threshold is reached (or blocked); below that it stays quiet so it isn't noise.
const show = computed(() => visible.value && (state.value === 'warn' || state.value === 'blocked'));

const variant = computed<'warning' | 'danger'>(() => (state.value === 'blocked' ? 'danger' : 'warning'));
const label = computed(() =>
  state.value === 'blocked'
    ? t('generator.sessions.budget.chipBlocked')
    : t('generator.sessions.budget.chipWarn', '', { percent: percent.value }),
);
</script>

<template>
  <Badge
    v-if="show"
    :variant="variant"
    tone="subtle"
    size="sm"
    icon="wallet"
    :title="t('generator.sessions.budget.estimatedHint')"
  >
    {{ label }}
  </Badge>
</template>
