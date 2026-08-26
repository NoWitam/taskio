<script setup lang="ts">
// SeriesScopeModal — "how much of this series are you about to change?", asked BEFORE
// anything changes.
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// WHY THIS COMES BEFORE THE FORM AND NOT AT THE SAVE BUTTON
// ─────────────────────────────────────────────────────────────────────────────────────────
// Because the form's OWN CONTENTS depend on the answer, and getting that backwards destroys
// data silently:
//
//   • `GET /calendar/events/{id}` returns the series' ANCHOR, not the square that was
//     clicked. A form seeded from the anchor and saved as `scope=occurrence` excludes the
//     CLICKED day and detaches a duplicate on the ANCHOR day — the user loses the very
//     Tuesday they were editing and gains a copy somewhere else.
//   • A form seeded from the clicked occurrence and saved as `scope=series` MOVES the anchor
//     and truncates the series' past.
//
// Each seeding is correct for exactly ONE scope and catastrophic for another, and the only
// moment the right one can be chosen is before the fields are filled. Asking at save time
// (as some calendars do) is only possible for a client whose read returns the clicked
// occurrence; ours does not.
//
// It also closes a whole error class for free: under `scope=occurrence` the repeat control is
// not rendered at all, so a `recurrence` block cannot be sent alongside it (422
// `occurrence_has_no_rule`) — the mistake becomes unreachable instead of caught afterwards.
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// WHAT THIS DIALOG MAY AND MAY NOT CLAIM
// ─────────────────────────────────────────────────────────────────────────────────────────
// It may say that "This and all following" leaves earlier occurrences as they were — that is
// true even when there are none. It may NOT say the opposite: `occurrence_date > anchor` does
// NOT prove anything survives the split. The server decides by PROJECTING the outgoing range
// (a series whose earlier days were all deleted one by one still has an anchor before the
// split), and this client has no projection. So the only claim made about the first
// occurrence is the one direction that is provable: when the named day IS the anchor day, the
// split certainly covers everything.
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// WITH NO DAY NAMED, TWO OF THE THREE OPTIONS DO NOT EXIST
// ─────────────────────────────────────────────────────────────────────────────────────────
// `occurrenceDate` is null whenever the event was reached WITHOUT a square — a deep link of
// the form `?event=<id>`, a search result, a series with nothing inside the window on screen.
// Both scoped writes are named BY that day (`occurrence_date` is required beside
// `scope != series`), so with no day there is nothing for them to be about: a scoped delete is
// a guaranteed 422 and a scoped edit degrades back to the whole series anyway.
//
// So they are DISABLED and `series` is pre-selected. The pre-selection is the load-bearing
// half: a control that cannot act is bad, and a control that cannot act AND is selected by
// default is worse, because a reader reaches it without choosing it — one reflexive Enter on
// a delete and the dialog answers with a refusal it could have avoided asking for.
//
// Their DESCRIPTION changes with them, and not only for tidiness: those sentences are built
// around `{date}`, so with nothing to interpolate `deleteHint` renders as " will disappear
// from the series." — a sentence opening on a space, with no subject. What replaces it says
// why the option is unavailable and where the day comes from, which is the one thing a reader
// in this state actually needs.
import { computed, nextTick, ref, watch } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import RadioGroup from '../../ui/forms/RadioGroup.vue';
import Radio from '../../ui/forms/Radio.vue';
import { useI18n } from '../../app/i18n';
import { fromIsoDate, fullDateLabel } from '../../ui/forms/date/dateCore';
import type { CalendarEventScope, IsoDay } from './types';

const props = withDefaults(
  defineProps<{
    /** `edit` only switches a mode; `delete` IS the operation and can fail. */
    mode: 'edit' | 'delete';
    /** The day the SERVER published on the clicked occurrence. Never derived here. */
    occurrenceDate: IsoDay | null;
    /** True when the named day is the series' own anchor day (see the header). */
    firstOccurrence?: boolean;
    locale: string;
    /** A request is in flight (delete mode only). */
    busy?: boolean;
    /**
     * A server message, already translated, rendered UNDER the chosen option — because the
     * realistic one (`exclusions_full`) points at ANOTHER OPTION OF THIS DIALOG, and a toast
     * would put the remedy out of reach of the choice it is about.
     */
    error?: string | null;
  }>(),
  { firstOccurrence: false, busy: false, error: null },
);

const emit = defineEmits<{ (e: 'confirm', scope: CalendarEventScope): void }>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const panelRef = ref<HTMLElement | null>(null);

const isDelete = computed(() => props.mode === 'delete');

const dateLabel = computed(() => {
  const date = fromIsoDate(props.occurrenceDate);
  return date ? fullDateLabel(date, props.locale) : (props.occurrenceDate ?? '');
});

const OPTIONS: CalendarEventScope[] = ['occurrence', 'following', 'series'];

/**
 * Whether an option can be acted on AT ALL — see the header. `series` always can: it names no
 * day and needs none, which is exactly why it is the fallback the whole module degrades to.
 */
function isAvailable(value: CalendarEventScope): boolean {
  return value === 'series' || !!props.occurrenceDate;
}

/**
 * THE NARROWEST SCOPE THAT CAN ACT IS THE DEFAULT, and that is a decision rather than a
 * convenience: a reflexive Enter has to do the SMALLEST possible damage — but "smallest" is
 * meaningless for an option the server will refuse, so with no day named the default is the
 * only scope left. It costs nothing in clarity either way, because the confirm button names
 * the choice at all times.
 */
const defaultScope = computed<CalendarEventScope>(() =>
  isAvailable('occurrence') ? 'occurrence' : 'series',
);

// Seeded from `defaultScope` rather than from a literal: `watch(open)` below only fires on a
// TRANSITION, so a dialog mounted already-open would otherwise start on a scope it disables.
const chosen = ref<CalendarEventScope>(defaultScope.value);

/**
 * The group's model, guarded. `RadioGroup` only knows about a GROUP-wide `disabled`, so its
 * arrow-key navigation would happily move the selection onto a per-option disabled radio —
 * which is the same unusable state by another route. Refusing the write here keeps one answer
 * to "can this option be chosen", used by the pointer, the keyboard and the confirm button
 * alike.
 */
const scope = computed<CalendarEventScope>({
  get: () => chosen.value,
  set: (value) => {
    if (isAvailable(value)) chosen.value = value;
  },
});

function hintFor(value: CalendarEventScope): string {
  // Not the `{date}` sentences: with no day to interpolate they render subject-less (header).
  if (!isAvailable(value)) return t('calendar.scope.needsOccurrence');
  const key = `calendar.scope.${value}.${isDelete.value ? 'deleteHint' : 'editHint'}`;
  const hint = t(key, '', { date: dateLabel.value });
  // The extra sentence is additive and appears only where it is provably true.
  if (value === 'following' && props.firstOccurrence) {
    return `${hint} ${t('calendar.scope.following.firstHint')}`;
  }
  return hint;
}

/** The confirm button NAMES the choice — voice control needs something to say. */
const confirmLabel = computed(() => {
  const verb = isDelete.value ? 'delete' : 'edit';
  const which = scope.value.charAt(0).toUpperCase() + scope.value.slice(1);
  return t(`calendar.scope.confirm.${verb}${which}`, '', { date: dateLabel.value });
});

const errorId = 'next-series-scope-error';

// Fresh every time it opens: a dialog remembering last time's answer is a dialog that
// eventually performs the wrong one on somebody's reflex.
watch(open, (isOpen) => {
  if (!isOpen) return;
  chosen.value = defaultScope.value;
  void nextTick(() => {
    requestAnimationFrame(() => {
      // The SELECTED option, not the confirm button: the dialog exists to be read and
      // answered, and the roving tabindex already puts the checked radio in the tab order.
      panelRef.value?.querySelector<HTMLElement>('input[type="radio"]:checked')?.focus({
        preventScroll: true,
      });
    });
  });
});

function onConfirm(): void {
  if (props.busy) return;
  // The belt to the guarded model's braces: whatever route the selection arrived by, an
  // operation the server cannot be asked for is not emitted from here.
  if (!isAvailable(chosen.value)) return;
  emit('confirm', chosen.value);
}

function onCancel(): void {
  if (props.busy) return;
  open.value = false;
}
</script>

<template>
  <Modal
    v-model:open="open"
    size="sm"
    :show-close="false"
    :close-on-esc="!busy"
    :close-on-scrim="!busy"
  >
    <template #title>
      {{ isDelete ? t('calendar.scope.delete.title') : t('calendar.scope.edit.title') }}
    </template>

    <div ref="panelRef" class="flex flex-col gap-next-3">
      <!-- CONTEXT, as real text above the group. Never a `title=` tooltip: which day this is
           about is the premise of the whole question, not a hint about it. -->
      <p v-if="occurrenceDate" class="text-next-sm text-next-muted-foreground">
        {{ t('calendar.scope.context', '', { date: dateLabel }) }}
      </p>

      <RadioGroup
        v-model="scope"
        :aria-label="isDelete ? t('calendar.scope.delete.title') : t('calendar.scope.edit.title')"
        :aria-invalid="!!error"
        :described-by-id="error ? errorId : undefined"
      >
        <!-- Rendered one by one rather than in a plain list so the server's message can sit
             directly under the option it is about (see the `error` prop). -->
        <template v-for="value in OPTIONS" :key="value">
          <!-- `md` (the default) keeps the touch target at 44px on a narrow screen.
               DISABLED, not removed, when no day was named: the option is a real part of this
               event's vocabulary and its description is where the reader is told how to reach
               it (click the day on the grid). Dropping it would leave the dialog silently
               shorter with nothing to explain the gap. -->
          <Radio
            :value="value"
            :label="t(`calendar.scope.${value}.label`)"
            :description="hintFor(value)"
            :disabled="!isAvailable(value)"
          />
          <p
            v-if="error && scope === value"
            :id="errorId"
            role="alert"
            class="ml-next-6 text-next-xs text-next-danger"
          >
            {{ error }}
          </p>
        </template>
      </RadioGroup>
    </div>

    <template #footer>
      <Button variant="outline" :disabled="busy" @click="onCancel">
        {{ t('calendar.event.cancel') }}
      </Button>
      <!-- Loading lives IN the button (fixed width, text stays). The options are deliberately
           left enabled while a delete is in flight: the realistic failure names another option
           of this same dialog as the remedy, so it has to stay one click away. Double-firing
           is prevented by the guard in `onConfirm`, not by taking controls out of the tree. -->
      <Button
        :variant="isDelete ? 'danger' : 'primary'"
        :loading="busy"
        @click="onConfirm"
      >
        {{ confirmLabel }}
      </Button>
    </template>
  </Modal>
</template>
