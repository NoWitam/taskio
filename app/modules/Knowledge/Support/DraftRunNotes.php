<?php

namespace App\Modules\Knowledge\Support;

/**
 * What the SERVER did to the model's answer during one composition run, collected as it happens.
 *
 * A run can succeed and still have been altered — a proposal degraded from a rewrite to an append, a
 * proposal dropped because applying it would burst the entry cap. `failure_reason` cannot carry those:
 * it is terminal and singular, and none of these stop the run. Without somewhere to put them the
 * reviewer sees a diff that does not match what the composer was asked for and has no way to find out
 * why, which is the point at which people stop trusting the whole review step.
 *
 * A COLLECTOR rather than accumulated state on the service: the service is resolved per job, but
 * mutable instance state that survives a call is exactly how one run's warnings end up attached to the
 * next one's set. This object is created at the top of a run, threaded through the laundering and the
 * write, and persisted once at the settle.
 *
 * Codes, never prose. The client owns the wording and the locale — the same rule `failure_reason`
 * follows, for the same reason.
 */
final class DraftRunNotes
{
    /** @var array<int, array<string, mixed>> */
    private array $notes = [];

    /** An entry shown to the composer only in part may be APPENDED to, never rewritten. */
    public const AMEND_APPEND_ONLY = 'amend_append_only';

    /** Appending would push the target past `knowledge.entry_max_chars`, so the proposal was dropped. */
    public const AMEND_TOO_LONG = 'amend_too_long';

    /**
     * The text would split into more passages than `knowledge.chunking.max_chunks_per_entry` allows,
     * so the proposal was dropped instead of published.
     *
     * A SEPARATE code from `amend_too_long`, although both are size rules, because they are facts
     * about different limits with different remedies: an entry can sit well under the character cap
     * and still fan out past the passage cap (many short headed sections do exactly that), and the
     * answer there is to split the subject rather than to shorten the prose.
     *
     * The failure it prevents is the invisible one. An over-cap entry used to be written and then fail
     * INDEXING in the background, leaving a page in the base that no search would ever return and no
     * screen would ever account for.
     */
    public const ENTRY_TOO_MANY_CHUNKS = 'entry_too_many_chunks';

    /**
     * A PROPOSAL THAT WAS NOT AN ENTRY — no title, or no body — dropped by the laundering.
     *
     * The drop is old and correct: a nameless entry has no address and a bodiless one has nothing to
     * say. What is new is that it is SAID. Until this note the branch was the one silent drop left in
     * the laundering (ADR-0049 recorded it as the least-covered rule of that batch: unreported in
     * behaviour and unpinned in test), which put a reviewer in the position of counting proposals
     * against a run that had quietly discarded one — the failure mode every sibling code here exists
     * to prevent.
     *
     * `{code, field, name}`. `field` is `title` or `content`, because the remedy differs: an entry
     * whose subject the model could not name is a different problem from one it named and then said
     * nothing about. `name` is the only handle a dropped proposal has — the model's own slug, or its
     * title when the title is the half that survived — and it is deliberately NOT called `slug`: the
     * client routes a note carrying a `slug` to that entry's CARD, and this proposal has no card. A
     * note about a discarded thing filed under the discarded thing is a note nobody reads.
     */
    public const ENTRY_INCOMPLETE = 'entry_incomplete';

    /**
     * A DATED LINE THE MATERIAL DOES NOT SUPPORT — an entry says `2026-09-13` and no 13 September
     * appears anywhere in the source.
     *
     * Usually a CORRECTION, and usually the right one: the composer is told to fix a date the context
     * makes impossible, and on the owner's material it moved the sushi from 13 July to 13 September —
     * correctly. What it did not do was DECLARE the change, though the instruction requires exactly
     * that, so the reviewer saw a date differing from the source with nothing to explain it.
     *
     * This is the note that made the check worth building: every reporting channel in this pipeline is
     * filled in by the same model whose work is being reported on, so a model that will not admit a
     * correction will not admit failing to report it either. This one needs no cooperation.
     *
     * REPORTED, NOT REFUSED. A derived date can be perfectly legitimate — "the next morning" becoming a
     * real date is the composer doing its job. The note states what the server can prove (this date is
     * not in the material) and leaves the judgement to the person.
     *
     * `{code, date, entry_slug}`.
     */
    public const DATE_NOT_IN_SOURCE = 'date_not_in_source';

    /**
     * A DATE THE MATERIAL NAMES THAT NO ENTRY WROTE DOWN — the same comparison, read the other way,
     * and the one that catches a dropped fact.
     *
     * This is the shape of the failure the owner actually hit: the alcohol incident and the wave of
     * criticism reached no entry at all, while an entry elsewhere referred to "the incident in
     * Thailand" as though it had been recorded. If the material dates something and no entry carries
     * that day, either the event went nowhere or it went in undated.
     *
     * Informational for the same reason as its sibling: a material can name a date in passing that
     * belongs in nobody's chronicle. Evidence, not a verdict.
     *
     * `{code, date}` — day and month, since the source has no year to give.
     */
    public const SOURCE_DATE_UNUSED = 'source_date_unused';

    /**
     * FACTS THE MATERIAL STATES THAT NO ENTRY CLAIMED — the reviewer's checklist, reduced to what is
     * missing.
     *
     * The claim is the model's own (`covers`), and it is deliberately never enforced. An answer is not
     * refused for missing a fact, because a model that writes "2026-08-15: wyjazd do Tajlandii" and
     * omits the incident inside that day has covered it TRUTHFULLY by any check code can make. The
     * omission is semantic; the only reader who can see it is a person, and this note is what puts the
     * sentence in front of them.
     *
     * `{code, facts: [{id, text, date}]}` — the date as the MATERIAL wrote it, so a reviewer compares
     * against the source rather than against an already-reconciled version of it.
     *
     * NOTHING EMITS THIS, and it is kept anyway — the one case where a dead name earns its keep. The
     * accusation was withdrawn (ADR-0050 D1: on the first real run the model returned no `covers`
     * claims at all, so the panel reported every fact as missing, including the ones the entries
     * plainly recorded). Four assertions in `KnowledgeFactChecklistTest` pin its ABSENCE, so the
     * constant is the name a re-added emitter would break against. Delete it and those pins become
     * string literals nobody can trace back to a decision.
     */
    public const FACTS_NOT_COVERED = 'facts_not_covered';

    /**
     * The reading returned more facts than `graph_extraction.max_facts` allows, so the list was cut.
     *
     * Said out loud, because a truncated checklist reports the tail of a long document as uncovered —
     * and a reviewer who is not told reads that as an omission by the writer rather than as a limit of
     * the reader.
     */
    public const FACTS_TRUNCATED = 'facts_truncated';

    /**
     * The reading produced NO fact list, on a run where the resolution pass fell short.
     *
     * Fail-soft is the rule — the composition proceeds exactly as it did before this layer existed —
     * but silence would be read as "nothing was missed", which is the opposite of what an absent
     * checklist means. The distinction matters only when something was ATTEMPTED and fell short; a
     * layer an operator switched off says so through `degraded` already.
     */
    public const FACTS_UNAVAILABLE = 'facts_unavailable';

    /**
     * An entry claimed a fact handle the frozen list does not contain.
     *
     * The claim is dropped and the ENTRY STANDS — a bad footnote is no reason to throw away good
     * writing. Reported rather than swallowed because it means one of two things a reviewer wants to
     * know: the model invented the handle, or it answered against a list that has since been
     * re-frozen — and in the second case the whole coverage panel is being read against the wrong set.
     *
     * `{code, handle}`.
     */
    public const UNKNOWN_FACT_HANDLE = 'unknown_fact_handle';

    /**
     * The reading pass named a subject the material is CONTINUOUSLY about, and the answer gave it no
     * entry.
     *
     * The measured failure this exists for: a material about a woman it only ever calls "influencerka"
     * produced seven entries — every city she visited, the man she met, the contest she ran — and none
     * for her. Everything downstream then broke in ways that read like separate defects: the cities
     * became the travellers, three relations were refused as place-to-place, the incident had no
     * subject to hang an edge on, and `[[influencerka]]` linked to nothing.
     *
     * SERVER-CHECKED, by comparing slugified titles and aliases — a comparison, not a question put to
     * the writer. It can be fooled by an entry that covers the subject under a different title, so it
     * is weaker than the date check; it is still stronger than the prose instruction it replaces.
     *
     * `{code, title, description}`.
     */
    public const PROTAGONIST_WITHOUT_ENTRY = 'protagonist_without_entry';

    /**
     * A chronicle line whose date is a PLACEHOLDER — `- 2023-08-??: Incydent z alkoholem`.
     *
     * Measured on real material. The composer knew the month and not the day, wrote what it knew, and
     * produced a line invisible to everything: not an ISO date, so the entry scanner skips it, so no
     * control compares it, so the base quietly gains a fact that no question about time will return.
     *
     * REPORTED, NOT REFUSED, and the choice matters. The line carries a REAL FACT whose only defect is
     * an unknown day; dropping it would destroy content to punish a formatting decision — the failure
     * this module closes everywhere else (`related_to` exists for precisely that reason). A reviewer
     * can supply the day, accept the line, or move the fact into prose where it stops pretending to be
     * dated.
     *
     * `{code, date, slug}` — the malformed date exactly as written.
     */
    public const DATE_INCOMPLETE = 'date_incomplete';

    /**
     * The material states no year, and an entry dated things to one other than the session's.
     *
     * THE MEASURED DEFECT: the same text produced 2026 on one run and 2023 on the next. A relation then
     * carries `valid_from` with an invented year, which is worse than carrying no date at all — it
     * looks like a fact. The server now supplies the session's own year as DATA (the information that
     * was missing, not another instruction), and this note says when the answer went elsewhere anyway.
     *
     * REPORTED, NOT REFUSED, for a reason worth keeping: A MATERIAL MAY BE ABOUT THE PAST. "Trzy lata
     * temu pojechała do Paryża", with no year in it, should be dated three years back — and an entry
     * doing that correctly would trip this note. Refusing would punish the right answer. So this is an
     * observation, and the judgement stays with the person who can read the sentence.
     *
     * `{code, year, expected, slug}`.
     */
    public const DATE_YEAR_UNSUPPORTED = 'date_year_unsupported';

    /**
     * A REWRITE proposal drops `[[wikilinks]]` the target currently carries (`context.links`).
     *
     * A note rather than a refusal, because a rewrite that removes a paragraph is SUPPOSED to remove
     * that paragraph's links, and nothing here can tell an intentional removal from a careless one.
     *
     * But it has to be said out loud, and the diff is not enough on its own. A diff shows that text
     * changed; it does not say "this deletes three outgoing edges somebody wrote on purpose". Losing a
     * link is qualitatively different from ordinary editing — it damages OTHER entries' view of the
     * graph, not just this one — and it is exactly the change a reviewer skims past, because the
     * sentence still reads perfectly well without it.
     */
    public const AMEND_LINKS_LOST = 'wikilinks_lost';

    /**
     * The ENTITY RESOLUTION ran with less than its full apparatus (`context.reason`).
     *
     * A note and never a failure reason: resolution makes the composer better and must never be why a
     * user cannot use it at all. But it cannot be silent either — a degraded pass reports names as "not
     * found" that are plainly in the base, and a reviewer who is not told will conclude the base is
     * missing entries rather than that the lookup was cut short.
     */
    public const RESOLUTION_DEGRADED = 'resolution_degraded';

    /**
     * Record one note. Duplicates by (code, subject) are collapsed: the same degradation reported
     * twice for one entry is noise, and a reviewer counting warnings would read it as two problems.
     *
     * @param  array<string, mixed>  $context
     */
    public function add(string $code, array $context = []): void
    {
        $note = ['code' => $code] + $context;

        foreach ($this->notes as $existing) {
            if ($existing === $note) {
                return;
            }
        }

        $this->notes[] = $note;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->notes;
    }
}
