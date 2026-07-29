<script setup lang="ts">
// FinalPostPane — the DOCKED "Gotowy post" rail (owner decision #1), shown only at ≥ next-xl.
//
// A thin sticky wrapper around the shared `FinalPostBody`: it fixes the artifact to the top of the
// conversation region (`sticky top-0`) at a comfortable rail width so the finished post + its Save
// action stay visible while the conversation scrolls. Below next-xl this pane is hidden by the parent
// grid and the SAME `FinalPostBody` is pinned as the last conversation turn instead — so the content is
// always reachable without a competing layout.
import FinalPostBody from './FinalPostBody.vue';
import type { ContentTypePart } from '../types';
import type { SessionResults, SessionStatus } from '../sessionTypes';
import type { SaveImageRequest } from './sessionImages';

defineProps<{
  parts: ContentTypePart[];
  results: SessionResults | null;
  status: SessionStatus;
  sessionId: string;
  sessionName?: string;
}>();

// Forward the produced-image save request up to the page (which owns the ONE save dialog).
defineEmits<{ (e: 'save', request: SaveImageRequest): void }>();
</script>

<template>
  <aside class="hidden w-[22rem] shrink-0 next-xl:block" :aria-label="$attrs['aria-label'] as string | undefined">
    <div class="sticky top-0 max-h-full overflow-y-auto">
      <FinalPostBody
        :parts="parts"
        :results="results"
        :status="status"
        :session-id="sessionId"
        :session-name="sessionName"
        @save="(request) => $emit('save', request)"
      />
    </div>
  </aside>
</template>
