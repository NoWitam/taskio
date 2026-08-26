// calendarDateText — the ONE date form this module may put after a preposition.
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// WHY A SECOND DATE FORMATTER EXISTS AT ALL
// ─────────────────────────────────────────────────────────────────────────────────────────
// `fullDateLabel` (dateCore) formats a date the way a date is SPOKEN on its own:
//
//     pl → "środa, 26 sierpnia 2026"
//     en → "Wednesday, August 26, 2026"
//
// That is correct wherever the date is the SUBJECT of its sentence ("26 sierpnia zniknie z
// serii") or the value after a colon ("Wybrane wystąpienie: …"), and those uses must not
// change. It is WRONG the moment a Polish preposition is put in front of it, because Polish
// declines and `Intl` hands back the nominative:
//
//     "Edytujesz wystąpienia od środa, 26 sierpnia"      ← what the module shipped
//     "Edytujesz wystąpienia od środy, 26 sierpnia"      ← what Polish wants
//
// There is no `Intl` option for a declined weekday, and composing one from a client-side
// table of seven genitives is the same mistake the recurrence copy already refuses to make
// elsewhere in this module (`weeklyDays` is written out one key per day precisely because a
// language that declines will not accept a name dropped into a slot).
//
// So the preposition case DROPS THE WEEKDAY instead of declining it. What is left is a form
// `Intl` already declines correctly, because the day-of-month + month-name pattern is
// genitive in Polish by construction:
//
//     pl → "26 sierpnia 2026"       → "od 26 sierpnia 2026"       ✔
//     en → "August 26, 2026"        → "from August 26, 2026"      ✔
//
// The weekday is not information the sentence needed: which day of the week a series falls
// on is a property of the SERIES, and the surfaces that use this form (the scope banner, the
// scope dialog's confirm button) are already about a named series.
//
// It is also, incidentally, the shorter string — which is what stops the scope dialog's
// confirm button from overflowing a `size="sm"` panel with "Usuń wystąpienia od wtorek,
// 2 września 2026" written across it.
//
// Pure and framework-free, like `calendarMeta` and `calendarZone`: callers own the i18n, this
// owns the shape.

/**
 * A day, written for a slot that follows a PREPOSITION ("od …", "from …").
 *
 * NEVER call this where the date is the subject of its sentence — `fullDateLabel` is right
 * there and says more. The two are not interchangeable in either direction.
 */
export function prepositionalDateLabel(date: Date, locale: string): string {
  return new Intl.DateTimeFormat(locale, {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(date);
}
