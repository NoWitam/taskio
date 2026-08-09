<?php

namespace App\Modules\Knowledge\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * The literal phrases an ERASURE REQUEST is expressed with, plus the only sanctioned way to turn them
 * into a query predicate.
 *
 * Deliberately DUMB. There is no stemming, no lemmatisation and no AI: an operator honouring a
 * "delete everything about Anna Kowalska" request supplies the inflected variants themselves
 * ("Kowalska", "Kowalskiej", "Kowalską"), and sees exactly what each one hit before anything is
 * deleted. A clever matcher would be the worst possible component here — its false positives destroy
 * unrelated knowledge irreversibly and its false negatives leave the request unfulfilled, and neither
 * is visible to the person reviewing the dry run.
 *
 * ESCAPING is the load-bearing detail. The phrases go into `ILIKE` patterns, where `%` and `_` are
 * WILDCARDS — so an unescaped underscore in "anna_k" would silently match "annaXk" and an unescaped
 * `%` would match everything after it. Every phrase is escaped with the Postgres default LIKE escape
 * character (backslash) before the surrounding `%…%` is added, so a phrase always means itself and
 * nothing else.
 *
 * ESCAPING IS NO LONGER WHY THIS BYPASSES THE SHARED SCOPE. `Searchable` used to pass its term
 * through unescaped, and that difference alone justified hand-rolling the predicate here; the shared
 * scope now escapes `\`, `%` and `_` by default, so the two agree on what a literal means and this
 * class is no longer compensating for it. The escaping below stays regardless — it is the same
 * neutralisation, applied where the cost of getting it wrong is an unrelated entry's existence rather
 * than one extra glance, and it must not depend on a scope elsewhere continuing to behave.
 *
 * WHAT STILL KEEPS THIS OUT OF THE SHARED SCOPE is everything else it does, none of which `Searchable`
 * offers: MANY phrases OR-ed together rather than one term; JSONB columns compared as `::text`; slug
 * columns matched in both raw and slugified form, inside the SAME `or` group; and — the one that
 * matters most — FAILING CLOSED on an empty phrase set, where the shared scope deliberately NO-OPS.
 * A no-op is right for a list filter and catastrophic here, because an unconstrained query is one the
 * command would offer to delete everything from. Everything hand-rolled lives HERE, in one class,
 * rather than being spread across the services.
 *
 * FAIL CLOSED. An empty phrase set produces `1 = 0`, never an unconstrained query. That inversion is
 * what stands between "the operator typed a blank argument" and "the command matched, and offered to
 * delete, the entire knowledge base".
 *
 * NEVER LOGGED. A phrase IS the personal data the request is about, so it must not reach the
 * application log under any circumstance (see the purge command). {@see __debugInfo()} redacts it so
 * even a `dd()`, a `var_dump()` or an exception dump of a surrounding object cannot spill it; the
 * OPERATOR'S REPORT is the one artefact that carries phrases, because that report is the evidence
 * that the request was honoured and it never leaves the terminal it was printed in.
 */
final class SubjectPhrases
{
    /** @param  list<string>  $phrases */
    private function __construct(
        private readonly array $phrases,
    ) {}

    /**
     * Normalise raw console arguments into a phrase set: trimmed, blanks dropped, duplicates removed
     * case-insensitively (an operator repeating "Kowalska Kowalska" should not double every OR term).
     *
     * @param  array<int, mixed>  $raw
     */
    public static function fromInput(array $raw): self
    {
        $phrases = [];
        $seen = [];

        foreach ($raw as $phrase) {
            if (!is_string($phrase)) {
                continue;
            }

            $phrase = trim($phrase);

            if ($phrase === '') {
                continue;
            }

            $key = mb_strtolower($phrase);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $phrases[] = $phrase;
        }

        return new self($phrases);
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->phrases;
    }

    public function count(): int
    {
        return count($this->phrases);
    }

    public function isEmpty(): bool
    {
        return $this->phrases === [];
    }

    /**
     * Whether $subject literally contains any phrase, case-insensitively.
     *
     * Used ONLY to attribute WHICH field of an already-matched row matched, never to decide whether a
     * row matched at all — that authority is the SQL predicate below. Keeping the two apart means a
     * disagreement between Postgres' case folding and PHP's can at worst under-attribute a field in
     * the report; it can never resurrect a row that should be deleted or delete one that should not.
     */
    public function matches(?string $subject): bool
    {
        if (!is_string($subject) || $subject === '') {
            return false;
        }

        foreach ($this->phrases as $phrase) {
            if (mb_stripos($subject, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $subject contains any phrase in EITHER its raw or its slugified form.
     *
     * The attribution counterpart of the slug patterns below — a slug holds `anna-kowalska`, which
     * `matches()` cannot see because the phrase has a space in it.
     */
    public function matchesSlug(?string $subject): bool
    {
        if (!is_string($subject) || $subject === '') {
            return false;
        }

        foreach ($this->phrases as $phrase) {
            $slug = WikilinkParser::normalize($phrase);

            if (mb_stripos($subject, $phrase) !== false || ($slug !== '' && mb_stripos($subject, $slug) !== false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * OR-match every phrase against plain text columns and against JSONB columns compared as `::text`.
     *
     * The JSONB comparison covers the serialised document, KEYS included. That over-matches slightly
     * and does so on purpose: in an erasure request the safe direction of error is "surfaced for
     * review", and every hit is shown in the dry run before anything happens.
     *
     * $slugColumns join the SAME `or` group rather than forming a second, AND-ed one — which is what
     * calling `whereMatchesSlug()` alongside this would have built, quietly turning "the name is in the
     * title OR the slug" into "in both". They are compared against the slug patterns (raw AND
     * slugified), because "Anna Kowalska" lives in a slug as `anna-kowalska` and the raw pattern, with
     * its space, cannot find it.
     *
     * @param  list<string>  $textColumns
     * @param  list<string>  $jsonColumns
     * @param  list<string>  $slugColumns
     */
    public function whereMatchesText(Builder $query, array $textColumns, array $jsonColumns = [], array $slugColumns = []): void
    {
        $patterns = $this->textPatterns();

        if ($patterns === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $grammar = $query->getQuery()->getGrammar();
        $slugPatterns = $slugColumns === [] ? [] : $this->slugPatterns();

        $query->where(function (Builder $inner) use ($patterns, $slugPatterns, $textColumns, $jsonColumns, $slugColumns, $grammar) {
            foreach ($patterns as $pattern) {
                foreach ($textColumns as $column) {
                    $inner->orWhereLike($column, $pattern);
                }

                foreach ($jsonColumns as $column) {
                    $inner->orWhereRaw($grammar->wrap($column) . '::text ilike ?', [$pattern]);
                }
            }

            foreach ($slugPatterns as $pattern) {
                foreach ($slugColumns as $column) {
                    $inner->orWhereLike($column, $pattern);
                }
            }
        });
    }

    /**
     * OR-match every phrase against a SLUG column, in both its raw and its slugified form.
     *
     * Both forms are needed and neither is redundant: "Kowalska" only ever appears in a slug as
     * `kowalska` (which the raw pattern still finds, ILIKE being case-insensitive), but a multi-word
     * phrase like "Anna Kowalska" appears as `anna-kowalska` and the raw pattern — with its space —
     * cannot find it. Slugifying through {@see WikilinkParser::normalize()} is what makes the two
     * agree, and it is the SAME normalisation that mints entry slugs, so a phrase can never be
     * slugged differently here than the data it is hunting.
     */
    public function whereMatchesSlug(Builder $query, string $column): void
    {
        $patterns = $this->slugPatterns();

        if ($patterns === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $inner) use ($patterns, $column) {
            foreach ($patterns as $pattern) {
                $inner->orWhereLike($column, $pattern);
            }
        });
    }

    /** @return list<string> */
    private function textPatterns(): array
    {
        return array_map(static fn (string $phrase) => self::pattern($phrase), $this->phrases);
    }

    /** @return list<string> */
    private function slugPatterns(): array
    {
        $patterns = [];

        foreach ($this->phrases as $phrase) {
            $patterns[self::pattern($phrase)] = true;

            $slug = WikilinkParser::normalize($phrase);

            if ($slug !== '') {
                $patterns[self::pattern($slug)] = true;
            }
        }

        return array_keys($patterns);
    }

    /**
     * A phrase as a CONTAINS pattern with its wildcards neutralised. `%`, `_` and the escape
     * character itself are backslash-escaped, which is Postgres' default LIKE/ILIKE escape character,
     * so no explicit `ESCAPE` clause is needed.
     */
    private static function pattern(string $phrase): string
    {
        return '%' . addcslashes($phrase, '\\%_') . '%';
    }

    /** Redacted on purpose: a dump of this object must never be a way for a phrase to reach a log. */
    public function __debugInfo(): array
    {
        return ['phrases' => '[redacted]', 'count' => count($this->phrases)];
    }
}
