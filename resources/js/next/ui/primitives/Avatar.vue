<script setup lang="ts">
// Avatar primitive for the "next" frontend.
//
// Three content modes with graceful fallback: an image avatar falls back to
// initials (and then to a generic user icon) if the image fails to load. All
// surfaces use semantic tokens, so dark mode is automatic.
//
// Status dot: conveyed by BOTH color AND shape (online = filled, away = ring,
// offline = hollow) and the status word is included in the avatar's
// `aria-label`, never color alone.
//
// A11y: image avatars need `alt` (defaults to `name`). The initials/icon
// fallback exposes the full `name` via `aria-label` + `role="img"`.
import { computed, ref, watch } from 'vue';
import Icon from './Icon.vue';

type AvatarSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';
type AvatarStatus = 'online' | 'away' | 'offline' | 'busy';

const props = withDefaults(
  defineProps<{
    /** Image source. Falls back to initials/icon on error. */
    src?: string;
    /** Full name — drives initials, alt text, and aria-label. */
    name?: string;
    /** Explicit alt text (defaults to `name`). */
    alt?: string;
    size?: AvatarSize;
    /** Presence indicator. Conveyed by color + shape + aria text. */
    status?: AvatarStatus;
  }>(),
  { size: 'md' },
);

const imageFailed = ref(false);

watch(
  () => props.src,
  () => {
    imageFailed.value = false;
  },
);

const showImage = computed(() => !!props.src && !imageFailed.value);

const initials = computed(() => {
  if (!props.name) return '';
  const parts = props.name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '';
  if (parts.length === 1) return parts[0]!.slice(0, 2).toUpperCase();
  return (parts[0]![0]! + parts[parts.length - 1]![0]!).toUpperCase();
});

const showInitials = computed(() => !showImage.value && initials.value.length > 0);

const SIZE_CLASS: Record<AvatarSize, string> = {
  xs: 'h-6 w-6 text-next-2xs',
  sm: 'h-8 w-8 text-next-xs',
  md: 'h-10 w-10 text-next-sm',
  lg: 'h-12 w-12 text-next-base',
  xl: 'h-16 w-16 text-next-xl',
};

const STATUS_COLOR: Record<AvatarStatus, string> = {
  online: 'bg-next-success',
  away: 'bg-next-warning',
  busy: 'bg-next-danger',
  offline: 'bg-next-muted-foreground',
};

const STATUS_LABEL: Record<AvatarStatus, string> = {
  online: 'online',
  away: 'away',
  busy: 'busy',
  offline: 'offline',
};

const dotSizeStyle: Record<AvatarSize, string> = {
  xs: 'height:0.375rem;width:0.375rem',
  sm: 'height:0.5rem;width:0.5rem',
  md: 'height:0.625rem;width:0.625rem',
  lg: 'height:0.75rem;width:0.75rem',
  xl: 'height:0.875rem;width:0.875rem',
};

const ariaLabel = computed(() => {
  const base = props.alt ?? props.name ?? 'User avatar';
  return props.status ? `${base}, ${STATUS_LABEL[props.status]}` : base;
});

function onImgError(): void {
  imageFailed.value = true;
}
</script>

<template>
  <span
    class="next-avatar relative inline-flex shrink-0 items-center justify-center overflow-visible rounded-next-full"
    :class="SIZE_CLASS[size]"
  >
    <span
      class="relative flex h-full w-full items-center justify-center overflow-hidden rounded-next-full bg-next-muted font-next-medium text-next-muted-foreground"
      :role="showImage ? undefined : 'img'"
      :aria-label="showImage ? undefined : ariaLabel"
    >
      <img
        v-if="showImage"
        :src="src"
        :alt="alt ?? name ?? ''"
        class="h-full w-full object-cover"
        @error="onImgError"
      />
      <span v-else-if="showInitials" aria-hidden="true">{{ initials }}</span>
      <Icon v-else name="user" aria-hidden="true" />
    </span>

    <!-- Status dot: distinct color AND shape; status word is in aria-label.
         online = solid · busy = solid (red) · away = ringed (hollow center) ·
         offline = hollow (transparent center, colored outline). -->
    <span
      v-if="status"
      class="absolute bottom-0 right-0 flex items-center justify-center rounded-next-full ring-2 ring-next-card"
      :class="status === 'offline'
        ? 'border-2 border-next-muted-foreground bg-next-card'
        : STATUS_COLOR[status]"
      :style="dotSizeStyle[size]"
      aria-hidden="true"
    >
      <span
        v-if="status === 'away'"
        class="block h-1/2 w-1/2 rounded-next-full bg-next-card"
      />
    </span>
  </span>
</template>
