<?php

namespace App\Modules\Bot\DTOs;

use Illuminate\Http\Request;

class BotDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $icon,
        // Text module
        public readonly string $persona,
        public readonly ?string $style,
        // dictionary: [{ term, meaning }]; phrases: [{ phrase, context }]; prohibitions: string[]
        public readonly array $dictionary,
        public readonly array $phrases,
        public readonly array $prohibitions,
        // Task-execution module — null leaves the column untouched.
        public readonly ?array $taskExecution,
        // Knowledge module — { enabled, entries: [{title, content}] }.
        public readonly array $knowledge,
    ) {}

    /**
     * Status is intentionally ABSENT: a bot is created inactive and toggled only through
     * the dedicated status endpoint, never via the create/update body.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->string('name')->value(),
            description: $request->string('description')->value() ?: null,
            icon: $request->string('icon')->value() ?: null,
            persona: $request->string('persona')->value(),
            style: $request->string('style')->value() ?: null,
            dictionary: self::normalizeDictionary($request),
            phrases: self::normalizePhrases($request),
            prohibitions: array_values(array_filter(
                $request->array('prohibitions'),
                fn ($item) => is_string($item) && $item !== ''
            )),
            taskExecution: self::normalizeTaskExecution($request),
            knowledge: self::normalizeKnowledge($request),
        );
    }

    /**
     * Dictionary entries into `{ term, meaning }`. Tolerates the legacy bare-string shape
     * (an old "foo" entry becomes `{term: 'foo', meaning: ''}`). Malformed rows are dropped.
     *
     * @return array<int, array{term: string, meaning: string}>
     */
    private static function normalizeDictionary(Request $request): array
    {
        return collect($request->array('dictionary'))
            ->map(function ($entry) {
                if (is_string($entry)) {
                    return $entry === '' ? null : ['term' => $entry, 'meaning' => ''];
                }

                if (is_array($entry) && filled($entry['term'] ?? null)) {
                    return ['term' => (string) $entry['term'], 'meaning' => (string) ($entry['meaning'] ?? '')];
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Phrase entries into `{ phrase, context }`. Tolerates the legacy bare-string shape
     * (an old "foo" phrase becomes `{phrase: 'foo', context: null}`). Malformed rows dropped.
     *
     * @return array<int, array{phrase: string, context: string|null}>
     */
    private static function normalizePhrases(Request $request): array
    {
        return collect($request->array('phrases'))
            ->map(function ($entry) {
                if (is_string($entry)) {
                    return $entry === '' ? null : ['phrase' => $entry, 'context' => null];
                }

                if (is_array($entry) && filled($entry['phrase'] ?? null)) {
                    $context = $entry['context'] ?? null;

                    return ['phrase' => (string) $entry['phrase'], 'context' => filled($context) ? (string) $context : null];
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Normalize the task-execution module into the persisted JSON shape
     * ({enabled, tools}) or null when absent. The legacy `knowledge_source` key is no
     * longer read or written — the knowledge module replaces it (any value sent by an
     * old client is silently ignored, not persisted).
     */
    private static function normalizeTaskExecution(Request $request): ?array
    {
        if (!$request->has('task_execution') || $request->input('task_execution') === null) {
            return null;
        }

        $config = $request->array('task_execution');

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'tools' => array_values($config['tools'] ?? []),
        ];
    }

    /**
     * Normalize the knowledge module into its persisted `{ enabled, entries }` shape.
     * `enabled` is an explicit per-module toggle (the module can be on with zero entries).
     * Entries are cleaned to a { title, content } list; malformed entries are dropped.
     *
     * @return array{enabled: bool, entries: array<int, array{title: string, content: string}>}
     */
    private static function normalizeKnowledge(Request $request): array
    {
        $knowledge = $request->array('knowledge');

        $entries = collect($knowledge['entries'] ?? [])
            ->filter(fn ($entry) => is_array($entry) && filled($entry['title'] ?? null))
            ->map(fn ($entry) => [
                'title' => (string) $entry['title'],
                'content' => (string) ($entry['content'] ?? ''),
            ])
            ->values()
            ->all();

        return [
            'enabled' => (bool) ($knowledge['enabled'] ?? false),
            'entries' => $entries,
        ];
    }
}
