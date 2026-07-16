<script setup lang="ts">
// StatusBadge — a semantic status chip built ON the Badge primitive (next).
//
// Maps a small set of well-known domain statuses to a Badge { variant, tone,
// icon } + a default label, so statuses render consistently across the app. The
// mapping is overridable/extendable per app domain via the `statusMap` prop and
// the `label` override.
//
// NEVER color-only: every status renders an icon (or a dot fallback) PLUS a text
// label, so the meaning survives for color-blind users and in grayscale.
//
// Sizes `sm` / `md` (forwarded to Badge). Tone defaults per status (`solid` for
// strong verdicts like success/error, `subtle` for quieter states) but can be
// overridden through the map.
import { computed } from 'vue';
import Badge from '../primitives/Badge.vue';
import type { IconName } from '../primitives/Icon.vue';

export type StatusKey =
  | 'active'
  | 'inactive'
  | 'pending'
  | 'success'
  | 'warning'
  | 'error'
  | 'info'
  | 'draft'
  | 'archived';

type BadgeVariant = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';
type BadgeTone = 'solid' | 'subtle';

export interface StatusDescriptor {
  label: string;
  variant: BadgeVariant;
  tone?: BadgeTone;
  /** Leading icon. Omit + set `dot` to fall back to a colored dot. */
  icon?: IconName;
  /** Use a dot instead of an icon (still not color-only — paired with the label). */
  dot?: boolean;
}

export type StatusMap = Partial<Record<string, StatusDescriptor>>;

// The built-in mapping. Each entry pairs a tone + an icon (or dot) with a label
// so no status relies on color alone.
const DEFAULT_MAP: Record<StatusKey, StatusDescriptor> = {
  active: { label: 'Active', variant: 'success', tone: 'subtle', icon: 'check-circle' },
  inactive: { label: 'Inactive', variant: 'neutral', tone: 'subtle', dot: true },
  pending: { label: 'Pending', variant: 'warning', tone: 'subtle', icon: 'clock' },
  success: { label: 'Success', variant: 'success', tone: 'solid', icon: 'check-circle' },
  warning: { label: 'Warning', variant: 'warning', tone: 'solid', icon: 'alert-triangle' },
  error: { label: 'Error', variant: 'danger', tone: 'solid', icon: 'x-circle' },
  info: { label: 'Info', variant: 'info', tone: 'subtle', icon: 'info' },
  draft: { label: 'Draft', variant: 'neutral', tone: 'subtle', icon: 'file-text' },
  archived: { label: 'Archived', variant: 'neutral', tone: 'subtle', icon: 'inbox' },
};

const props = withDefaults(
  defineProps<{
    /** A known status key, or any string covered by `statusMap`. */
    status: StatusKey | string;
    /** Override the mapped label (e.g. localized text). */
    label?: string;
    /** Extend / override the mapping per app domain (merged over the defaults). */
    statusMap?: StatusMap;
    size?: 'sm' | 'md';
    /** Force tone (overrides the descriptor's tone). */
    tone?: BadgeTone;
  }>(),
  { size: 'md' },
);

// Resolve the descriptor: caller map wins over the built-ins; unknown statuses
// fall back to a neutral dot + the raw key as the label (never blank, never
// color-only).
const descriptor = computed<StatusDescriptor>(() => {
  const merged = { ...DEFAULT_MAP, ...(props.statusMap ?? {}) } as Record<string, StatusDescriptor>;
  return (
    merged[props.status] ?? {
      label: props.status,
      variant: 'neutral',
      tone: 'subtle',
      dot: true,
    }
  );
});

const resolvedLabel = computed(() => props.label ?? descriptor.value.label);
const resolvedTone = computed<BadgeTone>(() => props.tone ?? descriptor.value.tone ?? 'subtle');
const useDot = computed(() => descriptor.value.dot && !descriptor.value.icon);
</script>

<template>
  <Badge
    :variant="descriptor.variant"
    :tone="resolvedTone"
    :size="size"
    :icon="useDot ? undefined : descriptor.icon"
    :dot="useDot"
  >
    {{ resolvedLabel }}
  </Badge>
</template>
