<?php

namespace App\Modules\Knowledge\Models;

use App\Models\AbstractModel;
use App\Modules\Knowledge\Enums\KnowledgeDraftSessionStatus;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONE sitting of the AI composer: raw material in, proposed entries out, refined until accepted or
 * abandoned.
 *
 * The session owns the CONVERSATION (source text, instruction history, status/claim); the drafts
 * themselves are ordinary {@see KnowledgeEntry} rows carrying this session's id — see
 * {@see \App\Modules\Knowledge\Models\Scopes\WithoutDraftsScope} for why that is not a second table.
 *
 * Not soft-deleted. Abandoning a session is meant to leave NOTHING: the drafts are purged and the row
 * goes with them. Keeping a trash of unfinished machine output would be keeping the user's raw source
 * text around for no one's benefit.
 */
class KnowledgeDraftSession extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, TenantAware;

    protected $table = 'knowledge_draft_sessions';

    protected $fillable = [
        'knowledge_base_id',
        'source_text',
        'status',
        'claimed_at',
        'failure_reason',
        'prompt_history',
        'relations_cache',
        'retrieval_set',
        'notes',
        // The typed-relation stages (G3-G5) fill these; declared here with the column so the model and
        // the schema arrive together. `resolution_set` is what a run resolved its mentions against,
        // `graph_ops` what it PROPOSED, `applied_ops` what a human actually applied — the last being
        // what stops a second accept from applying the same operation twice.
        'resolution_set',
        'graph_ops',
        'applied_ops',
        'seed_slug',
        'seed_title',
        'context_expanded_at',
        'creator_id',
    ];

    protected $casts = [
        'status' => KnowledgeDraftSessionStatus::class,
        'prompt_history' => 'array',
        'relations_cache' => 'array',
        'retrieval_set' => 'array',
        'notes' => 'array',
        'resolution_set' => 'array',
        'graph_ops' => 'array',
        'applied_ops' => 'array',
        'claimed_at' => 'datetime',
        'context_expanded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function base(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    /**
     * This session's drafts, in the order the composer proposed them.
     *
     * `onlyDraftsOf()` rather than a plain `hasMany`: the relation would otherwise inherit the entry
     * model's global scope and return nothing at all, which is the correct default everywhere except
     * here.
     */
    public function drafts(): HasMany
    {
        return $this->hasMany(KnowledgeEntry::class, 'draft_session_id')
            ->withoutGlobalScope(\App\Modules\Knowledge\Models\Scopes\WithoutDraftsScope::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * The instructions asked for so far, newest last, capped for replay into the next prompt.
     *
     * @return array<int, array{at: string, instruction: string}>
     */
    public function instructions(): array
    {
        $history = is_array($this->prompt_history) ? $this->prompt_history : [];

        $clean = [];

        foreach ($history as $item) {
            if (is_array($item) && is_string($item['instruction'] ?? null) && $item['instruction'] !== '') {
                $clean[] = [
                    'at' => (string) ($item['at'] ?? ''),
                    'instruction' => (string) $item['instruction'],
                ];
            }
        }

        $max = max(1, (int) config('knowledge.drafting.max_prompt_history'));

        // The OLDEST are dropped: the recent instruction is the one being served, and an unbounded
        // history would be unbounded user text going into every later provider call.
        return count($clean) <= $max ? $clean : array_slice($clean, -$max);
    }

    /**
     * The FROZEN retrieval set: the existing entries this session's material may belong to, as they
     * read when the session opened. Normalized on read, because it is stored jsonb and everything that
     * consumes it (the prompt, the amendment allow-list, the revision token) must be able to trust its
     * shape.
     *
     * `truncated` says the composer was shown only PART of that entry, which downstream turns into
     * "this one may be appended to but never rewritten". It defaults to TRUE when absent, and that
     * default is the safe direction rather than the convenient one: a set frozen before the cap existed
     * carries an excerpt of unknown completeness, and letting a model rewrite a document it may not
     * have read is exactly the silent deletion the flag was introduced to stop. The cost of being
     * wrong the safe way is an append where a rewrite would have done; the cost of being wrong the
     * other way is a deleted document.
     *
     * @return array<int, array{slug: string, title: string, current_revision_id: ?string, excerpt: string, truncated: bool}>
     */
    public function retrievalSet(): array
    {
        $clean = [];

        foreach ($this->retrievalItems() as $item) {
            if (!is_array($item) || !is_string($item['slug'] ?? null) || $item['slug'] === '') {
                continue;
            }

            $clean[] = [
                'slug' => (string) $item['slug'],
                'title' => (string) ($item['title'] ?? ''),
                'current_revision_id' => is_string($item['current_revision_id'] ?? null)
                    ? $item['current_revision_id']
                    : null,
                'excerpt' => (string) ($item['excerpt'] ?? ''),
                'truncated' => (bool) ($item['truncated'] ?? true),
            ];
        }

        return $clean;
    }

    /**
     * THE ONE CONTEXT the composer reads — whichever pass produced it.
     *
     * Two passes can freeze entries for a session, and only one of them ever runs. Entity resolution
     * SUPERSEDES retrieval when it is enabled: both froze entry text and both paid for an embedding, so
     * running them together meant buying the same context twice and quoting the same entries twice into
     * one prompt. This method is the seam that makes that switch safe.
     *
     * Everything downstream reads THIS rather than either source, and the reason is a safety one. The
     * amendment ALLOW-LIST (a proposal may only target an entry the composer was actually shown) and
     * the TRUNCATED→APPEND rule (an entry shown in part may never be rewritten) are both derived from
     * this list. Had the consumers kept reading `retrievalSet()`, switching extraction on would have
     * emptied the allow-list — and an empty allow-list does not fail loudly, it silently degrades every
     * amendment into a new entry, which is precisely the duplicate-breeding failure the layer exists to
     * prevent.
     *
     * `handle` is present only for resolution entities; the retrieval path has none, and nothing that
     * reads this list requires one.
     *
     * @return array<int, array{slug: string, title: string, current_revision_id: ?string, excerpt: string, truncated: bool, handle: ?string}>
     */
    public function contextEntries(): array
    {
        $entities = $this->resolutionSet()['entities'];

        if ($entities === []) {
            return array_map(
                static fn (array $item): array => $item + ['handle' => null],
                $this->retrievalSet(),
            );
        }

        $clean = [];

        foreach ($entities as $entity) {
            if (!is_string($entity['slug'] ?? null) || $entity['slug'] === '') {
                continue;
            }

            $clean[] = [
                'slug' => (string) $entity['slug'],
                'title' => (string) ($entity['title'] ?? ''),
                'current_revision_id' => is_string($entity['current_revision_id'] ?? null)
                    ? $entity['current_revision_id']
                    : null,
                'excerpt' => (string) ($entity['content'] ?? ''),
                // Defaults TRUE for the same reason the retrieval set's does: an entry of unknown
                // completeness must never be rewritten.
                'truncated' => (bool) ($entity['truncated'] ?? true),
                'handle' => is_string($entity['handle'] ?? null) ? $entity['handle'] : null,
            ];
        }

        return $clean;
    }

    /** Titles the ACTIVE context pass could not fit — named in the prompt, never dropped in silence. */
    public function contextOmitted(): array
    {
        $resolution = $this->resolutionSet();

        return $resolution['entities'] === [] ? $this->retrievalOmitted() : $resolution['omitted'];
    }

    /**
     * The raw stored items, whichever shape they were frozen in.
     *
     * The column held a bare LIST before the omission marker needed somewhere to live; it now holds
     * `{items, omitted}`. Both are read here so a session frozen a minute before a deploy keeps
     * working instead of losing its context mid-review.
     *
     * @return array<int, mixed>
     */
    private function retrievalItems(): array
    {
        $set = is_array($this->retrieval_set) ? $this->retrieval_set : [];

        if (array_key_exists('items', $set)) {
            return is_array($set['items']) ? array_values($set['items']) : [];
        }

        return array_values($set);
    }

    /**
     * Titles of entries that cleared the relevance bar but did not fit the context budget.
     *
     * Named in the prompt rather than dropped in silence: a composer that believes the base says
     * nothing about a subject writes a second entry about it, which is the duplicate the whole
     * retrieval layer exists to prevent.
     *
     * @return array<int, string>
     */
    public function retrievalOmitted(): array
    {
        $set = is_array($this->retrieval_set) ? $this->retrieval_set : [];
        $omitted = $set['omitted'] ?? [];

        if (!is_array($omitted)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($title): string => is_string($title) ? $title : '', $omitted),
            static fn (string $title): bool => $title !== '',
        ));
    }

    /**
     * The FROZEN RESOLUTION: who and what the material is about, matched against entries that exist.
     *
     * Normalized on read for the same reason `retrievalSet()` is — it is stored jsonb, and everything
     * that consumes it (the review report, and from G4 the prompt and the graph operations) must be
     * able to trust its shape without re-checking every key.
     *
     * Frozen at session creation and NOT recomputed by a refinement, exactly like the retrieval set:
     * the handles `E1`/`R7` have to mean the same thing in every later pass, and re-resolving mid
     * session would silently renumber them underneath a proposal that already named one.
     *
     * @return array{entities: array<int, array<string, mixed>>, ambiguous: array<int, array<string, mixed>>, unresolved: array<int, array<string, mixed>>, omitted: array<int, string>, degraded: array<int, string>}
     */
    public function resolutionSet(): array
    {
        $set = is_array($this->resolution_set) ? $this->resolution_set : [];

        return [
            'entities' => $this->listOf($set['entities'] ?? null),
            'ambiguous' => $this->listOf($set['ambiguous'] ?? null),
            'unresolved' => $this->listOf($set['unresolved'] ?? null),
            'omitted' => $this->stringsOf($set['omitted'] ?? null),
            // Non-empty means the pass ran with less than its full apparatus, so "not found" means
            // "I did not look properly" rather than "it is not there".
            'degraded' => $this->stringsOf($set['degraded'] ?? null),
            // The reviewer's checklist. Absent from every session frozen before it existed, which is
            // why it normalises to an empty list rather than being assumed present.
            'facts' => $this->listOf($set['facts'] ?? null),
            // WHO the material is about — the subjects that must get an entry, named or not.
            'protagonists' => $this->listOf($set['protagonists'] ?? null),
        ];
    }

    /**
     * The fact list as the reviewer's checklist.
     *
     * COVERAGE IS NOT RESOLVED HERE YET. The model's `covers` claim is a property of the draft, and a
     * draft has nowhere to keep it — that needs a `covers` column on `knowledge_entries`, in both
     * migration trees, which is a change to the owner's live database and therefore his to approve.
     * Until then the claim lives only in memory, long enough for the run to launder it.
     *
     * NOR IS THE OTHER HALF REPORTED ANY MORE. This used to say that whatever nobody claimed came back
     * as `DraftRunNotes::FACTS_NOT_COVERED`; that accusation was withdrawn (ADR-0050 D1) after a
     * measured run where the model made no claims at all and the panel duly reported nine facts out of
     * nine as missing. So the list is published as what it is — the material's own events, as text,
     * source date and subjects — and the reader is the check.
     *
     * @return array<int, array<string, mixed>>
     */
    public function factChecklist(): array
    {
        $facts = $this->resolutionSet()['facts'];

        if ($facts === []) {
            return [];
        }

        // handle => the drafts claiming it. A LIST, not one entry: an episode between two people
        // belongs in BOTH their chronicles, and "Łukasz przeprosił influencerkę" is a single fact two
        // entries rightly cover. Reporting only the first would make a correct answer look partial.
        $claims = [];

        foreach ($this->drafts as $draft) {
            foreach (is_array($draft->covers) ? $draft->covers : [] as $handle) {
                if (is_string($handle)) {
                    $claims[$handle][] = [
                        'id' => (string) $draft->getKey(),
                        'slug' => (string) $draft->slug,
                        'title' => (string) $draft->title,
                    ];
                }
            }
        }

        return array_map(static function (array $fact) use ($claims): array {
            $id = (string) ($fact['id'] ?? '');

            return [
                'id' => $id,
                'text' => (string) ($fact['text'] ?? ''),
                // AS THE MATERIAL WROTE IT, never normalised — it is what a reviewer compares an
                // entry's dated line against.
                'date' => $fact['date'] ?? null,
                'subjects' => is_array($fact['subjects'] ?? null) ? $fact['subjects'] : [],
                'covered' => isset($claims[$id]),
                'covered_by' => $claims[$id] ?? [],
            ];
        }, $facts);
    }

    /** @return array<int, array<string, mixed>> */
    private function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($item): bool => is_array($item)));
    }

    /** @return array<int, string> */
    private function stringsOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($item): string => is_string($item) ? $item : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * WHAT THE RUN PROPOSED TO DO TO THE GRAPH, after laundering — plus what was refused and why.
     *
     * `rejected[]` and `warnings[]` are not diagnostics, they are half the product. A vocabulary gap
     * that silently swallows every `mentored` relation is never discovered; the same gap reported as
     * "3 operations dropped, type `mentored` is not in this base's vocabulary" is the evidence for
     * adding it. The client renders both — see the resource.
     *
     * @return array{entities: array<int, array<string, mixed>>, wiki_updates: array<int, array<string, mixed>>, graph_updates: array<int, array<string, mixed>>, unresolved: array<int, array<string, mixed>>, rejected: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    public function graphOps(): array
    {
        $ops = is_array($this->graph_ops) ? $this->graph_ops : [];

        return [
            'entities' => $this->listOf($ops['entities'] ?? null),
            'wiki_updates' => $this->listOf($ops['wiki_updates'] ?? null),
            'graph_updates' => $this->listOf($ops['graph_updates'] ?? null),
            'unresolved' => $this->listOf($ops['unresolved'] ?? null),
            'rejected' => $this->listOf($ops['rejected'] ?? null),
            'warnings' => $this->listOf($ops['warnings'] ?? null),
        ];
    }

    /**
     * The graph operations a human has already applied, as the keys a client addresses.
     *
     * The ledger holds two different kinds of row: `graph:<n>`, which names an operation the client can
     * select, and `entity:<n>=<uuid>`, which is internal bookkeeping recording WHICH entry a declared
     * handle became. Only the first kind is anybody's business outside this module, so only the first
     * kind is published — an id in that column is a database address, and the client has no use for it.
     *
     * @return array<int, string>
     */
    public function appliedGraphOpKeys(): array
    {
        $applied = is_array($this->applied_ops) ? $this->applied_ops : [];

        return array_values(array_filter(
            array_map(static fn ($key): string => is_string($key) ? $key : '', $applied),
            static fn (string $key): bool => preg_match('/^graph:\d+$/', $key) === 1,
        ));
    }

    /**
     * BOUND PAIRS among the proposed relation operations — a `create` and the `end` it replaces.
     *
     * A SYMMETRIC map of operation key => partner key, so both halves can state what they are tied to
     * without anybody re-deriving the relationship. Derived rather than stored: the binding lives in
     * the operations themselves, and two representations of one fact drift apart.
     *
     * @return array<string, string>
     */
    public function graphOpPairs(): array
    {
        $updates = $this->graphOps()['graph_updates'];
        $ends = [];
        $pairs = [];

        foreach ($updates as $index => $op) {
            if (($op['op'] ?? null) === 'end' && is_string($op['relation'] ?? null)) {
                $ends[$op['relation']] = 'graph:' . $index;
            }
        }

        foreach ($updates as $index => $op) {
            $replaces = is_string($op['replaces'] ?? null) ? $op['replaces'] : null;
            $partner = $replaces === null ? null : ($ends[$replaces] ?? null);

            if ($partner !== null) {
                $pairs['graph:' . $index] = $partner;
                $pairs[$partner] = 'graph:' . $index;
            }
        }

        return $pairs;
    }

    /**
     * What the SERVER did to the model's answer on the last run — see the `notes` migration.
     *
     * @return array<int, array<string, mixed>>
     */
    public function runNotes(): array
    {
        $notes = is_array($this->notes) ? $this->notes : [];

        return array_values(array_filter(
            $notes,
            static fn ($note): bool => is_array($note) && is_string($note['code'] ?? null) && $note['code'] !== '',
        ));
    }

    /** "Write the entry this red link points at" — exactly one draft, at this slug. */
    public function hasSeed(): bool
    {
        return is_string($this->seed_slug) && $this->seed_slug !== '';
    }

    protected static function newFactory()
    {
        return \Database\Factories\KnowledgeDraftSessionFactory::new();
    }
}
