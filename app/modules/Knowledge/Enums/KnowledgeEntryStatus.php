<?php

namespace App\Modules\Knowledge\Enums;

/**
 * The EDITORIAL state of a knowledge entry — how much a reader (human or AI) should trust it.
 *
 * The set is small and closed on purpose. A knowledge base is only useful if "what does the company
 * say about X" has ONE answer, so the states describe a single line from "being written" to "no
 * longer true", with no parallel branches to reconcile:
 *
 *   draft      being written; not authoritative yet.
 *   proposed   finished and awaiting a human blessing.
 *   approved   the workspace stands behind it.
 *   archived   kept for history, deliberately no longer current.
 *
 * B1 stores and filters the status; it does NOT gate anything on it (no workflow, no approval
 * pipeline, no retrieval filter). That is deliberate — the vocabulary has to exist before the
 * consumers (B6) can decide which states they read, and inventing the gate first would freeze a
 * policy nobody has needed yet.
 */
enum KnowledgeEntryStatus: string
{
    case DRAFT = 'draft';
    case PROPOSED = 'proposed';
    case APPROVED = 'approved';
    case ARCHIVED = 'archived';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }
}
