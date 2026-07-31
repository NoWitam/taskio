# ADR-0042 — character visual identity: a bot's "Wygląd" module + delegated-session image consistency

**Date:** 2026-07-30 (created)
**Status:** Accepted
**Module:** `App\Modules\Bot` (`Models\Bot` — the `visual` column, first real logic; `Services\
BotVisualIdentityService`; `Http\Controllers\BotVisualController`; `Jobs\GenerateBotVisualJob`; `Enums\
BotVisualMode`; `DTOs\BotVisualGenerationDTO`; `Http\Requests\GenerateBotVisualRequest`,
`ApproveBotVisualRequest`, `DestroyBotVisualCandidateRequest`; `Rules\BotVisualFile`), `App\Modules\Disk`
(`Services\ImageAiService` — the null-image/generate mode + the resumable `produce()` split;
`Services\OpenAiImageEditClient` — `input_fidelity` actually sent; `Enums\DiskAiEditStatus` —
`SafetyRejected`), `App\Modules\Generator` (`Support\SessionVisualIdentity`, `Services\
SessionIdentityImageStore`, `Services\SessionDelegationService` — the frozen-look extension,
`Services\GenerationSessionExecutor` — the render-time consumption, `Services\ImageChainExecutor` —
reserve-generate/bill-edit, `Services\ImagePlanValidator` — the `character` field, `Models\GenerationSession`
— `bot_delegation.visual`, `has_character_image`)
**Relates to:** ADR-0036 (the session delegation overlay this ADR extends from voice-only to voice+look —
same snapshot-not-live posture, same one-way `Bot → Generator` edge, same reversible-undo contract), ADR-0038
(the creative-direction layer whose "Alternatives considered" named — and explicitly deferred — the
general image-to-image consistency gap this ADR closes for a NARROWER, DIFFERENT case; see "Alternatives
considered" below for why the two are not the same mechanism), ADR-0020 (the Disk masked-edit machinery and
`OpenAiImageEditClient` this feature rides rather than forks), ADR-0041 (the distributed storyboard frame
engine this feature's measured per-shot cost made necessary — built alongside it, not before it)

---

## Context

A session delegated to a bot (ADR-0036) already renders every text part in that bot's voice. The product gap
this ADR closes is the picture half of the same idea: a bot with a face should be able to draw itself into
the images of the content it authors, not merely write about itself. ADR-0038 had already named the general
version of this problem — exact character-face consistency across a storyboard's independently-generated
frames — and explicitly deferred it as a "v2 path" (image-to-image chaining), rejected for v1 because it is
serial (no per-shot parallelism), tends to over-preserve composition, and needs its own budget model.

**A pre-implementation spike (GO/NO-GO) tested the open questions before any of this was built:**

- **Q-A — does editing a portrait with a SCENE prompt over-preserve the original pose/background** (the
  exact failure mode ADR-0038 worried about for general image-to-image chaining)? Measured result: **no** —
  editing a reference photo with `images/edits` and a scene prompt produced "this person, in the new scene,"
  not "the original photo with small changes." This is the finding that made a reference-edit approach viable
  at all for a per-shot, independently-rendered frame (as opposed to ADR-0038's rejected serial chain).
- **Q-B — timing.** Median `ai_edit` latency (a reference-anchored edit) measured at **57.8s**, against
  ~33s for a plain `ai_generate`. Eight shots of edits alone would not fit inside `RunGenerationSessionJob`'s
  inviolate 300s window — this measurement is the direct trigger for ADR-0041's distributed frame engine,
  built alongside this feature rather than as a later follow-up, because a character-capable storyboard could
  not ship without it.
- **Q-C — does the provider's edit endpoint accept MULTIPLE reference images (`image[]`) in one call?**
  Confirmed yes. Not used by v1 (which freezes exactly one character), but it is the reason the frozen-bytes
  store is keyed PER CHARACTER rather than per session (D7 below) — a future "two characters in one frame"
  does not need a reshape, only a second key.
- **A real defect found during the spike, fixed as part of this work, affecting EVERY caller of the shared
  client:** `OpenAiImageEditClient`'s own comment already claimed `input_fidelity` was sent to the provider on
  every edit — the payload array never actually included it. This silently degraded fidelity to the source
  image on every Disk mask edit ever made, not only this feature's reference edits. Fixed by adding it to the
  `array_filter(...)` payload (still env-overridable via `AI_DISK_IMAGE_INPUT_FIDELITY`, empty value omits it
  from the request, matching every other tuning knob on that client).
- **A moderation finding that shaped the identity's field shape, not just its prompt wording:** the provider's
  OUTPUT-side moderation is wardrobe-sensitive for an otherwise-identical character — a swimsuit is refused as
  `[sexual]`, a dress passes. This is why `wardrobe` ships as its own first-class field (D13) rather than a
  clause inside free-form `aesthetic` prose.

The owner made two product decisions ahead of implementation: the identity is **image-based** (a reference
photo), never text-only — a purely-described character does not reliably draw as the same person twice, which
is the whole gap ADR-0038 left open; and creation must work from a description ALONE too (there may be no
photo to start from), not only from an uploaded reference.

## Decisions

**D1 — the identity is IMAGE-based, not text-only.** The owner explicitly rejected a text-only "described
appearance" persona for this feature (a separate discussion from the block-level voice/tone work of
ADR-0040, which stays text-only by design). An approved reference PHOTO, edited toward a scene, reliably
reproduces the same person (Q-A above); a written description alone does not — this is precisely the
limitation ADR-0038 named and declined to solve in v1.

**D2 — a new Bot module, "Wygląd" — the FIRST real logic behind an existing placeholder column.**
`bots.visual` shipped in the original `create_bots_table` migration as an explicit placeholder ("no logic
yet"), alongside `audio` (still a placeholder — untouched by this ADR). This ADR gives it real read/write
semantics without a schema change: still one nullable json column, now carrying `{enabled, descriptor,
aesthetic, wardrobe, prohibitions, reference_file_id, candidates, canonical_file_id, prompt}`. Like the
existing knowledge/task-execution modules, it is an EXPLICITLY toggled optional module — `enabled = false`
does not erase or block editing the identity, it only stops it being DRAWN from at generation time (D15).

**D3 — two creation paths, one pipeline, riding the Disk module's EXISTING async image machinery.**
REFERENCE mode edits a supplied/picked image toward the written identity; DESCRIPTION mode generates outright
from nothing but the identity text. Both route through `Disk\Services\ImageAiService::prepare()`/`process()`
— the SAME `DiskAiEdit` status row, the same per-workspace daily cap, the same $-cost-meter gate-before-spend,
the same poll (`GET /disk/ai/image/{id}`) and broadcast the Disk preview editor already uses — rather than a
second, near-identical async image pipeline. `ImageAiService` gained the ability to run WITHOUT an input image
(a null image now means text→image generation, mode derived from the persisted input path, not a new column)
specifically so this second path could exist without forking the service. This is the ONE new cross-module
edge in the app added by this ADR — **`Bot → Disk`, one-way** (Disk never names Bot) — pinned by tests in
BOTH directions (`test_the_visual_identity_seam_depends_on_disk`, `test_no_disk_file_ever_names_bot`).

**D4 — the produced bytes become a FILE OWNED BY THE BOT, deliberately invisible outside it.** A generated
candidate is a real `Disk\Models\File` row (so it reuses every existing storage/serve/delete mechanism), but
`fileable_type = 'bot'` — a RESOURCE file, not a disk-native one. The Disk browser lists only disk-native
files (`File::scopeDiskNative`) and the "Zasoby" resource tree only walks REGISTERED resource types, so a
bot's iteration strip never pollutes either surface. Materializing the result needed its OWN job
(`GenerateBotVisualJob`, not the Disk module's `EditDiskImageJob`) purely because of this: it has to file the
finished bytes as a bot-owned candidate, which is Bot-module logic Disk must never contain.

**D5 — a bounded candidate strip (6), oldest-unapproved-evicted.** `BotVisualIdentityService::
MAX_CANDIDATES = 6` — a strip to CHOOSE from, not an unbounded archive of provider output. A seventh
generation evicts the oldest candidate that is NOT the approved likeness (and deletes its bytes); the approved
one is never evicted by this mechanism. The FE warns before the click that crosses this line, not after the
deletion.

**D6 — session delegation FREEZES the look exactly as it already freezes the voice, extended (not
reinvented).** `BotSessionDelegationController::visualSnapshot()` reads the toggle and the identity ONCE, at
delegation time, and the SAME `applyDelegation()` save that stamps the voice overlay (ADR-0036) additionally
stamps `bot_delegation.visual = {enabled, descriptor, aesthetic, wardrobe, prohibitions, has_character_image,
source_file_id, snapshot_at}` — `enabled` itself is part of the freeze, so flipping the module off after
delegating cannot retroactively un-draw an already-half-rendered session. `source_file_id` is PROVENANCE
only, never re-read at render time. Both halves of the overlay are written/cleared TOGETHER with the voice —
there is no state where one is frozen and the other is not.

**D7 — the approved likeness's raw BYTES are COPIED, not referenced, into a per-character store.**
`SessionIdentityImageStore` writes `generation-identity/<workspaceId>/<sessionId>/<characterKey>.png` at
delegation time — a physical copy, not a pointer to the bot's file. Keyed by CHARACTER (the delegated
author's bot id) rather than by session, for the same reason Q-C's finding matters: a frame that has to show
TWO characters is the obvious next ask, and a per-session key would need reshaping to allow it; v1 freezes
exactly one. The store is deliberately a SEPARATE root from the session's produced images
(`generator-sessions/...`), which a full `generate` claim wipes wholesale — sharing a root would mean a
re-run destroys its own character reference and then draws the rest of the run without it.

**D8 — reserve the GENERATE ledger, bill the EDIT meter, for a character frame's base.** When a shot is
flagged as showing the character, its base image is produced by EDITING the frozen likeness
(`ImageAiService::edit()`) instead of a plain `ImageGenerateService::generate()` call — but
`ImageChainExecutor::generateBase()` still reserves against the per-run `ai_generate` ledger (it is still
conceptually the shot's BASE), while the shared cost METER records what actually happened, an
`ai_image_edit` call. Charging the EDIT ledger instead would let a storyboard's per-shot bases compete with
the SAME budget an authored `ai_edit` FILTER needs on every shot (the 8/8/8 lock-step ADR-0038's config
documents), silently losing the author's look on the tail of a long storyboard — the exact failure mode that
coupling exists to prevent. Ledger and meter answer different questions on purpose: the ledger is fan-out
control, the meter is accounting.

**D9 — WHO decides a frame shows the character differs by part kind, and neither is authored per storyboard
shot.** A `storyboard`'s shot list already decides who is on screen (the model returns a per-shot
`features_character` boolean, defaulting FALSE on absence/any non-boolean value — the cheap mistake of the
two, since inserting a person into a product shot and paying a reference edit for it is the expensive one). A
single authored `image_plan` has no shot list to ask, so ITS author gets an explicit switch instead —
`content.<key>.character: 'auto' | 'never'` (`ImagePlanValidator::CHARACTER_MODES`), default (and the meaning
of an ABSENT key) `auto`: a delegated session's image shows its creator by default, because that is what
"generate my post image" means once a whole session has been handed to a persona. `never` is the escape hatch
for the product shot / logo / chart case. There is deliberately no `always` — `auto` already means "yes, when
the session has one," and an `always` on a session with no character would be an unkeepable promise.

**D10 — subject-line precedence: the character REPLACES the derived subject, and says so in the prompt
text.** When a frame draws the character, the run's creative-direction subject line (ADR-0038,
`CreativeDirection::forImage()`) is not appended to but SUBSTITUTED by the character's own description
(`SessionVisualIdentity::subjectDescription()`) — a picture has exactly one recurring subject, and the
human-configured character (what was actually approved) outranks a model's inferred guess (what the
direction agent GUESSED from the recipe). The substitution carries an explicit precedence sentence
(`CreativeDirection::imageSubjectAnchor()`) because the composed base prompt is a stack of notes — the
continuity clause, the direction anchor, the authored style, the shot's own `visual` text — that were each
written without knowing a specific character would be drawn, and can legitimately disagree about who is in
frame; stating which one wins in the prompt itself is more reliable than trying to rewrite the others.

**D11 — aesthetic + prohibitions ride EVERY image of the session, flagged or not.**
`SessionVisualIdentity::guardrails()` (style + "never show" list) is composed into every `ai_generate` base
of a character-carrying session, independent of whether that particular frame draws the person. A set of
images made for one creator has to read as one set, and a prohibition like "no alcohol" is a constraint on
the PICTURE, not on who happens to be standing in it.

**D12 — prompts stay UNFENCED prose; the fence-scrub authority is shared, not restated.** Unlike the
direction/text/shot-list projections (fenced `--- BEGIN/END CREATIVE DIRECTION ---` DATA blocks), the
identity's image-facing projections (`subjectDescription()`, `guardrails()`) are plain prose — a text→image
prompt has no system channel, so a labeled fence would simply be DRAWN into the picture. The identity's
free-text fields are still run through `CreativeDirection::scrubFenceMarkers()` (made `public` for exactly
this reuse) so a human-authored descriptor/wardrobe/prohibition can never forge the OTHER fenced block riding
the same composed base string.

**D13 — wardrobe is a first-class field, not prose inside `aesthetic`.** The spike's own finding: the
provider's OUTPUT-side moderation refuses the identical character in a swimsuit (`[sexual]`) and accepts it
in a dress. `wardrobe` (max 500 chars) is therefore its own line in both the identity-generation prompt
(`BotVisualIdentityService::composePrompt()`) and every rendered frame's guardrails — the ONE steerable lever
a user actually has against a refusal, so it needed to be a field they can find and edit, not a clause buried
in a longer paragraph.

**D14 — moderation is a STORED-ONLY status, additive `error_code` on the wire.** `DiskAiEditStatus::
SafetyRejected` is a new terminal case, split from `Failed` because it is deterministic (a retry buys the
same refusal) and because it names a DIFFERENT fix (change the wardrobe/descriptor, not the plan) — but
`wireStatus()` still reports it as plain `failed`, so the `queued|processing|done|failed` vocabulary every
poll/broadcast consumer already branches on is never widened; the distinction rides `error_code:
'safety_rejected'` on `DiskAiEditResource` instead, additive and absent otherwise. The Generator's own image
chain mirrors this with its own domain code (`image_safety`, `ImageSafetyRejected::errorCode()`) so a
character-flagged frame's moderation refusal is equally actionable from the session side, without leaking
Disk's vocabulary across the module boundary.

**D15 — the kill switch (`generator.visual_identity.enabled`) gates CONSUMPTION only, never the freeze.**
Turning the switch off must not permanently strand an already-delegated session character-less once it is
switched back on — freezing a likeness at delegation time is cheap, reversible, and (while the switch is off)
simply unused; gating the freeze too would trade a few unused bytes (the lifecycle purge reclaims them anyway
— see below) for a far worse failure mode. `false` means the render layer reads nothing from the overlay and
injects nothing — every composed prompt is byte-identical to a run with no character, pinned by a test.

**D16 — everything defaults OFF/FALSE, so a run without this feature is byte-identical to one from before it
existed.** `features_character` defaults false on any absent/ambiguous model output; `character` defaults
`auto` but only MATTERS when the session actually has a frozen likeness (`visualIdentityFor()`'s three gates
— kill switch, overlay `enabled`, a frozen IMAGE — must all hold); a non-delegated run, a delegation with no
approved likeness, and the layer disabled are all pinned, by test, to compose the exact prompts they composed
before this ADR.

**D17 — a long-trashed ARCHIVED session gives up its frozen likeness, the one narrow exception to "archive
freezes everything."** The lifecycle reaper's blanket archive exemption (every other reaper step skips an
archived row entirely) has exactly one carve-out: a session archived, then manually trashed, then past the
purge window has no restore route back through the API — its row and its produced CONTENT stay forever (that
is what archive means), but the copy of a real person's face it is still holding is only ever readable by a
run that row can no longer have. `GenerationSessionLifecycleService::purgeArchivedIdentityImages()` reclaims
just that one store, for just that narrow row set, leaving everything else about the archive freeze untouched.

## Alternatives considered

- **A LIVE reference** (store the bot's file id on the session and re-read the bot's CURRENT approved
  likeness at render time), instead of copying bytes at delegation. Rejected for the identical reason the
  VOICE is already snapshotted, not re-read live (ADR-0036): the human may re-approve a different likeness, or
  delete the file, between delegating and generating, and a run that silently starts drawing a different
  person — or fails mid-storyboard because the reference vanished — is a worse failure mode than one that keeps
  drawing exactly who it was told to.
- **A Bot-module-owned image store**, instead of the Generator's own `SessionIdentityImageStore`. Not viable
  inside the one-way `Bot → Generator` edge: Generator must never import Bot, and the frozen bytes have to
  live where the SESSION that reads them at render time lives — the store's home follows the reader, not the
  source.
- **Unconditional subject substitution whenever a session is delegated with a likeness** (no per-shot/per-part
  opt-out). Rejected: a storyboard's product-shot/logo/chart beats, or a plain post whose image is not a
  portrait at all, must be able to say "not this one" — the `character: 'never'` switch and the shot list's
  own per-shot `features_character` flag both exist precisely so a character-capable session is not FORCED to
  put a face in every frame.
- **General image-to-image CHAINING** (ADR-0038's named, still-unbuilt "v2 path": feed frame N's produced
  bytes as frame N+1's edit base, for ANY storyboard, bot or not). This ADR does **not** build that. It solves
  a narrower, mechanically DIFFERENT problem — one FROZEN reference, independently edited-from by every
  flagged frame in PARALLEL, for the specific case of a session delegated to a bot with an approved likeness.
  The two do not obsolete each other: a non-delegated storyboard (or a delegated one whose bot has no
  approved likeness) still gets only prompt-anchored world consistency, exactly as ADR-0038 left it, and the
  general per-shot-chaining gap ADR-0038 named remains open.

## Consequences

- **Positive.** A delegated bot's content can now look like someone specific across a whole session's images,
  closing the half of ADR-0038's "honest limitation" that a written description alone could never close —
  without touching per-shot parallelism, because every flagged frame still renders in its own job (ADR-0041),
  independently, from the SAME frozen reference, rather than serially from each other.
- **Positive — a real, previously-shipped defect fixed, with a blast radius wider than this feature.** The
  spike caught `input_fidelity` being documented as sent to the provider on every Disk image edit while the
  actual request payload never carried it. The fix benefits every existing Disk mask edit, not only this
  feature's reference edits.
- **Positive — hardening findings from the adversarial review, both fixed before ship.** (1) A throwing
  `$onResult` materialization hook (the seam that files a candidate as the bot's own file) could previously
  have sent a queue retry back through the ALREADY-PAID provider call, billing it a second time —
  `ImageAiService::process()` was split so the one PAID step (`produce()`) persists `result_image` on the row
  the instant it returns, making a retry RESUME from there instead of re-spending; this benefits every caller
  of the shared machinery, not only the Bot module. (2) An identity that is a likeness with NO written text at
  all (every descriptor/wardrobe/aesthetic field blank, the picture itself the whole description) was
  silently losing its reference bytes on a plain authored `image_plan`/scene image, because the code only
  handed the frozen reference over when it also had a text anchor to prepend — fixed by resolving the
  reference independently of whether there was any prose to compose (`directedImagePlan()`'s early-exit note).
- **Reused, not duplicated.** Every dollar of implementation cost this feature avoided came from riding
  Disk's existing async image machinery (status row, daily cap, $ gate, poll, broadcast, reaper) rather than
  building a second copy of it — the price is a genuinely NEW cross-module edge (`Bot → Disk`), accepted and
  pinned by tests in both directions rather than hidden.
- **Trade-off (accepted) — a character frame is materially slower and more expensive than a plain one.** A
  reference edit measured at a 57.8s median versus ~33s for a plain generate; this is accommodated (not
  eliminated) by the frame job's own 240s budget (ADR-0041) and is simply the real cost of the capability.
- **Trade-off (accepted) — v1 freezes exactly ONE character.** The store's per-character keying is deliberately
  ready for a second (Q-C's finding), but nothing today composes two references into one provider call; a
  frame naming two on-screen characters is not supported.
- **Honest limitation (accepted, not hidden).** This ADR does not solve ADR-0038's GENERAL storyboard
  character-consistency gap — a non-delegated run, or a delegated one with no approved likeness, still gets
  only prompt-anchored world consistency and no guaranteed same-face-twice. That remains the deferred
  image-to-image "v2 path," unbuilt, and mechanically unrelated to what this ADR ships.

See `docs/backend/generator-sessions-api.md` → "Frozen character visual identity (the bot-look phase)" and
`docs/backend/bots-api.md` → "Visual identity module ('Wygląd')" for the shipped wire/config contract, and
`docs/decisions/ADR-0041-storyboard-frame-engine.md` for the distributed rendering engine this feature's
measured per-shot cost required.
