<?php

namespace App\Modules\Tasks\Repositories;

use App\Modules\Tasks\Enums\TaskStatus;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Read layer for Task aggregate reads — starting with the per-status board counts
 * that feed the kanban column badges.
 *
 * APPROVED PATTERN EXCEPTION: `.claude/rules/backend.md` treats repositories as the
 * exception, not the rule (before this, only `Users` had one). This repository is an
 * intentionally approved exception that introduces Taskio's CACHED READ-LAYER pattern.
 * The counts are a hot, read-heavy, cheap-to-cache aggregate, so this class owns both
 * the query and its cache lifecycle. Future modules that need a cached read should
 * mirror this shape — a per-workspace cache key, model-event invalidation, and a TTL
 * backstop — rather than caching ad hoc inside a service.
 */
class TasksRepository
{
    /** Key prefix; the active workspace id is appended so shared/own-DB tenants never collide. */
    private const CACHE_PREFIX = 'tasks:counts:';

    /** Backstop TTL (1h). Correctness comes from event invalidation; this only bounds drift. */
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly TenantContext $tenant
    ) {}

    /**
     * Per-status task counts for the active workspace.
     *
     * The UNFILTERED variant is cached per workspace (the common board load). A
     * FILTERED request ($cacheable = false) always computes live so column badges
     * stay correct under active filters without polluting the shared cache entry.
     *
     * @param  Closure(): Builder  $baseQuery  Fresh, filter-applied Task query (default scope).
     * @return array<string, int> Every TaskStatus case value → count.
     */
    public function statusCounts(Closure $baseQuery, bool $cacheable): array
    {
        if (!$cacheable) {
            return $this->readCounts($baseQuery);
        }

        return Cache::remember(
            $this->cacheKey($this->tenant->id()),
            self::CACHE_TTL,
            fn () => $this->readCounts($baseQuery)
        );
    }

    /**
     * Drop the cached counts for a workspace. Called from the Task model events, so
     * user-, workflow- and bot-authored mutations all invalidate through one seam.
     */
    public function forgetCounts(?string $workspaceId): void
    {
        Cache::forget($this->cacheKey($workspaceId));
    }

    /**
     * Build the counts map. The four active board statuses come from ONE grouped
     * query. Archive and trash are lifecycle buckets (archived_at / deleted_at)
     * excluded by global scopes, so they are read exactly as the list endpoint's
     * `?status=archive|trash` does — via onlyArchived()/onlyTrashed() — keeping every
     * column badge consistent with its own list call. The closure yields a fresh
     * builder per read because each bucket needs its own scope adjustment.
     *
     * @param  Closure(): Builder  $baseQuery
     * @return array<string, int>
     */
    private function readCounts(Closure $baseQuery): array
    {
        $grouped = $baseQuery()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [];

        foreach ([TaskStatus::TO_DO, TaskStatus::IN_PROGRESS, TaskStatus::IN_TEST, TaskStatus::DONE] as $status) {
            $counts[$status->value] = (int) ($grouped[$status->value] ?? 0);
        }

        $counts[TaskStatus::ARCHIVE->value] = $baseQuery()->onlyArchived()->count();
        $counts[TaskStatus::TRASH->value] = $baseQuery()->onlyTrashed()->count();

        return $counts;
    }

    private function cacheKey(?string $workspaceId): string
    {
        return self::CACHE_PREFIX . ($workspaceId ?? 'none');
    }
}
