<?php

namespace App\Modules\Knowledge\Support;

use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use Illuminate\Support\Carbon;

/**
 * The ONE place raw pgvector SQL lives.
 *
 * `knowledge_entry_chunks.embedding` is a `vector(1536)` added by raw DDL because Laravel's Blueprint
 * has no such type, and for the same reason it has no Eloquent cast — so it cannot be written through
 * `create()`/`update()` like any other column, and reading it back through a plain `get()` would drag
 * megabytes of floats into memory (see the model docblock). Everything that touches the column
 * therefore funnels through here: the module's documented, narrow exception to "prefer Eloquent over
 * DB::", exactly as the per-form analytical layer is.
 *
 * The write is a SEPARATE statement from the row itself rather than part of one raw INSERT, and that
 * is deliberate: the row must go through Eloquent so {@see \App\Traits\TenantAware} stamps
 * `workspace_id` in shared mode and OMITS it in own-database mode — where the column does not exist at
 * all. A hand-written INSERT naming every column would have to know which tenancy mode it is in, and
 * would break the moment it guessed wrong. Two statements per chunk, at most 50 chunks per entry, on
 * a background job: a trade with no downside.
 *
 * Values are bound as a text literal cast with `?::vector`, never interpolated. pgvector's text form
 * is a JSON-ish `[0.1,-0.2,...]`, so an interpolated float list would be an injection surface for
 * anything that ever produced a vector from untrusted input.
 */
class ChunkVector
{
    /**
     * Whether the active connection can store vectors at all. The chunks migration adds the column
     * ONLY on pgsql (Blueprint cannot express it elsewhere), so on any other driver the column is
     * simply absent and every statement here would fail with a confusing SQL error. Callers check
     * this first and stand down cleanly instead — the module is pgsql-only by design (pgvector 0.8.0
     * verified on every environment in the B0 spike), and this makes that explicit rather than fatal.
     */
    public static function supported(): bool
    {
        return self::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Store one chunk's vector and stamp which model produced it.
     *
     * @param  array<int, float>  $vector
     */
    public static function write(string $chunkId, array $vector, string $model, Carbon $indexedAt): void
    {
        self::connection()->update(
            'update knowledge_entry_chunks set embedding = ?::vector, embedding_model = ?, indexed_at = ?, updated_at = ? where id = ?',
            [self::toLiteral($vector), $model, $indexedAt, $indexedAt, $chunkId],
        );
    }

    /**
     * Read one chunk's vector back as floats, or null when it has none.
     *
     * Retrieval (B2b) will NOT use this — similarity is computed inside Postgres against the HNSW
     * index, which is the whole reason the column exists. This is for the cases that genuinely need
     * the numbers in PHP: asserting in a test that a re-index REUSED a vector rather than re-buying
     * it, and the `knowledge.vector_store=php` escape hatch.
     *
     * @return array<int, float>|null
     */
    public static function read(string $chunkId): ?array
    {
        if (!self::supported()) {
            return null;
        }

        $row = self::connection()->selectOne(
            'select embedding::text as embedding from knowledge_entry_chunks where id = ?',
            [$chunkId],
        );

        return self::fromLiteral($row?->embedding);
    }

    /**
     * pgvector's text form: `[v1,v2,...]`. Values are serialized with a fixed precision so the same
     * vector always produces the same literal — a float printed with locale-dependent or
     * shortest-round-trip formatting would make the stored bytes depend on the PHP build.
     *
     * @param  array<int, float>  $vector
     */
    public static function toLiteral(array $vector): string
    {
        return '[' . implode(',', array_map(
            static fn (float $value): string => rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.') ?: '0',
            array_map(static fn ($value): float => (float) $value, array_values($vector)),
        )) . ']';
    }

    /**
     * Parse pgvector's text form back into floats.
     *
     * @return array<int, float>|null
     */
    public static function fromLiteral(?string $literal): ?array
    {
        if (!is_string($literal) || $literal === '') {
            return null;
        }

        $decoded = json_decode($literal, true);

        if (!is_array($decoded)) {
            return null;
        }

        return array_map(static fn ($value): float => (float) $value, array_values($decoded));
    }

    /**
     * The connection the chunk model is currently routed to — the DEFAULT one in shared mode, the
     * dedicated tenant connection while an own-database workspace is active. Reading it off the model
     * (rather than naming a connection) is what keeps these raw statements tenancy-correct.
     */
    private static function connection(): \Illuminate\Database\ConnectionInterface
    {
        return (new KnowledgeEntryChunk)->getConnection();
    }
}
