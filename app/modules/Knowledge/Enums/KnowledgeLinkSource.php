<?php

namespace App\Modules\Knowledge\Enums;

/**
 * WHERE a knowledge link came from. It is part of the link's unique key
 * ([from_entry_id, target_slug, source]) because the three kinds have different OWNERS and must not
 * overwrite one another:
 *
 *   wikilink    parsed from the source entry's content. The CONTENT is the authority, so the whole
 *               wikilink set of an entry is deleted and re-inserted on every save.
 *   similarity  proposed by the vector layer (B2a/B2b) with a `score` and `evidence`. Machine-owned:
 *               a re-index may withdraw it, and `dismissed_at` records a human saying "no".
 *   mention     the source entry's prose NAMES the target's title, found by
 *               {@see \App\Modules\Knowledge\Support\MentionScanner} (B10). Machine-owned like
 *               similarity, but derived from WORDS rather than from vectors — deterministic, free, and
 *               explainable ("you wrote its name here"). Carries no score; its `evidence` is the
 *               `{char_start, char_length}` of the first mention.
 *   manual      drawn by a human in the UI. Nothing automated may remove it.
 *
 * Mixing them under one key would mean a save of the source entry silently deleting a human's manual
 * edge, or a re-index deleting a written `[[wikilink]]`.
 *
 * WIKILINK AND MENTION ARE MUTUALLY EXCLUSIVE per target, and the wikilink wins: writing `[[x]]` is an
 * author's explicit commitment, while a mention is an inference about the same sentence. Two edges
 * saying the same thing with different confidence would just be a duplicate on screen. Similarity may
 * coexist with either — "these two documents are about the same thing" is a different claim from
 * "this document names that one".
 */
enum KnowledgeLinkSource: string
{
    case WIKILINK = 'wikilink';
    case SIMILARITY = 'similarity';
    case MENTION = 'mention';

    /**
     * RESERVED — READ, NEVER WRITTEN. No endpoint creates a manual link.
     *
     * "A human draws an edge" was superseded as a product feature by the TYPED RELATION (G2), which
     * records what an edge MEANS rather than merely that somebody drew it. The case is kept rather
     * than deleted for one concrete reason: {@see \App\Modules\Knowledge\Services\KnowledgeMentionLinker}
     * already suppresses a mention wherever a wikilink OR a manual edge exists, so the precedence rule
     * is complete for the day one is written — and removing the case would silently narrow that query
     * to wikilinks alone. Nothing should be built on top of it without a write path landing first.
     */
    case MANUAL = 'manual';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether a human may DISMISS this kind of edge — i.e. whether it was PROPOSED rather than
     * written. A wikilink is what the text says (edit the text); a manual edge was drawn on purpose
     * (delete it). Both machine-derived kinds are dismissible, and a dismissal survives re-indexing.
     */
    public function isDismissable(): bool
    {
        return match ($this) {
            self::SIMILARITY, self::MENTION => true,
            self::WIKILINK, self::MANUAL => false,
        };
    }
}
