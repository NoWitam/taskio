<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ALIASES: the other surface forms an entry is called by — "wieży Eiffla", "wieżą Eiffla",
 * "Eiffel Tower".
 *
 * WHY A STORED LIST RATHER THAN BETTER MATCHING. The mention layer recognises an entry's name in prose
 * by truncation stemming, which is a guess about Polish inflection and wrong in both directions: it
 * misses irregular forms and it over-matches ordinary nouns. The principled fix is dictionary
 * lemmatisation — and the research found no usable one for Polish in a PHP stack (Morfologik is
 * Java/Elasticsearch), so recommending it would have been recommending nothing.
 *
 * An alias list is the cheap remedy the same research found in Obsidian's frontmatter: instead of
 * inferring the forms, someone WRITES them — and for entries the AI composer creates, the composer
 * writes them itself, because a language model knows Polish inflection far better than a prefix rule
 * ever will. It is a list of strings on the row that produced it, so it costs one column and no
 * queries.
 *
 * SCOPE: aliases feed the MENTION layer only. A `[[wikilink]]` still resolves by SLUG and nothing else
 * — the slug is the entry's address, aliases are how its name is spelled in a sentence, and letting a
 * link target an alias would give one entry several addresses that a rename could not follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->jsonb('aliases')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });
    }
};
