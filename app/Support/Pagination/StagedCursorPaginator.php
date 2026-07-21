<?php

namespace App\Support\Pagination;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Paginator;

/**
 * Cursor-paginate several ordered STAGES (each a query over ANY table) as ONE logical list:
 * all of stage 0, then all of stage 1, and so on — with a single `?cursor=` param and the exact
 * `{ data, links, meta: { next_cursor, prev_cursor, path, per_page } }` envelope Laravel's own
 * cursor paginator produces (so it is a drop-in for `Resource::collection()`).
 *
 * The disk browser uses it to serve a folder's subfolders (stage 0) and files (stage 1) from ONE
 * endpoint, but nothing here is disk-specific — a module wanting a cross-table feed does:
 *
 *     StagedCursorPaginator::make(20)
 *         ->stage('comments', fn () => Comment::query()->where(...)->orderBy('created_at')->orderBy('id'))
 *         ->stage('events',   fn () => Event::query()->where(...)->orderBy('created_at')->orderBy('id'))
 *         ->paginate($request->query('cursor'));
 *
 * How it works: each stage delegates its multi-column SEEK to Laravel's own
 * `Builder::cursorPaginate()` (never hand-rolled) and the generated cursor carries — besides the
 * order-by values — a `_stage` marker so the next page resumes in the right stage. When a resumed
 * stage's seek dries up, the loop rolls into the next stage from its top; because the cursor always
 * points at the LAST EMITTED item, stage boundaries need no special-casing.
 *
 * Contract: every stage's query MUST end its `orderBy` with a UNIQUE column (the primary key) or the
 * seek skips/duplicates rows at page edges. Tenancy is NOT a cursor concern — each stage builds a
 * fresh, globally-scoped `Model::query()`, so a forged cursor can only yield an odd/empty page, never
 * another workspace's rows.
 *
 * Forward-only for now (`prev_cursor` is always null, shape-valid); the cursor is direction-aware, so
 * backward is an additive follow-up.
 */
class StagedCursorPaginator
{
    /** @var array<string, Closure> ordered stage key => builder factory (PHP preserves order) */
    private array $stages = [];

    private EloquentBuilder|QueryBuilder|null $base = null;

    private ?string $path = null;

    private string $cursorName = 'cursor';

    public function __construct(private int $perPage) {}

    public static function make(int $perPage): self
    {
        return new self($perPage);
    }

    /**
     * An OPTIONAL shared base query, CLONED into every stage callback (never shared by reference,
     * so one stage's mutations can't leak into another). Omit for cross-table use, where each stage
     * returns its own `Model::query()` and simply ignores the clone.
     */
    public function from(EloquentBuilder|QueryBuilder $base): self
    {
        $this->base = $base;

        return $this;
    }

    /**
     * Append a stage. `$fn` receives a clone of the base query (or null when none was set) and
     * returns ANY builder with its own `orderBy` (ending in the primary key — see the class note).
     *
     * @param  Closure(EloquentBuilder|QueryBuilder|null): (EloquentBuilder|QueryBuilder)  $fn
     */
    public function stage(string $key, Closure $fn): self
    {
        $this->stages[$key] = $fn;

        return $this;
    }

    public function withPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function withCursorName(string $name): self
    {
        $this->cursorName = $name;

        return $this;
    }

    /**
     * Run the pagination. `$cursor` is the incoming cursor (a `Cursor`, an encoded string, or null).
     */
    public function paginate(Cursor|string|null $cursor = null): CursorPaginator
    {
        $incoming = $cursor instanceof Cursor ? $cursor : Cursor::fromEncoded($cursor);
        $keys = array_keys($this->stages);
        $target = $this->perPage + 1; // one probe row beyond the page tells us a next page exists

        // Resolve which stage to resume in. An unknown/absent `_stage` (or a tampered cursor) falls
        // back to the first stage with no seek — a safe "first page".
        $startIndex = 0;
        if ($incoming !== null) {
            $found = array_search($incoming->toArray()['_stage'] ?? null, $keys, true);
            $found === false ? $incoming = null : $startIndex = $found;
        }

        /** @var array<int, array{model: object, sp: CursorPaginator, stage: string}> */
        $collected = [];
        for ($i = $startIndex; $i < count($keys) && count($collected) < $target; $i++) {
            $need = $target - count($collected);
            $builder = ($this->stages[$keys[$i]])($this->base ? clone $this->base : null);

            // Only the RESUMED stage seeks, and only if the cursor carries this stage's order
            // columns (else a tampered/short cursor would throw — treat it as this stage's start).
            $stageCursor = ($i === $startIndex && $incoming !== null && $this->cursorCoversOrder($builder, $incoming))
                ? $incoming
                : null;

            $sp = $builder->cursorPaginate($need, ['*'], $this->cursorName, $stageCursor);

            foreach ($sp->getCollection() as $model) {
                $collected[] = ['model' => $model, 'sp' => $sp, 'stage' => $keys[$i]];
            }
        }

        $hasMore = count($collected) > $this->perPage;

        // Build the next cursor from the LAST EMITTED item (index perPage-1), not the probe row.
        $next = null;
        if ($hasMore) {
            $anchor = $collected[$this->perPage - 1];
            $next = new Cursor(
                $anchor['sp']->getParametersForItem($anchor['model']) + ['_stage' => $anchor['stage']],
                true,
            );
        }

        // Pass up to perPage+1 models so the parent computes an honest hasMore and slices to perPage;
        // our overridden nextCursor() supplies the staged cursor.
        $models = array_map(fn ($e) => $e['model'], array_slice($collected, 0, $target));

        return new StagedCursorResult($models, $this->perPage, $incoming, [
            'path' => $this->path ?? Paginator::resolveCurrentPath(),
            'cursorName' => $this->cursorName,
            'stagedNext' => $next,
            'stagedPrev' => null,
        ]);
    }

    /** Whether `$cursor` carries every order column `$builder` seeks on (guards tampered cursors). */
    private function cursorCoversOrder(EloquentBuilder|QueryBuilder $builder, Cursor $cursor): bool
    {
        $params = $cursor->toArray();
        $orders = ($builder instanceof EloquentBuilder ? $builder->getQuery() : $builder)->orders ?? [];

        foreach ($orders as $order) {
            $column = $order['column'] ?? null;
            if ($column === null || !array_key_exists($column, $params)) {
                return false; // a raw order or a missing column → can't safely seek
            }
        }

        return true;
    }
}
