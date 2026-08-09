<?php

namespace App\Modules\Knowledge\Support;

/**
 * FAIL-CLOSED refusal of template-engine syntax inside knowledge content.
 *
 * A knowledge entry is DATA. Its whole purpose is to be retrieved and pasted into an AI context, and
 * several of the surfaces that will do that (B6) run their text through the shared template engine.
 * If an entry may contain that engine's markers, then whoever can write an entry can write a
 * directive that a completely different feature later EXECUTES on someone else's behalf — reading
 * another tier's variables, or spending on an AI call, from inside what everybody treats as a note.
 * The cheapest place to close that is here, at the only door content comes in through.
 *
 * So the rule is refusal, not escaping. Escaping would mean every present and future consumer had to
 * remember to un-escape identically, and the first one that forgot would reopen the hole silently.
 * Refusing costs a writer the ability to type two characters that have no meaning in prose, and it
 * cannot be forgotten by a consumer that does not exist yet.
 *
 * The patterns MIRROR the engine's own parser rather than approximating it — deliberately including
 * the branch-marker regex verbatim, so this guard can never be NARROWER than the thing it protects
 * against. That does reject a whole-line wikilink whose target starts with `IF`/`ELSE` in capitals
 * (`[[IFRS-standards]]`); minted slugs are lowercase, so the cost is a link nobody could have
 * followed anyway, and the alternative — being cleverer than the parser — is how these guards fail.
 *
 * NUL bytes are refused for the neighbouring reason: the shared resolver's injection mask assumes
 * values are NUL-free, so a NUL in retrieved text could forge a masked span.
 */
class TemplateDirectiveGuard
{
    /**
     * marker key => detector. The key names the translation line shown to the writer, so a refusal
     * says WHICH syntax was found instead of a generic "invalid content".
     *
     * @var array<string, string> marker key => regex
     */
    private const PATTERNS = [
        // Editor directives: @[variable]("…"), @[ai-text]("…") — matched on the opener alone.
        'directive' => '/@\[/',
        // Flat reference tokens: {{ path }} — matched on the opener alone.
        'reference' => '/\{\{/',
        // The fenced conditional container.
        'if_block' => '/^\s*```if-block\b/m',
        // Its branch markers — the engine's own regex, applied per line.
        'branch' => '/^[ \t]*\[\[(IF|ELSE_IF|ELSE)(.*)?\]\][ \t]*$/m',
        // A NUL byte would let content forge the resolver's injection mask.
        'nul' => '/\x00/',
    ];

    /**
     * The key of the FIRST template marker found in $content, or null when it is clean. One key (not
     * a list) because the message is an instruction to remove the syntax, and naming every occurrence
     * would not make that instruction clearer.
     */
    public function violation(?string $content): ?string
    {
        if (!is_string($content) || $content === '') {
            return null;
        }

        foreach (self::PATTERNS as $key => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return $key;
            }
        }

        return null;
    }

    public function isClean(?string $content): bool
    {
        return $this->violation($content) === null;
    }

    /**
     * The same check over an arbitrary VALUE — every string inside a scalar, list or map (keys
     * included). Metadata goes through here: a `text` metadata field is prose like any other, and it
     * will be injected into the same contexts the entry body is, so exempting it would leave the
     * door open one field to the left of where it was closed.
     */
    public function violationIn(mixed $value): ?string
    {
        if (is_string($value)) {
            return $this->violation($value);
        }

        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $key => $item) {
            $found = is_string($key) ? $this->violation($key) : null;

            if ($found === null) {
                $found = $this->violationIn($item);
            }

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
