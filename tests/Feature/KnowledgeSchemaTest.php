<?php

namespace Tests\Feature;

use App\Modules\Knowledge\Models\KnowledgeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pins the parts of the Knowledge schema that no other test would notice breaking.
 *
 * The vector column's WIDTH is the important one. It is hardcoded in the migration (a schema that
 * changes shape with an env var is a schema nobody can reason about), which means a change to
 * `config('knowledge.embedding.dimensions')` would otherwise diverge from the column silently — and
 * only surface much later, as an insert error from a worker, once real embeddings existed. Comparing
 * the two here turns that into a failing test the moment the config moves.
 *
 * The HNSW index is pinned for the neighbouring reason: without it a similarity query still WORKS
 * (Postgres falls back to a sequential scan), so its absence shows up as a slow product rather than
 * a broken one.
 */
class KnowledgeSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_module_tables_exist(): void
    {
        foreach ([
            'knowledge_bases',
            'knowledge_entries',
            'knowledge_entry_revisions',
            'knowledge_links',
            'knowledge_entry_chunks',
            'knowledge_bindings',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "expected the {$table} table to exist");
        }
    }

    public function test_vectors_live_on_chunks_and_never_on_an_entry(): void
    {
        $this->assertTrue(Schema::hasColumn('knowledge_entry_chunks', 'embedding'));
        // An entry is chunked before it is embedded; a vector on the entry would mean the whole
        // document averaged into one point, which is the thing chunking exists to avoid.
        $this->assertFalse(Schema::hasColumn('knowledge_entries', 'embedding'));
    }

    public function test_the_embedding_column_width_matches_the_configured_dimensions(): void
    {
        $type = DB::selectOne(
            "select format_type(a.atttypid, a.atttypmod) as type
             from pg_attribute a
             join pg_class c on c.oid = a.attrelid
             where c.relname = 'knowledge_entry_chunks' and a.attname = 'embedding'"
        );

        $this->assertNotNull($type, 'the embedding column must exist');
        $this->assertSame(
            'vector(' . (int) config('knowledge.embedding.dimensions') . ')',
            $type->type,
            'the stored vector width must match config(knowledge.embedding.dimensions) — changing the '
            . 'model width needs a migration that re-creates the column and re-indexes everything.',
        );
    }

    public function test_the_chunk_embedding_carries_an_hnsw_cosine_index(): void
    {
        $index = DB::selectOne(
            "select indexdef from pg_indexes
             where tablename = 'knowledge_entry_chunks' and indexname = 'knowledge_entry_chunks_embedding_hnsw'"
        );

        $this->assertNotNull($index, 'the HNSW index must exist — without it retrieval degrades to a seq scan');
        $this->assertStringContainsString('USING hnsw', $index->indexdef);
        $this->assertStringContainsString('vector_cosine_ops', $index->indexdef);
    }

    /**
     * The entry factory stamps `index_digest`, which is NOT in $fillable (only the service writes
     * it). Factories bypass mass-assignment, so this works — pinned because the day it stops working
     * every fixture would silently carry a null digest and the "needs indexing" tests would still
     * pass for the wrong reason.
     */
    public function test_the_entry_factory_stamps_an_index_digest(): void
    {
        $entry = KnowledgeEntry::factory()->create();

        $this->assertNotNull($entry->fresh()->index_digest);
        $this->assertSame(64, strlen((string) $entry->fresh()->index_digest));
    }

    public function test_the_chunking_config_declares_the_values_the_digest_depends_on(): void
    {
        // The chunker version is folded into every entry's index_digest, so it must exist and be a
        // number a change can be detected against.
        $this->assertIsInt(config('knowledge.chunking.version'));
        $this->assertSame(40000, config('knowledge.entry_max_chars'));
        $this->assertGreaterThan(0, config('knowledge.chunking.max_chunks_per_entry'));
    }
}
