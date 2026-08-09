<?php

namespace App\Modules\Knowledge\DTOs;

use App\Modules\Knowledge\Enums\KnowledgeBindingMode;

/**
 * ONE consumer's knowledge context, ready to be pasted into a prompt — plus the receipt for what went
 * into it.
 *
 * The receipt is not decoration. A bot reads its base LIVE, at the moment it runs: two runs a week apart
 * see different text, and the second one cannot be explained by looking at the base today. So every read
 * records which entries it saw and at which REVISION, which is the difference between "the bot was told
 * about the refund policy" and "the bot was told the refund policy AS IT READ ON TUESDAY". Without the
 * revision ids the audit trail answers the easy question and not the one anybody actually asks after a
 * bot says something wrong.
 *
 * `$omittedTitles` is the honest half of the inline budget: entries that exist and were NOT shown. They
 * are named in the block itself (so the model knows its picture is partial) and carried here (so the audit
 * shows what was withheld). An empty array on a compiled INLINE read is also the signal `auto` uses to
 * decide the base still fits.
 */
final class CompiledKnowledge
{
    /**
     * @param  string  $text  the fenced block, ready to compose into a prompt
     * @param  array<int, string>  $entryIds  entries whose text is IN the block
     * @param  array<int, string>  $revisionIds  the revision each of those entries was at
     * @param  array<int, string>  $omittedTitles  entries that exist but did not fit (inline only)
     */
    public function __construct(
        public readonly string $text,
        public readonly string $baseId,
        public readonly KnowledgeBindingMode $mode,
        public readonly array $entryIds,
        public readonly array $revisionIds,
        public readonly array $omittedTitles = [],
    ) {}

    /**
     * The audit payload — the shape a consumer records alongside its run. Deliberately assembled HERE
     * rather than by each consumer, so two consumers cannot disagree about what "what did it see" means.
     *
     * @return array<string, mixed>
     */
    public function auditPayload(): array
    {
        return [
            'knowledge_base_id' => $this->baseId,
            'mode' => $this->mode->value,
            'entry_ids' => $this->entryIds,
            'revision_ids' => $this->revisionIds,
            'omitted_titles' => $this->omittedTitles,
            'chars' => mb_strlen($this->text),
        ];
    }
}
