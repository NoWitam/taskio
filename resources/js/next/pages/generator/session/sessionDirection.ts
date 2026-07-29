// sessionDirection — the PURE, testable core of the read-only CREATIVE DIRECTION card (the direction layer).
//
// The direction is DERIVED per run and normalized server-side, so the FE never edits it and never guesses a
// value. This module owns the two decisions the card and its host (SessionChatView) must agree on:
//
//   1. VISIBILITY — {@link hasCreativeDirection}: the card renders ONLY when the direction is non-null AND at
//      least one field survived. The backend already collapses an all-empty direction to `null`, but the FE
//      re-checks rather than trusting it: a defensive predicate is the difference between "no card" and an
//      empty shell with a title and nothing under it. The HOST gates on this too, because the card sits inside
//      a `SessionTurn` (gutter glyph + author line) that would otherwise render as an empty turn.
//   2. FIELD ORDER — {@link DIRECTION_TEXT_FIELDS} / {@link DIRECTION_VISUAL_FACETS}: the declared render order
//      for the simple text fields and the art-direction facets, so the card iterates a list instead of
//      hard-coding a dozen near-identical blocks (and so a new wire field is one array entry + two i18n keys).
//
// Nothing here formats for display: every VALUE is model-derived content and is rendered as PLAIN TEXT by the
// card (interpolation only — never `v-html`, never a markdown renderer).
import type { CreativeDirection, CreativeDirectionVisualStyle } from '../sessionTypes';

/** The simple one-value text fields, in RENDER order (label i18n key = `direction.labels.<camelCase>`). */
export const DIRECTION_TEXT_FIELDS = [
  'message',
  'goal',
  'audience',
  'tone',
  'through_line',
  'subject',
  'setting',
] as const;

export type DirectionTextField = (typeof DIRECTION_TEXT_FIELDS)[number];

/** The art-direction facets, in RENDER order (mirrors the backend whitelist + its emission order). */
export const DIRECTION_VISUAL_FACETS = ['medium', 'palette', 'lighting', 'camera'] as const;

export type DirectionVisualFacet = (typeof DIRECTION_VISUAL_FACETS)[number];

/** One resolved label/value pair the card renders (the value is already known non-empty). */
export interface DirectionEntry<K extends string = string> {
  key: K;
  value: string;
}

/** A non-empty string, or null — the single "does this field carry anything" rule. */
function text(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

/** The present text fields in declared order (empty / non-string / absent fields are skipped). */
export function directionTextEntries(
  direction: CreativeDirection | null | undefined,
): DirectionEntry<DirectionTextField>[] {
  if (!direction) return [];

  return DIRECTION_TEXT_FIELDS.flatMap((key) => {
    const value = text(direction[key]);
    return value === null ? [] : [{ key, value }];
  });
}

/** The present art-direction facets in declared order (a facet with no value is skipped). */
export function directionVisualEntries(
  direction: CreativeDirection | null | undefined,
): DirectionEntry<DirectionVisualFacet>[] {
  const style: CreativeDirectionVisualStyle | null | undefined = direction?.visual_style;
  if (!style || typeof style !== 'object') return [];

  return DIRECTION_VISUAL_FACETS.flatMap((facet) => {
    const value = text(style[facet]);
    return value === null ? [] : [{ key: facet, value }];
  });
}

/** The ordered narrative beats that carry text (a bounded list server-side; defensive here). */
export function directionBeats(direction: CreativeDirection | null | undefined): string[] {
  const beats = direction?.arc_beats;
  if (!Array.isArray(beats)) return [];

  return beats.flatMap((beat) => {
    const value = text(beat);
    return value === null ? [] : [value];
  });
}

/** The target duration in whole seconds when it is a usable positive number, else null. */
export function directionDuration(direction: CreativeDirection | null | undefined): number | null {
  const seconds = direction?.duration_target_seconds;
  return typeof seconds === 'number' && Number.isFinite(seconds) && seconds > 0 ? seconds : null;
}

/** The continuity notes when present, else null. */
export function directionContinuity(direction: CreativeDirection | null | undefined): string | null {
  return text(direction?.continuity_notes);
}

/**
 * Whether there is a direction WORTH rendering: non-null AND carrying at least one non-empty field. This is
 * the card's visibility gate — an "empty shell" card (a title over nothing) is worse than no card at all.
 */
export function hasCreativeDirection(direction: CreativeDirection | null | undefined): boolean {
  if (!direction) return false;

  return (
    directionTextEntries(direction).length > 0 ||
    directionBeats(direction).length > 0 ||
    directionVisualEntries(direction).length > 0 ||
    directionDuration(direction) !== null ||
    directionContinuity(direction) !== null
  );
}

/**
 * The collapsed header TEASER: the `message` if there is one, else the `through_line` (the two fields that
 * summarize the piece). Null when neither survived — the header then shows the title alone. Truncation is a
 * CSS concern (the card clips it), never a substring here.
 */
export function directionTeaser(direction: CreativeDirection | null | undefined): string | null {
  return text(direction?.message) ?? text(direction?.through_line);
}
