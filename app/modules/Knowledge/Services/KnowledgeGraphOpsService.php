<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Support\GraphOpsContext;
use App\Modules\Knowledge\Support\KnowledgeGraphOps;
use App\Modules\Knowledge\Support\RelationVocabulary;
use App\Modules\Knowledge\Support\TemplateDirectiveGuard;

/**
 * The bridge between a session's FROZEN RESOLUTION and the laundering of what the composer proposed to
 * do to the graph.
 *
 * It exists to keep {@see KnowledgeGraphOps::fromArray()} a PURE function. That method is the security
 * boundary for a model-written object, and a boundary that queries as it goes is one whose verdict
 * depends on when it ran — untestable without a database and, worse, capable of disagreeing with
 * itself between two runs over the same answer. So the facts are gathered here, once, and handed over
 * as a value.
 *
 * NOTHING IS WRITTEN. The laundered result is stored on the session as a PROPOSAL (`graph_ops`) and
 * applied — if a human accepts it — by the ordinary relation service, which re-checks every rule from
 * scratch. That double check is deliberate: the proposal may sit on a reviewer's screen for an hour,
 * and the base can move underneath it.
 */
class KnowledgeGraphOpsService
{
    public function __construct(
        private TemplateDirectiveGuard $guard,
    ) {}

    /**
     * Launder the `graph_updates`/`wiki_updates`/`entities` sections of one composer answer.
     *
     * @param  array<string, mixed>  $decoded  the whole decoded reply
     */
    public function launder(KnowledgeDraftSession $session, KnowledgeBase $base, array $decoded): KnowledgeGraphOps
    {
        // OFF means off: no context is built, no ops are read, and the stored column stays empty.
        if (!config('knowledge.graph_extraction.enabled')) {
            return KnowledgeGraphOps::none();
        }

        return KnowledgeGraphOps::fromArray(
            [
                'entities' => $decoded['entities'] ?? null,
                'wiki_updates' => $decoded['wiki_updates'] ?? null,
                'graph_updates' => $decoded['graph_updates'] ?? null,
                'unresolved' => $decoded['unresolved'] ?? null,
            ],
            $this->context($session, $base),
            $this->guard,
        );
    }

    /**
     * Everything the launderer is allowed to believe, read from the database ONCE.
     *
     * The entity map is built from the FROZEN resolution rather than re-resolved, which is what makes a
     * handle mean the same thing here as it did in the prompt. Re-resolving would let `E3` name one
     * entity in the question and another in the answer.
     */
    public function context(KnowledgeDraftSession $session, KnowledgeBase $base): GraphOpsContext
    {
        $resolution = $session->resolutionSet();

        $entities = [];
        $relations = [];
        $slugs = [];

        foreach ($resolution['entities'] as $entity) {
            $handle = is_string($entity['handle'] ?? null) ? $entity['handle'] : null;
            $slug = is_string($entity['slug'] ?? null) ? $entity['slug'] : null;

            if ($handle === null || $slug === null) {
                continue;
            }

            $slugs[$handle] = $slug;

            foreach (is_array($entity['relations'] ?? null) ? $entity['relations'] : [] as $relation) {
                if (is_string($relation['handle'] ?? null) && is_string($relation['type'] ?? null)) {
                    // The frozen ID rides along. A relation listed under both of its ends arrives here
                    // twice with one handle and one id, so writing it twice is idempotent.
                    $relations[$relation['handle']] = [
                        'type' => $relation['type'],
                        'id' => is_string($relation['id'] ?? null) ? $relation['id'] : '',
                    ];
                }
            }
        }

        // ONE query for the entries the handles stand for. Resolved by SLUG through the base's own
        // relation — an entry that has been trashed or renamed since the freeze simply is not found,
        // and its handle then behaves exactly like an invented one: the operation is dropped and
        // reported, which is the honest outcome for a proposal about something that no longer exists.
        $rows = $slugs === [] ? collect() : KnowledgeEntry::query()
            ->where('knowledge_base_id', $base->getKey())
            ->whereIn('slug', array_values($slugs))
            ->get(['id', 'slug', 'title', 'content', 'entry_type'])
            ->keyBy('slug');

        $byId = [];

        foreach ($resolution['entities'] as $entity) {
            $handle = is_string($entity['handle'] ?? null) ? $entity['handle'] : null;
            $row = $handle === null ? null : $rows->get($slugs[$handle] ?? '');

            if ($handle === null || $row === null) {
                continue;
            }

            $entities[$handle] = [
                'id' => (string) $row->getKey(),
                'slug' => (string) $row->slug,
                'title' => (string) $row->title,
                'entry_type' => $row->entry_type instanceof KnowledgeEntryType ? $row->entry_type : null,
                // The TRUNCATION FLAG comes from the freeze, not from the row: what matters is how much
                // the composer was SHOWN, and re-deriving it from the live text would let an entry that
                // shrank since become rewritable by a model that never read it whole.
                'truncated' => (bool) ($entity['truncated'] ?? true),
                // THE REVISION THE COMPOSER READ — also from the freeze, for the sharper version of the
                // same reason. This is the optimistic-lock token a rewrite is judged against, so taking
                // it from the LIVE row would compare the entry against itself and pass every time: a
                // lock that exists and can never fire.
                'revision' => is_string($entity['current_revision_id'] ?? null) ? $entity['current_revision_id'] : null,
                // The LIVE text, because the link guard asks what a rewrite would destroy NOW.
                'content' => (string) $row->content,
            ];

            $byId[] = (string) $row->getKey();
        }

        return new GraphOpsContext(
            entities: $entities,
            relations: $this->relationHandles($relations, $byId),
            allowedTypes: RelationVocabulary::for($base),
            existing: $this->activeRelations($byId),
            ambiguous: $this->ambiguousCandidates($resolution['ambiguous']),
        );
    }

    /**
     * Attach the live rows to the `R<n>` handles the freeze minted.
     *
     * BY ID, from the frozen set, re-checked against what is ACTIVE now — a relation the composer was
     * shown may since have been ended, in which case its handle must stop resolving rather than address
     * a statement nobody is asserting any more.
     *
     * It used to match by TYPE and ORDINAL: the n-th handle of a type was assumed to be the n-th live
     * relation of that type, on the theory that the freeze numbered them the same way. It did not — the
     * freeze numbers per entity, and a relation with both ends in the set was listed twice — so the two
     * sequences drifted apart the moment a base held two active relations of one type, and the handle
     * silently addressed the wrong edge. An identifier that has to be re-derived is not an identifier.
     *
     * @param  array<string, array{type: string, id: string}>  $handles
     * @param  array<int, string>  $entryIds
     * @return array<string, array{id: string, type: string, from_entry_id: string, to_entry_id: string}>
     */
    private function relationHandles(array $handles, array $entryIds): array
    {
        $ids = array_values(array_filter(array_column($handles, 'id')));

        if ($handles === [] || $entryIds === [] || $ids === []) {
            return [];
        }

        $live = KnowledgeRelation::query()
            ->current()
            ->whereKey($ids)
            // The entity guard stays. A frozen id must still belong to the set this session resolved,
            // so a set carried over from elsewhere cannot address an edge outside it.
            ->where(fn ($query) => $query->whereIn('from_entry_id', $entryIds)->orWhereIn('to_entry_id', $entryIds))
            ->get(['id', 'relation_type', 'from_entry_id', 'to_entry_id'])
            ->keyBy(fn (KnowledgeRelation $relation): string => (string) $relation->getKey());

        $resolved = [];

        foreach ($handles as $handle => $meta) {
            $relation = $live->get((string) ($meta['id'] ?? ''));

            if ($relation === null) {
                continue; // ended, deleted, or frozen before ids were carried — the handle stops resolving
            }

            $resolved[$handle] = [
                'id' => (string) $relation->getKey(),
                'type' => (string) $relation->relation_type?->value,
                'from_entry_id' => (string) $relation->from_entry_id,
                'to_entry_id' => (string) $relation->to_entry_id,
            ];
        }

        return $resolved;
    }

    /**
     * The ACTIVE relations touching these entries, in the shape the duplicate guard compares against.
     *
     * @param  array<int, string>  $entryIds
     * @return array<int, array{from: string, to: string, type: string, valid_from: ?string, id: string}>
     */
    private function activeRelations(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        return KnowledgeRelation::query()
            ->current()
            ->where(fn ($query) => $query->whereIn('from_entry_id', $entryIds)->orWhereIn('to_entry_id', $entryIds))
            ->get(['id', 'relation_type', 'from_entry_id', 'to_entry_id', 'valid_from'])
            ->map(static fn (KnowledgeRelation $relation): array => [
                'id' => (string) $relation->getKey(),
                'from' => (string) $relation->from_entry_id,
                'to' => (string) $relation->to_entry_id,
                'type' => (string) ($relation->relation_type?->value ?? ''),
                'valid_from' => $relation->valid_from?->toDateString(),
            ])
            ->all();
    }

    /**
     * Ambiguous mentions keyed by their text, with the handles that could answer them.
     *
     * @param  array<int, array<string, mixed>>  $ambiguous
     * @return array<string, array<int, string>>
     */
    private function ambiguousCandidates(array $ambiguous): array
    {
        $map = [];

        foreach ($ambiguous as $item) {
            $text = is_string($item['text'] ?? null) ? $item['text'] : null;

            if ($text === null) {
                continue;
            }

            $map[$text] = array_values(array_filter(array_map(
                static fn ($candidate): string => is_array($candidate) && is_string($candidate['handle'] ?? null)
                    ? $candidate['handle']
                    : '',
                is_array($item['candidates'] ?? null) ? $item['candidates'] : [],
            )));
        }

        return $map;
    }
}
