<?php

namespace App\Modules\Knowledge\Enums;

/**
 * The INDEXING state of a knowledge entry: where its chunks/embeddings stand relative to its current
 * text. Orthogonal to {@see KnowledgeEntryStatus} — an `approved` entry can be `pending`, and a
 * `draft` can be fully `indexed`.
 *
 *   pending         the text changed (or it is new); nothing has embedded it yet.
 *   indexing        a worker holds it.
 *   indexed         every chunk carries a current embedding.
 *   partial         some chunks embedded, some did not — the honest outcome of a run that hit a
 *                   provider error partway. It is a distinct state rather than a retry of `failed`
 *                   because the entry IS partially retrievable, and calling that "failed" would
 *                   throw away work already paid for.
 *   pending_budget  refused BEFORE spending: the workspace is at its AI cap. Distinct from `failed`
 *                   because nothing is broken and no retry will help — raising the cap will.
 *   failed          the run could not produce anything usable.
 *
 * Whether an entry NEEDS work is never read off this column alone: the authority is
 * `index_digest != indexed_digest` (see the entries migration), so a status that goes stale — a
 * worker that died holding `indexing` — cannot make an out-of-date entry look current. B1 only ever
 * writes `pending`; B2a owns the rest.
 */
enum KnowledgeIndexStatus: string
{
    case PENDING = 'pending';
    case INDEXING = 'indexing';
    case INDEXED = 'indexed';
    case PARTIAL = 'partial';
    case PENDING_BUDGET = 'pending_budget';
    case FAILED = 'failed';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether this state represents a run that DID NOT FINISH — i.e. one a human can meaningfully ask
     * to run again ({@see \App\Modules\Knowledge\Services\KnowledgeIndexService::retry()}).
     *
     * It lives on the enum rather than in the service because it is a property of the STATE, not a
     * policy about it: the three states below are precisely the ones whose docblocks above describe
     * work left undone. Keeping it here means the API's `can_retry` flag and the service's 422 read the
     * same definition — a flag that promised an action the service then refused would be worse than no
     * flag at all.
     *
     * The other three are excluded for reasons that are not symmetrical, which is why they are listed:
     * `indexed` has nothing left to do, `indexing` is claimed by a live worker, and `pending` is
     * already queued.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::FAILED, self::PENDING_BUDGET, self::PARTIAL => true,
            self::PENDING, self::INDEXING, self::INDEXED => false,
        };
    }
}
