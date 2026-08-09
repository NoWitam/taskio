<script setup lang="ts">
// KnowledgeDraftDiffPanel — what this draft would change (spec §24.5).
//
// Expanded INSIDE the card, never a modal: a diff is context to read next to the content, not an
// interruption. The baselines differ by draft kind, because the question differs:
//   • a NEW entry asks "how has the machine's proposal moved since I started?"  (original / previous)
//   • a SHADOW asks "what would this do to the entry that already exists?"      (+ the stored version)
//
// THE DIFF IS COMPUTED HERE, from two texts the server hands over — and computed ON OPEN, once per
// baseline, never per keystroke. `diffText` is a synchronous LCS over up to 40 000 characters; the
// board would stutter if this ran while the refine box was being typed into.
import { computed, ref, watch } from 'vue';
import SegmentedControl from '../../../ui/forms/SegmentedControl.vue';
import TextDiffView from '../../../ui/data/TextDiffView.vue';
import Alert from '../../../ui/feedback/Alert.vue';
import Text from '../../../ui/primitives/Text.vue';
import Button from '../../../ui/primitives/Button.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import { useKnowledgeStore } from '../../../app/stores/knowledge';
import { useI18n } from '../../../app/i18n';
import type { KnowledgeDraftDiff, KnowledgeDraftDiffBaseline, KnowledgeDraftEntry } from '../types';

const props = withDefaults(
  defineProps<{
    draft: KnowledgeDraftEntry;
    /** Whether the session has iterated at least once — "previous draft" needs something before it. */
    hasPreviousIteration: boolean;
    /** A rebase is running (owned by the card, which also owns the badge that offers it). */
    rebasing?: boolean;
  }>(),
  { rebasing: false },
);

const emit = defineEmits<{
  /** The user asked to re-point this proposal at the target's current text. Costs no AI. */
  (e: 'rebase'): void;
}>();

const { t } = useI18n();
const store = useKnowledgeStore();

const isShadow = computed(() => !!props.draft.targets_entry);

/** A shadow opens on the STORED version — the comparison a reviewer of an amendment wants. */
const baseline = ref<KnowledgeDraftDiffBaseline>(isShadow.value ? 'target' : 'original');

const options = computed(() => {
  const rows: Array<{ value: KnowledgeDraftDiffBaseline; label: string; disabled?: boolean }> = [];
  if (isShadow.value) {
    rows.push({ value: 'target', label: t('knowledge.compose.diffStored') });
  }
  rows.push({ value: 'original', label: t('knowledge.compose.diffOriginal') });
  rows.push({
    value: 'previous',
    label: t('knowledge.compose.diffPrevious'),
    // Nothing to compare with on a first generation — disabled, with the reason in the title.
    disabled: !props.hasPreviousIteration,
  });
  return rows;
});

const diff = ref<KnowledgeDraftDiff | null>(null);
const loading = ref(false);
const errored = ref(false);
let token = 0;

async function load(): Promise<void> {
  const myToken = (token += 1);
  loading.value = true;
  errored.value = false;
  try {
    const result = await store.fetchDraftDiff(props.draft.id, baseline.value);
    if (myToken !== token) return;
    diff.value = result;
  } catch {
    if (myToken !== token) return;
    diff.value = null;
    errored.value = true;
  } finally {
    if (myToken === token) loading.value = false;
  }
}

// On open and on every baseline switch — the only two moments the texts can change...
watch(baseline, () => void load(), { immediate: true });

// ...plus one more: a REBASE re-points `target_revision_id`, so the `target` baseline is now a
// different revision. Reloading is what makes the panel show what the reviewer just asked for.
watch(
  () => props.draft.target_revision_id,
  () => {
    if (baseline.value === 'target') void load();
  },
);

/** The target moved after the composer read it — the diff on screen compares against old text. */
const targetStale = computed(
  () => props.draft.target_revision_stale === true || diff.value?.target_revision_stale === true,
);

/** The baseline text; '' when the server says there is no baseline (a first generation). */
const oldText = computed(() => diff.value?.from?.content ?? '');
const newText = computed(() => diff.value?.to?.content ?? props.draft.content ?? '');

/** The TITLE is diffed separately, above the body — it is not the article's first line. */
const oldTitle = computed(() => diff.value?.from?.title ?? '');
const newTitle = computed(() => diff.value?.to?.title ?? props.draft.title ?? '');
const titleChanged = computed(() => !!diff.value?.from && oldTitle.value !== newTitle.value);
</script>

<template>
  <div class="flex flex-col gap-next-3 border-t border-next-border pt-next-3" data-diff-panel>
    <SegmentedControl
      v-model="baseline"
      :options="options"
      size="sm"
      :aria-label="t('knowledge.compose.diffBaseline')"
    />

    <!-- The comparison on screen is against text that has since changed. Said HERE as well as on
         the card, because this is where a reviewer is actually reading the difference — and the
         remedy is one click away and costs nothing. -->
    <Alert v-if="isShadow && targetStale && baseline === 'target'" variant="warning" size="sm" data-diff-stale>
      <div class="flex flex-wrap items-center justify-between gap-next-2">
        <span>{{ t('knowledge.compose.conflict.staleDiff') }}</span>
        <Button
          variant="outline"
          size="sm"
          leading-icon="rotate-ccw"
          :loading="rebasing"
          data-diff-rebase
          @click="emit('rebase')"
        >
          {{ t('knowledge.compose.conflict.rebase') }}
        </Button>
      </div>
    </Alert>

    <div v-if="loading" class="flex flex-col gap-next-2" role="status" :aria-label="t('knowledge.common.loadingLabel')">
      <Skeleton variant="text" width="40%" />
      <Skeleton variant="rect" height="6rem" radius="md" />
    </div>

    <Alert v-else-if="errored" variant="danger" size="sm">
      <div class="flex items-center justify-between gap-next-2">
        <span>{{ t('knowledge.common.loadError') }}</span>
        <Button variant="ghost" size="xs" leading-icon="rotate-ccw" @click="load">
          {{ t('knowledge.common.retry') }}
        </Button>
      </div>
    </Alert>

    <template v-else-if="diff">
      <!-- No baseline at all: honest, and not an error — a first generation has nothing before it. -->
      <Text v-if="!diff.has_baseline" variant="caption" tone="muted">
        {{ t('knowledge.compose.diffNoBaseline') }}
      </Text>

      <template v-else>
        <!-- Title first, on its own. Folding it into the body diff would turn the entry's name into
             the article's first line, which it is not. -->
        <div v-if="titleChanged" class="flex flex-col gap-next-1">
          <Text variant="caption" tone="muted">{{ t('knowledge.compose.diffTitle') }}</Text>
          <TextDiffView :old="oldTitle" :current="newTitle" max-height="6rem" />
        </div>

        <TextDiffView
          :old="oldText"
          :current="newText"
          max-height="24rem"
          :empty-label="t('knowledge.compose.diffUnchanged')"
        />
      </template>
    </template>
  </div>
</template>
