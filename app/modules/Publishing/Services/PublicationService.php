<?php

namespace App\Modules\Publishing\Services;

use App\Modules\Publishing\DTOs\PublicationDTO;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Models\Publication;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The CONTENT half of a publication: writing it, listing it, counting it.
 *
 * Everything about WHERE IT IS IN ITS LIFE lives in {@see \App\Modules\Publishing\Managers\PublicationManager},
 * and this class never touches `status`. That split is the module's main structural decision, and it is
 * asserted by test rather than left as a convention: a service that also flipped a status would make
 * the state machine advisory, and the line that does it always looks reasonable in review.
 *
 * There is NO REPOSITORY, in line with the house rule (repositories are the exception; only Users and
 * Tasks have one). The reads here are ordinary Eloquent and there is no caching layer to make room for
 * yet. When the queue screen needs a cached due-count, that is the moment to introduce one — not now,
 * on the guess that it might.
 */
class PublicationService
{
    /**
     * Create a publication. Always a DRAFT.
     *
     * A create is never an arming, even when the payload carries a moment — see {@see PublicationDTO}.
     * The status is not passed here at all: the column's default does the work, so this method has no
     * line that assigns one and cannot grow into a second entrance to the state machine.
     *
     * NO TRANSACTION: exactly one row is written. Wrapping a single insert would say "several things
     * happen here" to the next reader, which is the opposite of true.
     */
    public function create(PublicationDTO $dto): Publication
    {
        return Publication::create($this->attributesFrom($dto));
    }

    /**
     * Rewrite a publication's content.
     *
     * WHETHER this is allowed at all is decided upstream, by `PublicationPolicy` composing "who is
     * asking" with `PublicationStatus::isEditable()` — not here, and not in a hidden guard. A service
     * that refused writes on its own would be authorization in a place nobody looks for it, and the
     * `can_be_edited` flag on the resource would have a second opinion to disagree with.
     */
    public function update(Publication $publication, PublicationDTO $dto): Publication
    {
        $publication->update($this->attributesFrom($dto));

        return $publication;
    }

    /** Move to the trash. Soft delete — the record survives, which is the point of keeping one. */
    public function delete(Publication $publication): void
    {
        $publication->delete();
    }

    /**
     * The list, NEWEST FIRST BY CREATION — deliberately not by the schedule.
     *
     * Sorting a publishing list by `scheduled_at` is the obvious thing to want and is unsafe with cursor
     * pagination, because the column is NULLABLE: every draft has a null there, and a cursor's
     * `where(scheduled_at < X)` comparison silently drops null rows, so drafts would vanish from page
     * two onwards with nothing indicating it. Creation time is non-null and monotonic, which is what a
     * cursor needs.
     *
     * The queue view that genuinely wants due-order is B3's, and it is safe there for a reason worth
     * writing down: it filters to `scheduled`, and within that status the column is non-null by
     * construction — the Manager sets it on the same write that enters the state.
     */
    public function index(Request $request): CursorPaginator
    {
        return $this->listQuery($request)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->integer('per_page', 25));
    }

    /**
     * Per-status counts for the module's tabs and its navigation badge.
     *
     * Modelled on `GET /tasks/counts` and shaped identically, with one addition argued for on
     * {@see \App\Modules\Publishing\Http\Resources\PublicationCountsResource}: `needs_attention`.
     *
     * EVERY STATUS IS ALWAYS PRESENT, zero where there are none. A client that had to branch on whether
     * a key exists would render "—" for a status that simply has no rows, which is a different statement
     * from zero.
     *
     * The filters are the LIST's filters minus `status` — the endpoint answers for every status at once,
     * so filtering by one would make each count a count of itself.
     *
     * @return array{counts: array<string, int>, total: int, needs_attention: int}
     */
    public function counts(Request $request): array
    {
        $counted = $this->applyFilters(Publication::query(), $request)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [];

        foreach (PublicationStatus::cases() as $status) {
            $counts[$status->value] = (int) ($counted[$status->value] ?? 0);
        }

        $needsAttention = 0;

        foreach (PublicationStatus::cases() as $status) {
            if ($status->needsAttention()) {
                $needsAttention += $counts[$status->value];
            }
        }

        return [
            'counts' => $counts,
            // EVERY publication, unlike the tasks endpoint's `total`. Tasks excludes archive and trash
            // because those are secondary lifecycle buckets with their own tabs; publishing has no such
            // bucket — a published publication is the point of the module, not an archive of it.
            'total' => array_sum($counts),
            'needs_attention' => $needsAttention,
        ];
    }

    /** @return Builder<Publication> */
    private function listQuery(Request $request): Builder
    {
        $query = Publication::query()
            ->with('creator')
            ->when(
                $status = $request->enum('status', PublicationStatus::class),
                fn (Builder $query) => $query->where('status', $status),
            );

        return $this->applyFilters($query, $request);
    }

    /**
     * Every list filter EXCEPT status. Shared by {@see listQuery()} and {@see counts()} so both honour
     * the same active filters — the same arrangement `TaskService` uses, and for the same reason.
     *
     * @param  Builder<Publication>  $query
     * @return Builder<Publication>
     */
    private function applyFilters(Builder $query, Request $request): Builder
    {
        return $query
            // The shared scope, with the term passed as an argument — it never reads request() itself,
            // and it escapes `%`/`_` so a search matches only itself.
            ->search(['title', 'body'], $request->get('search'))
            ->when(
                $platforms = array_values(array_filter($request->array('platform'), 'is_string')),
                fn (Builder $query) => $query->whereIn('platform', $platforms),
            )
            ->when(
                $request->filled('scheduled_from'),
                fn (Builder $query) => $query->where('scheduled_at', '>=', $request->date('scheduled_from')),
            )
            ->when(
                $request->filled('scheduled_to'),
                fn (Builder $query) => $query->where('scheduled_at', '<=', $request->date('scheduled_to')),
            );
    }

    /**
     * The DTO as columns.
     *
     * NOTE WHAT IS NOT HERE: `status`, `remote_id`, `remote_draft_id`, `attempts`. The write surface
     * cannot reach any of them, which is what makes "the Manager owns the lifecycle" true of the code
     * rather than of a comment.
     *
     * @return array<string, mixed>
     */
    private function attributesFrom(PublicationDTO $dto): array
    {
        return [
            'title' => $dto->title,
            'body' => $dto->body,
            'platform' => $dto->platform,
            'platform_connection_id' => $dto->platformConnectionId,
            'scheduled_at' => $dto->scheduledAt,
            'media' => $dto->media,
            'options' => $dto->options,
        ];
    }
}
