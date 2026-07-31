<?php

namespace App\Modules\Bot\Models;

use App\Models\AbstractModel;
use App\Modules\Bot\Enums\BotStatus;
use App\Modules\Disk\Models\File;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
        // Visual module: the bot's LOOK — { enabled, descriptor, aesthetic, wardrobe,
        // prohibitions, reference_file_id, candidates, canonical_file_id, prompt }.
        'visual',
        // Placeholder (no logic yet — Audio module)
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
     * Dictionary entries as `{ term, meaning }`, tolerating the legacy bare-string shape
     * (an old "foo" entry reads back as `{term: 'foo', meaning: ''}`). Malformed rows dropped.
     *
     * @return array<int, array{term: string, meaning: string}>
     */
    public function dictionaryEntries(): array
    {
        return collect($this->dictionary ?? [])
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
     * Phrase entries as `{ phrase, context }`, tolerating the legacy bare-string shape
     * (an old "foo" phrase reads back as `{phrase: 'foo', context: null}`).
     *
     * @return array<int, array{phrase: string, context: string|null}>
     */
    public function phraseEntries(): array
    {
        return collect($this->phrases ?? [])
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
     * Whether the VISUAL module is enabled. Like the knowledge module this is an explicitly
     * toggled optional module: the identity keeps its stored content while off, it is simply
     * not applied downstream. Fail-soft on the legacy NULL column (a bot created before the
     * module existed) — no cast, no key, no error.
     */
    public function visualEnabled(): bool
    {
        return is_array($this->visual) && (bool) ($this->visual['enabled'] ?? false);
    }

    /**
     * The bot's visual identity in its NORMALIZED shape, or NULL when the module was never
     * configured (the legacy `visual = null` column). Mirrors {@see knowledgeEntries()}: every
     * read goes through here, so a partially-written or malformed blob still reads back as a
     * complete, typed map instead of leaking whatever JSON happens to sit in the column.
     *
     *   descriptor   — SHORT character description (feeds the future subject line),
     *   aesthetic    — palette / medium / lighting, applies to EVERY image,
     *   wardrobe     — the default outfit; the ONE steerable defense against the provider's
     *                  output-side moderation wall (a swimsuit render is refused as [sexual],
     *                  a dress passes), so it is a first-class field, not part of `aesthetic`,
     *   prohibitions — visual "never draw this" list,
     *   reference_file_id — the source image (upload or Disk pick) a (re)generation edits,
     *   candidates   — generated iterations to choose from (file ids, newest last),
     *   canonical_file_id — the APPROVED likeness,
     *   prompt       — the last composed generation prompt (audit / re-run).
     *
     * @return array{enabled: bool, descriptor: ?string, aesthetic: ?string, wardrobe: ?string, prohibitions: array<int, string>, reference_file_id: ?string, candidates: array<int, string>, canonical_file_id: ?string, prompt: ?string}|null
     */
    public function visualIdentity(): ?array
    {
        $visual = $this->visual;

        if (!is_array($visual) || $visual === []) {
            return null;
        }

        return [
            'enabled' => (bool) ($visual['enabled'] ?? false),
            'descriptor' => self::visualText($visual['descriptor'] ?? null),
            'aesthetic' => self::visualText($visual['aesthetic'] ?? null),
            'wardrobe' => self::visualText($visual['wardrobe'] ?? null),
            'prohibitions' => collect($visual['prohibitions'] ?? [])
                ->filter(fn ($item) => is_string($item) && $item !== '')
                ->map(fn (string $item) => $item)
                ->values()
                ->all(),
            'reference_file_id' => self::visualText($visual['reference_file_id'] ?? null),
            'candidates' => collect($visual['candidates'] ?? [])
                ->filter(fn ($item) => is_string($item) && $item !== '')
                ->map(fn (string $item) => $item)
                ->values()
                ->all(),
            'canonical_file_id' => self::visualText($visual['canonical_file_id'] ?? null),
            'prompt' => self::visualText($visual['prompt'] ?? null),
        ];
    }

    /** A stored visual string, or null for anything blank/non-scalar. */
    private static function visualText(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Every FILE this bot owns — reference uploads and generated identity candidates, stored as
     * resource files (`fileable_type = 'bot'`, the morph alias this module registers). They are
     * deliberately NOT disk-native, so the Disk browser (which selects `fileable_type = 'folder'`
     * via File::scopeDiskNative) never lists them and the "Zasoby" tree — which only walks
     * REGISTERED resource types — never surfaces them either.
     */
    public function visualImages(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
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
