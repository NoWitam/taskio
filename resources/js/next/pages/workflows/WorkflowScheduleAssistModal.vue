<script setup lang="ts">
// WorkflowScheduleAssistModal — the AI natural-language assist as a focus-trapped
// Modal (§4.5.9). Opened from the summary's "Zaplanuj z AI"; it PREFILLS the draft but
// NEVER auto-applies — every result (even a feasible one) is shown as a *proposal* the
// user commits with "Zastosuj". The whole draft (three axes + exclusions + tz) is
// replaced on apply; the drawer's Save still re-validates.
//
// The six states (§4.5.9): composing / loading / proposal-feasible / proposal-
// alternative / infeasible-no-alternative / failure-throttle. The proposal card shows
// the deterministic `describeSchedule` sentence + a compact 5-tile preview (its own
// token-guarded `store.schedulePreview`, quietly dropped on error while the sentence
// stays). ALL model text (`explanation` / `unsupported[]` / `note`) renders as PLAIN
// TEXT via interpolation — NEVER v-html (untrusted output). Actions live ONLY in the
// sticky footer (Anuluj / Zastosuj), never in the header.
import { computed, nextTick, ref, watch } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Surface from '../../ui/layout/Surface.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Button from '../../ui/primitives/Button.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import { useI18n } from '../../app/i18n';
import { useToast } from '../../app/composables/useToast';
import { useWorkflowsStore, ScheduleAssistError } from '../../app/stores/workflows';
import {
  configToDraft,
  describeSchedule,
  occurrencePartsFormatter,
  formatOccurrenceParts,
  type ScheduleDraft,
} from './workflowSchedule';
import type { ScheduleAssistEnvelope, ScheduleConfig } from './types';

const props = withDefaults(defineProps<{ tz?: string | null }>(), { tz: null });

const emit = defineEmits<{ (e: 'apply', draft: ScheduleDraft): void }>();

const open = defineModel<boolean>('open', { default: false });

const { t, locale } = useI18n();
const store = useWorkflowsStore();
const toast = useToast();
const MAX_PROMPT = 500;

const prompt = ref('');
const loading = ref(false);
const envelope = ref<ScheduleAssistEnvelope | null>(null);
const errorKey = ref<string | null>(null);
const composerEl = ref<HTMLElement | null>(null);

/** The active tz hint sent to the assist (§4.5.8): the prop, else the browser zone. */
const activeTz = computed(() => {
  if (props.tz != null && props.tz !== '') return props.tz;
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || null;
  } catch {
    return null;
  }
});

const canSubmit = computed(() => !loading.value && prompt.value.trim().length > 0);

// --- Response-state derivations (§4.5.9) ------------------------------------
const feasible = computed(() => !!envelope.value?.feasible && !!envelope.value.config);
const hasAlternative = computed(() => !!envelope.value && !envelope.value.feasible && !!envelope.value.alternative);
const infeasibleNoAlt = computed(() => !!envelope.value && !envelope.value.feasible && !envelope.value.alternative);
const unsupported = computed(() => envelope.value?.unsupported ?? []);

/** The config the user would apply — the feasible one, else the alternative, else null. */
const proposedConfig = computed<ScheduleConfig | null>(() => {
  if (feasible.value) return envelope.value!.config;
  if (hasAlternative.value) return envelope.value!.alternative!.config;
  return null;
});
const proposedDraft = computed<ScheduleDraft | null>(() =>
  proposedConfig.value ? configToDraft(proposedConfig.value) : null,
);
/** The deterministic "what you'll get" sentence for the proposal (§4.5.10). */
const proposedSentence = computed(() => (proposedDraft.value ? describeSchedule(proposedDraft.value, t) : ''));
const canApply = computed(() => proposedConfig.value !== null);

// --- Compact preview of the proposal (its own token-guarded fetch) ----------
const previewOccurrences = ref<string[]>([]);
let previewToken = 0;

const renderZone = computed(() => {
  const z = proposedConfig.value?.tz?.trim();
  if (z) return z;
  return activeTz.value ?? 'UTC';
});
const formatters = computed(() => occurrencePartsFormatter(locale.value, renderZone.value));
const previewTiles = computed(() =>
  previewOccurrences.value.map((iso) => ({ iso, ...formatOccurrenceParts(iso, formatters.value) })),
);

/** Fetch the proposal's next runs; drop the list quietly on error (keep the sentence). */
async function loadPreview(config: ScheduleConfig | null): Promise<void> {
  const my = (previewToken += 1);
  previewOccurrences.value = [];
  if (!config) return;
  try {
    const res = await store.schedulePreview(config, { count: 5 });
    if (my !== previewToken) return;
    previewOccurrences.value = res.empty ? [] : res.occurrences;
  } catch {
    if (my !== previewToken) return;
    previewOccurrences.value = [];
  }
}
watch(proposedConfig, (config) => void loadPreview(config));

// --- Composer + lifecycle ---------------------------------------------------
/** Reset to a fresh composer whenever the modal opens; land focus in the Textarea. */
watch(open, (isOpen) => {
  if (!isOpen) return;
  prompt.value = '';
  envelope.value = null;
  errorKey.value = null;
  previewOccurrences.value = [];
  previewToken += 1;
  void nextTick(() => composerEl.value?.querySelector('textarea')?.focus());
});

async function submit(): Promise<void> {
  if (!canSubmit.value) return;
  loading.value = true;
  envelope.value = null;
  errorKey.value = null;
  previewOccurrences.value = [];
  try {
    envelope.value = await store.scheduleAssist(prompt.value.trim(), activeTz.value);
  } catch (err: unknown) {
    // FE-owned copy only — NEVER the raw backend message.
    errorKey.value =
      err instanceof ScheduleAssistError && err.kind === 'throttled'
        ? 'workflows.schedule.assist.throttled'
        : 'workflows.schedule.assist.failed';
  } finally {
    loading.value = false;
  }
}

/** Commit the reviewed proposal — the ONLY apply path (no auto-apply, §4.5.9). */
function apply(): void {
  if (!proposedDraft.value) return;
  emit('apply', proposedDraft.value);
  open.value = false;
  toast.success(t('workflows.schedule.assist.appliedToast'));
}

function onCancel(): void {
  open.value = false;
}
</script>

<template>
  <Modal v-model:open="open" size="lg">
    <template #title>{{ t('workflows.schedule.assist.title') }}</template>
    <template #description>{{ t('workflows.schedule.assist.subtitle') }}</template>

    <div class="flex flex-col gap-next-4">
      <!-- Composer. -->
      <div ref="composerEl" class="flex flex-col gap-next-2">
        <Textarea
          v-model="prompt"
          :rows="3"
          :maxlength="MAX_PROMPT"
          :disabled="loading"
          :placeholder="t('workflows.schedule.assist.placeholder')"
          :aria-label="t('workflows.schedule.assist.inputLabel')"
        />
        <div>
          <Button
            variant="primary"
            size="sm"
            leading-icon="sparkles"
            :loading="loading"
            :disabled="!canSubmit"
            @click="submit"
          >
            {{ t('workflows.schedule.assist.run') }}
          </Button>
        </div>
      </div>

      <!-- Loading: a skeleton proposal card. -->
      <Skeleton v-if="loading" variant="rect" height="8rem" radius="lg" />

      <!-- (a) Feasible → success explanation + proposal card. -->
      <template v-else-if="feasible">
        <Alert variant="success" size="sm">{{ envelope?.explanation }}</Alert>
      </template>

      <!-- (b) Infeasible + alternative → warning + unsupported list + alt proposal card. -->
      <template v-else-if="hasAlternative">
        <Alert variant="warning" size="sm">
          {{ envelope?.explanation }}
          <template v-if="unsupported.length">
            <p class="mt-next-2 font-next-medium">{{ t('workflows.schedule.assist.unsupportedTitle') }}</p>
            <ul class="mt-next-1 list-disc pl-next-4">
              <li v-for="(item, i) in unsupported" :key="i">{{ item }}</li>
            </ul>
          </template>
        </Alert>
      </template>

      <!-- (c) Infeasible, no alternative → warning + unsupported list, no card. -->
      <template v-else-if="infeasibleNoAlt">
        <Alert variant="warning" size="sm">
          {{ envelope?.explanation }}
          <template v-if="unsupported.length">
            <p class="mt-next-2 font-next-medium">{{ t('workflows.schedule.assist.unsupportedTitle') }}</p>
            <ul class="mt-next-1 list-disc pl-next-4">
              <li v-for="(item, i) in unsupported" :key="i">{{ item }}</li>
            </ul>
          </template>
        </Alert>
      </template>

      <!-- (d) Throttle / failure → FE-owned copy (never the raw backend message). -->
      <Alert v-else-if="errorKey" variant="danger" size="sm">{{ t(errorKey) }}</Alert>

      <!-- Proposal card — the "what you'll get" sentence + compact preview, shown for a
           feasible OR alternative result BEFORE apply (never auto-applied). -->
      <Surface v-if="canApply" bg="muted" border radius="lg" class="flex flex-col gap-next-3 p-next-4">
        <p class="text-next-sm font-next-medium text-next-fg">{{ proposedSentence }}</p>

        <p
          v-if="hasAlternative && envelope?.alternative?.note"
          class="text-next-xs text-next-muted-foreground"
        >
          <span class="font-next-medium">{{ t('workflows.schedule.assist.alternativeTag') }}:</span>
          {{ envelope?.alternative?.note }}
        </p>

        <div v-if="previewTiles.length" class="flex flex-col gap-next-1">
          <span class="text-next-xs uppercase tracking-wide text-next-muted-foreground">
            {{ t('workflows.schedule.assist.previewLabel') }}
          </span>
          <ul class="flex flex-wrap gap-next-2">
            <li
              v-for="tile in previewTiles"
              :key="tile.iso"
              class="flex w-[7rem] shrink-0 flex-col gap-next-0_5 rounded-next-md border border-next-border bg-next-card p-next-2"
              :aria-label="t('workflows.schedule.preview.tileAria', undefined, { weekday: tile.weekday, date: tile.date, time: tile.time })"
            >
              <!-- REV5: compact two-line tile (weekday + date on line 1, time on line 2). -->
              <span class="flex items-center gap-next-1">
                <span class="text-next-2xs uppercase tracking-wide text-next-muted-foreground">{{ tile.weekday }}</span>
                <span class="text-next-sm font-next-semibold tabular-nums text-next-fg">{{ tile.date }}</span>
              </span>
              <span class="text-next-sm tabular-nums text-next-fg">{{ tile.time }}</span>
            </li>
          </ul>
        </div>
      </Surface>
    </div>

    <!-- Sticky footer: Anuluj + Zastosuj (DISABLED until a proposal exists). -->
    <template #footer>
      <Button variant="ghost" @click="onCancel">{{ t('workflows.editor.cancel') }}</Button>
      <Button variant="primary" :disabled="!canApply" @click="apply">
        {{ t('workflows.schedule.assist.apply') }}
      </Button>
    </template>
  </Modal>
</template>
