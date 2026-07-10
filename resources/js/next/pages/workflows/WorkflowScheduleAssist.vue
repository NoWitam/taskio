<script setup lang="ts">
// WorkflowScheduleAssist — the AI natural-language schedule assist (§4.5.3-4.5.5).
//
// A BUILDER AID, never a submit path: it *prefills* the family + params below; the
// user reviews/edits and the normal Save re-validates. Placement (§4.5.3): a
// collapsed `sparkles` affordance at the TOP of the schedule panel that expands to a
// composer — a Textarea (maxlength 500) + a stable-width `:loading` submit Button.
//
// It calls `store.scheduleAssist(prompt, tz)` (throwing `ScheduleAssistError` kind
// throttled|failed) and renders the FOUR response states (§4.5.5):
//   (a) feasible          → emit apply(configToDraft(config)) + success Alert.
//   (b) infeasible + alt  → warning Alert (explanation + unsupported list) + a
//                           "suggested alternative" PREVIEW (a deterministic
//                           describeSchedule sentence + the model note + the alt's next
//                           runs) shown BEFORE the "use alternative" Button so the user
//                           sees what they'd get before applying (B5).
//   (c) infeasible, no alt→ warning Alert (explanation + unsupported); builder stays.
//   (d) throttle / failure→ FE-OWNED i18n error copy (never the raw backend message).
//
// ALL model text (`explanation`, `unsupported[]`, `note`) renders as PLAIN TEXT via
// interpolation — NEVER v-html (it is untrusted model output, §4.5.5).
//
// Emits `apply(draft)` — the builder host wires it onto the builder's v-model.
import { computed, nextTick, ref } from 'vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Button from '../../ui/primitives/Button.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import { useI18n } from '../../app/i18n';
import { useWorkflowsStore, ScheduleAssistError } from '../../app/stores/workflows';
import {
  configToDraft,
  describeSchedule,
  occurrenceFormatter,
  formatOccurrence,
  type ScheduleDraft,
} from './workflowSchedule';
import type { ScheduleAssistEnvelope, ScheduleConfig } from './types';

const props = withDefaults(
  defineProps<{
    /**
     * The user's ACTIVE timezone hint sent to the assist (§4.5.4). Defaults to the
     * browser's resolved tz when the host doesn't pass one (no app-level tz source
     * exists yet).
     */
    tz?: string | null;
    disabled?: boolean;
  }>(),
  { disabled: false },
);

const emit = defineEmits<{ (e: 'apply', draft: ScheduleDraft): void }>();

const { t, locale } = useI18n();
const store = useWorkflowsStore();

const MAX_PROMPT = 500;

const open = ref(false);
const prompt = ref('');
const loading = ref(false);

/** The last successful envelope (drives the response Alerts). */
const envelope = ref<ScheduleAssistEnvelope | null>(null);
/** An FE-owned error key (throttle / failure), or null. */
const errorKey = ref<string | null>(null);

const activeTz = computed(() => {
  if (props.tz != null && props.tz !== '') return props.tz;
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || null;
  } catch {
    return null;
  }
});

const canSubmit = computed(
  () => !loading.value && !props.disabled && prompt.value.trim().length > 0,
);

// --- Response-state derivations (§4.5.5) -------------------------------------
const feasible = computed(() => !!envelope.value?.feasible && !!envelope.value.config);
const hasAlternative = computed(() => !!envelope.value && !envelope.value.feasible && !!envelope.value.alternative);
const infeasibleNoAlt = computed(
  () => !!envelope.value && !envelope.value.feasible && !envelope.value.alternative,
);
const unsupported = computed(() => envelope.value?.unsupported ?? []);
/** The applied alternative's note, shown as a plain-text caption once applied. */
const appliedAlternativeNote = ref<string | null>(null);

// --- (b) Alternative PREVIEW (shown BEFORE apply, B5) ------------------------
/** The alternative config (server-validated ⇒ safe to describe/preview), or null. */
const alternative = computed<ScheduleConfig | null>(() => envelope.value?.alternative?.config ?? null);
/** The deterministic i18n cadence sentence — the main answer to "what will I get". */
const alternativeSentence = computed(() =>
  alternative.value ? describeSchedule(alternative.value, t) : '',
);
/** The alternative's next runs (preview), its loading flag, and the fetch token. */
const alternativeOccurrences = ref<string[]>([]);
const alternativePreviewLoading = ref(false);
let alternativePreviewToken = 0;

/** Format an occurrence in the ALT config's own tz (server-merged), locale-aware. */
const alternativeFormatter = computed(() => occurrenceFormatter(locale.value, alternative.value?.tz));
function formatAlternativeOccurrence(iso: string): string {
  return formatOccurrence(iso, alternativeFormatter.value);
}

/**
 * Fetch the alternative's next 4 runs. A network error / an `empty` result (never
 * expected — the alt passed server validation with an emptiness guard) simply drops
 * the list; the sentence + note stay. Token-guarded against a stale response.
 */
async function loadAlternativePreview(config: ScheduleConfig): Promise<void> {
  const myToken = (alternativePreviewToken += 1);
  alternativePreviewLoading.value = true;
  alternativeOccurrences.value = [];
  try {
    const res = await store.schedulePreview(config, 4);
    if (myToken !== alternativePreviewToken) return;
    alternativeOccurrences.value = res.empty ? [] : res.occurrences;
  } catch {
    if (myToken !== alternativePreviewToken) return;
    alternativeOccurrences.value = []; // quiet: the sentence + note remain
  } finally {
    if (myToken === alternativePreviewToken) alternativePreviewLoading.value = false;
  }
}

function reset(): void {
  envelope.value = null;
  errorKey.value = null;
  appliedAlternativeNote.value = null;
  alternativeOccurrences.value = [];
  alternativePreviewLoading.value = false;
  alternativePreviewToken += 1; // drop any in-flight preview
}

/** The expanded composer's container — used to move focus into the Textarea on expand. */
const composerEl = ref<HTMLElement | null>(null);

function openComposer(): void {
  if (props.disabled) return;
  open.value = true;
  // B7 review: a keyboard user should land IN the prompt input, not have to tab to it.
  // Textarea exposes no focus() — query the inner control once the composer renders.
  void nextTick(() => composerEl.value?.querySelector('textarea')?.focus());
}

async function submit(): Promise<void> {
  if (!canSubmit.value) return;
  loading.value = true;
  reset();
  try {
    const result = await store.scheduleAssist(prompt.value.trim(), activeTz.value);
    envelope.value = result;
    // (a) Feasible → apply immediately; the success Alert stays for context.
    if (result.feasible && result.config) {
      emit('apply', configToDraft(result.config));
    } else if (!result.feasible && result.alternative) {
      // (b) Infeasible + alternative → fetch the alt's next runs for the preview.
      void loadAlternativePreview(result.alternative.config);
    }
  } catch (err: unknown) {
    // (d) FE-owned copy only — NEVER the raw backend message.
    errorKey.value =
      err instanceof ScheduleAssistError && err.kind === 'throttled'
        ? 'workflows.schedule.assist.throttled'
        : 'workflows.schedule.assist.failed';
  } finally {
    loading.value = false;
  }
}

/** (b) Apply the suggested alternative + surface its note as a plain-text caption. */
function useAlternative(): void {
  const alt = envelope.value?.alternative;
  if (!alt) return;
  emit('apply', configToDraft(alt.config));
  appliedAlternativeNote.value = alt.note;
}
</script>

<template>
  <div class="flex flex-col gap-next-2">
    <!-- Collapsed affordance (§4.5.3). -->
    <div v-if="!open" class="flex items-center gap-next-2">
      <p class="text-next-xs text-next-muted-foreground">{{ t('workflows.schedule.assist.prompt') }}</p>
      <Button
        variant="outline"
        size="sm"
        leading-icon="sparkles"
        :disabled="disabled"
        :aria-expanded="open"
        @click="openComposer"
      >
        {{ t('workflows.schedule.assist.open') }}
      </Button>
    </div>

    <!-- Expanded composer + the four response states. -->
    <div
      v-else
      ref="composerEl"
      class="flex flex-col gap-next-3 rounded-next-lg border border-next-border bg-next-muted/40 p-next-3"
    >
      <Textarea
        v-model="prompt"
        :rows="2"
        :maxlength="MAX_PROMPT"
        :disabled="loading || disabled"
        :placeholder="t('workflows.schedule.assist.placeholder')"
        :aria-label="t('workflows.schedule.assist.inputLabel')"
      />

      <div class="flex items-center gap-next-2">
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

      <!-- (a) Feasible → success Alert (plain-text explanation). -->
      <Alert v-if="feasible" variant="success" size="sm">
        {{ envelope?.explanation }}
      </Alert>

      <!-- (b) Infeasible + alternative → warning + unsupported list + alt PREVIEW + use-alt button. -->
      <Alert v-else-if="hasAlternative" variant="warning" size="sm">
        {{ envelope?.explanation }}
        <template v-if="unsupported.length">
          <p class="mt-next-2 font-next-medium">{{ t('workflows.schedule.assist.unsupportedTitle') }}</p>
          <ul class="mt-next-1 list-disc pl-next-4">
            <li v-for="(item, i) in unsupported" :key="i">{{ item }}</li>
          </ul>
        </template>

        <!-- The proposed alternative, shown BEFORE apply: sentence + note + next runs. -->
        <div class="mt-next-3 rounded-next-md border border-next-border bg-next-bg/50 p-next-3">
          <p class="font-next-medium">{{ t('workflows.schedule.assist.alternativePreviewTitle') }}</p>
          <!-- The deterministic cadence sentence (the main "what will I get"). -->
          <p v-if="alternativeSentence" class="mt-next-1 font-next-medium text-next-fg">{{ alternativeSentence }}</p>
          <!-- The model note (untrusted → PLAIN TEXT, never v-html). -->
          <p v-if="envelope?.alternative?.note" class="mt-next-1 text-next-muted-foreground">
            {{ envelope?.alternative?.note }}
          </p>

          <!-- The alternative's next runs. -->
          <p class="mt-next-2 text-next-xs font-next-medium text-next-muted-foreground">
            {{ t('workflows.schedule.preview.heading') }}
          </p>
          <div
            v-if="alternativePreviewLoading"
            class="mt-next-1 flex flex-col gap-next-1"
            role="status"
            :aria-label="t('workflows.schedule.preview.loading')"
          >
            <Skeleton v-for="n in 3" :key="n" variant="text" width="70%" />
          </div>
          <ul v-else-if="alternativeOccurrences.length" class="mt-next-1 flex flex-col gap-next-1">
            <li
              v-for="(occ, i) in alternativeOccurrences"
              :key="i"
              class="flex items-center gap-next-2 text-next-xs text-next-muted-foreground"
            >
              <Icon name="clock" class="shrink-0" aria-hidden="true" />
              <span class="tabular-nums">{{ formatAlternativeOccurrence(occ) }}</span>
            </li>
          </ul>
        </div>

        <template #actions>
          <Button variant="outline" size="sm" leading-icon="sparkles" @click="useAlternative">
            {{ t('workflows.schedule.assist.useAlternative') }}
          </Button>
        </template>
      </Alert>

      <!-- (c) Infeasible, no alternative → warning + unsupported list. -->
      <Alert v-else-if="infeasibleNoAlt" variant="warning" size="sm">
        {{ envelope?.explanation }}
        <template v-if="unsupported.length">
          <p class="mt-next-2 font-next-medium">{{ t('workflows.schedule.assist.unsupportedTitle') }}</p>
          <ul class="mt-next-1 list-disc pl-next-4">
            <li v-for="(item, i) in unsupported" :key="i">{{ item }}</li>
          </ul>
        </template>
      </Alert>

      <!-- (d) Throttle / failure → FE-owned copy. -->
      <Alert v-if="errorKey" variant="danger" size="sm">
        {{ t(errorKey) }}
      </Alert>

      <!-- The applied alternative's note (plain-text caption under the builder link). -->
      <p v-if="appliedAlternativeNote" class="text-next-xs text-next-muted-foreground">
        {{ t('workflows.schedule.assist.appliedNote', '', { note: appliedAlternativeNote }) }}
      </p>
    </div>
  </div>
</template>
