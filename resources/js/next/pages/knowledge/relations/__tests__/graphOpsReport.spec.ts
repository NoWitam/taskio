// graphOpsReport.spec — server CODES → sentences, and the tone each one carries.
//
// Two properties are load-bearing:
//
//   1. EVERY code the backend can send renders as prose. The lists are enumerated here from
//      `KnowledgeGraphOps.php` and `KnowledgeGraphOpsApplier.php`, so a backend that adds a
//      thirteenth refusal reason makes this file fail rather than making a user read `pair_refused`.
//   2. AN UNKNOWN CODE STILL RENDERS. The count above the list has already promised N rows; a blank
//      one is worse than either number alone.
import { describe, it, expect, beforeEach } from 'vitest';
import { setLocale } from '../../../../app/i18n';
import { noteLine, noteSlug, opLabel, rejectLine, skipLine, warnLine } from '../graphOpsReport';

/** Every refusal code in `KnowledgeGraphOps`. */
const REJECT_CODES = [
  'unknown_handle',
  'unknown_relation_type',
  'type_not_allowed',
  'self_loop',
  'forbidden_op',
  'unknown_op',
  'properties_refused',
  'duplicate_relation',
  'pair_refused',
  'op_cap_reached',
  'template_directive',
  'malformed',
] as const;

/** Every warning code in `KnowledgeGraphOps`. */
const WARN_CODES = [
  'pair_unchecked',
  'rewrite_degraded_to_append',
  'wikilinks_lost',
  'ambiguity_unresolved',
  'moved_to_review',
] as const;

/** Every skip code in `KnowledgeGraphOpsApplier`. */
const SKIP_CODES = [
  'already_applied',
  'relation_gone',
  'dependency_not_accepted',
  'refused',
  'not_selected',
] as const;

describe('every server code becomes a sentence, in both languages', () => {
  for (const locale of ['pl', 'en'] as const) {
    it(`words all twelve refusal codes (${locale})`, () => {
      setLocale(locale);

      for (const code of REJECT_CODES) {
        const line = rejectLine({ code });

        expect(line.title, `${code} in ${locale}`).not.toContain('knowledge.relations');
        expect(line.title.length).toBeGreaterThan(0);
        expect(line.icon.length).toBeGreaterThan(0);
      }
    });

    it(`words all four warning codes (${locale})`, () => {
      setLocale(locale);

      for (const code of WARN_CODES) {
        const line = warnLine({ code, links: [], reason: 'truncated' });

        expect(line.title, `${code} in ${locale}`).not.toContain('knowledge.relations');
        expect(line.title.length).toBeGreaterThan(0);
      }
    });

    it(`words all five skip codes (${locale})`, () => {
      setLocale(locale);

      for (const code of SKIP_CODES) {
        const line = skipLine({ code });

        expect(line.title, `${code} in ${locale}`).not.toContain('knowledge.relations');
        expect(line.title.length).toBeGreaterThan(0);
      }
    });
  }
});

describe('the context each code carries reaches the sentence', () => {
  beforeEach(() => setLocale('en'));

  it('names the offending PROPERTY key', () => {
    const line = rejectLine({ code: 'properties_refused', property: 'salary' });

    // Refused rather than dropped, so saying WHICH key is the whole point of the message.
    expect(line.title).toContain('salary');
  });

  it('states the cap that was hit', () => {
    expect(rejectLine({ code: 'op_cap_reached', max: 40 }).title).toContain('40');
  });

  it('carries the existing relation id on a duplicate, so the UI can offer to show it', () => {
    const line = rejectLine({ code: 'duplicate_relation', existing_relation_id: 'rel-1' });

    expect(line.existingRelationId).toBe('rel-1');
  });

  it('leaves `existingRelationId` null on every other code', () => {
    expect(rejectLine({ code: 'self_loop' }).existingRelationId).toBeNull();
  });

  it('explains WHY a rewrite became an append, differently per reason', () => {
    const truncated = warnLine({ code: 'rewrite_degraded_to_append', reason: 'truncated' });
    const noRevision = warnLine({ code: 'rewrite_degraded_to_append', reason: 'no_revision' });

    // Same severity, different cause. The generic wording would read as an arbitrary decision.
    expect(truncated.hint).not.toBe(noRevision.hint);
    expect(truncated.hint).toBeTruthy();
    expect(noRevision.hint).toBeTruthy();
  });

  it('lists the wikilinks a rewrite would drop', () => {
    const line = warnLine({ code: 'wikilinks_lost', links: ['cennik', 'zwroty'] });

    expect(line.title).toContain('2');
    expect(line.hint).toContain('cennik');
    expect(line.hint).toContain('zwroty');
  });

  it('names the ENTRY whose change moved to the board, and does not call it an alarm', () => {
    const line = warnLine({ code: 'moved_to_review', entity: 'E1', title: 'Cennik' });

    // The operation did not vanish; a reviewer who saw it proposed last round must be able to find
    // out where it went. And it reports something going RIGHT — the change was routed to a card
    // with a diff — so a warning triangle here would flag the safe path as the alarming one.
    expect(line.title).toContain('Cennik');
    expect(line.icon).not.toBe('alert-triangle');
    expect(line.hint).toBeTruthy();
  });

  it('names the mention nothing could settle', () => {
    expect(warnLine({ code: 'ambiguity_unresolved', mention: 'Anna' }).title).toContain('Anna');
  });

  it('carries the rule that refused an operation at accept time', () => {
    expect(skipLine({ code: 'refused', reason: 'unknown_type' }).hint).toBe('unknown_type');
  });
});

describe('an unknown code still renders', () => {
  beforeEach(() => setLocale('en'));

  it('names a refusal this build has never heard of', () => {
    const line = rejectLine({ code: 'brand_new_reason' as never });

    expect(line.title).not.toContain('knowledge.relations');
    expect(line.title.length).toBeGreaterThan(0);
  });

  it('names an unknown warning and an unknown skip too', () => {
    expect(warnLine({ code: 'whatever' as never }).title).not.toContain('knowledge.relations');
    expect(skipLine({ code: 'whatever' as never }).title).not.toContain('knowledge.relations');
  });
});

describe('the operation prefix is a TENSE', () => {
  beforeEach(() => setLocale('en'));

  it('says what WOULD happen, differently per operation', () => {
    // The review shows a proposal; a row reading "Anna is a member of Acme" would state as fact a
    // relation that does not exist yet.
    const labels = [opLabel('create'), opLabel('update'), opLabel('end')];

    expect(new Set(labels).size).toBe(3);
    for (const label of labels) expect(label).not.toContain('knowledge.relations');
  });

  it('falls back to the additive reading for an operation it does not know', () => {
    expect(opLabel('something_else')).toBe(opLabel('create'));
  });
});

// ------------------------------------------------------------------------------------------------
// RUN NOTES — the only messages that answer "why did I get something other than what I asked for?"
//
// A reviewer requests a rewrite and receives an append. The card is honest about WHAT will happen
// and silent about WHY, and silence there reads as "the model ignored me" or "this is broken".
// Both are wrong, both corrosive, and the real answer is usually mundane.

/** Every note code in `DraftRunNotes` that the notes LIST words for itself. */
const NOTE_CODES = [
  'amend_append_only',
  'amend_too_long',
  'wikilinks_lost',
  'resolution_degraded',
  'entry_incomplete',
] as const;

/** Every notable degradation in `ResolutionSet` (`disabled` is deliberately never noted). */
const DEGRADE_REASONS = [
  'budget',
  'scan_limit',
  'vectors_unsupported',
  'embedding_failed',
  'extraction_failed',
] as const;

describe('every run note becomes a sentence, in both languages', () => {
  for (const locale of ['pl', 'en'] as const) {
    it(`words every note code (${locale})`, () => {
      setLocale(locale);

      for (const code of NOTE_CODES) {
        // The union of context every code might carry; each one reads only its own.
        const line = noteLine({ code, links: ['a'], name: 'Polityka zwrotów', field: 'title' });

        expect(line.title, `${code} in ${locale}`).not.toContain('knowledge.compose');
        expect(line.title.length).toBeGreaterThan(0);
        // Every one of these EXPLAINS something; a headline with no explanation would leave the
        // reviewer exactly where they started.
        expect(line.hint, `${code} hint in ${locale}`).toBeTruthy();
        expect(line.hint).not.toContain('knowledge.compose');
      }
    });

    it(`words every degradation reason (${locale})`, () => {
      setLocale(locale);

      for (const reason of DEGRADE_REASONS) {
        const line = noteLine({ code: 'resolution_degraded', reason });

        expect(line.hint, `${reason} in ${locale}`).not.toContain('knowledge.compose');
        expect(line.hint?.length ?? 0).toBeGreaterThan(0);
      }
    });
  }
});

describe('a run note carries its context', () => {
  beforeEach(() => setLocale('en'));

  it('says WHICH shortfall degraded the resolution', () => {
    // "The budget ran out" and "this base is past the scan limit" call for different actions, and
    // neither is "the base does not contain that name" — which is what an unexplained `unresolved`
    // list otherwise reads as.
    const budget = noteLine({ code: 'resolution_degraded', reason: 'budget' });
    const scan = noteLine({ code: 'resolution_degraded', reason: 'scan_limit' });

    expect(budget.hint).not.toBe(scan.hint);
    expect(budget.hint).toBeTruthy();
    expect(scan.hint).toBeTruthy();
  });

  it('falls back to the generic explanation for an unknown reason', () => {
    const line = noteLine({ code: 'resolution_degraded', reason: 'something_new' });

    expect(line.hint).toBeTruthy();
    expect(line.hint).not.toContain('knowledge.compose');
  });

  it('lists the wikilinks a rewrite would drop', () => {
    const line = noteLine({ code: 'wikilinks_lost', links: ['cennik', 'zwroty'] });

    expect(line.title).toContain('cennik');
    expect(line.title).toContain('zwroty');
  });

  it('names WHICH proposal was dropped and WHAT was missing from it', () => {
    // This note is the only one about something the reviewer cannot see — the proposal it
    // describes was discarded, so there is no card to compare against. Without the name they
    // cannot tell whether the entry they were waiting for is the one that fell out; without the
    // field they cannot tell what to ask for differently. Both used to be silence.
    const line = noteLine({ code: 'entry_incomplete', field: 'content', name: 'polityka-zwrotow' });

    expect(line.title).toContain('polityka-zwrotow');
    expect(line.hint).toBeTruthy();
    // The field is an ENUM on the wire; printing it raw would put an English key in a Polish UI.
    setLocale('pl');
    const pl = noteLine({ code: 'entry_incomplete', field: 'content', name: 'polityka-zwrotow' });
    expect(pl.hint).toContain('treść');
    expect(pl.hint).not.toContain('content');
    setLocale('en');
  });

  it('still words the note when the server sends no field or name', () => {
    // Older sessions and any future shape change: a note that renders half a sentence is still
    // better than a blank row, which the count above the list has already promised will be full.
    const line = noteLine({ code: 'entry_incomplete' });

    expect(line.title).not.toContain('{name}');
    expect(line.hint).not.toContain('{field}');
    expect(line.title.length).toBeGreaterThan(0);
  });

  it('reports an unknown note code rather than rendering a blank row', () => {
    const line = noteLine({ code: 'something_the_backend_added' });

    expect(line.title).not.toContain('knowledge.compose');
    expect(line.title.length).toBeGreaterThan(0);
  });

  it('never dresses a note as a warning — nothing here went wrong', () => {
    // The server did the safe thing and is reporting that it did. Alarm glyphs on these would
    // teach people to stop reading them, which is exactly what they cannot afford.
    for (const code of NOTE_CODES) {
      expect(noteLine({ code }).icon).toBe('info');
    }
  });
});

describe('a note names its subject by SLUG, or nothing at all', () => {
  it('reports the entry an amendment note is about', () => {
    // Slug, never draft id: the note is written while the draft is still being assembled.
    expect(noteSlug({ code: 'amend_append_only', slug: 'cennik' })).toBe('cennik');
  });

  it('reports null for a note about the whole run', () => {
    expect(noteSlug({ code: 'resolution_degraded', reason: 'budget' })).toBeNull();
  });
});
