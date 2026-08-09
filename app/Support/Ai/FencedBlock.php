<?php

namespace App\Support\Ai;

/**
 * A LABELLED, UNFORGEABLE DATA FENCE for prompt composition.
 *
 * Every prompt this product builds is a stack of trusted instructions with untrusted content composed into
 * it — a model-written creative direction, a knowledge entry somebody typed, a form answer. The defence is
 * always the same shape: wrap the untrusted span in markers, say plainly in the label that the span is DATA,
 * and make it IMPOSSIBLE for the span itself to emit either marker. It was implemented once, carefully, in
 * {@see \App\Modules\Generator\Support\CreativeDirection}; this is that mechanism extracted so a second
 * consumer reuses the hardened original instead of forking a second, subtly weaker copy.
 *
 * Placement mirrors {@see \App\Support\Meter\MeterActorResolver}: App\Support, not a module. The Knowledge
 * module may not name the Generator (pinned literally by KnowledgeModuleBoundaryTest), and the Generator may
 * not name Knowledge either — so a fence shared by both can only live below both.
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT THE SCRUB GUARANTEES, PRECISELY
 *
 * Every occurrence of either marker (case-insensitively) is REPLACED — never deleted — with a non-empty
 * sentinel. Deleting would be unsafe: removing an inner marker lets the two halves of a deliberately SPLIT
 * outer one rejoin into a working marker ("--- BEGIN <marker>KNOWLEDGE ---" reassembles), and a single pass
 * would hand that forged marker straight to the prompt. A sentinel makes that impossible by construction —
 * it shares no substring with either marker, so no marker can ever span it, and every marker occurrence
 * inside a surviving segment was already replaced by the same pass.
 *
 * It does NOT sanitize prose. A value may still SAY "end of knowledge" in words, and that is fine: the block
 * is framed as DATA and the fence only has to be UNFORGEABLE, not unmentioned.
 *
 * ------------------------------------------------------------------------------------------------
 * MARKERS ARE PER BLOCK, AND THAT IS THE POINT
 *
 * Each consumer names its own pair, so a block only ever defends against forging ITS OWN extent. A single
 * global marker would mean that scrubbing a knowledge entry also had to know about the creative direction's
 * fence, and every new fence would silently widen what every existing consumer strips out of user text.
 *
 * Instances are immutable and cheap; a consumer holds one as a constant-like static (see
 * {@see \App\Modules\Knowledge\Support\KnowledgeFence}).
 */
final class FencedBlock
{
    /**
     * What a marker found INSIDE content is replaced with. Non-empty, and contains no character sequence
     * appearing in a marker built by {@see markers()} — the `[` alone is enough to guarantee that, since a
     * marker is a dashed word run. Inert prose: an agent reading it sees a redaction, nothing more.
     */
    public const SCRUBBED = '[removed]';

    private function __construct(
        private readonly string $open,
        private readonly string $close,
    ) {}

    /**
     * A fence with explicitly-written markers — the form that can reproduce a pre-existing pair exactly
     * (the creative direction's markers predate this class and are frozen bytes).
     */
    public static function of(string $open, string $close): self
    {
        return new self($open, $close);
    }

    /**
     * A fence with markers derived from a NAME: `--- BEGIN <NAME> ---` / `--- END <NAME> ---`, the house
     * form. Prefer this for anything new so two fences cannot disagree about their own shape.
     */
    public static function named(string $name): self
    {
        return new self('--- BEGIN ' . $name . ' ---', '--- END ' . $name . ' ---');
    }

    public function open(): string
    {
        return $this->open;
    }

    public function close(): string
    {
        return $this->close;
    }

    /** Neutralize every occurrence of this fence's markers in a value. See the class docblock. */
    public function scrub(string $value): string
    {
        return str_ireplace([$this->open, $this->close], self::SCRUBBED, $value);
    }

    /**
     * Wrap already-scrubbed content in the labelled fence.
     *
     * The label goes ABOVE the opening marker, so the sentence that says "this is data" is itself outside
     * the span it describes — a reader (and a model) can tell the framing from the framed.
     *
     * The content is NOT scrubbed here, deliberately: a caller assembles a block from many values and must
     * scrub each of them as it normalizes them, which is also where the length caps and control-character
     * strips belong. Scrubbing again at render time would hide a caller that forgot, and the invariant
     * worth having is "exactly one of each marker per block" — asserted by the callers' own tests.
     */
    public function render(string $label, string $content): string
    {
        return $label . "\n"
            . $this->open . "\n"
            . $content . "\n"
            . $this->close;
    }
}
