<?php

namespace App\Modules\Bot\DTOs;

use App\Modules\Bot\Enums\BotStatus;
use Illuminate\Http\Request;

class BotDTO
{
    public function __construct(
        public readonly string $name,
        public readonly BotStatus $status,
        public readonly ?string $description,
        public readonly ?string $icon,
        // Text module
        public readonly string $persona,
        public readonly ?string $style,
        public readonly array $dictionary,
        public readonly array $phrases,
        public readonly array $prohibitions,
        // Task-execution module — null leaves the column untouched.
        public readonly ?array $taskExecution,
        // Knowledge module — { enabled, entries: [{title, content}] }.
        public readonly array $knowledge,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->string('name')->value(),
            status: BotStatus::from($request->input('status', BotStatus::DRAFT->value)),
            description: $request->string('description')->value() ?: null,
            icon: $request->string('icon')->value() ?: null,
            persona: $request->string('persona')->value(),
            style: $request->string('style')->value() ?: null,
            dictionary: $request->array('dictionary'),
            phrases: $request->array('phrases'),
            prohibitions: $request->array('prohibitions'),
            taskExecution: self::normalizeTaskExecution($request),
            knowledge: self::normalizeKnowledge($request),
        );
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
