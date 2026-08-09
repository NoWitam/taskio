// graphOpsReport — server CODES turned into sentences a person can act on.
//
// The backend deliberately reports `{code, ...context}` and never prose: prose in a JSON payload
// cannot be translated, cannot be tested for, and drifts from the rule that produced it. The cost
// of that discipline is paid HERE, once, in a pure function — rather than in each component with
// its own half of the mapping.
//
// The rule this file exists to serve: a reviewer must be able to tell "the agent found nothing"
// from "the agent found things and the server threw them away". Those are the same empty screen
// and completely different news, and only the second one is a reason to distrust the surface.
//
// An UNKNOWN code still renders. A backend that adds a thirteenth refusal reason must not produce
// a blank row here — the count would say "3 not saved" and the list would show two, which is worse
// than either number alone.
import { translate } from '../../../app/i18n';
import type {
  KnowledgeDraftSkipped,
  KnowledgeGraphOpReport,
  KnowledgeRelationTypeId,
  KnowledgeRunNote,
} from '../types';
import { predicateLabel } from './relationLabels';

/** One line of the "not saved" / "notes" list: a headline, an optional explanation, an icon. */
export interface ReportLine {
  code: string;
  title: string;
  hint: string | null;
  icon: string;
  /** `duplicate_relation` carries the relation that already says this — the UI offers to show it. */
  existingRelationId: string | null;
}

const REJECT_ICONS: Record<string, string> = {
  unknown_handle: 'help-circle',
  unknown_relation_type: 'x-circle',
  type_not_allowed: 'x-circle',
  self_loop: 'x-circle',
  forbidden_op: 'shield',
  unknown_op: 'x-circle',
  properties_refused: 'x-circle',
  duplicate_relation: 'copy',
  pair_refused: 'alert-triangle',
  op_cap_reached: 'alert-triangle',
  template_directive: 'shield',
  malformed: 'x-circle',
};

/**
 * Turn one REJECTION into a line.
 *
 * The context keys differ per code (`property` here, `max` there, `existing_relation_id` on one),
 * which is why interpolation is done against the whole report object rather than a fixed shape.
 */
export function rejectLine(report: KnowledgeGraphOpReport): ReportLine {
  const key = `knowledge.relations.reject.${report.code}`;
  const known = translate(key) !== key;

  return {
    code: report.code,
    title: known
      ? translate(key, '', {
          property: String(report.property ?? ''),
          max: String(report.max ?? ''),
          type: String(report.type ?? ''),
        })
      : // A code this build has never heard of. Named rather than blanked: the count above the list
        // has already promised a row, and an empty one reads as a rendering fault.
        translate('knowledge.relations.reject.unknown'),
    hint: null,
    icon: REJECT_ICONS[report.code] ?? 'x-circle',
    existingRelationId:
      typeof report.existing_relation_id === 'string' ? report.existing_relation_id : null,
  };
}

const WARN_ICONS: Record<string, string> = {
  pair_unchecked: 'alert-triangle',
  rewrite_degraded_to_append: 'alert-triangle',
  wikilinks_lost: 'alert-triangle',
  ambiguity_unresolved: 'help-circle',
  // Not an alarm: the change was ROUTED to the board, which is the outcome we want.
  moved_to_review: 'arrow-right',
};

/**
 * Turn one WARNING into a line.
 *
 * Two of the four have a `reason` or a list that changes what the sentence should say, and both
 * are cases where the generic wording would be actively misleading: "appended instead of
 * rewritten" without saying WHY reads as an arbitrary decision, when in fact the entry was too
 * long to show the agent.
 */
export function warnLine(report: KnowledgeGraphOpReport): ReportLine {
  const base = `knowledge.relations.warn.${report.code}`;
  const known = translate(base) !== base;

  if (!known) {
    return {
      code: report.code,
      title: translate('knowledge.relations.warn.unknown'),
      hint: null,
      icon: 'info',
      existingRelationId: null,
    };
  }

  const links = Array.isArray(report.links) ? report.links : [];

  const hint = (() => {
    if (report.code === 'rewrite_degraded_to_append') {
      // `truncated` | `no_revision` — a different explanation, not a different severity.
      const suffix = report.reason === 'no_revision' ? 'NoRevision' : 'Truncated';

      return translate(`${base}${suffix}`);
    }
    if (report.code === 'wikilinks_lost') {
      return translate(`${base}Hint`, '', { links: links.join(', ') });
    }
    if (report.code === 'pair_unchecked' || report.code === 'moved_to_review') {
      return translate(`${base}Hint`);
    }

    return null;
  })();

  return {
    code: report.code,
    title: translate(base, '', {
      count: String(links.length),
      mention: String(report.mention ?? ''),
      title: String(report.title ?? ''),
    }),
    hint,
    // `moved_to_review` is not a caveat at all — it is a REDIRECTION, and the only line in this
    // list that reports something going right. A warning triangle on it would tell a reviewer that
    // the safe path is the alarming one.
    icon: WARN_ICONS[report.code] ?? 'alert-triangle',
    existingRelationId: null,
  };
}

/**
 * Turn one accept-time SKIP into a line.
 *
 * Rendered as INFORMATION, never as an error. A 200 with four entries saved and two skipped is the
 * ordinary result of accepting a subset — the two that did not run depended on the rest — and
 * dressing that in red would teach a reviewer to fear their own partial approvals.
 */
export function skipLine(skipped: KnowledgeDraftSkipped): ReportLine {
  const key = `knowledge.relations.skip.${skipped.code}`;
  const known = translate(key) !== key;

  return {
    code: skipped.code,
    title: known ? translate(key) : translate('knowledge.relations.skip.unknown'),
    // `refused` carries the rule that refused it; the rest are complete on their own.
    hint: skipped.code === 'refused' && skipped.reason ? String(skipped.reason) : null,
    // `not_selected` gets the tick too: it is the reviewer's own decision reported back, and the
  // only reason it appears in this list at all is so they can see that it took effect.
  icon: skipped.code === 'already_applied' || skipped.code === 'not_selected' ? 'check-circle' : 'info',
    existingRelationId: null,
  };
}

/**
 * Turn one RUN NOTE into a line.
 *
 * ------------------------------------------------------------------------------------------------
 * These are the only messages that answer "why did I get something different from what I asked
 * for?"
 *
 * A reviewer requests a rewrite and receives an append. The card is honest about WHAT will happen
 * and says nothing about WHY, so without these the only available explanations are "the model
 * ignored me" or "this is broken" — both wrong, both corrosive, and neither correctable. The real
 * answer is usually mundane (the entry was too long to show the composer in full), and mundane
 * answers only reassure when somebody says them.
 *
 * A note is NOT a warning and must not be dressed as one: nothing here went wrong. The server did
 * the safe thing and is reporting that it did.
 */
export function noteLine(note: KnowledgeRunNote): ReportLine {
  const base = `knowledge.compose.note.${note.code}`;
  const known = translate(base) !== base;

  if (!known) {
    return {
      code: note.code,
      title: translate('knowledge.compose.note.unknown'),
      hint: null,
      icon: 'info',
      existingRelationId: null,
    };
  }

  // `resolution_degraded` carries WHICH shortfall, and the distinction is the whole value of the
  // note: "the budget ran out" and "this base is past the scan limit" call for different actions,
  // and neither is "the base does not contain that name" — which is what an unexplained
  // `unresolved` list otherwise reads as.
  const reason = typeof note.reason === 'string' ? note.reason : null;

  // `entry_incomplete` is the one note about something the reviewer CANNOT see: the proposal it
  // describes was dropped, so there is no card to compare against. Both halves of its context are
  // therefore load-bearing — WHICH proposal fell out (`name`: the model's slug for it, or the one
  // half that survived) and WHAT was missing from it (`field`). Told only that "a proposal was
  // incomplete", a reviewer cannot tell whether the thing they were expecting is the thing that
  // went missing, which leaves them exactly where the silent drop did.
  const name = typeof note.name === 'string' ? note.name : '';
  const rawField = typeof note.field === 'string' ? note.field : '';
  // The field name is an enum on the wire (`title` / `content`) — worded here, never printed raw.
  const field = rawField === '' ? '' : translate(`knowledge.compose.note.field.${rawField}`, rawField);

  const hint =
    note.code === 'resolution_degraded' && reason !== null
      ? translate(`knowledge.compose.note.reason.${reason}`, translate(`${base}Hint`))
      : translate(`${base}Hint`, '', { field, name }) || null;

  return {
    code: note.code,
    title: translate(base, '', {
      links: Array.isArray(note.links) ? note.links.join(', ') : '',
      field,
      name,
    }),
    hint,
    icon: 'info',
    existingRelationId: null,
  };
}

/**
 * The entry a note is ABOUT, or null when it describes the run as a whole.
 *
 * Notes name their subject by SLUG (the amendment target), never by draft id: the note is written
 * while the draft is still being assembled and has no id yet.
 */
export function noteSlug(note: KnowledgeRunNote): string | null {
  return typeof note.slug === 'string' ? note.slug : null;
}

/**
 * The operation prefix — "Will add" / "Will change" / "Will end".
 *
 * A tense, and it is doing real work: the review shows what WOULD happen, and a row that reads
 * "Anna is a member of Acme" is a statement of fact about a relation that does not exist yet.
 */
export function opLabel(op: string): string {
  const keys: Record<string, string> = {
    create: 'knowledge.relations.opAdd',
    update: 'knowledge.relations.opUpdate',
    end: 'knowledge.relations.opEnd',
  };

  return translate(keys[op] ?? 'knowledge.relations.opAdd');
}

/** The verb of a proposed op, worded for display. Proposals carry no server label to fall back on. */
export function opPredicate(type: KnowledgeRelationTypeId | string | null | undefined): string {
  return predicateLabel(type ?? null, 'forward');
}
