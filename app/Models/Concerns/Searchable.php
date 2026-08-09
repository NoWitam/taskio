<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait Searchable
{
    /**
     * Case-insensitive partial-match search across one or more columns.
     * The term is supplied by the caller (not read from the request).
     * No-ops when the term is null or blank, so it can be chained
     * unconditionally. Matched columns are OR-grouped to keep combination
     * with surrounding filters safe.
     *
     * THE TERM IS A LITERAL, NOT A PATTERN. Everything a user types is escaped before it is wrapped
     * in the surrounding `%…%`, so a search means itself and nothing else. Left unescaped, `%` alone
     * matched every row in every list in the application, and `raport_2026` also matched
     * `raport-2026`, `raport 2026` and `raportX2026` — a wildcard nobody asked for, nothing
     * advertised in the UI, and nothing in the codebase depended on.
     *
     * ORDER IS LOAD-BEARING: the backslash goes first. Escaping `%` and `_` before the backslash
     * re-escapes the backslashes just inserted, turning `\_` into `\\_` — which a LIKE engine reads as
     * "a literal backslash, then a wildcard". The pattern then demands a backslash that is not in the
     * data, so the search silently returns NOTHING for any term containing `\`, `%` or `_`. It fails
     * quietly and in the opposite direction to the bug above, which is why it is worth naming here:
     * `SearchableWildcardTest` catches it on a POSITIVE assertion, not a negative one.
     *
     * THIS RELIES ON THE DRIVER'S DEFAULT LIKE ESCAPE CHARACTER, and does NOT emit an explicit
     * `ESCAPE` clause. Postgres and MySQL both default to backslash, so a bound `\%` is a literal
     * per cent on both. SQLite has NO default escape character and would read `\%` as a literal
     * backslash followed by a wildcard — under-matching rather than over-matching, so it fails in
     * the safe direction, and it is not a driver this application runs on: the central connection is
     * pgsql, and `WorkspaceProvisioner` REFUSES to provision an own-database workspace on sqlite
     * (pinned by `WorkspaceProvisionerTest`). The alternative — a raw `like ? escape '\'` fragment —
     * would buy correctness on a driver we refuse, at the cost of hand-rolling the case-insensitivity
     * that `orWhereLike()` picks per driver (`ilike` on Postgres) and of putting a raw SQL fragment
     * behind all 19 call sites. Not worth it. `SubjectPhrases` in the Knowledge module leans on the
     * same default for the same reason.
     *
     * A JSON COLUMN IS NOT PROSE, AND THIS SCOPE DOES NOT KNOW THE DIFFERENCE. Eloquent's `array`
     * cast writes JSON with escaped Unicode, so a term must be re-spelled into that encoding BEFORE
     * it reaches this scope — see `FormSubmissionService::asStoredJsonText()`, the one caller that
     * needs it. Do not move that encoding in here: the other 18 call sites search plain text columns
     * holding raw UTF-8, and encoding their terms would break them the way the JSON one was broken.
     *
     * @param  bool  $allowWildcards  Pass the term through UNESCAPED, restoring the old behaviour
     *                                where `%` and `_` are live wildcards. DELIBERATELY UNUSED TODAY —
     *                                there is not a single call site passing true, and that is the
     *                                point. It is the seam for a future "advanced search" (an operator
     *                                syntax, a power-user filter box) that wants to hand wildcards
     *                                through on purpose, kept here so that feature extends one scope
     *                                instead of re-hand-rolling `whereLike` in a service and
     *                                re-opening the hole above. IT IS NOT DEAD CODE TO BE SWEPT UP BY
     *                                THE NEXT UNUSED-PARAMETER CLEANUP — its behaviour is pinned by
     *                                `SearchableWildcardTest`, and deleting it deletes a decision.
     *                                Any caller that sets it must sanitise its own input.
     */
    public function scopeSearch(Builder $query, array|string $columns, ?string $term, bool $allowWildcards = false): void
    {
        $term = is_string($term) ? trim($term) : '';

        if ($term === '') {
            return;
        }

        $pattern = '%' . ($allowWildcards ? $term : self::escapeLikeWildcards($term)) . '%';

        $query->where(function (Builder $query) use ($columns, $pattern) {
            foreach ((array) $columns as $column) {
                $query->orWhereLike($column, $pattern);
            }
        });
    }

    /** Neutralise the two LIKE wildcards and the escape character itself. Backslash FIRST — see above. */
    private static function escapeLikeWildcards(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
