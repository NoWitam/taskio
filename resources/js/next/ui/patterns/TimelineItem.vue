<script setup lang="ts">
// TimelineItem — one node in a Timeline (next frontend).
//
// Renders a connector line + a node (an icon in a status-toned circle, OR an
// Avatar via the `#node` slot), a title, an optional clamped/expandable
// description, a right-/under-title timestamp (a real <time datetime>), and
// optional per-item actions (`#actions`).
//
// States: default · `highlighted` (accent ring on the node — e.g. the focused
// entry) · `pending` (in-progress step: a soft pulsing node) · `last` (drops the
// connector below). Density follows the parent Timeline (`compact`), injected so
// items don't need the prop passed manually.
import { computed, inject, ref } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import { useI18n } from '../../app/i18n';

const { t } = useI18n();
import { TIMELINE_KEY } from './timeline';

type NodeTone = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';

const props = withDefaults(
  defineProps<{
    /** Item title (or use the `#title` slot). */
    title?: string;
    /** Node icon (when not using the `#node` slot). */
    icon?: IconName;
    /** Node circle tone. */
    tone?: NodeTone;
    /** Timestamp text (rendered in a <time>). */
    time?: string;
    /** Machine-readable datetime for the <time datetime> attribute. */
    datetime?: string;
    /** Description text (or use the default slot for rich content). */
    description?: string;
    /** Clamp the description to N lines + offer a "Show more" toggle. */
    clampLines?: number;
    /** Accent ring on the node (e.g. the currently-focused entry). */
    highlighted?: boolean;
    /** In-progress step: a soft pulsing node. */
    pending?: boolean;
    /** Last item — drop the connector line below the node. */
    last?: boolean;
  }>(),
  {
    tone: 'neutral',
    highlighted: false,
    pending: false,
    last: false,
  },
);

const ctx = inject(TIMELINE_KEY, null);
const compact = computed(() => ctx?.compact.value ?? false);

const NODE_TONE: Record<NodeTone, string> = {
  neutral: 'bg-next-muted text-next-muted-foreground',
  primary: 'bg-next-primary-subtle text-next-primary-subtle-foreground',
  success: 'bg-next-success-subtle text-next-success-subtle-foreground',
  warning: 'bg-next-warning-subtle text-next-warning-subtle-foreground',
  danger: 'bg-next-danger-subtle text-next-danger-subtle-foreground',
  info: 'bg-next-info-subtle text-next-info-subtle-foreground',
};

// Description clamp + expand.
const expanded = ref(false);
const clampStyle = computed(() =>
  props.clampLines && !expanded.value
    ? {
        display: '-webkit-box',
        WebkitLineClamp: String(props.clampLines),
        WebkitBoxOrient: 'vertical' as const,
        overflow: 'hidden',
      }
    : undefined,
);
</script>

<template>
  <!-- li lives in the parent <ol>. The node column is a fixed width so the
       connector line (drawn here, behind the node) stays vertically aligned. -->
  <li class="next-timeline-item relative flex gap-next-3" :class="compact ? 'pb-next-4' : 'pb-next-6'">
    <!-- Node column + connector. -->
    <div class="relative flex shrink-0 flex-col items-center">
      <!-- Node: a status-toned circle with an icon, or the #node slot (e.g. an
           Avatar). `highlighted` rings it; `pending` softly pulses it. -->
      <span
        class="relative z-10 flex shrink-0 items-center justify-center rounded-next-full"
        :class="[
          compact ? 'h-7 w-7 text-next-sm' : 'h-9 w-9 text-next-base',
          $slots.node ? '' : NODE_TONE[tone],
          highlighted ? 'outline outline-2 outline-offset-2 [outline-color:var(--color-next-primary)]' : '',
          pending ? 'next-timeline-pending' : '',
        ]"
      >
        <slot name="node">
          <Icon v-if="icon" :name="icon" aria-hidden="true" />
        </slot>
      </span>

      <!-- Connector line below the node (omitted on the last item). -->
      <span
        v-if="!last"
        class="absolute bottom-0 w-px bg-next-border"
        :class="compact ? 'top-7' : 'top-9'"
        aria-hidden="true"
      />
    </div>

    <!-- Content. -->
    <div class="min-w-0 flex-1" :class="compact ? 'pb-next-1' : 'pb-next-2'">
      <div class="flex items-start justify-between gap-next-3">
        <div class="min-w-0">
          <div class="flex items-center gap-next-2">
            <span
              class="min-w-0 font-next-semibold text-next-fg"
              :class="compact ? 'text-next-xs' : 'text-next-sm'"
            >
              <slot name="title">{{ title }}</slot>
            </span>
            <slot name="afterTitle" />
          </div>
          <time
            v-if="time"
            :datetime="datetime"
            class="mt-next-0_5 block text-next-muted-foreground"
            :class="compact ? 'text-next-2xs' : 'text-next-xs'"
          >
            {{ time }}
          </time>
        </div>

        <div v-if="$slots.actions" class="relative z-10 flex shrink-0 items-center gap-next-1">
          <slot name="actions" />
        </div>
      </div>

      <!-- Description: clamped + expandable, or rich default-slot content. -->
      <div
        v-if="description || $slots.default"
        class="text-next-muted-foreground"
        :class="compact ? 'mt-next-1 text-next-xs' : 'mt-next-1_5 text-next-sm'"
      >
        <div :style="clampStyle">
          <slot>{{ description }}</slot>
        </div>
        <button
          v-if="clampLines && description"
          type="button"
          class="mt-next-0_5 rounded-next-sm text-next-xs font-next-medium text-next-primary underline-offset-4 hover:underline"
          :aria-expanded="expanded"
          @click="expanded = !expanded"
        >
          {{ expanded ? t('common.showLess', 'Show less') : t('common.showMore', 'Show more') }}
        </button>
      </div>
    </div>
  </li>
</template>

<style scoped>
/* In-progress step: a soft pulse on the node (respects reduced motion globally). */
.next-timeline-pending {
  animation: next-timeline-pulse 1.4s ease-in-out infinite;
}
@keyframes next-timeline-pulse {
  0%,
  100% {
    box-shadow: 0 0 0 0 color-mix(in srgb, var(--color-next-primary) 45%, transparent);
  }
  50% {
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--color-next-primary) 0%, transparent);
  }
}
@media (prefers-reduced-motion: reduce) {
  .next-timeline-pending {
    animation: none;
  }
}
</style>
