<script setup lang="ts">
// EventDrawer — the Calendar's ONLY write surface, in three modes: view, edit, create.
//
// EIGHT THINGS HERE ARE LOAD-BEARING. Each has a way of failing silently, which is why each
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
//
// 6. THE SCOPE IS CHOSEN BEFORE THE FORM IS FILLED, NEVER AT THE SAVE BUTTON.
//    The GET returns the series' ANCHOR, not the square that was clicked, so the FORM'S OWN
//    SEEDING depends on the scope: a form seeded from the anchor and saved as
//    `scope=occurrence` excludes the clicked day and detaches a duplicate on the anchor day;
//    a form seeded from the occurrence and saved as `scope=series` moves the anchor and cuts
//    off the series' past. Each seeding is right for exactly one scope. See
//    `SeriesScopeModal.vue`, which is where the choice is made.
//
// 7. THE RULE TRAVELS WHOLE. `recurrence` is a column like any other on a whole-event write:
//    omitting it REMOVES the series, and sending it without `exclusions.dates` RESURRECTS
//    every day somebody deleted one at a time. So the control is seeded from the GET and its
//    compiled result always goes back — including for a rule this form cannot express, which
//    is echoed verbatim rather than rewritten (`RecurrenceField.vue`, "Another rule").
//
// 8. A SCOPED SAVE MAY ANSWER WITH A DIFFERENT ROW THAN THE ONE IN THE URL.
//    A detach and a genuine split both create a new event and answer `201` — which reaches
//    this component as "the id in the body is not the id we asked for", since the shared api
//    client returns `response.data` and is not being widened for one case. The id we were
//    holding has stopped being the one to edit next, so the drawer closes and the grid
//    refetches rather than carrying a stale id forward.
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
import RecurrenceField from './RecurrenceField.vue';
import SeriesScopeModal from './SeriesScopeModal.vue';
import { useI18n } from '../../app/i18n';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import {
  buildEventPayload,
  useCalendarStore,
  type CalendarWriteError,
  type CalendarWriteScope,
} from '../../app/stores/calendar';
import {
  emptyRecurrenceState,
  recurrenceStateFrom,
  recurrenceStateToWire,
  type RecurrenceState,
} from './recurrencePresets';
import {
  composeWallClock,
  instantToWallClock,
  instantToZonedParts,
  monthOf,
  monthStartDate,
  workspaceToday,
} from './calendarZone';
import { fromIsoDate, fullDateLabel, monthYearLabel } from '../../ui/forms/date/dateCore';
import type { CalendarEvent, CalendarEventPayload, CalendarEventScope, IsoDay } from './types';

const props = withDefaults(
  defineProps<{
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
    /** How much of the series a write touches, from the URL. `series` unless named. */
    scope?: CalendarEventScope;
    /**
     * WHICH occurrence, exactly as the server published it on the clicked square
     * (`occurrence.occurrence_date`). Never derived here from an instant and a zone: it is
     * reckoned on the series' own STAMPED clock, and the name sent back must not drift from
     * the key the grid renders by.
     */
    occurrenceDate?: IsoDay | null;
    /**
     * The clicked occurrence's own instant (`occurrence.starts_at`), used ONLY to seed the
     * form's date + time for a scoped edit. A separate key from `occurrenceDate` because they
     * answer different questions — one names the occurrence, the other is a moment — and the
     * day/instant distinction runs through this whole module.
     */
    occurrenceStartsAt?: string | null;
    /** The cadence sentence the clicked square carried. SERVER prose; never composed here. */
    occurrenceCadenceLabel?: string | null;
  }>(),
  { scope: 'series', occurrenceDate: null, occurrenceStartsAt: null, occurrenceCadenceLabel: null },
);

const emit = defineEmits<{
  (e: 'saved', event: CalendarEvent): void;
  (e: 'deleted'): void;
  /** Switch to edit mode AT THIS SCOPE (the URL owns both, so the page performs the switch). */
  (e: 'request-edit', scope: CalendarEventScope): void;
  /** Leave edit mode without saving. */
  (e: 'cancel-edit'): void;
  /** Navigate the grid to a month (`yyyy-mm`) — the series' own start or end. */
  (e: 'go-to-month', month: string): void;
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
const isSeries = computed(() => !!event.value?.recurrence);

// --- Scope ------------------------------------------------------------------
/**
 * The scope actually in force, after the URL has been checked against what was loaded.
 *
 * Two degradations, and they are deliberately DIFFERENT: a scoped link pointing at an event
 * that no longer repeats is a stale link, not a user's mistake, so it degrades silently; a
 * scoped link with no occurrence named cannot be honoured at all, and says so out loud —
 * otherwise "edit this Tuesday" would quietly become "edit every Tuesday".
 */
const effectiveScope = computed<CalendarEventScope>(() => {
  if (props.scope === 'series') return 'series';
  if (!isSeries.value) return 'series';
  if (!props.occurrenceDate) return 'series';
  return props.scope;
});

const scopeFallbackNotice = computed(
  () => props.scope !== 'series' && isSeries.value && !props.occurrenceDate,
);

/**
 * WHICH DAY the form is about. The one thing that differs between the two seedings, and
 * therefore the only thing a scope change has to re-seed.
 */
const seedSource = computed<'anchor' | 'occurrence'>(() =>
  effectiveScope.value === 'series' ? 'anchor' : 'occurrence',
);

const writeScope = computed<CalendarWriteScope>(() => ({
  scope: effectiveScope.value,
  occurrenceDate: effectiveScope.value === 'series' ? null : props.occurrenceDate,
}));

/**
 * THE DAY THE SERIES REALLY STARTS ON — the day the STORED rule is anchored on, read on the
 * SERIES' OWN STAMPED CLOCK, which is not always today's workspace clock (gap L13).
 *
 * ONE READING, USED EVERYWHERE THE SERIES' FIRST DAY IS SPOKEN ABOUT. It answers three
 * questions that are the same question — is the named occurrence the first one
 * (`atFirstOccurrence`), what does the "Series starts" row say, which month does "Show the
 * start of the series" go to — and they were not all answered the same way: two of them read
 * the anchor on the WINDOW's clock instead, so a workspace that changed zone after the series
 * was written made the drawer name a different day from the one the grid draws the first
 * square on.
 *
 * THE STAMPED CLOCK IS THE RIGHT ONE BY THE SAME ARGUMENT THE SERVER USES: the source
 * publishes every `occurrence_date` as `$moment->setTimezone($rule->timezone())` — the rule's
 * clock decides which day an occurrence lands on, so anything naming one of those days has to
 * be reckoned there too, or it names a day the grid has no square on.
 *
 * NOT to be confused with the FORM's anchor (`draftAnchorDay`), which is deliberately read on
 * the window's clock: the form is about the day the user is editing, and when the two clocks
 * disagree the repeat control degrades to "Another rule" rather than re-deriving a cadence.
 * These are two different questions and each keeps its own answer.
 */
const seriesAnchorDay = computed<IsoDay | null>(() => {
  const loaded = event.value;
  if (!loaded) return null;
  if (loaded.all_day) return loaded.start_date;
  const zone = loaded.recurrence_timezone ?? props.timezone;
  return loaded.starts_at ? (instantToZonedParts(loaded.starts_at, zone)?.day ?? null) : null;
});

const atFirstOccurrence = computed(
  () => !!props.occurrenceDate && props.occurrenceDate === seriesAnchorDay.value,
);

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

/** The repeat control's own state. Compiled to a `recurrence` block only at save time. */
const recurrence = ref<RecurrenceState>(emptyRecurrenceState());

const fieldErrors = ref<Record<string, string>>({});
const formError = ref<string | null>(null);

/** The day the rule is anchored on AS THE FORM CURRENTLY HOLDS IT (workspace clock). */
const draftAnchorDay = computed<IsoDay | null>(() =>
  draft.all_day ? draft.start_date : draft.starts_day,
);

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
  recurrence.value = emptyRecurrenceState();
}

/**
 * Seed the two time groups from the loaded event, in the WORKSPACE zone — from the ANCHOR
 * under `scope=series`, and from the CLICKED OCCURRENCE under the two scoped writes.
 *
 * Split out from `seedFrom` because a scope change re-seeds exactly this and nothing else:
 * a title somebody typed is not a fact about a day.
 */
function seedTime(loaded: CalendarEvent): void {
  const anchorStart = instantToWallClock(loaded.starts_at, props.timezone);
  const anchorEnd = instantToWallClock(loaded.ends_at, props.timezone);
  const fallbackDay = workspaceToday(props.timezone);

  if (seedSource.value === 'anchor') {
    draft.start_date = loaded.start_date ?? anchorStart.day ?? fallbackDay;
    draft.starts_day = anchorStart.day ?? loaded.start_date ?? fallbackDay;
    draft.starts_time = anchorStart.time ?? '09:00';
    draft.ends_day = anchorEnd.day;
    draft.ends_time = anchorEnd.time;
    return;
  }

  // The occurrence's own instant when the grid handed one over; otherwise the named day at
  // the series' single hour — which is the anchor's hour, since a series has exactly one.
  const occStart = props.occurrenceStartsAt
    ? instantToWallClock(props.occurrenceStartsAt, props.timezone)
    : { day: props.occurrenceDate, time: anchorStart.time };
  const occEnd = instantToWallClock(occurrenceEndInstant(loaded), props.timezone);

  draft.start_date = props.occurrenceDate ?? loaded.start_date ?? fallbackDay;
  draft.starts_day = occStart.day ?? props.occurrenceDate ?? fallbackDay;
  draft.starts_time = occStart.time ?? anchorStart.time ?? '09:00';
  draft.ends_day = occEnd.day;
  draft.ends_time = occEnd.time;
}

/**
 * The clicked occurrence's end, as the server itself computes it: the ANCHOR'S OWN DURATION
 * applied as a fixed interval. An hour-long standup stays exactly an hour across a
 * daylight-saving transition, rather than becoming 55 or 65 minutes.
 *
 * This applies a duration; it does not project a cadence. Nothing here walks the rule.
 */
function occurrenceEndInstant(loaded: CalendarEvent): string | null {
  if (loaded.all_day || !loaded.starts_at || !loaded.ends_at || !props.occurrenceStartsAt) return null;
  const span = Date.parse(loaded.ends_at) - Date.parse(loaded.starts_at);
  const start = Date.parse(props.occurrenceStartsAt);
  if (!Number.isFinite(span) || !Number.isFinite(start)) return null;
  return new Date(start + span).toISOString();
}

/**
 * Seed an EDIT from the loaded event.
 *
 * Only the keys the FORM owns are read. An event stored before the colour choice was
 * removed may still come back with a `color` on it from an older server; it is simply not
 * read, so nothing here can fail on its presence or its absence.
 *
 * The rule is recognised against the day the FORM ends up holding, not against some other
 * reckoning of the anchor — so whatever the control says it can produce, it really can. When
 * the two disagree (the workspace changed zone since the series was written) recognition
 * simply fails and the rule becomes "Another rule", which echoes it back byte for byte. That
 * is the safe direction to fail in.
 */
function seedFrom(loaded: CalendarEvent): void {
  draft.title = loaded.title;
  draft.description = loaded.description ?? '';
  draft.all_day = loaded.all_day;
  seedTime(loaded);
  recurrence.value = recurrenceStateFrom(loaded.recurrence, draftAnchorDay.value);
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

/**
 * A scope change re-seeds the DAY the form is about, and only that. The user has already
 * agreed to it — `onScopeChosen` asks before letting the change through — so nothing here
 * has to guard against surprising them.
 */
watch(seedSource, () => {
  const loaded = event.value;
  if (!open.value || !loaded || isCreate.value) return;
  seedTime(loaded);
});

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
// where the whole-event write, the one-time-group rule, the explicit-offset rule and the
// rule-travels-whole rule all meet, each of which fails silently, so it is unit-tested
// rather than trusted.
function buildPayload(): CalendarEventPayload {
  return buildEventPayload(
    { ...draft, recurrence: recurrenceStateToWire(recurrence.value, draftAnchorDay.value) },
    event.value,
    props.timezone,
    writeScope.value,
  );
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

/**
 * Whether this form has SOMEWHERE to render a message about this field.
 *
 * The `recurrence.` PREFIX matters and cannot be enumerated: the rule's 422s arrive on a
 * dozen different paths (`recurrence.day.mode`, `recurrence.until`,
 * `recurrence.exclusions.dates`, …) and `RecurrenceField` maps every one of them to one of
 * its two controls. Without the prefix test they would all be judged homeless and shown in
 * an alert at the top — a message about a control that is right there on the screen, put
 * anywhere except beside it.
 *
 * `recurrence.time` / `recurrence.tz` / an unknown key are deliberately NOT covered: there is
 * no control for them because the server authors those values, so a rejection naming one is a
 * defect in the client (a stale bundle, a hand-built payload), not a state a user is in — and
 * it belongs at the top, where a defect can be read.
 */
function hasControlFor(field: string): boolean {
  if (FORM_FIELDS.has(field)) return true;
  // Not a control, but a home all the same: these get their own alert with a way back into
  // the scope dialog (below). Counting them as homeless would print the same message twice.
  if (field === 'scope' || field === 'occurrence_date') return true;
  if (field === 'recurrence.time' || field === 'recurrence.tz') return false;
  return field === 'recurrence' || field.startsWith('recurrence.');
}

/** The toast NAMES the scope — three operations reporting identically cannot be told apart. */
function savedMessage(scope: CalendarEventScope): string {
  if (scope === 'occurrence') return t('calendar.event.savedOccurrence');
  if (scope === 'following') {
    return t('calendar.event.savedFollowing', '', { date: occurrenceLabel.value });
  }
  return isSeries.value ? t('calendar.event.savedSeries') : t('calendar.event.saved');
}

async function onSave(): Promise<void> {
  if (blockedReason.value || saving.value) return;
  fieldErrors.value = {};
  formError.value = null;
  const scope = writeScope.value.scope;
  try {
    const saved = await store.saveEvent(buildPayload(), isEdit.value ? props.eventId : null);
    toast.success(isCreate.value ? t('calendar.event.saved') : savedMessage(scope));
    // NOTE 8: `saved.id !== props.eventId` means a NEW row was created (a detach, or the far
    // half of a split). Nothing here holds the old id afterwards — the drawer closes and the
    // page refetches the window — and the store already points at the row that came back,
    // together with its (possibly re-stamped) `recurrence_timezone`.
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
      const homeless = Object.entries(rejected).filter(([field]) => !hasControlFor(field));
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

/** A 422 the drawer cannot place at a control, and which invalidates the CHOICE itself. */
const scopeError = computed<string | null>(
  () => fieldErrors.value.scope ?? fieldErrors.value.occurrence_date ?? null,
);

// --- The scope dialog -------------------------------------------------------
const scopeModalOpen = ref(false);
const scopeModalMode = ref<'edit' | 'delete'>('edit');
const scopeModalError = ref<string | null>(null);

function openScopeModal(mode: 'edit' | 'delete'): void {
  scopeModalMode.value = mode;
  scopeModalError.value = null;
  scopeModalOpen.value = true;
}

/** Edit. A one-off event has nothing to scope and keeps exactly the behaviour it had. */
function onEditRequested(): void {
  if (!isSeries.value) {
    emit('request-edit', 'series');
    return;
  }
  openScopeModal('edit');
}

/** Delete. Same split: a one-off event keeps the plain confirm it always had. */
function onDeleteRequested(): void {
  if (!isSeries.value) {
    void onDeleteSingle();
    return;
  }
  openScopeModal('delete');
}

async function onScopeChosen(scope: CalendarEventScope): Promise<void> {
  if (scopeModalMode.value === 'delete') {
    await runDelete(scope);
    return;
  }
  // Changing the scope MID-EDIT can change which day the form is about, which re-seeds the
  // date and time. Losing what somebody typed must be something they agreed to, not a side
  // effect of a choice about scope.
  const nextSource = scope === 'series' ? 'anchor' : 'occurrence';
  if (isEdit.value && nextSource !== seedSource.value) {
    const agreed = await confirm({
      title: t('calendar.scope.reseedConfirm.title'),
      message: t('calendar.scope.reseedConfirm.message'),
      confirmLabel: t('calendar.scope.change'),
      cancelLabel: t('calendar.event.cancel'),
    });
    if (!agreed) return;
  }
  scopeModalOpen.value = false;
  emit('request-edit', scope);
}

// --- Deleting ---------------------------------------------------------------
/** A one-off event: the pre-existing path, byte for byte. */
async function onDeleteSingle(): Promise<void> {
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

function deletedMessage(scope: CalendarEventScope): string {
  if (scope === 'occurrence') return t('calendar.event.deletedOccurrence');
  if (scope === 'following') {
    return t('calendar.event.deletedFollowing', '', { date: occurrenceLabel.value });
  }
  return t('calendar.event.deleted');
}

/**
 * A scoped delete, run FROM INSIDE the dialog.
 *
 * The failure that actually happens — a series already carrying fifty excluded days —
 * names ANOTHER OPTION OF THIS SAME DIALOG as the remedy. So the message renders under the
 * chosen option, the dialog stays open, the choice stays selected and the other two options
 * stay live: the fix is one click away instead of a walk back through the whole path.
 */
async function runDelete(scope: CalendarEventScope): Promise<void> {
  const target = event.value;
  if (!target) return;
  scopeModalError.value = null;
  try {
    await store.deleteEvent(target.id, {
      scope,
      occurrenceDate: scope === 'series' ? null : props.occurrenceDate,
    });
    scopeModalOpen.value = false;
    toast.success(deletedMessage(scope));
    emit('deleted');
  } catch (err: unknown) {
    const failure = err as CalendarWriteError;
    const fieldMessage =
      failure.fieldErrors?.occurrence_date ?? failure.fieldErrors?.scope ?? null;
    scopeModalError.value = fieldMessage ?? failure.message ?? t('calendar.event.deleteError');
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

/** A day, written out. Used wherever a date is spoken rather than edited. */
function dayLabel(day: IsoDay | null): string {
  const date = fromIsoDate(day);
  return date ? fullDateLabel(date, props.locale) : (day ?? '');
}

const occurrenceLabel = computed(() => dayLabel(props.occurrenceDate));

/**
 * The series' cadence sentence — SERVER PROSE, from whichever source has it.
 *
 * The clicked square's own `cadence_label` wins when there is one: it came from the same
 * refresh the user is looking at. Without a clicked square (a deep link, or a series with no
 * occurrence in this window) the event's own `recurrence_label` answers instead. When BOTH
 * are null the row does not render at all — this client never assembles a sentence about a
 * cadence from `recurrence.day.*`, in any branch.
 */
const cadenceSentence = computed<string | null>(() => {
  const fromGrid = props.occurrenceCadenceLabel?.trim();
  if (fromGrid) return fromGrid;
  const fromEvent = event.value?.recurrence_label?.trim();
  return fromEvent ? fromEvent : null;
});

const skippedDays = computed<string[]>(() => event.value?.recurrence?.exclusions?.dates ?? []);

const ruleTimezone = computed<string | null>(() => {
  const zone = event.value?.recurrence_timezone;
  // Shown only when it DIFFERS: identical is the ordinary case and the row would be noise.
  return zone && zone !== props.timezone ? zone : null;
});

const metaItems = computed<DescriptionItem[]>(() => {
  const loaded = event.value;
  if (!loaded) return [];
  const rows: DescriptionItem[] = [{ key: 'when', label: t('calendar.occurrence.when'), value: whenText.value }];
  if (!loaded.all_day) {
    rows.push({ key: 'tz', label: t('calendar.occurrence.timezone'), value: props.timezone });
  }
  if (!loaded.recurrence) return rows;

  if (props.occurrenceDate) {
    rows.push({
      key: 'occurrence',
      label: t('calendar.series.selectedOccurrence'),
      value: occurrenceLabel.value,
    });
  }
  if (cadenceSentence.value) {
    rows.push({ key: 'repeats', label: t('calendar.series.repeats'), value: cadenceSentence.value });
  }
  rows.push({
    key: 'seriesStart',
    label: t('calendar.series.start'),
    // On the RULE'S clock, via the one reading (`seriesAnchorDay`) — this row names the day
    // the first square is drawn on, and the grid draws it where the rule's zone puts it.
    value: dayLabel(seriesAnchorDay.value),
  });
  rows.push({
    key: 'seriesEnd',
    label: t('calendar.series.end'),
    value: loaded.recurrence.until ? dayLabel(loaded.recurrence.until) : t('calendar.series.endNever'),
  });
  if (skippedDays.value.length > 0) {
    rows.push({ key: 'skipped', label: t('calendar.series.skipped') });
  }
  if (ruleTimezone.value) {
    rows.push({ key: 'ruleTz', label: t('calendar.series.ruleTimezone'), value: ruleTimezone.value });
  }
  return rows;
});

/**
 * A series with nothing to show in the month on screen — commoner than it looks: a series
 * that ended last year, a yearly rule seen from another month, one that starts in June.
 *
 * THE SOURCE MUST HAVE BEEN ASKED AND HAVE ANSWERED, or this sentence is a lie: "there are
 * none here" and "we did not ask" are two different statements, and the filter or a failed
 * source can produce the second while looking exactly like the first.
 */
const eventSourceAnswered = computed(() => {
  const selected = store.currentQuery?.sources ?? [];
  const asked = selected.length === 0 || selected.includes('event');
  return asked && !store.unavailableSources.some((entry) => entry.source === 'event');
});

const seriesHasNoOccurrenceHere = computed(() => {
  const loaded = event.value;
  if (!loaded?.recurrence || !store.loaded || !eventSourceAnswered.value) return false;
  return !store.occurrences.some((occurrence) => occurrence.subject?.id === loaded.id);
});

/** `yyyy-mm` of the series' own start / end. Dates that are READ, never occurrences guessed. */
function monthLabelOf(month: string | null): string {
  if (!month) return '';
  const start = monthStartDate(month);
  return start ? monthYearLabel(start, props.locale) : month;
}

const seriesStartMonth = computed<string | null>(() => {
  if (!event.value?.recurrence) return null;
  // The RULE'S clock, same reading as the row above: this button navigates the grid to the
  // month holding the FIRST SQUARE, and on a rule stamped in another zone the window's clock
  // can put that square in the neighbouring month.
  const day = seriesAnchorDay.value;
  return day ? monthOf(day) : null;
});

const seriesEndMonth = computed<string | null>(() => {
  const until = event.value?.recurrence?.until ?? null;
  if (!until) return null;
  const month = monthOf(until);
  return month === seriesStartMonth.value ? null : month;
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

/** The series' single hour, for the note under the repeat control. */
const seriesHour = computed<{ time: string; tz: string } | null>(() =>
  draft.all_day || !draft.starts_time ? null : { time: draft.starts_time, tz: props.timezone },
);

/**
 * The lower bound on a SPLIT's new start: a `following` payload starting before the day it
 * splits at would leave the new series overlapping days the closed half still covers (422
 * `split_starts_before_the_split`). The one exception is the series' own first occurrence,
 * where the server treats the write as an ordinary whole-event edit and moving the series
 * backwards is legal — so no bound is imposed there.
 *
 * There is NO bound under `scope=occurrence`: moving a detached day somewhere else ("this one
 * Tuesday we meet on Wednesday") is the whole point of that operation.
 */
const startMin = computed<IsoDay | null>(() =>
  effectiveScope.value === 'following' && !atFirstOccurrence.value ? props.occurrenceDate : null,
);
</script>

<template>
  <Drawer v-model:open="open" side="right" size="lg" :aria-label="title">
    <template #title>{{ title }}</template>

    <!-- LOADING: the shape of the panel, not a spinner — title, meta rows, a body. A series
         has three to five more meta rows, so the skeleton shows more of them. -->
    <div v-if="!isCreate && store.eventLoading" class="flex flex-col gap-next-4">
      <Skeleton variant="text" width="60%" :label="t('calendar.event.view')" />
      <Skeleton variant="text" width="40%" />
      <Skeleton variant="text" width="45%" />
      <Skeleton variant="text" width="35%" />
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

      <!-- The CHOICE itself was refused. The form keeps everything that was typed; the only
           thing that has to be redone is the scope. -->
      <Alert v-if="scopeError" variant="danger" size="sm">
        {{ scopeError }}
        <template #actions>
          <Button variant="ghost" size="xs" @click="openScopeModal('edit')">
            {{ t('calendar.scope.retry') }}
          </Button>
        </template>
      </Alert>

      <!-- A scoped link that named no occurrence. Said out loud, because the silent
           alternative is "edit this Tuesday" quietly becoming "edit every Tuesday". -->
      <Alert v-if="scopeFallbackNotice" variant="info" size="sm">
        {{ t('calendar.scope.fallbackNotice') }}
      </Alert>

      <!-- THE SCOPE BANNER — permanent for the whole edit, not a one-off confirmation. It is
           the only thing on screen that says which occurrences this form is about, and
           `variant="info"` already gives it `role="status"` (not `alert`): it is a standing
           state, not an event, and an assertive announcement would interrupt typing. -->
      <Alert v-if="isEdit && isSeries" variant="info" size="sm">
        {{
          t(`calendar.scope.banner.${effectiveScope}`, '', { date: occurrenceLabel })
        }}
        <template #actions>
          <Button variant="ghost" size="xs" @click="openScopeModal('edit')">
            {{ t('calendar.scope.change') }}
          </Button>
        </template>
      </Alert>

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
        <DatePicker
          v-model="draft.start_date"
          :min="startMin"
          :readonly="saving"
          :aria-label="t('calendar.event.field.start')"
        />
      </FormField>

      <!-- TIMED: one VALUE (a moment) split across two controls, grouped under one label so
           it never reads as two independent fields one of which could be saved alone. -->
      <template v-else>
        <FormField :label="t('calendar.event.field.start')" required :error="fieldErrors.starts_at">
          <div class="flex flex-col gap-next-2 next-sm:flex-row">
            <div class="min-w-0 flex-[3]">
              <DatePicker
                v-model="draft.starts_day"
                :min="startMin"
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

      <!-- THE REPEAT CONTROL — absent under `scope=occurrence`, and that absence is doing
           real work: one occurrence of a series is not itself a series, and a `recurrence`
           block sent with that scope is a 422. With no control there is nothing to send, so
           the whole error class is unreachable rather than caught after the fact. -->
      <p v-if="effectiveScope === 'occurrence'" class="text-next-xs text-next-muted-foreground">
        {{ t('calendar.recurrence.detachNote') }}
      </p>
      <RecurrenceField
        v-else
        v-model="recurrence"
        :anchor-day="draftAnchorDay"
        :locale="locale"
        :readonly="saving"
        :errors="fieldErrors"
        :hour="seriesHour"
        :stored-label="event?.recurrence_label ?? null"
      />

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

      <DescriptionList :items="metaItems" layout="horizontal" size="sm">
        <!-- The COUNT, with the days themselves in the tooltip: fifty dates are not a row. -->
        <template #value-skipped>
          <span :title="skippedDays.join(', ')">{{ skippedDays.length }}</span>
        </template>
      </DescriptionList>

      <!-- The series is real but has nothing in the month on screen. The two actions are
           true as facts about a DATE the resource carries — never "show the next
           occurrence", which would mean projecting the cadence in the client. -->
      <Alert v-if="seriesHasNoOccurrenceHere" variant="info" size="sm">
        {{ t('calendar.series.noneInWindow') }}
        <template #actions>
          <Button
            v-if="seriesStartMonth"
            variant="ghost"
            size="xs"
            @click="emit('go-to-month', seriesStartMonth)"
          >
            {{ t('calendar.series.goToStart', '', { month: monthLabelOf(seriesStartMonth) }) }}
          </Button>
          <Button
            v-if="seriesEndMonth"
            variant="ghost"
            size="xs"
            @click="emit('go-to-month', seriesEndMonth)"
          >
            {{ t('calendar.series.goToEnd', '', { month: monthLabelOf(seriesEndMonth) }) }}
          </Button>
        </template>
      </Alert>

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
        <!-- Gated on the POLICY flags, never on `is_owner` (note 4). For a SERIES both open
             the scope dialog first (note 6) — the user finds out what they are about to
             change before anything changes. -->
        <Button
          v-if="event.can_be_deleted"
          variant="danger"
          leading-icon="trash"
          :loading="saving"
          @click="onDeleteRequested"
        >
          {{ t('calendar.event.delete') }}
        </Button>
        <Button v-if="event.can_be_edited" variant="primary" leading-icon="pencil" @click="onEditRequested">
          {{ t('calendar.event.edit') }}
        </Button>
      </template>
    </template>
  </Drawer>

  <SeriesScopeModal
    v-model:open="scopeModalOpen"
    :mode="scopeModalMode"
    :occurrence-date="occurrenceDate"
    :first-occurrence="atFirstOccurrence"
    :locale="locale"
    :busy="scopeModalMode === 'delete' && saving"
    :error="scopeModalError"
    @confirm="onScopeChosen"
  />
</template>
