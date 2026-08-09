<script setup lang="ts">
// WikilinkSuggest — the ROW of the `[[` wikilink popup. The popup itself is `SuggestListPopup`.
//
// Sibling of `MentionSuggest.vue` and driven by the SAME reactive `suggestionStore`: the plugin
// publishes caret rect / query / rows / loading / errored, the shared shell renders + positions and
// owns the listbox scaffold, and this file supplies what is actually different — an entry title with
// its status and slug, plus the trailing "create entry" affordance.
//
// The "create" row is a real row PUBLISHED BY THE PLUGIN, not chrome invented here, so the keyboard
// model stays a single array (an off-list extra row is how ↑/↓/Enter arithmetic grows an off-by-one).
//
// COPY LIVES IN i18n, NOT IN ENGLISH FALLBACKS. `labels` used to be optional with English defaults,
// so every host that forgot to pass them — the styleguide among them — rendered English into a Polish
// UI, `aria-label` included.
import { computed } from 'vue';
import Icon from '../../primitives/Icon.vue';
import StatusBadge, { type StatusMap } from '../../data/StatusBadge.vue';
import SuggestListPopup from './SuggestListPopup.vue';
import type { SuggestionStore } from './suggestionStore';

const props = withDefaults(
  defineProps<{
    store: SuggestionStore;
    /**
     * The entry-status descriptors, passed IN rather than imported.
     *
     * `entryStatusMap` lives in the Knowledge module and this is a `ui/` component: importing it
     * here would point the design system at a feature. Every other consumer already hands the map
     * to the primitive, so this row does what the rest of the module does.
     */
    statusMap?: StatusMap;
    /** Localized strings. REQUIRED — this file carries no copy of its own, in any language. */
    labels: {
      list: string;
      create: (query: string) => string;
      empty: string;
      /** The search itself failed, as distinct from matching nothing. */
      error: string;
    };
  }>(),
  {},
);

const listboxId = 'next-wikilink-listbox';

/** The shell's state copy; the `create` label is per-row and stays here. */
const stateLabels = computed(() => ({ empty: props.labels.empty, error: props.labels.error }));

function createLabel(query: string): string {
  return props.labels.create(query);
}
</script>

<template>
  <SuggestListPopup
    :store="store"
    :listbox-id="listboxId"
    :aria-label="labels.list"
    :labels="stateLabels"
    size="md"
    skeleton-lead="1rem"
  >
    <template #row="{ item }">
      <template v-if="item.kind === 'create'">
        <Icon name="plus" class="shrink-0 text-next-sm" aria-hidden="true" />
        <span class="min-w-0 truncate">{{ createLabel(item.label) }}</span>
      </template>

      <template v-else>
        <Icon name="file-text" class="shrink-0 text-next-sm" aria-hidden="true" />
        <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
        <!--
          The status through the PRIMITIVE and the module's own map — label, variant and icon. It
          used to print `item.status` raw, so a Polish UI showed the English enum word `approved`,
          and the badge carried no icon where every sibling has one.
        -->
        <StatusBadge
          v-if="item.status && statusMap"
          :status="item.status"
          :status-map="statusMap"
          size="sm"
        />
        <span class="shrink-0 font-next-mono text-next-2xs text-next-muted-foreground">
          {{ item.slug }}
        </span>
      </template>
    </template>
  </SuggestListPopup>
</template>
