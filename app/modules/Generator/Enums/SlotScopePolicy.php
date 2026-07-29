<?php

namespace App\Modules\Generator\Enums;

use App\Modules\Variables\Enums\VariableType;

/**
 * WHO is filling a session's slots — the SCOPE policy that decides which declared slots an automatic
 * (non-human) fill may touch. It decides OFFERABILITY ONLY: which descriptors are in scope. It never
 * validates a value — every accepted value is still re-validated server-side against its descriptor by
 * {@see \App\Modules\Generator\Services\SessionDelegationService} (through the shared
 * {@see \App\Modules\Variables\Services\ConstantTypeValidator}, which also rejects NUL bytes), whatever
 * the policy. So this is a SCOPE parameter on ONE shared fill path, never a second implementation.
 *
 *   - Bot          the R2 sub-stage 3 delegation: a bot proposes values. FILE slots are REFUSED.
 *   - Automation   the R2 sub-stage 5 automation seam: a workflow mapping supplies values. FILE slots
 *                  are ALLOWED (owner decision D4).
 *
 * WHY the FILE divergence (D4). A bot's values come from a MODEL: it could FABRICATE a plausible Disk
 * reference it was never given, so a bot must not be able to attach a file at all — its scope stops at
 * typed literals. An automation mapping is authored by a TRUSTED workspace human (they wrote the step),
 * and the value it carries is resolved through the TENANT-SCOPED `File` model before anything is
 * persisted: a foreign-workspace, deleted, or made-up id simply does not resolve and the value is
 * dropped like any other invalid one. The authorization boundary is therefore the tenant scope + the
 * human who authored the mapping, not the absence of the capability.
 *
 * DEEP COMPOSITES stay out of scope for BOTH policies (unchanged): an `array<object>`, an object nesting
 * another object/file, and a LIST of files are deferred shapes the catalog/resolver do not offer per
 * element yet, so no automatic filler may write one.
 *
 * A string-backed enum so a future caller (or a log line) can carry the scope as a plain id.
 */
enum SlotScopePolicy: string
{
    case Bot = 'bot';

    case Automation = 'automation';

    /**
     * Whether a declared slot descriptor is OFFERABLE under this policy: NOT a `file` (unless this policy
     * allows file slots, and then only a single one — a list of files is a deferred composite), NOT an
     * `array<object>`, and — for a plain object — every field a SCALAR leaf (no nested object/file, i.e.
     * no composite deeper than one level). A scalar (text/number/boolean/date/enum), incl. its `array`
     * form, is always offerable.
     *
     * @param  array<string, mixed>  $descriptor
     */
    public function accepts(array $descriptor): bool
    {
        $base = $descriptor['base'] ?? null;

        if ($base === VariableType::FILE->value) {
            // A LIST of files is a deferred composite for EVERY policy (per-element file access is the
            // deferred loop) — only a single file reference is ever offerable.
            return $this->allowsFileSlots() && ($descriptor['array'] ?? false) !== true;
        }

        if ($base === VariableType::OBJECT->value) {
            // A list of objects, or an object nesting an object/file, is a DEFERRED composite.
            if (($descriptor['array'] ?? false) === true) {
                return false;
            }

            $fields = is_array($descriptor['fields'] ?? null) ? $descriptor['fields'] : [];

            foreach ($fields as $field) {
                $fieldBase = is_array($field) && is_array($field['descriptor'] ?? null)
                    ? ($field['descriptor']['base'] ?? null)
                    : null;

                if ($fieldBase === VariableType::OBJECT->value || $fieldBase === VariableType::FILE->value) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Whether this scope may fill a FILE slot at all (see the class note for the D4 rationale). */
    public function allowsFileSlots(): bool
    {
        return $this === self::Automation;
    }
}
