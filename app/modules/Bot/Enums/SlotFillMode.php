<?php

namespace App\Modules\Bot\Enums;

/**
 * HOW a delegation's autonomous slot-fill treats the inputs the human ALREADY typed — an explicit CHOICE the
 * human makes at click time (`fill_mode` on `POST /api/bots/{bot}/sessions/{session}/delegate`), never an
 * implicit guess by the engine.
 *
 *   - Gaps   fill ONLY the slots that are currently EMPTY. A value the human typed is never offered to the
 *            model and — belt AND braces — never accepted back from it (the service refuses a non-offered
 *            key server-side, so a prompt-injected "fill everything" can NOT overwrite a human input). The
 *            filled slots still travel as read-only CONTEXT so the proposal is coherent with what the human
 *            wrote. This is the DEFAULT (the non-destructive reading) when the caller says nothing.
 *   - Fresh  "take it over and do it your way": every in-scope slot is offered and the model is told to
 *            propose a DIFFERENT take from what is there now. Destructive by design, and safe because the
 *            delegation overlay's `slot_values_before` snapshot makes undo a full restore.
 *
 * WHY this exists (the two owner-reported defects it fixes): the fill used to offer EVERY in-scope slot with
 * its `[current: …]` value and no instruction to change it, so delegating a fully-filled session (a) still
 * made — and billed — an AI call that (b) echoed the same values straight back. The mode makes the intent
 * explicit on both sides: `gaps` spends nothing when there is no gap, `fresh` demands a different answer.
 *
 * A string-backed enum so it can ride the wire / a log line as a plain id. It lives in the BOT module: it
 * only shapes the bot's PROMPT + which slots it offers — the Generator's scope authority
 * ({@see \App\Modules\Generator\Enums\SlotScopePolicy}) and its re-validating persist path are untouched, and
 * the Generator still never names a Bot class (the one-way edge).
 */
enum SlotFillMode: string
{
    case Gaps = 'gaps';

    case Fresh = 'fresh';

    /**
     * The mode a caller that says NOTHING gets: `gaps`, the non-destructive reading — an existing client that
     * never learned about `fill_mode` can therefore never overwrite a human's inputs.
     */
    public static function default(): self
    {
        return self::Gaps;
    }

    /** Resolve a wire value, falling back to {@see default} for absent/unknown input (the FormRequest 422s first). */
    public static function fromNullable(?string $value): self
    {
        return ($value === null ? null : self::tryFrom($value)) ?? self::default();
    }

    /** The wire values, for the FormRequest's `in:` rule. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
