<?php

namespace App\Modules\Knowledge\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * DRAFT ENTRIES DO NOT EXIST, unless somebody explicitly asks for them.
 *
 * A draft is an ordinary `knowledge_entries` row carrying a `draft_session_id` — which buys it
 * revisions, slugs, metadata validation, the directive guard, the policy and tenancy for free, and
 * makes "accept" a single atomic column write instead of a copy. The price of that decision is that
 * every read in the product would otherwise start returning unreviewed, machine-written text: the
 * entry list, the reader, hybrid search, the graph, the trash, the base's entry count, and — the one
 * that actually matters — the context a BOT quotes to a customer as fact.
 *
 * This scope pays that price ONCE, in the one place that cannot be forgotten. Mirrors
 * {@see \App\Models\Scopes\WorkspaceScope}: a global scope plus a narrow, named escape hatch. The
 * alternative — a `whereNull('draft_session_id')` in every query — is cheap to write and wrong the
 * first time somebody adds a feature without knowing drafts exist, which is exactly the kind of gap
 * that ships.
 *
 * `withDrafts()` is the escape hatch and belongs ONLY to the drafting endpoints. It reads as an
 * exception at every call site, which is the point: a reviewer seeing it outside the composer knows
 * immediately that something is wrong.
 */
class WithoutDraftsScope implements Scope
{
    public const COLUMN = 'draft_session_id';

    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->qualifyColumn(self::COLUMN));
    }

    public function extend(Builder $builder): void
    {
        // Every entry, drafts included — the composer's own reads.
        $builder->macro('withDrafts', fn (Builder $builder) => $builder->withoutGlobalScope($this));

        // ONLY drafts, and only one session's: the composer listing what it just produced.
        $builder->macro('onlyDraftsOf', fn (Builder $builder, string $sessionId) => $builder
            ->withoutGlobalScope($this)
            ->where($builder->getModel()->qualifyColumn(self::COLUMN), $sessionId));
    }
}
