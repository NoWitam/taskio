<script setup lang="ts">
// SaveViewModal — create / edit a saved view (FilterTabs Stage 2).
//
// Built on Modal + Button + TextInput + SegmentedControl. Three sections:
//   1. Name (required, ≤60, mirrors backend) with inline 422 mapping.
//   2. Icon picker (optional) — a search box + grid of next glyphs restricted to
//      those with a valid IconEnum counterpart (R1: option c). The chosen next
//      name is stored as-is here; the page maps it to an IconEnum value on save.
//   3. Deadline date format (D1) — shown ONLY when the snapshot carries concrete
//      dates (from / to). Per endpoint the user picks `absolute` (fixed date) or
//      `relative` (offset from today). Presets get no such choice.
//
// The modal is presentational: it emits `submit` with the name, chosen icon, and
// the per-endpoint date modes; the PAGE owns the actual snapshot serialization
// (encoding the offset) + the API call. Server 422 on `name` is surfaced inline
// via `nameError` (an i18n key) and the modal stays open.
import { computed, ref, watch } from 'vue';
import Modal from '../overlay/Modal.vue';
import Button from '../primitives/Button.vue';
import TextInput from '../forms/TextInput.vue';
import IconInput from '../forms/IconInput.vue';
import SegmentedControl, { type SegmentOption } from '../forms/SegmentedControl.vue';
import { PICKABLE_ICONS } from '../forms/filterTabIcon';
import { resolveLabelIcon } from '../forms/labelIcon';
import { useI18n } from '../../app/i18n';
import { fromIsoDate, today, compareDay } from '../forms/date/dateCore';
import type { IconName } from '../primitives/icons';

export type DateMode = 'absolute' | 'relative';
export interface SaveViewDateModes {
  from: DateMode;
  to: DateMode;
}
export interface SaveViewSubmit {
  name: string;
  /** Chosen next icon name (null when cleared). */
  icon: IconName | null;
  /** Per-endpoint date format choice (D1). */
  dateModes: SaveViewDateModes;
}

const NAME_MAX = 60;

const props = withDefaults(
  defineProps<{
    mode?: 'create' | 'edit';
    /** Prefill the name (edit mode). */
    initialName?: string;
    /** Prefill the icon — a next IconName, or an IconEnum value resolved on open. */
    initialIcon?: string | null;
    /** Concrete dates in the current snapshot (drives the D1 section visibility). */
    snapshotFrom?: string | null;
    snapshotTo?: string | null;
    /** True while the parent's save request is in flight. */
    submitting?: boolean;
    /** Inline error i18n key for the name field (e.g. from a 422). */
    nameError?: string | null;
  }>(),
  {
    mode: 'create',
    initialName: '',
    initialIcon: null,
    snapshotFrom: null,
    snapshotTo: null,
    submitting: false,
    nameError: null,
  },
);

const emit = defineEmits<{
  (e: 'submit', payload: SaveViewSubmit): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

// --- Name ------------------------------------------------------------------
const name = ref('');
const iconName = ref<IconName | null>(null);
const fromMode = ref<DateMode>('absolute');
const toMode = ref<DateMode>('absolute');

// The icon picker is restricted to glyphs that have a valid IconEnum counterpart
// (the page maps the chosen next name → IconEnum on save).
const iconPool = PICKABLE_ICONS;

// (Re)seed local state whenever the modal opens.
watch(open, (isOpen) => {
  if (!isOpen) return;
  name.value = props.initialName ?? '';
  fromMode.value = 'absolute';
  toMode.value = 'absolute';
  // initialIcon may be a next name OR an IconEnum value (edit). Resolve to next.
  iconName.value = props.initialIcon
    ? (resolveLabelIcon(props.initialIcon) as IconName)
    : null;
});

const nameTrimmed = computed(() => name.value.trim());
const nameTooLong = computed(() => name.value.length > NAME_MAX);
const nameInvalid = computed(() => !nameTrimmed.value || nameTooLong.value);

// --- D1 deadline-date section ----------------------------------------------
const hasFrom = computed(() => !!props.snapshotFrom);
const hasTo = computed(() => !!props.snapshotTo);
const showDateSection = computed(() => hasFrom.value || hasTo.value);

const dateModeOptions = computed<SegmentOption<DateMode>[]>(() => [
  { value: 'absolute', label: t('tasks.savedViews.modal.dateMode.absolute') },
  { value: 'relative', label: t('tasks.savedViews.modal.dateMode.relative') },
]);

/** Display dd.mm.yyyy preview of an absolute endpoint. */
function displayAbsolute(ymd: string | null | undefined): string {
  if (!ymd) return '';
  const m = String(ymd).match(/^(\d{4})-(\d{2})-(\d{2})$/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : String(ymd);
}

/** Offset-from-today preview text for a relative endpoint. */
function offsetPreview(ymd: string | null | undefined): string {
  const d = fromIsoDate(ymd ?? null);
  if (!d) return '';
  const base = today();
  const days = Math.round((d.getTime() - base.getTime()) / 86_400_000);
  const cmp = compareDay(d, base);
  if (cmp === 0) return t('tasks.savedViews.modal.dateMode.offsetToday');
  return days > 0
    ? t('tasks.savedViews.modal.dateMode.offsetFuture', '', { days })
    : t('tasks.savedViews.modal.dateMode.offsetPast', '', { days: Math.abs(days) });
}

// --- Footer / submit -------------------------------------------------------
const title = computed(() =>
  props.mode === 'edit'
    ? t('tasks.savedViews.modal.editTitle')
    : t('tasks.savedViews.modal.createTitle'),
);
const submitLabel = computed(() =>
  props.mode === 'edit'
    ? t('tasks.savedViews.modal.saveChanges')
    : t('tasks.savedViews.modal.create'),
);

function onSubmit(): void {
  if (nameInvalid.value || props.submitting) return;
  emit('submit', {
    name: nameTrimmed.value,
    icon: iconName.value,
    dateModes: { from: fromMode.value, to: toMode.value },
  });
}
</script>

<template>
  <Modal v-model:open="open" size="xl" :close-on-esc="!submitting" :close-on-scrim="!submitting">
    <template #title>{{ title }}</template>
    <template #description>{{ t('tasks.savedViews.modal.subtitle') }}</template>

    <form class="flex flex-col gap-next-4" @submit.prevent="onSubmit">
      <!-- Name (wide) + Icon (compact) on one line. -->
      <div class="flex flex-col gap-next-3 next-sm:flex-row next-sm:items-start">
        <!-- Name -->
        <div class="flex min-w-0 flex-1 flex-col gap-next-1">
          <label class="text-next-sm font-next-medium text-next-fg" for="save-view-name">
            {{ t('tasks.savedViews.modal.nameLabel') }}
          </label>
          <TextInput
            id="save-view-name"
            v-model="name"
            :placeholder="t('tasks.savedViews.modal.namePlaceholder')"
            :aria-invalid="!!nameError || nameTooLong"
            :aria-label="t('tasks.savedViews.modal.nameLabel')"
          />
          <p
            v-if="nameError"
            class="text-next-xs text-next-danger"
            role="alert"
          >
            {{ t(nameError) }}
          </p>
          <p v-else class="text-next-xs text-next-muted-foreground">
            {{ t('tasks.savedViews.modal.nameHint') }}
          </p>
        </div>

        <!-- Icon (optional) — the prepared IconInput, narrower than the name. -->
        <div class="flex w-full shrink-0 flex-col gap-next-1 next-sm:w-56">
          <label class="text-next-sm font-next-medium text-next-fg">
            {{ t('tasks.savedViews.modal.iconLabel') }}
            <span class="font-next-normal text-next-muted-foreground">({{ t('common.optional') }})</span>
          </label>
          <IconInput
            v-model="iconName"
            :icons="iconPool"
            :placeholder="t('tasks.savedViews.modal.iconPlaceholder')"
            :search-placeholder="t('tasks.savedViews.modal.iconSearch')"
            :aria-label="t('tasks.savedViews.modal.iconLabel')"
          />
        </div>
      </div>

      <!-- D1 deadline date format (only when concrete dates exist) -->
      <fieldset v-if="showDateSection" class="flex flex-col gap-next-2 rounded-next-md border border-next-border p-next-3">
        <legend class="px-next-1 text-next-sm font-next-medium text-next-fg">
          {{ t('tasks.savedViews.modal.dateMode.sectionLabel') }}
        </legend>

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div v-if="hasFrom" class="flex flex-col gap-next-1">
            <span class="text-next-xs font-next-medium text-next-muted-foreground">
              {{ t('tasks.savedViews.modal.dateMode.fromLabel') }}
            </span>
            <SegmentedControl
              v-model="fromMode"
              :options="dateModeOptions"
              size="sm"
              equal-width
              :aria-label="t('tasks.savedViews.modal.dateMode.fromLabel')"
            />
            <span class="text-next-xs text-next-muted-foreground">
              {{ fromMode === 'absolute' ? displayAbsolute(snapshotFrom) : offsetPreview(snapshotFrom) }}
            </span>
          </div>

          <div v-if="hasTo" class="flex flex-col gap-next-1">
            <span class="text-next-xs font-next-medium text-next-muted-foreground">
              {{ t('tasks.savedViews.modal.dateMode.toLabel') }}
            </span>
            <SegmentedControl
              v-model="toMode"
              :options="dateModeOptions"
              size="sm"
              equal-width
              :aria-label="t('tasks.savedViews.modal.dateMode.toLabel')"
            />
            <span class="text-next-xs text-next-muted-foreground">
              {{ toMode === 'absolute' ? displayAbsolute(snapshotTo) : offsetPreview(snapshotTo) }}
            </span>
          </div>
        </div>

        <p class="text-next-xs text-next-muted-foreground">
          {{ t('tasks.savedViews.modal.dateMode.hint') }}
        </p>
      </fieldset>
    </form>

    <template #footer="{ close }">
      <Button variant="ghost" :disabled="submitting" @click="close">
        {{ t('common.cancel') }}
      </Button>
      <Button
        variant="primary"
        :loading="submitting"
        :disabled="nameInvalid"
        @click="onSubmit"
      >
        {{ submitLabel }}
      </Button>
    </template>
  </Modal>
</template>
