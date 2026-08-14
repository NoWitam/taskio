<script setup lang="ts">
// EventDrawer — the Calendar's ONLY write surface, in three modes: view, edit, create.
//
// FIVE THINGS HERE ARE LOAD-BEARING. Each has a way of failing silently, which is why each
// gets a paragraph rather than a line.
//
// 1. A MOMENT IS ALWAYS SENT WITH AN EXPLICIT OFFSET.
//    The user types a wall clock; `zonedWallClockToInstant` turns it into
//    `2026-08-09T14:30:00+02:00` using the WORKSPACE zone from `meta.timezone`. A bare
//    `2026-08-09T14:30` is not a moment — it becomes one only when some layer supplies a
//    zone, and which layer that is has already changed once in this project's life. The
//    offset settles it on the wire: the value names one instant, it round-trips through the
//    GET unchanged, and no reader downstream has anything left to assume. See
//    `zonedWallClockToInstant` for the DST edges that come with computing it here.
//
// 2. PUT IS A WHOLE-EVENT WRITE, NOT A PATCH.
//    `CalendarEventService::attributes()` writes every column on every save and zeroes the
//    unused time group. A payload assembled from the form alone would therefore CLEAR
//    `description` and — worst — the `subject` pointer that a `create_event` workflow step
//    set, silently severing the link between a run and what it produced. So the drawer
//    holds the whole object from the GET and carries `subject_*` through untouched. The UI
//    never authors that pointer (there is no picker for it: the Calendar does not
//    dereference morph aliases, so a picker could only show `task: 9f3e…`).
//
// 3. EXACTLY ONE TIME GROUP GOES ON THE WIRE.
//    The other is FORBIDDEN, not ignored — a stray `starts_at` on an all-day payload is a
//    422. But BOTH are kept in the local draft, so flipping the switch and flipping back
//    returns the user's own hour instead of a blank field.
//
// 4. ACTIONS ARE GATED ON `can_be_edited` / `can_be_deleted`, NEVER ON `is_owner`.
//    `is_owner` is HUMAN authorship: for an event a workflow run created it is always
//    false, while the workspace owner may perfectly well edit it. Gating on it would lock
//    owners out of their own workspace's automated events.
//
// 5. THERE IS NO COLOUR CONTROL, AND THAT IS THE POINT.
//    Colour on the calendar is a DICTIONARY OF MEANINGS, not a palette: a task deadline is
//    coloured by its priority, a workflow run by its result, a schedule by the one colour
//    that says "this is a projection, not a fact". An event let a human pick from the same
//    six values and the pick meant nothing — so in one grid red said "urgent", "failed" and
//    nothing at all. The control is gone and `color` is gone from the write surface and from
//    the event resource; the grid still colours event occurrences, with a constant the
//    server assigns. Do not reintroduce a picker here: it would re-break the dictionary.
import { computed, nextTick, reactive, ref, watch } from 'vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import DescriptionList, { type DescriptionItem } from '../../ui/data/DescriptionList.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Switch from '../../ui/forms/Switch.vue';
import DatePicker from '../../ui/forms/DatePicker.vue';
import TimePicker from '../../ui/forms/TimePicker.vue';
import { useI18n } from '../../app/i18n';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { buildEventPayload, useCalendarStore, type CalendarWriteError } from '../../app/stores/calendar';
import { composeWallClock, instantToWallClock, workspaceToday } from './calendarZone';
import { fromIsoDate, fullDateLabel } from '../../ui/forms/date/dateCore';
import type { CalendarEvent, CalendarEventPayload, IsoDay } from './types';

const props = defineProps<{
  /** The event being viewed/edited, or null in create mode. */
  eventId: string | null;
  /** `view` and `edit` need an id; `create` does not. */
  mode: 'view' | 'edit' | 'create';
  /** The workspace zone from `meta.timezone` — the only zone this form speaks. */
  timezone: string;
  /** The browser's zone, when it differs from the workspace's (drives the hint's wording). */
  browserZone: string | null;
  locale: string;
  /** The day a create was seeded from (a click on a cell), if any. */
  seedDate: IsoDay | null;
}>();

const emit = defineEmits<{
  (e: 'saved', event: CalendarEvent): void;
  (e: 'deleted'): void;
  /** Switch to edit mode (the URL owns the mode, so the page performs the switch). */
  (e: 'request-edit'): void;
  /** Leave edit mode without saving. */
  (e: 'cancel-edit'): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const toast = useToast();
const confirm = useConfirm();
const store = useCalendarStore();

const isCreate = computed(() => props.mode === 'create');
const isEdit = computed(() => props.mode === 'edit');
const isForm = computed(() => isCreate.value || isEdit.value);

const event = computed<CalendarEvent | null>(() => store.eventDetail);

// --- Draft ------------------------------------------------------------------
// BOTH time groups live here at once (see note 3). Only the chosen one is emitted.
const draft = reactive({
  title: '',
  description: '',
  all_day: true,
  start_date: null as IsoDay | null,
  starts_day: null as IsoDay | null,
  starts_time: null as string | null,
  ends_day: null as IsoDay | null,
  ends_time: null as string | null,
});

const fieldErrors = ref<Record<string, string>>({});
const formError = ref<string | null>(null);

/**
 * Seed a CREATE. All-day is the default on purpose: the user clicked a DAY, and defaulting
 * to a timed event would mean inventing an hour they never chose. Flipping the switch fills
 * the time side from what is already here, so nothing is lost by starting simple.
 */
function seedCreate(): void {
  const day = props.seedDate ?? workspaceToday(props.timezone);
  draft.title = '';
  draft.description = '';
  draft.all_day = true;
  draft.start_date = day;
  draft.starts_day = day;
  draft.starts_time = '09:00';
  draft.ends_day = null;
  draft.ends_time = null;
}

/**
 * Seed an EDIT from the loaded event, decomposing instants in the WORKSPACE zone.
 *
 * Only the keys the FORM owns are read. An event stored before the colour choice was
 * removed may still come back with a `color` on it from an older server; it is simply not
 * read, so nothing here can fail on its presence or its absence.
 */
function seedFrom(loaded: CalendarEvent): void {
  const start = instantToWallClock(loaded.starts_at, props.timezone);
  const end = instantToWallClock(loaded.ends_at, props.timezone);
  draft.title = loaded.title;
  draft.description = loaded.description ?? '';
  draft.all_day = loaded.all_day;
  draft.start_date = loaded.start_date ?? start.day ?? workspaceToday(props.timezone);
  draft.starts_day = start.day ?? loaded.start_date ?? workspaceToday(props.timezone);
  draft.starts_time = start.time ?? '09:00';
  draft.ends_day = end.day;
  draft.ends_time = end.time;
}

/**
 * Flipping the discriminator carries the DATE across so the two sides stay in step —
 * without erasing the other side's own values, which is the point of keeping both.
 */
watch(
  () => draft.all_day,
  (allDay) => {
    if (allDay) {
      if (draft.starts_day) draft.start_date = draft.starts_day;
      return;
    }
    if (draft.start_date) draft.starts_day = draft.start_date;
    if (!draft.starts_time) draft.starts_time = '09:00';
  },
);

// Load / seed whenever the drawer's identity or mode changes.
watch(
  () => [open.value, props.eventId, props.mode] as const,
  async ([isOpen, id, mode]) => {
    if (!isOpen) return;
    fieldErrors.value = {};
    formError.value = null;
    if (mode === 'create') {
      store.clearEvent();
      seedCreate();
      return;
    }
    if (!id) return;
    // Re-seed from a freshly loaded event; an already-loaded one is reused so switching
    // view → edit does not refetch (and cannot lose an in-flight edit).
    const loaded = store.eventDetail?.id === id ? store.eventDetail : await store.fetchEvent(id);
    if (loaded) seedFrom(loaded);
  },
  { immediate: true },
);

// --- Local validation -------------------------------------------------------
/**
 * Why Save is unavailable, or null when it is available. A reason string, not a boolean,
 * so the disabled control can still explain itself (`title=`) — the house rule.
 */
const blockedReason = computed<string | null>(() => {
  if (draft.title.trim() === '') return t('calendar.event.validation.titleRequired');
  if (draft.all_day) {
    return draft.start_date ? null : t('calendar.event.validation.startDateRequired');
  }
  if (!draft.starts_day || !draft.starts_time) return t('calendar.event.validation.startsAtRequired');
  const startLocal = composeWallClock(draft.starts_day, draft.starts_time);
  const endLocal = composeWallClock(draft.ends_day, draft.ends_time);
  // Only a COMPLETE end is judged: half an end (a day with no time) is simply not an end
  // yet, and refusing to save over it would block a user who is mid-thought.
  if (startLocal && endLocal && endLocal < startLocal) return t('calendar.event.validation.endBeforeStart');
  return null;
});

// --- Payload ----------------------------------------------------------------
// The assembly itself lives in the store as a PURE function (`buildEventPayload`): it is
// where the whole-event write, the one-time-group rule and the explicit-offset rule all
// meet, each of which fails silently, so it is unit-tested rather than trusted.
function buildPayload(): CalendarEventPayload {
  return buildEventPayload({ ...draft }, event.value, props.timezone);
}

// --- Save / delete ----------------------------------------------------------
const saving = computed(() => store.saving);

/** Put the caret where the problem is — the first control the server rejected. */
function focusFirstError(): void {
  void nextTick(() => {
    const panel = document.querySelector<HTMLElement>('.next-drawer [aria-invalid="true"]');
    panel?.focus?.();
  });
}

/**
 * Every field this form can put a message UNDER. Anything the server rejects that is not in
 * here has nowhere to render, so it must be raised at the top instead of vanishing.
 *
 * The case is real rather than theoretical: `color` is now `prohibited` on the write surface,
 * so a browser holding a bundle from before this change posts a key the server refuses BY
 * NAME — and a Save that quietly does nothing is exactly the failure this drawer is written
 * to avoid everywhere else. The same guard covers any field the contract grows next.
 */
const FORM_FIELDS = new Set([
  'title',
  'description',
  'all_day',
  'start_date',
  'starts_at',
  'ends_at',
]);

async function onSave(): Promise<void> {
  if (blockedReason.value || saving.value) return;
  fieldErrors.value = {};
  formError.value = null;
  try {
    const saved = await store.saveEvent(buildPayload(), isEdit.value ? props.eventId : null);
    toast.success(t('calendar.event.saved'));
    emit('saved', saved);
  } catch (err: unknown) {
    const failure = err as CalendarWriteError;
    if (failure.status === 422) {
      // The server's messages are ALREADY translated (`lang/{pl,en}/calendar.php`) and are
      // shown verbatim under their fields. No toast: a field-level problem belongs at the
      // field, not in a corner of the screen.
      const rejected = failure.fieldErrors ?? {};
      fieldErrors.value = rejected;
      // …unless NOTHING it named is on this form, in which case there is no field to put it
      // at and staying quiet would make Save look like it did nothing. Then it is a
      // form-level problem and is said out loud, verbatim, at the top.
      const homeless = Object.entries(rejected).filter(([field]) => !FORM_FIELDS.has(field));
      if (homeless.length > 0 && homeless.length === Object.keys(rejected).length) {
        formError.value = homeless[0][1] ?? failure.message ?? t('calendar.event.saveError');
        toast.danger(t('calendar.event.saveError'));
        return;
      }
      focusFirstError();
      return;
    }
    // Anything else keeps the form filled and says so at the top — losing a half-written
    // event to a network blip is the one outcome worth engineering against.
    formError.value = failure.message ?? t('calendar.event.saveError');
    toast.danger(t('calendar.event.saveError'));
  }
}

async function onDelete(): Promise<void> {
  const target = event.value;
  if (!target) return;
  const confirmed = await confirm({
    variant: 'danger',
    title: t('calendar.event.deleteConfirm.title'),
    // Deliberately NOT "you can undo this": rows are soft-deleted, but there is no restore
    // endpoint, so from the user's side the event is gone.
    message: t('calendar.event.deleteConfirm.message', '', { name: target.title }),
    confirmLabel: t('calendar.event.delete'),
    cancelLabel: t('calendar.event.cancel'),
  });
  if (!confirmed) return;
  try {
    await store.deleteEvent(target.id);
    toast.success(t('calendar.event.deleted'));
    emit('deleted');
  } catch (err: unknown) {
    const failure = err as CalendarWriteError;
    formError.value = failure.message ?? t('calendar.event.deleteError');
    toast.danger(t('calendar.event.deleteError'));
  }
}

// --- View-mode presentation -------------------------------------------------
const whenText = computed<string>(() => {
  const loaded = event.value;
  if (!loaded) return '';
  if (loaded.all_day) {
    const date = loaded.start_date ? fromIsoDate(loaded.start_date) : null;
    return `${t('calendar.occurrence.allDay')} · ${date ? fullDateLabel(date, props.locale) : (loaded.start_date ?? '')}`;
  }
  const start = instantToWallClock(loaded.starts_at, props.timezone);
  const end = instantToWallClock(loaded.ends_at, props.timezone);
  const date = start.day ? fromIsoDate(start.day) : null;
  const dateLabel = date ? fullDateLabel(date, props.locale) : (start.day ?? '');
  if (!start.time) return dateLabel;
  return end.time
    ? `${dateLabel}, ${t('calendar.occurrence.range', '', { from: start.time, to: end.time })}`
    : `${dateLabel}, ${t('calendar.occurrence.from', '', { time: start.time })}`;
});

const metaItems = computed<DescriptionItem[]>(() => {
  const loaded = event.value;
  if (!loaded) return [];
  const rows: DescriptionItem[] = [{ key: 'when', label: t('calendar.occurrence.when'), value: whenText.value }];
  if (!loaded.all_day) {
    rows.push({ key: 'tz', label: t('calendar.occurrence.timezone'), value: props.timezone });
  }
  return rows;
});

/** Nobody may act on it — say why, rather than showing a row of missing buttons. */
const noPermission = computed(
  () => !!event.value && !event.value.can_be_edited && !event.value.can_be_deleted,
);

const title = computed(() => {
  if (isCreate.value) return t('calendar.event.new');
  if (isEdit.value) return t('calendar.event.edit');
  return event.value?.title ?? t('calendar.event.view');
});

/** The persistent zone hint under the time controls — never a hover tooltip: the user is
 *  typing an hour and must know whose it is while they type. */
const zoneHint = computed(() => t('calendar.timezone.fieldHint', '', { tz: props.timezone }));
const zoneMismatch = computed(() => !!props.browserZone && props.browserZone !== props.timezone);
</script>

<template>
  <Drawer v-model:open="open" side="right" size="lg" :aria-label="title">
    <template #title>{{ title }}</template>

    <!-- LOADING: the shape of the panel, not a spinner — title, two meta rows, a body. -->
    <div v-if="!isCreate && store.eventLoading" class="flex flex-col gap-next-4">
      <Skeleton variant="text" width="60%" :label="t('calendar.event.view')" />
      <Skeleton variant="text" width="40%" />
      <Skeleton variant="rect" height="6rem" />
    </div>

    <EmptyState
      v-else-if="!isCreate && store.eventError"
      variant="error"
      :title="t('calendar.event.loadError')"
      :description="store.eventError"
    />

    <!-- FORM (create + edit) -->
    <div v-else-if="isForm" class="flex flex-col gap-next-4">
      <Alert v-if="formError" variant="danger" size="sm">{{ formError }}</Alert>

      <FormField :label="t('calendar.event.field.title')" required :error="fieldErrors.title">
        <TextInput
          v-model="draft.title"
          :maxlength="255"
          :readonly="saving"
          :placeholder="t('calendar.event.field.titlePlaceholder')"
          :aria-label="t('calendar.event.field.title')"
        />
      </FormField>

      <!-- THE DISCRIMINATOR. Always visible, always sent. -->
      <FormField :label="t('calendar.event.field.allDay')" :error="fieldErrors.all_day">
        <Switch v-model="draft.all_day" :label="t('calendar.event.field.allDayToggle')" :disabled="saving" />
      </FormField>

      <!-- ALL-DAY: a day, and nothing that implies an hour. -->
      <FormField
        v-if="draft.all_day"
        :label="t('calendar.event.field.start')"
        required
        :error="fieldErrors.start_date"
      >
        <DatePicker v-model="draft.start_date" :readonly="saving" :aria-label="t('calendar.event.field.start')" />
      </FormField>

      <!-- TIMED: one VALUE (a moment) split across two controls, grouped under one label so
           it never reads as two independent fields one of which could be saved alone. -->
      <template v-else>
        <FormField :label="t('calendar.event.field.start')" required :error="fieldErrors.starts_at">
          <div class="flex flex-col gap-next-2 next-sm:flex-row">
            <div class="min-w-0 flex-[3]">
              <DatePicker
                v-model="draft.starts_day"
                :readonly="saving"
                :aria-label="t('calendar.event.field.startDay')"
              />
            </div>
            <div class="min-w-0 flex-[2]">
              <TimePicker
                v-model="draft.starts_time"
                :readonly="saving"
                :locale="locale"
                :aria-label="t('calendar.event.field.startTime')"
              />
            </div>
          </div>
        </FormField>

        <FormField :label="t('calendar.event.field.end')" :error="fieldErrors.ends_at">
          <div class="flex flex-col gap-next-2 next-sm:flex-row">
            <div class="min-w-0 flex-[3]">
              <DatePicker
                v-model="draft.ends_day"
                :readonly="saving"
                :aria-label="t('calendar.event.field.endDay')"
              />
            </div>
            <div class="min-w-0 flex-[2]">
              <TimePicker
                v-model="draft.ends_time"
                :readonly="saving"
                :locale="locale"
                :aria-label="t('calendar.event.field.endTime')"
              />
            </div>
          </div>
        </FormField>

        <p class="-mt-next-2 flex items-start gap-next-1 text-next-xs text-next-muted-foreground">
          <Icon :name="zoneMismatch ? 'alert-triangle' : 'clock'" class="mt-px shrink-0" aria-hidden="true" />
          <span>{{ zoneHint }}</span>
        </p>
      </template>

      <!-- NO colour control (note 5). Colour here is a dictionary of meanings, not a
           palette, and an event's is the server's to assign. -->

      <FormField :label="t('calendar.event.field.description')" :error="fieldErrors.description">
        <Textarea
          v-model="draft.description"
          :rows="4"
          :maxlength="5000"
          counter
          :readonly="saving"
          :aria-label="t('calendar.event.field.description')"
        />
      </FormField>
    </div>

    <!-- VIEW -->
    <div v-else-if="event" class="flex flex-col gap-next-4">
      <!-- The colour NAME used to lead this row. It said nothing: the value was a free pick
           from a vocabulary that means priority elsewhere. Only authorship remains, and the
           row disappears when there is none rather than leaving a gap. -->
      <div v-if="event.creator" class="flex flex-wrap items-center gap-next-2">
        <CreatorBadge :creator="event.creator" size="sm" />
      </div>

      <DescriptionList :items="metaItems" layout="horizontal" size="sm" />

      <!-- PLAIN TEXT. There is no markdown contract on a calendar event, so the description
           is shown exactly as it was typed, newlines and all — never parsed. -->
      <p v-if="event.description" class="whitespace-pre-wrap text-next-sm text-next-fg">
        {{ event.description }}
      </p>

      <p v-if="noPermission" class="text-next-xs text-next-muted-foreground">
        {{ t('calendar.event.noPermission') }}
      </p>
    </div>

    <template #footer>
      <template v-if="isForm">
        <Button variant="ghost" :disabled="saving" @click="isCreate ? (open = false) : emit('cancel-edit')">
          {{ t('calendar.event.cancel') }}
        </Button>
        <Button
          variant="primary"
          :loading="saving"
          :disabled="!!blockedReason"
          :title="blockedReason ?? undefined"
          @click="onSave"
        >
          {{ t('calendar.event.save') }}
        </Button>
      </template>
      <template v-else-if="event">
        <!-- Gated on the POLICY flags, never on `is_owner` (note 4). -->
        <Button
          v-if="event.can_be_deleted"
          variant="danger"
          leading-icon="trash"
          :loading="saving"
          @click="onDelete"
        >
          {{ t('calendar.event.delete') }}
        </Button>
        <Button v-if="event.can_be_edited" variant="primary" leading-icon="pencil" @click="emit('request-edit')">
          {{ t('calendar.event.edit') }}
        </Button>
      </template>
    </template>
  </Drawer>
</template>
