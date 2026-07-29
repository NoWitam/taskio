<script setup lang="ts">
// SessionDirectionCard — the READ-ONLY "creative direction" turn of the session chat (the direction layer).
//
// A run derives ONE creative frame (message / goal / audience / tone / through-line / arc beats / subject /
// setting / art direction / target duration / continuity notes) and every part of that run is made to it —
// which is exactly why the human needs to SEE it: it explains why the post body, the shot list and the
// storyboard frames agree with each other. So this card is provenance, not a form.
//
// DISPLAY-ONLY by design (v1): no edit, no pin, no regenerate. The direction is re-derived by the next FULL
// run — a claim NULLS it, so the card is legitimately ABSENT while `generating` and reappears with the
// settled session. It renders COLLAPSED by default (a compact header: glyph + title + a one-line teaser),
// because the frame is context, not the artifact the user came for.
//
// VISIBILITY is delegated to {@link hasCreativeDirection} — the same predicate the host (SessionChatView)
// gates the surrounding SessionTurn on, so an empty/null direction produces NO card and no empty turn.
//
// SECURITY: every value here is MODEL-DERIVED content laundered from user-supplied slot values. The backend
// normalizes and caps it; this card must not re-open that hole — values are rendered as PLAIN TEXT through
// interpolation ONLY. No `v-html`, no MarkdownViewer, ever.
import { computed, ref } from 'vue';
import Card from '../../../ui/layout/Card.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import Button from '../../../ui/primitives/Button.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import { nextId } from '../../../ui/forms/formField';
import { useI18n } from '../../../app/i18n';
import {
  directionBeats,
  directionContinuity,
  directionDuration,
  directionTeaser,
  directionTextEntries,
  directionVisualEntries,
  hasCreativeDirection,
  type DirectionTextField,
} from './sessionDirection';
import type { CreativeDirection } from '../sessionTypes';

const props = defineProps<{
  /** The run's derived direction (null / absent while a run is claimed, or when none was derived). */
  direction: CreativeDirection | null | undefined;
}>();

const { t } = useI18n();

/** The wire key → its i18n label key (the catalog is camelCase; the wire is snake_case). */
const TEXT_LABEL_KEY: Record<DirectionTextField, string> = {
  message: 'message',
  goal: 'goal',
  audience: 'audience',
  tone: 'tone',
  through_line: 'throughLine',
  subject: 'subject',
  setting: 'setting',
};

/** The same gate the host applies — belt and braces, so this component can never render an empty shell. */
const visible = computed(() => hasCreativeDirection(props.direction));

const textEntries = computed(() => directionTextEntries(props.direction));
const beats = computed(() => directionBeats(props.direction));
const facets = computed(() => directionVisualEntries(props.direction));
const duration = computed(() => directionDuration(props.direction));
const continuity = computed(() => directionContinuity(props.direction));
const teaser = computed(() => directionTeaser(props.direction));

// Collapsed by default: the direction is CONTEXT for the results below it, not the artifact itself.
const expanded = ref(false);
function toggle(): void {
  expanded.value = !expanded.value;
}

// A stable id so the toggle's `aria-controls` always resolves. The body stays MOUNTED and is hidden with
// v-show (rather than v-if) precisely so that reference is never dangling.
const bodyId = nextId('next-direction');

function textLabel(key: DirectionTextField): string {
  return t(`generator.sessions.direction.labels.${TEXT_LABEL_KEY[key]}`);
}
</script>

<template>
  <Card v-if="visible" variant="default">
    <template #header>
      <div class="flex min-w-0 flex-1 items-center gap-next-2">
        <Icon name="palette" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
        <h3 class="shrink-0 text-next-sm font-next-semibold text-next-fg">
          {{ t('generator.sessions.direction.title') }}
        </h3>
        <!-- Collapsed teaser: the message (or the through-line) on one clipped line. -->
        <span
          v-if="!expanded && teaser"
          class="min-w-0 truncate text-next-xs text-next-muted-foreground"
        >
          {{ teaser }}
        </span>
      </div>
    </template>

    <template #headerActions>
      <Button
        variant="ghost"
        size="sm"
        :leading-icon="expanded ? 'chevron-up' : 'chevron-down'"
        :aria-expanded="expanded ? 'true' : 'false'"
        :aria-controls="bodyId"
        @click="toggle"
      >
        {{ expanded ? t('generator.sessions.direction.collapse') : t('generator.sessions.direction.expand') }}
      </Button>
    </template>

    <div v-show="expanded" :id="bodyId" class="flex flex-col gap-next-4">
      <!-- The simple label/value pairs (empty fields are skipped upstream). -->
      <dl v-if="textEntries.length" class="flex flex-col gap-next-3">
        <div v-for="entry in textEntries" :key="entry.key" class="flex flex-col gap-next-0_5">
          <dt class="text-next-xs font-next-medium text-next-muted-foreground">{{ textLabel(entry.key) }}</dt>
          <dd class="whitespace-pre-line text-next-sm text-next-fg">{{ entry.value }}</dd>
        </div>
      </dl>

      <!-- Arc beats: an ORDERED list (the sequence is the meaning). -->
      <div v-if="beats.length" class="flex flex-col gap-next-1_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">
          {{ t('generator.sessions.direction.labels.arcBeats') }}
        </span>
        <ol class="flex flex-col gap-next-1">
          <li
            v-for="(beat, index) in beats"
            :key="index"
            class="flex items-start gap-next-2 text-next-sm text-next-fg"
          >
            <span
              class="mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-next-full bg-next-muted text-[0.625rem] text-next-muted-foreground"
              aria-hidden="true"
            >
              {{ index + 1 }}
            </span>
            <span class="min-w-0">{{ beat }}</span>
          </li>
        </ol>
      </div>

      <!-- Art direction: labeled chips (the label carries the meaning — never color alone). -->
      <div v-if="facets.length" class="flex flex-col gap-next-1_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">
          {{ t('generator.sessions.direction.labels.visualStyle') }}
        </span>
        <div class="flex flex-wrap gap-next-1_5">
          <Badge v-for="facet in facets" :key="facet.key" variant="neutral" tone="subtle" size="sm" icon="image">
            {{ t(`generator.sessions.direction.labels.${facet.key}`) }}: {{ facet.value }}
          </Badge>
        </div>
      </div>

      <!-- Target duration (the field that keeps a shot list honest about length). -->
      <div v-if="duration !== null" class="flex flex-col gap-next-0_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">
          {{ t('generator.sessions.direction.labels.duration') }}
        </span>
        <span class="inline-flex items-center gap-next-1 text-next-sm text-next-fg">
          <Icon name="clock" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
          {{ t('generator.sessions.direction.durationValue', '', { seconds: duration }) }}
        </span>
      </div>

      <!-- Continuity notes last, muted (a footnote to the frame above). -->
      <div v-if="continuity" class="flex flex-col gap-next-0_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">
          {{ t('generator.sessions.direction.labels.continuity') }}
        </span>
        <p class="whitespace-pre-line text-next-sm text-next-muted-foreground">{{ continuity }}</p>
      </div>

      <p class="text-next-xs text-next-muted-foreground">
        {{ t('generator.sessions.direction.derivedCaption') }}
      </p>
    </div>
  </Card>
</template>
