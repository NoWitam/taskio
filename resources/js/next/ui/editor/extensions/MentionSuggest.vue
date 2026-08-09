<script setup lang="ts">
// MentionSuggest — the ROW of the `@` mention popup. The popup itself is `SuggestListPopup`.
//
// It used to serve the `{` VARIABLE trigger too, as a second row `variant`. That trigger now
// renders the shared `ui/variables/VariableBrowser` through `VariableSuggest.vue` (B4) — a flat
// list of names could not show type markers, expand a container, or stop a user inserting an
// object. The two popups still share the caret-anchoring approach and the same store.
//
// This file was a near-verbatim copy of `WikilinkSuggest` around a different row, and the copies
// drifted: the wikilink popup learned to say "the search FAILED" while this one kept rendering the
// same failure as "no matches". Both now render through one shell, so the error arm exists here
// too — and `mention.ts` sets the flag that turns it on.
//
// COPY: the shell carries no strings, so the three this popup needs (the listbox name, the empty
// wording, the failure wording) are translated here. The empty row used to be the English literal
// "No matches" baked into a design-system component.
import { computed } from 'vue';
import { useI18n } from '../../../app/i18n';
import Avatar from '../../primitives/Avatar.vue';
import SuggestListPopup from './SuggestListPopup.vue';
import type { SuggestionStore } from './suggestionStore';

const props = defineProps<{ store: SuggestionStore }>();

const { t } = useI18n();

// The store still carries the trigger `variant` (both plugins set it); this popup only ever
// renders the MENTION one, so the ids/label are named for it.
const listboxId = computed(() => `next-${props.store.variant}-listbox`);

const labels = computed(() => ({
  empty: t('editor.suggest.mentionEmpty'),
  error: t('editor.suggest.mentionError'),
}));
</script>

<template>
  <SuggestListPopup
    :store="store"
    :listbox-id="listboxId"
    :aria-label="t('editor.suggest.mentionList')"
    :labels="labels"
    size="sm"
    skeleton-lead="1.5rem"
  >
    <template #row="{ item }">
      <Avatar :src="item.avatar ?? undefined" :name="item.label" size="xs" class="shrink-0" />
      <span class="min-w-0 truncate">{{ item.label }}</span>
    </template>
  </SuggestListPopup>
</template>
