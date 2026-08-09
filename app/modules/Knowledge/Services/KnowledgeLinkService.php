<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Support\EntryAliases;
use App\Modules\Knowledge\Support\SubjectPhrases;
use App\Modules\Knowledge\Support\WikilinkParser;

/**
 * Keeps the `[[wikilink]]` edges of the graph in step with what entries actually SAY.
 *
 * The content is the authority for wikilinks, which is why a save DELETES the entry's whole wikilink
 * set and re-inserts it rather than diffing. Diffing would be an optimisation over a handful of rows
 * that buys a whole class of drift bugs — an edge that survives the sentence that created it. The
 * unique key is scoped by `source`, so this wholesale replacement can never touch a `manual` edge a
 * human drew or a `similarity` edge the vector layer proposed.
 *
 * GHOSTS are the other half. A link to an entry that does not exist yet is stored with a null
 * `to_entry_id` and its `target_slug` intact, so the base can show what is missing; when an entry
 * with that slug appears, {@see attachGhosts()} adopts every ghost pointing at it. Resolution keys on
 * the slug — never on the title — which is exactly why slugs never follow a rename.
 *
 * Every method here is called INSIDE the calling service's transaction: an entry whose text mentions
 * a link and whose edges say otherwise is the one inconsistency this module cannot tolerate, because
 * both the UI and (later) the retrieval expansion read the edges, not the text.
 */
class KnowledgeLinkService
{
    public function __construct(
        private WikilinkParser $parser,
    ) {}

    /**
     * Replace the entry's `[[wikilink]]` edges with the ones its current content names. Targets are
     * resolved against the LIVE entries of the same base; anything unresolved is stored as a ghost.
     */
    public function syncWikilinks(KnowledgeEntry $entry): void
    {
        if ($entry->isDraft()) {
            // A DRAFT DRAWS NO EDGES. It is invisible everywhere, so its links would be rows leading
            // out of a node nobody can see — and a draft that links to a sibling draft would resolve to
            // nothing and be stored as a GHOST, permanently reporting a red link against a slug that is
            // about to exist. Acceptance re-runs this pass, at which point every endpoint is real.
            return;
        }

        $targets = $this->parser->targets($entry->content);

        KnowledgeLink::query()
            ->where('from_entry_id', $entry->getKey())
            ->ofSource(KnowledgeLinkSource::WIKILINK)
            ->delete();

        if ($targets === []) {
            return;
        }

        $resolved = KnowledgeEntry::query()
            ->where('knowledge_base_id', $entry->knowledge_base_id)
            ->whereIn('slug', $targets)
            ->pluck('id', 'slug');

        // AN INFLECTED TARGET IS CORRECTED TO THE CANONICAL ADDRESS, not resolved around it.
        //
        // Polish inflects, and a model writing `[[nowego-tokio]]` for the entry addressed `nowe-tokio`
        // produced a DEAD edge in the same sentence where the mention scanner — which does know the
        // alias — produced a live one. The same words, one green link and one red, with nothing to tell
        // a reader why.
        //
        // The address is repaired HERE, before the edge is stored, so the slug remains the one and only
        // address a link resolves by (ADR-0048): nothing resolves an alias at read time, because what
        // was written down is already canonical.
        $canonical = $this->canonicalise(
            (string) $entry->knowledge_base_id,
            array_values(array_diff($targets, $resolved->keys()->all())),
        );

        foreach ($targets as $slug) {
            $corrected = $canonical[$slug] ?? null;
            $slug = $corrected === null ? $slug : (string) $corrected->slug;

            KnowledgeLink::create([
                'knowledge_base_id' => $entry->knowledge_base_id,
                'from_entry_id' => $entry->getKey(),
                'to_entry_id' => $corrected?->getKey() ?? $resolved->get($slug),
                'target_slug' => $slug,
                'source' => KnowledgeLinkSource::WIKILINK->value,
            ]);
        }
    }

    /**
     * Unresolved targets that are really an ALIAS of a live entry, mapped to that entry.
     *
     * Consulted ONLY for a target no slug matched, which makes the precedence rule automatic rather
     * than something this method has to enforce: A SLUG ALWAYS WINS. Where one entry's alias slugifies
     * to another entry's address, the address is what the link means — the writer typed the thing that
     * IS an identifier, and re-pointing it at somebody else's alias would move a live link onto a
     * different subject.
     *
     * @param  array<int, string>  $unresolved
     * @return array<string, KnowledgeEntry>
     */
    private function canonicalise(string $baseId, array $unresolved): array
    {
        if ($unresolved === []) {
            return [];
        }

        // Aliases live in a jsonb column, so the comparison is made in PHP over the base's entries that
        // have any. Bounded by EntryAliases::MAX per row, and only reached when a link was otherwise
        // going to be a ghost — the costly case is already the rare one.
        $found = [];

        $candidates = KnowledgeEntry::query()
            ->where('knowledge_base_id', $baseId)
            ->whereNotNull('aliases')
            ->get(['id', 'slug', 'aliases']);

        foreach ($candidates as $candidate) {
            foreach (EntryAliases::normalize($candidate->aliases) as $alias) {
                $slugified = WikilinkParser::normalize($alias);

                if ($slugified !== '' && in_array($slugified, $unresolved, true) && !isset($found[$slugified])) {
                    $found[$slugified] = $candidate;
                }
            }
        }

        return $found;
    }

    /**
     * Adopt every unresolved edge in the base that was waiting for this entry's slug. Called when an
     * entry is created, restored, or renamed — the three moments a slug starts existing.
     */
    public function attachGhosts(KnowledgeEntry $entry): int
    {
        if ($entry->isDraft()) {
            // A DRAFT ADOPTS NO GHOSTS, and this is the sharper half of the rule. Adoption writes into
            // OTHER entries' rows: a real entry's red `[[cennik]]` would silently resolve to an
            // invisible draft, so its backlink panel would point at something the reader cannot open
            // and the base's red-link count would drop for work nobody has accepted. A shadow's
            // reserved `__shadow-…` slug makes this unreachable for shadows; an ordinary draft borrows
            // a real slug and would hit it immediately.
            return 0;
        }

        // BY SLUG **OR** BY ANY OF THIS ENTRY'S ALIASES, slugified.
        //
        // A ghost points at nothing, so adopting one loses nothing — and the ghosts an inflected link
        // left behind (`nowego-tokio` for the entry addressed `nowe-tokio`) are exactly the ones a
        // reader sees as red while the identical words elsewhere render green.
        //
        // This is also what repairs data written before the correction above existed: the addresses
        // start existing as far as adoption is concerned the moment the entry declares the alias, which
        // is the same rule the slug has always followed.
        $addresses = [(string) $entry->slug];

        foreach (EntryAliases::normalize($entry->aliases) as $alias) {
            $slugified = WikilinkParser::normalize($alias);

            if ($slugified !== '' && !in_array($slugified, $addresses, true)) {
                $addresses[] = $slugified;
            }
        }

        return KnowledgeLink::query()
            ->where('knowledge_base_id', $entry->knowledge_base_id)
            ->whereIn('target_slug', $addresses)
            // A SLUG STILL WINS: an address that is a live entry's own slug is not this entry's to
            // adopt, however its aliases happen to slugify.
            ->when(count($addresses) > 1, fn ($query) => $query->whereNotIn(
                'target_slug',
                KnowledgeEntry::query()
                    ->where('knowledge_base_id', $entry->knowledge_base_id)
                    ->whereKeyNot($entry->getKey())
                    ->whereIn('slug', $addresses)
                    ->select('slug'),
            ))
            ->ghost()
            ->update(['to_entry_id' => $entry->getKey()]);
    }

    /**
     * Degrade every edge pointing AT this entry back to a ghost. Deleting them instead would erase
     * the writer's intent along with the target: the sentence in the other entry still says
     * `[[this-thing]]`, and a base that forgets that cannot tell anyone what is now missing.
     */
    public function degradeIncomingToGhosts(KnowledgeEntry $entry): int
    {
        return KnowledgeLink::query()
            ->where('to_entry_id', $entry->getKey())
            ->update(['to_entry_id' => null]);
    }

    /** Drop every edge this entry DRAWS (any source) — used when the entry itself is destroyed. */
    public function deleteOutgoing(KnowledgeEntry $entry): int
    {
        return KnowledgeLink::query()
            ->where('from_entry_id', $entry->getKey())
            ->delete();
    }

    /**
     * Delete the edges pointing AT this entry whose `target_slug` names an erasure subject, INSTEAD of
     * degrading them to ghosts.
     *
     * The single, deliberate exception to {@see degradeIncomingToGhosts()}. A slug is normally a
     * neutral pointer worth preserving — but `[[anna-kowalska]]` is the person's name, and a ghost
     * renders it as a "missing entry" chip on every page that links to it. Honouring an erasure
     * request by degrading those links would leave the base naming the subject in MORE places than
     * before it ran. Every other inbound edge still degrades normally, so the rule is narrowed, not
     * abandoned.
     *
     * Called only from the purge path, before the degradation, so the two never fight over a row.
     */
    public function deleteIncomingMatching(KnowledgeEntry $entry, SubjectPhrases $phrases): int
    {
        $query = KnowledgeLink::query()->where('to_entry_id', $entry->getKey());

        // Fails closed on an empty phrase set (`1 = 0`), so this can never widen into "delete every
        // inbound link".
        $phrases->whereMatchesSlug($query, 'target_slug');

        return $query->delete();
    }

    /**
     * Record that a human rejected a machine-proposed edge.
     *
     * A STAMP, never a delete. The similarity linker replaces an entry's proposed edges on every
     * re-index, so a rejection expressed by removing the row would be undone by the next edit of any
     * paragraph — and a suggestion that keeps coming back after being refused is worse than one that
     * was never made. The linker reads these rows and SKIPS their targets, which is the whole
     * mechanism (see {@see KnowledgeSimilarityLinker}).
     *
     * Idempotent: dismissing an already-dismissed edge keeps the ORIGINAL timestamp, so "when did we
     * decide this" survives a double click.
     */
    public function dismiss(KnowledgeLink $link): KnowledgeLink
    {
        if ($link->dismissed_at === null) {
            $link->forceFill(['dismissed_at' => now()])->save();
        }

        return $link->refresh();
    }

    /** Undo a rejection. The edge returns with the score and evidence it already had. */
    public function undismiss(KnowledgeLink $link): KnowledgeLink
    {
        if ($link->dismissed_at !== null) {
            $link->forceFill(['dismissed_at' => null])->save();
        }

        return $link->refresh();
    }
}
