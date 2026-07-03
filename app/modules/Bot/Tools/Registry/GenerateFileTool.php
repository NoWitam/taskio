<?php

namespace App\Modules\Bot\Tools\Registry;

use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Tools\BotToolContext;
use App\Modules\Disk\Enums\FileType;
use App\Modules\Disk\Models\File;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * generate_file(name, content): create a text-based file and attach it to the CURRENT
 * task through the existing Disk mechanism (a File row fileable to the task, stored on
 * the default disk). Enforces safe text extensions, sanitizes the filename and caps the
 * size. The attachment is visible/downloadable like any other. Records `tool_used` with
 * the final filename.
 */
class GenerateFileTool implements Tool
{
    /** Text-only extensions the bot may generate → mime type. */
    private const ALLOWED = [
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'csv' => 'text/csv',
        'json' => 'application/json',
    ];

    public function __construct(
        private BotToolContext $ctx,
    ) {}

    public function description(): Stringable|string
    {
        return 'Tworzy plik tekstowy (txt, md, csv, json) i dołącza go jako załącznik do zadania. '
            . 'Używaj do zapisania rezultatu, który ma być pobierany przez człowieka.';
    }

    public function handle(Request $request): Stringable|string
    {
        $name = (string) ($request['name'] ?? '');
        $content = (string) ($request['content'] ?? '');

        [$filename, $extension] = $this->sanitizeName($name);

        if ($extension === null) {
            return 'Niedozwolone rozszerzenie pliku. Dozwolone: ' . implode(', ', array_keys(self::ALLOWED)) . '.';
        }

        $maxBytes = (int) config('ai.generate_file_max_bytes', 1024 * 1024);
        if (strlen($content) > $maxBytes) {
            return 'Treść pliku przekracza dozwolony rozmiar.';
        }

        $path = 'uploads/' . Str::uuid() . '.' . $extension;
        Storage::put($path, $content);

        // uploader_id is NOT NULL; a bot has no user, so attribute the file to the
        // task's (human) creator. fileable = the task, so it shows as a task attachment.
        // NOTE (S1, documented compromise): attributing a bot-generated file to the task
        // creator is a deliberate stopgap until a future schema pass makes uploader_id
        // nullable and adds an explicit bot-uploader marker (B6). Do not "fix" here.
        File::create([
            'name' => $filename,
            'path' => $path,
            'type' => FileType::fromMimeType(self::ALLOWED[$extension]),
            'mime_type' => self::ALLOWED[$extension],
            'size' => strlen($content),
            'uploader_id' => $this->ctx->task->creator_id,
            'fileable_type' => $this->ctx->task->getMorphClass(),
            'fileable_id' => $this->ctx->task->getKey(),
        ]);

        $this->ctx->actions->record($this->ctx->bot, $this->ctx->task, BotActionType::ToolUsed, [
            'tool' => 'generate_file',
            'file' => $filename,
        ]);

        return "Utworzono załącznik: {$filename}";
    }

    /**
     * Sanitize the requested name to a safe basename + allowed extension.
     *
     * @return array{0: string, 1: ?string} [filename, extension|null-when-disallowed]
     */
    private function sanitizeName(string $name): array
    {
        // Strip any path components — no traversal, basename only.
        $name = basename(str_replace('\\', '/', $name));

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = pathinfo($name, PATHINFO_FILENAME);

        // Keep a conservative charset for the base name, then collapse dot/space runs
        // (so "pa ss..wd" -> "pa ss.wd") — no traversal artifacts survive.
        $base = preg_replace('/[^A-Za-z0-9 _.\-]/u', '', $base) ?: 'plik';
        $base = preg_replace('/\.{2,}/', '.', $base) ?? $base;
        $base = trim($base, ' .') ?: 'plik';
        $base = Str::limit($base, 100, '');

        if (!array_key_exists($extension, self::ALLOWED)) {
            return [$base, null];
        }

        return ["{$base}.{$extension}", $extension];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Nazwa pliku z rozszerzeniem (txt, md, csv lub json).')
                ->required(),
            'content' => $schema->string()
                ->description('Zawartość tekstowa pliku.')
                ->required(),
        ];
    }
}
