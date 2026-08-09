# ADR-0050 — Content quality, closed out with three measured runs

**Date:** 2026-08-08
**Status:** Accepted
**Module:** `App\Modules\Knowledge` (`Agents\KnowledgeDraftAgent`, `Services\KnowledgeDraftService`,
`Services\KnowledgeMentionExtractor`, `DTOs\{ExtractedFact,ExtractedProtagonist,ExtractedMention}`,
`Support\{SourceDateScanner,DraftRunNotes}`, `Enums\KnowledgeRelationType`),
`tests/Feature/KnowledgeFactChecklistTest.php`
**Relates to:** ADR-0046 (the AI composer this batch hardens), ADR-0047 (typed relations — the matrix
this batch's "reversed `located_in`" limitation is about), ADR-0048 (the subject paradigm — the
`interacted_with` verb this batch's measurements validate), ADR-0049 (authorship withdrawn — everything
this ADR measures is now the ONLY way content enters a base)

---

## Context

This closes the content-quality thread ADR-0048 opened. Three separate runs against the SAME real
material — an owner's own chronicle of a trip, the material every "measured on real material" comment in
this batch's code cites — were used to find out what actually works and what does not, on evidence rather
than opinion. **The numbers below (rejection counts, relation counts, the cost delta) are the product
owner's own measurements from three manual comparison runs and are reported here as such; they are not
re-derivable from static code inspection.** What IS verified in code, and is the substance of this ADR, is
every MECHANISM the measurements are about: it exists, behaves as described, and is covered by tests where
a test can meaningfully pin it (an "0 rejections on this run" figure cannot be pinned by a unit test —
what can be pinned, and is, is that the mechanism producing that outcome runs deterministically).

## The guiding pattern

**Mechanisms that do not require the model's cooperation worked. Mechanisms that ask the model for
something failed.** This is not editorial framing — it is stated, near-verbatim, inside the code itself,
independently, in at least three places:

- `SourceDateScanner`'s own docblock: *"Three rounds of asking more firmly produced less each time. The
  reason is structural: EVERY reporting channel in this pipeline is filled in by the same model whose
  work is being reported on, so a model that will not admit a correction will also not admit failing to
  report it. A check that needs the cooperation of the thing being checked is a different class of thing
  from one that does not. This needs none."*
- `ExtractedProtagonist`'s docblock: *"The prompt did carry a sentence telling the writer to give the
  protagonist an entry — and a sentence is what this module has now watched fail three times."*
- `KnowledgeDraftAgent`'s "KNOWN LIMITATIONS" section: *"every remedy we could find was either another
  paragraph of instruction — a pattern that has decayed three times running — or a server-side guess
  dressed up as a check."*

Every fix this batch actually keeps follows the same shape: read something the model does not have to
agree to, and compare.

## Decisions

**D1 — `covers` (the model's own self-assessed fact-coverage claim) is withdrawn from its alarm role,
kept only as plumbing.** The design that shipped with `ExtractedFact` asked the composer to declare which
extracted facts each entry absorbed and reported the rest as `facts_not_covered`. On the FIRST real run
the model returned zero claims for nine facts — 0/9 — so the panel reported every fact missing, including
several the entries plainly recorded. `KnowledgeDraftService::reportCoverage()`'s own comment: *"A false
alarm at 9/9 is worse than no alarm: it is an interface asserting something it cannot know, and the thing
it teaches is to stop reading the panel."* The alternative — making `covers` mandatory and refusing an
answer without it — was rejected too: *"it punishes the answer for a bookkeeping field rather than for
its content, and this module has spent three rounds learning that asking a model more firmly is not a
mechanism."* The field, the column, and the laundering all stay (`covers` → `covered_by` on publish,
tested) so a future, more cooperative model can have the claim reinstated WITH evidence — what is gone is
the server treating an empty field as an accusation. `KnowledgeFactChecklistTest::
test_the_server_no_longer_asserts_that_a_fact_was_missed` pins the withdrawal directly.

**D2 — a "reading pass" extracts facts and protagonists by asking what the material CONTAINS, never
asking the writer to grade itself.** `KnowledgeMentionExtractor` (channel `ai_knowledge_resolve`, its own
metered call, run BEFORE composition) reads the raw material once and returns two lists: `ExtractedFact[]`
(one thing the material says happened, `{id, text, date, subjects}`, `date` kept as the source's own
wording so a reviewer sees a difference rather than an already-reconciled one) and `ExtractedProtagonist[]`
(who the material is about, even when it never names them — `description` from the source's own wording,
`title` the proper name where one exists). Both are FROZEN on the session and shown to the reviewer as
evidence, never enforced as a gate — `ExtractedFact`'s own reasoning: *"a model that writes '2026-08-15:
wyjazd do Tajlandii' and leaves out the incident inside that day has covered the fact TRUTHFULLY by any
check code can make... The omission is semantic; the only reader who can see it is a person."* The
distinction the coordinator's framing captures exactly: this asks the READER (a fresh pass over the
material) what it sees, not the WRITER (the composer) to grade its own homework — which is why it works
where `covers` did not.

**D3 — a protagonist with no entry is now a SERVER-CHECKED refusal condition (`protagonist_without_entry`),
not a prompt sentence.** `KnowledgeDraftService::reportMissingProtagonists()` compares the reading pass's
protagonist list against every draft's slugified title AND aliases (`WikilinkParser::normalize`) — a
string comparison, not a question put to the model. Weaker than the date check (an entry that covers the
subject under a different title still evades it), and still a real guarantee where there was none. The
defect this closes, verbatim from `ExtractedProtagonist`: a material about a woman the source only ever
calls "influencerka" produced seven entries — Paryż, Tokio, Warszawa, Wieża Eiffla, Tajlandia, Łukasz
Barszcz, a contest — and none for her, and everything downstream broke in ways that read like separate
defects (cities became travellers — `Paryż visited Tajlandia`, refused by the type matrix three times;
the incident had no subject to hang a relation on; `[[influencerka]]` linked to nothing).

**D4 — dates get a deterministic scanner (`SourceDateScanner`), comparing the source against the drafts
directly, with four report codes on `DraftRunNotes` covering both directions and two malformations:**

| Code | Catches |
|---|---|
| `date_not_in_source` | An entry dates something to a day the source never mentions — usually a legitimate correction the model made but did not declare. |
| `source_date_unused` | The source names a day no entry recorded — the shape of the dropped-fact failure the owner actually hit (the alcohol incident and the wave of criticism reached no entry). |
| `date_incomplete` | A chronicle line whose date is not one: a PLACEHOLDER where a number belongs (`- 2023-08-??: …`, `- 2023-08-XX: …`) or a date that STOPS EARLY (`- 2026-08: …`, `- sierpień 2026: …`). Originally `?` only; the other spellings were added by the follow-up this ADR left open (see "Known limitations"). |
| `date_year_unsupported` | An entry's year does not match the session's own year (see D6). |

All four are REPORTED, never refused — a derived date can be entirely correct (see D6), and a placeholder
carries a real fact whose only defect is an unknown day; refusing either would destroy content to punish
a formatting decision, the exact failure this module closes everywhere else (`related_to`, ADR-0048,
exists for the identical reason). Matching is (day, month) only, not the full date — the source's own
prose rarely carries a year — with the accepted cost stated in the class docblock: two different years
sharing a day are indistinguishable, a real blind spot on a long-running base spanning several years.

**D5 — the slug is now DERIVED FROM THE TITLE; the model's own `slug` field is read only as a fallback
when the title itself slugifies to nothing.** `KnowledgeDraftService::launder()`:
`$slug = Str::slug($title) ?: Str::slug((string) ($entry['slug'] ?? ''))`. The defect this closes,
verbatim from the code: *"It used to be taken as given, and on the owner's material it produced a base
addressed in the genitive: titles 'Tajlandia' and 'Warszawa' with slugs `tajlandii` and `warszawie`,
because Polish inflects and the model wrote the form its sentence needed. Every link to those entries
then had to be written in the same inflected form to resolve"* — the `[[nowego-tokio]]` class of defect,
at its source rather than its symptom. Applied SILENTLY, not as a refusal, because there is nothing a
model can tell the server about an address its own title does not already determine: *"the title is the
fact; the slug is a function of it."* `decollide()` and `enforceSeed()` are unaffected — both still
operate on the derived value.

**D6 — supplying a missing reference year is DATA, not another instruction, and the two are different
acts.** When the source material carries no year at all (`SourceDateScanner::hasYear()` false), the
server now injects the session's own creation year as the reference an entry's dated lines are compared
against, feeding `date_year_unsupported` (D4) rather than asking the model, once more, to "please use the
right year." Stated exactly in `DraftRunNotes::DATE_YEAR_UNSUPPORTED`'s own docblock: *"The server now
supplies the session's own year as DATA (the information that was missing, not another instruction), and
this note says when the answer went elsewhere anyway."* The measured defect this closes: the identical
source text produced `2026` on one run and `2023` on the next — an invented year inside a `valid_from` is
worse than no date at all, because it looks like a fact. The distinction matters because the fix is
explicitly NOT "ask the model to judge better" — that request already failed three times per the guiding
pattern above — it is "give the model the one fact it was missing and let it reason from there," which
is a request for a JUDGEMENT given a FACT, not a request for a better judgement in the dark.
**Deliberately still a report, not a refusal**: a material may legitimately be ABOUT the past ("trzy lata
temu pojechała do Paryża", no year given, correctly dated three years back) — refusing on a year mismatch
would punish exactly that right answer, so the check states what it can prove and leaves the judgement to
the reviewer.

**D7 — measurement validates, rather than adds, the `interacted_with` verb ADR-0048 introduced.** Before
that verb, the only edges available between two people were `knows`/`opposes`, both symmetric — an
apology, a criticism, a public thanks all collapsed into the same undirected acquaintance. The owner's
material (an apology over sushi in Tokyo) is exactly the shape `interacted_with{act, sentiment}` exists
for, and per the guiding pattern this is a mechanism that needed no model cooperation beyond picking a
verb from a fixed vocabulary the model is already shown — which is why, unlike `covers` or the drama-in-
the-body attempts below, it held up across the measured runs.

## Measurement log (owner-reported; the mechanisms are code-verified above)

- **Run 2 — before the structural fixes.** No entry for the protagonist → cascade: 3 relation rejections
  from the type matrix (place-to-place edges standing in for what should have been person-to-place),
  cities recorded as the travellers, the central incident left with no subject to attach a relation to.
- **Run 3 — after D3 (protagonist), D5 (slug), and the date-scanner work (D4/D6) landed.** The protagonist
  gets an entry; **0** relation rejections (down from the cascade above); **8** relations recorded; the
  inflected-slug defect (D5) no longer appears; date-check noise fell from 3-out-of-4 flagged lines to
  0-out-of-1; cost rose **+8.9%** over the prior run (the reading pass is one additional metered call —
  `ai_knowledge_resolve` — per session/context-expansion, priced separately from composition; see
  `config/ai.php`).

## Field test: RAG beats full injection, and both beat nothing (owner-reported)

A/B across three separate bot conversations on the same question, reported by the product owner: without
a bound knowledge base, the bot's answer stayed on-brand, was grammatically correct, and carried NO
information — and, worse, asserted things it had no way to know ("już wszystko w porządku"), true only by
accident. **"The difference is not about eloquence — it is about whether the sentence can be checked."**
Binding the base and answering through `rag` mode (one embedding call, the nearest passages, ADR-0044/0045)
produced a better answer than compiling the WHOLE base inline, and cost less doing it — consistent with
the module's own `auto` mode default (try free `inline` first; only pay for `rag` once the base has
outgrown the budget), which this field test is independent evidence for rather than a mechanism this ADR
changes.

## Known limitations

Five, all listed in `KnowledgeDraftAgent`'s own "KNOWN LIMITATIONS — MEASURED, AND DELIBERATELY NOT
PATCHED" docblock, kept exactly there rather than duplicated into a comment nobody reads twice — recorded
here because closing this ADR means the reasoning for NOT patching them has to be legible on its own:

- **Reversed `located_in`.** The composer wrote "Paryż located_in Wieża Eiffla" (backwards). The type
  matrix cannot catch it — place→place is legal in both directions, and nothing distinguishes "contains"
  from "is contained by" without knowing which thing contains the other. The direction table inside the
  agent's own instructions is the only defense that exists, and per the guiding pattern that defense asks
  for the model's cooperation.
- **Euphemism.** "Zarzygał innemu turyście buty" became "incydent z alkoholem" — the fact survives, its
  force does not. No check can distinguish a softened sentence from a genuinely concise one; this is the
  class of failure a "keep the details vivid" prompt instruction was tried against and, consistent with
  the guiding pattern, did not reliably hold.
- **Inferred detail.** An apology the material places specifically "over sushi in Tokyo" was written as
  happening "w Japonii" — true, less precise, and nothing a comparison can flag (there is no wrong fact
  to compare against, only a lost one).
- **An event with no edge.** The alcohol incident reached the prose of two entries and no relation.
  Whether a fact deserves an edge is a judgement; a server insisting on one would be inventing relations
  nobody stated.
- **A subject dropped between runs.** One run gave a contest its own entry and a `won` relation; the next
  did not. Neither answer is individually wrong, which is exactly why it cannot be checked mechanically.

Two further, narrower limitations on the mechanisms THIS ADR adds:

- ~~**`SourceDateScanner`'s placeholder detection is hardcoded to the literal `?` character**~~
  **CLOSED by the follow-up below.** `ENTRY_LINE_INCOMPLETE` now reads `[\d?xX]{2}`, and two sibling
  patterns catch the truncated forms the same invisibility hides in: `ENTRY_LINE_MONTH_ONLY`
  (`- 2026-08: …`, month range-checked `01`–`12`) and `ENTRY_LINE_MONTH_WORD` (`- sierpień 2026: …`,
  the word checked against the module's own month table). **What is still NOT read, deliberately:** a
  dash placeholder (inside an ISO date the dash is the separator, so `2026-08---` would parse by luck),
  a word in the day slot ("nieznany" — that is prose, and the "a date was clearly attempted" anchor is
  gone), and a BARE YEAR (`- 2026: …` — a four-digit number before a colon is as likely to be a count
  or a label, and a false alarm costs this control more than a miss). Pinned by
  `KnowledgeDateDriftTest::test_an_x_placeholder_is_reported`,
  `::test_a_date_that_stops_at_the_month_is_reported`, `::test_a_month_named_beside_a_year_is_reported`,
  and — as the negatives that keep it honest — `::test_a_year_span_is_not_a_truncated_date`,
  `::test_a_word_that_is_not_a_month_is_not_a_date`,
  `::test_an_undated_bullet_is_not_a_malformed_date`.
- **The (day, month)-only date comparison (D4) cannot distinguish two different years sharing a calendar
  day** — stated as an accepted, explicit blind spot in the class's own docblock, real on a long-running
  base spanning multiple years, not a hypothetical.

## Alternatives considered

- **Make `covers` mandatory and refuse an answer that omits it.** Rejected (D1) — punishes an answer for
  a bookkeeping field rather than its content, and the module has already spent three rounds learning
  that asking more firmly is not a mechanism.
- **Server-side euphemism/tone detection.** Not attempted — there is no reliable signal distinguishing a
  softened sentence from a concise one by shape alone (the same class of rejection ADR-0048 gave for
  regex-detecting episode-shaped titles).
- **A hardcoded direction table enforced in code for symmetric-seeming pairs like place→place.** Not
  built — the type matrix already carries a direction table inside the PROMPT (per the agent's
  instructions); moving it server-side would require the server to know, for every pair of entry types,
  which one can contain the other, which is exactly the kind of world-knowledge judgement this module
  otherwise leaves to the model and a human reviewer.
- **Extend `SourceDateScanner` to catch every placeholder spelling instead of just `?`.** Left for a
  follow-up rather than bundled here — the fix is a regex change with no design question behind it, and
  bundling it would have obscured which parts of this batch were the measured, load-bearing decisions.
  **Done since** (see "Known limitations"), and the follow-up found the design question after all: not
  *which characters* spell a placeholder, but *where the anchor for "a date was attempted" stops* —
  which is why `- 2026-27:` and `- 2026:` are still, deliberately, left alone.
