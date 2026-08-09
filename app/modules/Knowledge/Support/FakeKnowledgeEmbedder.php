<?php

namespace App\Modules\Knowledge\Support;

use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\DTOs\EmbeddingBatchResult;
use Throwable;

/**
 * The test double for {@see KnowledgeEmbedder}. It lives in the module rather than in tests/ for the
 * same reason laravel/ai ships its own fake gateway: it is part of the seam's contract, and every
 * consumer that later reads the knowledge base (B7) will need it to test without spending money.
 *
 * DETERMINISTIC BY CONSTRUCTION. The vector is derived from sha256 of the text, so the same text
 * always yields the same vector and two different texts practically never collide. That is what makes
 * the differential-indexing assertions meaningful: a test can prove a chunk's vector SURVIVED a
 * re-index by comparing it to the one the fake would have produced, which a random fake could not
 * express. The result is normalized to unit length because the stored index ranks by cosine distance
 * and an un-normalized fake would make similarity assertions depend on text length.
 *
 * COUNTS EVERYTHING. `$calls` is the assertion behind "a 40k entry costs ONE provider call" and "an
 * unchanged re-save costs ZERO"; `$batches` records the exact inputs so a test can name which texts
 * were re-embedded after an edit. These are the only way to observe spend from the outside — the job
 * is fire-and-forget by design.
 *
 * `$failure` makes the provider-error branch reachable: set it and the next call throws.
 */
class FakeKnowledgeEmbedder implements KnowledgeEmbedder
{
    /** How many times {@see embed()} was invoked — i.e. how many provider round-trips were paid for. */
    public int $calls = 0;

    /**
     * The inputs of every call, in order.
     *
     * @var array<int, array<int, string>>
     */
    public array $batches = [];

    /** Set to make the next call throw — the provider-error branch of the indexer. */
    public ?Throwable $failure = null;

    /** Tokens the fake reports per text, mirroring the ~4-characters-per-token rule of thumb. */
    public function embed(array $texts): EmbeddingBatchResult
    {
        $texts = array_values($texts);

        $this->calls++;
        $this->batches[] = $texts;

        if ($this->failure !== null) {
            $failure = $this->failure;
            $this->failure = null;

            throw $failure;
        }

        $dimensions = (int) config('knowledge.embedding.dimensions');
        $tokens = 0;
        $vectors = [];

        foreach ($texts as $text) {
            $vectors[] = self::vectorFor($text, $dimensions);
            $tokens += (int) ceil(mb_strlen($text) / 4);
        }

        return new EmbeddingBatchResult($vectors, $tokens);
    }

    /** Reset the counters between phases of a test. */
    public function reset(): void
    {
        $this->calls = 0;
        $this->batches = [];
        $this->failure = null;
    }

    /** Every text this fake has been asked to embed, flattened across all calls. */
    public function embeddedTexts(): array
    {
        return array_merge(...($this->batches ?: [[]]));
    }

    /**
     * The unit vector this fake produces for a text — exposed statically so a test can assert that a
     * STORED vector is the one that belongs to a given passage (i.e. that the passage was really
     * re-embedded, or really left alone) without re-running the fake.
     *
     * Expansion is by counter-keyed sha256 blocks: each 32-byte block yields 16 signed values, and
     * blocks are drawn until the requested width is filled. Deterministic, dependency-free, and fast
     * enough for a 1536-wide vector.
     *
     * @return array<int, float>
     */
    public static function vectorFor(string $text, int $dimensions): array
    {
        $values = [];
        $block = 0;

        while (count($values) < $dimensions) {
            $bytes = hash('sha256', $block . '|' . $text, true);

            for ($offset = 0; $offset < 32 && count($values) < $dimensions; $offset += 2) {
                $pair = unpack('n', substr($bytes, $offset, 2));
                $values[] = ((int) $pair[1] / 32767.5) - 1.0;
            }

            $block++;
        }

        $magnitude = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $values)));

        if ($magnitude <= 0.0) {
            return $values; // unreachable for a sha256-derived vector; guards the division regardless
        }

        return array_map(static fn (float $value): float => $value / $magnitude, $values);
    }
}
