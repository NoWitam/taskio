<?php

namespace App\Modules\Bot\Models;

use App\Models\AbstractModel;
use App\Modules\Bot\Enums\BotStatus;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bot extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, SoftDeletes, TenantAware;

    protected $table = 'bots';

    protected $fillable = [
        'name',
        'status',
        'description',
        'icon',
        // Text module (mandatory)
        'persona',
        'style',
        'dictionary',
        'phrases',
        'prohibitions',
        // Task-execution module
        'task_execution',
        // Knowledge module: array of { title, content } entries.
        'knowledge',
        // Placeholders (no logic yet — Visual / Audio modules)
        'visual',
        'audio',
        'creator_id',
    ];

    protected $casts = [
        'status' => BotStatus::class,
        'dictionary' => 'array',
        'phrases' => 'array',
        'prohibitions' => 'array',
        'task_execution' => 'array',
        'knowledge' => 'array',
        'visual' => 'array',
        'audio' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Whether the knowledge module is enabled. When off, entries are NOT injected into
     * the execution context (an explicitly toggled optional module — the user turns it on
     * before it takes effect). Tolerates the legacy bare-array shape (treated as enabled
     * when it held entries) so older stored data keeps working.
     */
    public function knowledgeEnabled(): bool
    {
        $knowledge = $this->knowledge;

        if (is_array($knowledge) && array_key_exists('enabled', $knowledge)) {
            return (bool) $knowledge['enabled'];
        }

        // Legacy bare-array shape: enabled iff it carried any entries.
        return $this->knowledgeEntries() !== [];
    }

    /**
     * The bot's knowledge entries — an array of { title, content } maps (empty when the
     * knowledge module is unused). Reads the `{enabled, entries}` module shape, and falls
     * back to the legacy bare-array shape. Normalizes malformed entries to a clean list.
     *
     * @return array<int, array{title: string, content: string}>
     */
    public function knowledgeEntries(): array
    {
        $knowledge = $this->knowledge;
        $entries = (is_array($knowledge) && array_key_exists('entries', $knowledge))
            ? $knowledge['entries']
            : $knowledge; // legacy: the column WAS the entries array

        return collect($entries ?? [])
            ->filter(fn ($entry) => is_array($entry) && filled($entry['title'] ?? null))
            ->map(fn ($entry) => [
                'title' => (string) $entry['title'],
                'content' => (string) ($entry['content'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Whether the bot is wired up to execute assigned tasks. Task execution
     * itself ships in Batch 2 — this only reflects the persisted config flag.
     */
    public function canExecuteTasks(): bool
    {
        return $this->status === BotStatus::ACTIVE
            && (bool) ($this->task_execution['enabled'] ?? false);
    }

    protected static function newFactory()
    {
        return \Database\Factories\BotFactory::new();
    }
}
