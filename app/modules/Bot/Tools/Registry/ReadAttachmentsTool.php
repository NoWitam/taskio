<?php

namespace App\Modules\Bot\Tools\Registry;

use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Tools\BotToolContext;
use App\Modules\Disk\Models\File;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * read_attachments(name?): list the CURRENT task's attachments, or return the TEXT
 * content of one named attachment. Text-y types only (txt/md/csv/json), size-capped.
 * Attachments are resolved STRICTLY through the task's files() relation — no path
 * traversal, and another task's files are unreachable. Records `tool_used`.
 */
class ReadAttachmentsTool implements Tool
{
    /** Extensions that are safe to return as text. */
    private const TEXT_EXTENSIONS = ['txt', 'md', 'csv', 'json'];

    private const TEXT_MIMES = ['text/plain', 'text/markdown', 'text/csv', 'application/json', 'application/xml', 'text/xml'];

    public function __construct(
        private BotToolContext $ctx,
    ) {}

    public function description(): Stringable|string
    {
        return 'Wyświetla listę załączników zadania lub zwraca treść jednego załącznika tekstowego '
            . '(txt, md, csv, json). Bez podania nazwy zwraca listę. '
            . 'Jeśli kilka załączników ma tę samą nazwę, odczytywany jest najnowszy.';
    }

    public function handle(Request $request): Stringable|string
    {
        $name = trim((string) ($request['name'] ?? ''));

        if ($name === '') {
            return $this->list();
        }

        return $this->read($name);
    }

    private function list(): string
    {
        $files = $this->ctx->task->files()->get();

        $this->ctx->actions->record($this->ctx->bot, $this->ctx->task, BotActionType::ToolUsed, [
            'tool' => 'read_attachments',
            'action' => 'list',
            'count' => $files->count(),
        ]);

        if ($files->isEmpty()) {
            return 'To zadanie nie ma załączników.';
        }

        return $files->map(fn (File $f) => "- {$f->name} ({$f->size} B)")->implode("\n");
    }

    private function read(string $name): string
    {
        // Resolve strictly within THIS task's attachments (no traversal).
        $matches = $this->ctx->task->files()->where('name', $name)->orderByDesc('created_at')->get();

        if ($matches->isEmpty()) {
            return "Nie znaleziono załącznika o nazwie \"{$name}\" w tym zadaniu.";
        }

        // S2: duplicate display names are ambiguous — read the NEWEST and say so, so the
        // bot never silently reads an arbitrary one (documented in the tool description).
        $file = $matches->first();
        $ambiguityNote = $matches->count() > 1
            ? "(Uwaga: {$matches->count()} załączników o tej nazwie — odczytano najnowszy.)\n"
            : '';

        if (!$this->isTextual($file)) {
            return 'Ten załącznik nie jest tekstowy. Dozwolone typy: ' . implode(', ', self::TEXT_EXTENSIONS) . '.';
        }

        $maxBytes = (int) config('ai.read_attachment_max_bytes', 1024 * 1024);
        if ((int) $file->size > $maxBytes) {
            return 'Załącznik przekracza dozwolony rozmiar do odczytu.';
        }

        $content = Storage::get($file->path);

        if ($content === null) {
            return 'Nie udało się odczytać zawartości załącznika.';
        }

        // S3: don't trust stored mime/extension — sniff the actual bytes. Content that is
        // not valid UTF-8 or is control-char heavy is treated as binary.
        if (!$this->looksTextual($content)) {
            return 'Ten załącznik nie jest tekstowy. Dozwolone typy: ' . implode(', ', self::TEXT_EXTENSIONS) . '.';
        }

        $this->ctx->actions->record($this->ctx->bot, $this->ctx->task, BotActionType::ToolUsed, [
            'tool' => 'read_attachments',
            'action' => 'read',
            'file' => $file->name,
        ]);

        return $ambiguityNote . $content;
    }

    /**
     * Cheap content sniff: reject content that is not valid UTF-8 or contains a NUL /
     * high ratio of control characters (binary payload masquerading as a text extension).
     */
    private function looksTextual(string $content): bool
    {
        if ($content === '') {
            return true;
        }

        if (str_contains($content, "\0")) {
            return false;
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            return false;
        }

        // Sample the head; count control chars excluding tab/newline/carriage-return.
        $sample = substr($content, 0, 4096);
        $control = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sample);

        return $control / max(strlen($sample), 1) < 0.1;
    }

    private function isTextual(File $file): bool
    {
        $extension = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));

        if (in_array($extension, self::TEXT_EXTENSIONS, true)) {
            return true;
        }

        return in_array($file->mime_type, self::TEXT_MIMES, true);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Nazwa załącznika do odczytania (pomiń, aby wyświetlić listę).')
                ->nullable()
                ->required(),
        ];
    }
}
