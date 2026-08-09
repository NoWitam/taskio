<?php

namespace Tests\Concerns;

use App\Modules\Knowledge\DTOs\KnowledgeEntryDTO;
use App\Modules\Knowledge\DTOs\KnowledgeRelationDTO;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeRelationOrigin;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Services\KnowledgeEntryService;
use App\Modules\Knowledge\Services\KnowledgeRelationService;
use Illuminate\Support\Carbon;

/**
 * Knowledge fixtures built through the SERVICES, because there is no HTTP way to make one.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY THIS EXISTS
 *
 * Almost every test in this module used to build its world by POSTing to `/knowledge/entries` and
 * `/knowledge/relations`. Those endpoints are gone: entries and relations are written by the composer
 * and published by a human's acceptance, and nothing else may author them. The tests were not testing
 * those endpoints — they were using them as a convenient constructor — so they need a constructor.
 *
 * Going through the SERVICE rather than a factory is deliberate. The service is what the composer and
 * the applier call, so a fixture built here is a real entry: its slug is minted and de-collided, its
 * first revision is appended, its wikilinks are parsed, its ghosts are adopted and its index state is
 * stamped. A factory row skips all of that, and half this module's behaviour hangs off it.
 *
 * A fixture is `approved` by default because that is what an entry looks like once a reviewer has
 * accepted it, which is the state most tests mean when they say "the base already contains X".
 */
trait CreatesKnowledgeFixtures
{
    /** @param  array<int, string>  $aliases */
    protected function makeEntry(
        KnowledgeBase $base,
        string $title,
        string $content = 'Tresc wpisu.',
        ?KnowledgeEntryType $type = null,
        ?string $slug = null,
        KnowledgeEntryStatus $status = KnowledgeEntryStatus::APPROVED,
        array $aliases = [],
        ?Carbon $staleAt = null,
        array $metadata = [],
    ): KnowledgeEntry {
        return app(KnowledgeEntryService::class)->create($base, new KnowledgeEntryDTO(
            title: $title,
            content: $content,
            metadata: $metadata,
            status: $status,
            staleAt: $staleAt,
            slug: $slug,
            aliases: $aliases,
            entryType: $type,
        ));
    }

    /**
     * Change an entry the way the composer's acceptance path changes one.
     *
     * The removed `UpdateKnowledgeEntryRequest` was what turned a PATCH into a whole resulting state
     * ("absent means unchanged") before the DTO was built. That resolution has to live somewhere for a
     * test to express "only the content moved", so it lives here — a null argument means "leave it as
     * it is", exactly as an absent key did, and the DTO still receives the entry's complete resulting
     * state, which is the contract {@see KnowledgeEntryService::update()} has always been given.
     *
     * @param  array<string, mixed>|null  $metadata
     * @param  array<int, string>|null  $aliases
     */
    protected function updateEntry(
        KnowledgeEntry $entry,
        ?string $title = null,
        ?string $content = null,
        ?array $metadata = null,
        ?KnowledgeEntryStatus $status = null,
        ?string $slug = null,
        ?array $aliases = null,
        ?KnowledgeEntryType $type = null,
        ?Carbon $staleAt = null,
        ?string $changeNote = null,
        ?string $expectedRevisionId = null,
    ): KnowledgeEntry {
        return app(KnowledgeEntryService::class)->update($entry, new KnowledgeEntryDTO(
            title: $title ?? (string) $entry->title,
            content: $content ?? (string) $entry->content,
            metadata: $metadata ?? (is_array($entry->metadata) ? $entry->metadata : []),
            status: $status ?? $entry->status,
            staleAt: $staleAt ?? $entry->stale_at,
            // Null is "no rename": the slug never follows a title, so only an explicit value moves it.
            slug: $slug,
            changeNote: $changeNote,
            expectedRevisionId: $expectedRevisionId,
            aliases: $aliases ?? (is_array($entry->aliases) ? $entry->aliases : []),
            entryType: $type ?? $entry->entry_type,
        ));
    }

    /** Soft delete, as the base cascade and the erasure command do it. */
    protected function trashEntry(KnowledgeEntry $entry): void
    {
        app(KnowledgeEntryService::class)->delete($entry);
    }

    /** @throws \App\Modules\Knowledge\Exceptions\KnowledgeSlugConflictException */
    protected function restoreEntry(KnowledgeEntry $entry): KnowledgeEntry
    {
        return app(KnowledgeEntryService::class)->restore($entry);
    }

    /** Irreversible destruction — the erasure command's path. */
    protected function purgeEntry(KnowledgeEntry $entry): void
    {
        app(KnowledgeEntryService::class)->purge($entry);
    }

    /**
     * A relation as the applier writes one — `composer` origin, because that is now the only origin a
     * new relation can have.
     *
     * @param  array<string, mixed>  $properties
     */
    protected function makeRelation(
        KnowledgeBase $base,
        KnowledgeEntry $from,
        KnowledgeEntry $to,
        KnowledgeRelationType $type = KnowledgeRelationType::KNOWS,
        ?string $description = null,
        array $properties = [],
        ?Carbon $validFrom = null,
        ?Carbon $validTo = null,
    ): KnowledgeRelation {
        return app(KnowledgeRelationService::class)->create($base, new KnowledgeRelationDTO(
            fromEntryId: (string) $from->getKey(),
            toEntryId: (string) $to->getKey(),
            type: $type,
            description: $description,
            properties: $properties,
            validFrom: $validFrom,
            validTo: $validTo,
            origin: KnowledgeRelationOrigin::COMPOSER,
        ));
    }

    /** This stopped being true — the applier's `end` operation. */
    protected function endRelation(KnowledgeRelation $relation, ?Carbon $validTo = null): KnowledgeRelation
    {
        return app(KnowledgeRelationService::class)->end($relation, $validTo);
    }

    /** This was never true. Keeps the row; there is no HTTP path to it any more. */
    protected function retractRelation(KnowledgeRelation $relation): KnowledgeRelation
    {
        return app(KnowledgeRelationService::class)->retract($relation);
    }

    /** This was replaced by that — the applier's `replaces` binding. */
    protected function supersedeRelation(KnowledgeRelation $relation, KnowledgeRelation $successor): KnowledgeRelation
    {
        return app(KnowledgeRelationService::class)->supersede($relation, $successor);
    }

    /** @param  array<string, mixed>|null  $properties */
    protected function updateRelation(
        KnowledgeRelation $relation,
        ?string $description = null,
        ?array $properties = null,
        ?Carbon $validFrom = null,
        ?Carbon $validTo = null,
    ): KnowledgeRelation {
        return app(KnowledgeRelationService::class)->update(
            $relation,
            $description ?? $relation->description,
            $properties,
            $validFrom ?? $relation->valid_from,
            $validTo ?? $relation->valid_to,
        );
    }

    /** Destroy the row. Reachable from the erasure command only — see the service's docblock. */
    protected function deleteRelation(KnowledgeRelation $relation): void
    {
        app(KnowledgeRelationService::class)->delete($relation);
    }
}
