<?php

namespace App\Modules\Knowledge\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-queue the indexing of one entry.
 *
 * Gated on the entry's `update` ability — i.e. any workspace member, the same people who may edit the
 * text. That is the right comparison: a retry re-indexes the entry's OWN content and can change nothing
 * a save could not, so a rule stricter than "may edit this entry" would only mean the person who noticed
 * the failed badge has to find someone else to click it.
 *
 * It can SPEND (one embedding batch), which is the argument for a narrower gate — but the spend is
 * bounded by the same per-entry cap any save incurs, and it is refused by the same workspace budget gate
 * inside the job. The cost of a wrongly-clicked retry is one entry's re-index; the cost of gating it to
 * owners is a knowledge base that stays half-indexed because the only person allowed to fix it is away.
 *
 * WHICH index states may be retried is a STATE question, not an input one, so it is decided by
 * {@see \App\Modules\Knowledge\Services\KnowledgeIndexService::retry()} and answered as a 422. Putting
 * it here would spread the lifecycle across two layers and let a second caller bypass it.
 */
class RetryKnowledgeIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // `retryIndex`, not `update`: nobody may update an entry any more, and re-queuing a failed
        // indexing run is not an edit — it changes no text and asks for work that already should have
        // happened. Borrowing `update` here would have made this endpoint die with hand-authorship.
        return $this->user()?->can('retryIndex', $this->route('entry')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
