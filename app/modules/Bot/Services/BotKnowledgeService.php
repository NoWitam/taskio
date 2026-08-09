<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\Models\Bot;
use App\Modules\Knowledge\DTOs\KnowledgeBaseDTO;
use App\Modules\Knowledge\DTOs\KnowledgeEntryDTO;
use App\Modules\Knowledge\Enums\KnowledgeBindingMode;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeBinding;
use App\Modules\Knowledge\Services\KnowledgeBaseService;
use App\Modules\Knowledge\Services\KnowledgeBindingService;
use App\Modules\Knowledge\Services\KnowledgeEntryService;
use App\Modules\Knowledge\Support\TemplateDirectiveGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WIRING a bot to a knowledge base: bind, unbind, and lift a bot's legacy built-in knowledge into a real
 * base.
 *
 * Every call across the module edge passes PRIMITIVES ({@see BotKnowledgeReader::BINDABLE_TYPE} plus the
 * bot's uuid) — Knowledge never learns what a bot is. Authorization is NOT decided here: the FormRequests
 * gate every one of these on the bot's `update` ability before a controller reaches this class, which is
 * where an ownership decision about a bot belongs.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY MIGRATION IS AN EXPLICIT, USER-INVOKED ACTION
 *
 * A bot's `knowledge` JSON column already holds entries, so the obvious move is to convert them
 * automatically. That would be wrong twice over. It would spend the workspace's AI budget indexing text
 * nobody asked to have indexed, and it would silently change what every existing bot reads — the one
 * property this batch guarantees it does not do. So a human presses the button, once, per bot.
 *
 * The migration is ADDITIVE: the `knowledge` column is left exactly as it was. The bot stops reading it
 * because a BINDING now exists and takes precedence, and un-binding restores the old behaviour perfectly.
 * Deleting the column's contents would make the action irreversible for the sake of tidiness.
 */
class BotKnowledgeService
{
    public function __construct(
        private KnowledgeBindingService $bindings,
        private KnowledgeBaseService $bases,
        private KnowledgeEntryService $entries,
        private TemplateDirectiveGuard $guard,
    ) {}

    public function binding(Bot $bot): ?KnowledgeBinding
    {
        return $this->bindings->get(BotKnowledgeReader::BINDABLE_TYPE, (string) $bot->getKey());
    }

    /** Point the bot at a base (upsert). Throws ModelNotFoundException for a base outside the workspace. */
    public function bind(Bot $bot, string $knowledgeBaseId, KnowledgeBindingMode $mode): KnowledgeBinding
    {
        return $this->bindings->attach(
            BotKnowledgeReader::BINDABLE_TYPE,
            (string) $bot->getKey(),
            $knowledgeBaseId,
            $mode,
        );
    }

    /** Stop the bot reading a base — it falls back to its own `knowledge` module. Idempotent. */
    public function unbind(Bot $bot): bool
    {
        return $this->bindings->detach(BotKnowledgeReader::BINDABLE_TYPE, (string) $bot->getKey());
    }

    /**
     * Create a knowledge base from the bot's built-in entries, fill it, and bind it.
     *
     * Entries land as `approved`: they were already being injected into this bot's prompts verbatim, so
     * treating them as drafts would be a fiction — and would make the migrated bot read NOTHING, since
     * every consumer path reads approved entries only.
     *
     * The whole thing is one transaction. A half-migrated bot — a base with three of eleven entries, or
     * entries with no binding — is worse than a failed migration, because the user's only recovery would
     * be to find and delete a partially-filled base by hand before trying again.
     *
     * @return array{base: KnowledgeBase, binding: KnowledgeBinding, entries_count: int}
     */
    public function migrateLegacy(Bot $bot): array
    {
        $entries = $bot->knowledgeEntries();

        if ($entries === []) {
            throw ValidationException::withMessages([
                'knowledge' => __('bot.knowledge.nothing_to_migrate'),
            ]);
        }

        $this->assertMigratable($entries);

        return DB::transaction(function () use ($bot, $entries): array {
            $base = $this->bases->create(new KnowledgeBaseDTO(
                name: __('bot.knowledge.base_name', ['bot' => $bot->name]),
                description: __('bot.knowledge.base_description', ['bot' => $bot->name]),
                charter: __('bot.knowledge.base_charter', ['bot' => $bot->name]),
                metadataSchema: [],
                language: app()->getLocale(),
            ));

            foreach ($entries as $entry) {
                $this->entries->create($base, new KnowledgeEntryDTO(
                    title: $entry['title'],
                    content: $entry['content'],
                    metadata: [],
                    status: KnowledgeEntryStatus::APPROVED,
                    staleAt: null,
                    changeNote: __('bot.knowledge.migration_note', ['bot' => $bot->name]),
                ));
            }

            return [
                'base' => $base,
                'binding' => $this->bind($bot, (string) $base->getKey(), KnowledgeBindingMode::AUTO),
                'entries_count' => count($entries),
            ];
        });
    }

    /**
     * Refuse the whole migration when any entry carries template syntax.
     *
     * The knowledge write path is FAIL-CLOSED about template markers ({@see TemplateDirectiveGuard}): an
     * entry is DATA, and a base is read by surfaces that run text through the template engine, so a stored
     * directive is a directive somebody else's feature will eventually execute. The bot's own column never
     * had that guard, so migrating without it would be the one door that walks unguarded content into the
     * guarded store.
     *
     * All-or-nothing rather than per-entry, and the offending titles are named: a base missing the three
     * entries that failed is a base whose owner does not know it is incomplete.
     *
     * @param  array<int, array{title: string, content: string}>  $entries
     */
    private function assertMigratable(array $entries): void
    {
        $offenders = [];

        foreach ($entries as $entry) {
            if (!$this->guard->isClean($entry['title']) || !$this->guard->isClean($entry['content'])) {
                $offenders[] = $entry['title'];
            }
        }

        if ($offenders !== []) {
            throw ValidationException::withMessages([
                'knowledge' => __('bot.knowledge.migration_blocked', [
                    'titles' => implode('; ', $offenders),
                ]),
            ]);
        }
    }
}
