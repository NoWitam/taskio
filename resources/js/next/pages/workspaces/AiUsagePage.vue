<script setup lang="ts">
// AiUsagePage — the $-first workspace AI-usage meter + owner cap editor (R2 sub-stage 4).
//
// Any workspace MEMBER sees the read-only meter (cost_used / cost_cap / cost_remaining big in $, an
// accessible Progress meter paired with a StatusBadge, the per-channel StatCards and the per-actor
// breakdown). The OWNER additionally sees the monthly-cap editor — gated on the SERVER `summary.can_manage`
// flag, never a client role guess. Every $ figure is an ESTIMATE (the caveat is always visible); tokens are
// a SECONDARY, muted display. cap_source 'unlimited' / cost_cap 0 renders a clean "no limit" state (no scary
// empty meter). display_name:null actors fall back to a localized per-type label; the "others" bucket is
// labelled. Covers loading / error / empty / success. NEXT tokens only; the meter carries aria; no signal is
// color-only.
import { computed, onMounted, ref } from 'vue';
import { useAuthStore } from '../../app/stores/auth';
import { useAiUsageStore, type AiUsageActor, type AiUsageChannel } from '../../app/stores/aiUsage';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useI18n } from '../../app/i18n';
import Container from '../../ui/layout/Container.vue';
import Stack from '../../ui/layout/Stack.vue';
import Card from '../../ui/layout/Card.vue';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import Progress from '../../ui/feedback/Progress.vue';
import StatsGrid from '../../ui/patterns/StatsGrid.vue';
import StatCard from '../../ui/patterns/StatCard.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import StatusBadge, { type StatusMap } from '../../ui/data/StatusBadge.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import Button from '../../ui/primitives/Button.vue';
import AiUsageCapEditor from './AiUsageCapEditor.vue';
import {
  actorIcon,
  actorLabel,
  actorGlyphTone,
  channelIcon,
  channelLabel,
  formatMoney,
  formatTokens,
  isUncapped,
  meterState,
  meterTone,
  usedPercent,
} from './aiUsageMeta';

const { t } = useI18n();
const auth = useAuthStore();
const store = useAiUsageStore();
const toast = useToast();
const confirm = useConfirm();

const workspaceId = computed(() => auth.currentWorkspaceId);
const summary = computed(() => store.summary);
const currency = computed(() => summary.value?.currency ?? 'USD');

// --- Load -------------------------------------------------------------------
async function load(): Promise<void> {
  const id = workspaceId.value;
  if (id == null) return;
  // The store owns loading/errored; a rejection is already reflected there.
  await store.fetchAiUsage(id).catch(() => undefined);
}
onMounted(load);

// --- Derived meter state ----------------------------------------------------
const uncapped = computed(() => (summary.value ? isUncapped(summary.value) : true));
const state = computed(() => (summary.value ? meterState(summary.value) : 'unlimited'));
const tone = computed(() => meterTone(state.value));
const percent = computed(() => (summary.value ? usedPercent(summary.value) : 0));

const meterStatusMap = computed<StatusMap>(() => ({
  ok: { label: t('workspaces.aiUsage.state.ok'), variant: 'success', tone: 'subtle', icon: 'check-circle' },
  warn: { label: t('workspaces.aiUsage.state.warn'), variant: 'warning', tone: 'subtle', icon: 'alert-triangle' },
  blocked: { label: t('workspaces.aiUsage.state.blocked'), variant: 'danger', tone: 'solid', icon: 'x-circle' },
  unlimited: { label: t('workspaces.aiUsage.state.unlimited'), variant: 'info', tone: 'subtle', icon: 'check-circle' },
}));

// --- $ figures + labels -----------------------------------------------------
const usedLabel = computed(() => (summary.value ? formatMoney(summary.value.cost_used, currency.value) : '—'));
const capLabel = computed(() =>
  !summary.value || uncapped.value
    ? t('workspaces.aiUsage.noLimit')
    : formatMoney(summary.value.cost_cap, currency.value),
);
const remainingLabel = computed(() => {
  if (!summary.value || uncapped.value || summary.value.cost_remaining == null) {
    return t('workspaces.aiUsage.noLimit');
  }
  return formatMoney(summary.value.cost_remaining, currency.value);
});
const tokensLabel = computed(() => (summary.value ? formatTokens(summary.value.tokens_used) : '0'));

const capSourceNote = computed(() => {
  if (!summary.value) return '';
  return t(`workspaces.aiUsage.capSource.${summary.value.cap_source}`);
});

const resetsOn = computed(() => {
  const iso = summary.value?.period?.resets_at;
  if (!iso) return '';
  const parsed = new Date(iso);
  return Number.isNaN(parsed.getTime())
    ? ''
    : new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' }).format(parsed);
});

const meterAria = computed(() =>
  t('workspaces.aiUsage.meterAria', '', {
    used: usedLabel.value,
    cap: summary.value && !uncapped.value ? formatMoney(summary.value.cost_cap, currency.value) : capLabel.value,
  }),
);

// --- Breakdowns -------------------------------------------------------------
const channels = computed<AiUsageChannel[]>(() => summary.value?.per_channel ?? []);
const actors = computed<AiUsageActor[]>(() => summary.value?.per_actor ?? []);
const channelsEmpty = computed(() => channels.value.length === 0);
const actorsEmpty = computed(() => actors.value.length === 0);

function actorGlyphClass(actor: AiUsageActor): string {
  switch (actorGlyphTone(actor)) {
    case 'primary':
      return 'bg-next-primary-subtle text-next-primary-subtle-foreground';
    case 'info':
      return 'bg-next-info-subtle text-next-info-subtle-foreground';
    default:
      return 'bg-next-muted text-next-muted-foreground';
  }
}

function actorRowKey(actor: AiUsageActor, index: number): string {
  return `${actor.actor_type}|${actor.actor_id ?? ''}|${index}`;
}

// --- Owner cap write --------------------------------------------------------
const capSummaryFor = (cap: number | null): string => {
  if (cap == null) return t('workspaces.aiUsage.confirm.toDefault');
  if (cap === 0) return t('workspaces.aiUsage.confirm.toUnlimited');
  return t('workspaces.aiUsage.confirm.toLimit', '', { amount: formatMoney(cap, currency.value) });
};

async function onCapSubmit(cap: number | null): Promise<void> {
  const id = workspaceId.value;
  if (id == null) return;
  const ok = await confirm({
    title: t('workspaces.aiUsage.confirm.title'),
    message: capSummaryFor(cap),
    confirmLabel: t('workspaces.aiUsage.editor.save'),
    cancelLabel: t('common.cancel', 'Cancel'),
  });
  if (!ok) return;
  try {
    await store.updateAiCap(id, cap);
    toast.success(t('workspaces.aiUsage.toasts.saved'));
  } catch (err: unknown) {
    const code = (err as { response?: { status?: number } })?.response?.status;
    toast.danger(code === 403 ? t('workspaces.aiUsage.toasts.forbidden') : t('workspaces.aiUsage.toasts.saveError'));
  }
}

const showLoading = computed(() => store.loading && !summary.value);
const showError = computed(() => store.errored && !summary.value);
</script>

<template>
  <Container size="lg" flush>
    <Stack direction="vertical" gap="6">
      <PageHeader
        :title="t('workspaces.aiUsage.title')"
        :description="t('workspaces.aiUsage.subtitle')"
        icon="wallet"
      >
        <template #meta>
          <!-- The always-visible ESTIMATE caveat marker (icon + text, not color-only). -->
          <Badge variant="info" tone="subtle" size="sm" icon="info">
            {{ t('workspaces.aiUsage.estimatedBadge') }}
          </Badge>
        </template>
      </PageHeader>

      <!-- Loading skeleton mirrors the headline + grid geometry. -->
      <div v-if="showLoading" class="flex flex-col gap-next-6" aria-hidden="true">
        <Card>
          <div class="flex flex-col gap-next-4">
            <Skeleton variant="text" width="30%" />
            <div class="flex gap-next-6">
              <Skeleton variant="rect" width="8rem" height="2.5rem" radius="md" />
              <Skeleton variant="rect" width="8rem" height="2.5rem" radius="md" />
              <Skeleton variant="rect" width="8rem" height="2.5rem" radius="md" />
            </div>
            <Skeleton variant="rect" width="100%" height="0.75rem" radius="full" />
          </div>
        </Card>
        <StatsGrid :cols="3" loading :count="3" />
      </div>

      <!-- Error. -->
      <EmptyState
        v-else-if="showError"
        variant="error"
        :title="t('workspaces.aiUsage.errors.title')"
        :description="t('workspaces.aiUsage.errors.description')"
      >
        <template #action>
          <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="load">
            {{ t('common.retry', 'Retry') }}
          </Button>
        </template>
      </EmptyState>

      <!-- Content. -->
      <template v-else-if="summary">
        <!-- ===== $-PRIMARY headline ===== -->
        <Card>
          <template #header>
            <div class="flex min-w-0 flex-wrap items-center justify-between gap-next-2">
              <div class="flex min-w-0 flex-col gap-next-0_5">
                <h2 class="text-next-base font-next-semibold text-next-fg">
                  {{ t('workspaces.aiUsage.thisMonth') }}
                </h2>
                <p v-if="resetsOn" class="text-next-sm text-next-muted-foreground">
                  {{ t('workspaces.aiUsage.resetsOn', '', { date: resetsOn }) }}
                </p>
              </div>
              <StatusBadge :status="state" :status-map="meterStatusMap" size="md" />
            </div>
          </template>

          <div class="flex flex-col gap-next-4">
            <!-- The three $ figures ($-primary; tokens are elsewhere + muted). -->
            <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-3">
              <div class="flex flex-col gap-next-0_5">
                <span class="text-next-sm text-next-muted-foreground">{{ t('workspaces.aiUsage.used') }}</span>
                <span class="text-next-3xl font-next-semibold tabular-nums text-next-fg">{{ usedLabel }}</span>
              </div>
              <div class="flex flex-col gap-next-0_5">
                <span class="text-next-sm text-next-muted-foreground">{{ t('workspaces.aiUsage.cap') }}</span>
                <span class="text-next-3xl font-next-semibold tabular-nums text-next-fg">{{ capLabel }}</span>
              </div>
              <div class="flex flex-col gap-next-0_5">
                <span class="text-next-sm text-next-muted-foreground">{{ t('workspaces.aiUsage.remaining') }}</span>
                <span class="text-next-3xl font-next-semibold tabular-nums text-next-fg">{{ remainingLabel }}</span>
              </div>
            </div>

            <!-- Meter (capped) OR a clean "no limit" line (uncapped — never a scary empty meter). -->
            <div v-if="!uncapped" class="flex flex-col gap-next-1">
              <Progress
                :value="percent"
                :max="100"
                :tone="tone"
                size="md"
                :aria-label="meterAria"
              />
              <div class="flex items-center justify-between text-next-xs text-next-muted-foreground">
                <span>{{ capSourceNote }}</span>
                <span class="tabular-nums">{{ percent }}%</span>
              </div>
            </div>
            <div v-else class="flex items-center gap-next-2 text-next-sm text-next-muted-foreground">
              <Icon name="check-circle" class="text-next-success" aria-hidden="true" />
              <span>{{ t('workspaces.aiUsage.noLimitNote') }}</span>
            </div>

            <!-- The always-visible estimate caveat + the secondary token figure. -->
            <div class="flex flex-wrap items-center justify-between gap-next-2 border-t border-next-border pt-next-3">
              <p class="text-next-xs text-next-muted-foreground">{{ t('workspaces.aiUsage.estimatedCaveat') }}</p>
              <p class="text-next-xs text-next-muted-foreground">
                {{ t('workspaces.aiUsage.tokensSecondary', '', { tokens: tokensLabel }) }}
              </p>
            </div>
          </div>
        </Card>

        <!-- ===== Owner cap editor (only with can_manage) ===== -->
        <Card v-if="summary.can_manage">
          <template #header>
            <div class="flex min-w-0 flex-col gap-next-0_5">
              <h2 class="text-next-base font-next-semibold text-next-fg">
                {{ t('workspaces.aiUsage.editor.title') }}
              </h2>
              <p class="text-next-sm text-next-muted-foreground">
                {{ uncapped ? t('workspaces.aiUsage.editor.uncappedPrompt') : t('workspaces.aiUsage.editor.subtitle') }}
              </p>
            </div>
          </template>

          <AiUsageCapEditor :summary="summary" :saving="store.saving" @submit="onCapSubmit" />
        </Card>

        <!-- ===== Per-channel ===== -->
        <section class="flex flex-col gap-next-3">
          <h2 class="text-next-base font-next-semibold text-next-fg">
            {{ t('workspaces.aiUsage.byChannel') }}
          </h2>
          <EmptyState
            v-if="channelsEmpty"
            size="sm"
            icon="sparkles"
            :title="t('workspaces.aiUsage.empty.channelsTitle')"
            :description="t('workspaces.aiUsage.empty.channelsBody')"
          />
          <StatsGrid v-else :cols="3">
            <StatCard
              v-for="ch in channels"
              :key="ch.channel"
              :label="channelLabel(ch.channel, t)"
              :value="formatMoney(ch.cost, currency)"
              :icon="channelIcon(ch.channel)"
              :helper="t('workspaces.aiUsage.tokensHelper', '', { tokens: formatTokens(ch.tokens) })"
            />
          </StatsGrid>
        </section>

        <!-- ===== Per-actor ===== -->
        <Card>
          <template #header>
            <div class="flex min-w-0 flex-col gap-next-0_5">
              <h2 class="text-next-base font-next-semibold text-next-fg">
                {{ t('workspaces.aiUsage.byActor') }}
              </h2>
              <p class="text-next-sm text-next-muted-foreground">
                {{ t('workspaces.aiUsage.byActorSubtitle') }}
              </p>
            </div>
          </template>

          <EmptyState
            v-if="actorsEmpty"
            size="sm"
            icon="users"
            :title="t('workspaces.aiUsage.empty.actorsTitle')"
            :description="t('workspaces.aiUsage.empty.actorsBody')"
          />
          <ul v-else class="flex flex-col divide-y divide-next-border">
            <li
              v-for="(actor, index) in actors"
              :key="actorRowKey(actor, index)"
              class="flex items-center gap-next-3 py-next-3 first:pt-next-0 last:pb-next-0"
            >
              <span
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-next-full"
                :class="actorGlyphClass(actor)"
                aria-hidden="true"
              >
                <Icon :name="actorIcon(actor)" />
              </span>
              <span class="min-w-0 flex-1 truncate text-next-sm font-next-medium text-next-fg">
                {{ actorLabel(actor, t) }}
              </span>
              <div class="flex flex-col items-end gap-next-0_5">
                <span class="text-next-sm font-next-semibold tabular-nums text-next-fg">
                  {{ formatMoney(actor.cost, currency) }}
                </span>
                <span class="text-next-xs tabular-nums text-next-muted-foreground">
                  {{ t('workspaces.aiUsage.tokensHelper', '', { tokens: formatTokens(actor.tokens) }) }}
                </span>
              </div>
            </li>
          </ul>
        </Card>
      </template>
    </Stack>
  </Container>
</template>
